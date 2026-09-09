<?php
/**
 * 2TOOLNE AI GATEWAY & ACCESS KEYS CONTROLLER
 * Provides scoped, jailed HTTPS automation access for local AIs (Codex, Claude, Antigravity)
 * and developer scripts without exposing web sessions, passwords, or unrelated Cloud assets.
 * Compatible with PHP 7.4.33 & PHP 8.x
 */

declare(strict_types=1);

require_once __DIR__ . "/../Database.php";
require_once __DIR__ . "/../Router.php";
require_once __DIR__ . "/../storage/CloudAuthHelper.php";
require_once __DIR__ . "/../storage/CloudQuotaManager.php";
require_once __DIR__ . "/../storage/StorageAllocator.php";
require_once __DIR__ . "/../storage/CryptoService.php";
require_once __DIR__ . "/../storage/GoogleDriveStorageAdapter.php";
require_once __DIR__ . "/../services/WorkspacePermissionService.php";

class AiGatewayController {

    /**
     * POST /api/v1/ai/keys
     * Create a dedicated, scoped AI Access Key bound to exactly one Workspace
     */
    public static function createKey(array $params, array $body): void {
        $user = CloudAuthHelper::getCurrentUser();
        if (!$user) {
            Router::error("Authentication required to create AI Access Key", 401, "UNAUTHORIZED");
        }

        if (!empty($user["ai_access_key"])) {
            Router::error("AI Access Keys cannot create other AI Access Keys", 403, "FORBIDDEN");
        }

        $userId = (string)$user["id"];
        $displayName = trim($body["display_name"] ?? ($body["name"] ?? ""));
        if (empty($displayName)) {
            $displayName = "AI Access Key " . date("Y-m-d H:i");
        }

        $workspaceId = trim($body["workspace_id"] ?? "");
        if (empty($workspaceId)) {
            Router::error("workspace_id is required. 1 API key must be bound to 1 workspace.", 400);
        }

        $auth = CloudAuthHelper::authorizeSpaceAccess($workspaceId, $userId);
        if (!$auth["allowed"]) {
            Router::error("You do not have permission to access the selected workspace", 403, "FORBIDDEN");
        }

        $space = $auth["space"];
        $workspaceType = ($space["owner_type"] === "TEAM") ? "TEAM" : "PERSONAL";
        $teamId = ($workspaceType === "TEAM") ? $space["owner_id"] : null;

        // Ensure "AI Inputs" root folder exists in this space
        $rootFolderId = self::ensureAiInputsFolder($workspaceId, $userId);

        // Generate cryptographically secure key: 2tl_ai_<prefix>_<secret>
        $keyId = "aikey_" . bin2hex(random_bytes(8));
        $prefix = "2tl_ai_" . substr(bin2hex(random_bytes(6)), 0, 8);
        $secret = bin2hex(random_bytes(32));
        $fullKey = "{$prefix}_{$secret}";
        $secretHash = hash("sha256", $secret);

        $defaultScopes = ["CREATE_FOLDER", "CREATE_SUBFOLDER", "UPLOAD", "LIST", "READ"];
        $scopes = isset($body["scopes"]) && is_array($body["scopes"]) ? $body["scopes"] : $defaultScopes;

        $expiresInDays = isset($body["expires_in_days"]) ? (int)$body["expires_in_days"] : 365;
        $expiresAt = ($expiresInDays > 0) ? date("Y-m-d H:i:s", time() + ($expiresInDays * 86400)) : null;

        $db = Database::getConnection();
        $stmt = $db->prepare("
            INSERT INTO ai_access_keys (
                id, user_id, workspace_type, workspace_id, team_id,
                display_name, key_prefix, secret_hash, root_folder_id,
                scopes, created_at, expires_at
            ) VALUES (
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, NOW(), ?
            )
        ");
        $stmt->execute([
            $keyId, $userId, $workspaceType, $workspaceId, $teamId,
            $displayName, $prefix, $secretHash, $rootFolderId,
            json_encode($scopes), $expiresAt
        ]);

        Router::json([
            "ok"             => true,
            "success"        => true,
            "key"            => $fullKey, // Shown ONLY ONCE
            "id"             => $keyId,
            "display_name"   => $displayName,
            "workspace_id"   => $workspaceId,
            "workspace_type" => $workspaceType,
            "team_id"        => $teamId,
            "root_folder_id" => $rootFolderId,
            "root_path"      => "AI Inputs/",
            "scopes"         => $scopes,
            "created_at"     => date("c"),
            "expires_at"     => $expiresAt ? date("c", strtotime($expiresAt)) : null,
            "warning"        => "Store this key safely now. The raw key cannot be retrieved again."
        ], 201);
    }

    /**
     * GET /api/v1/ai/keys
     * List all AI Access Keys belonging to the authenticated user (masked)
     */
    public static function listKeys(array $params, array $body): void {
        $user = CloudAuthHelper::getCurrentUser();
        if (!$user) {
            Router::error("Authentication required", 401, "UNAUTHORIZED");
        }

        if (!empty($user["ai_access_key"])) {
            Router::error("AI Access Keys cannot list other keys", 403, "FORBIDDEN");
        }

        $userId = (string)$user["id"];
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT k.id, k.display_name, k.workspace_type, k.workspace_id, k.team_id,
                   k.key_prefix, k.scopes, k.created_at, k.last_used_at, k.expires_at, k.revoked_at,
                   cs.name as workspace_name
            FROM ai_access_keys k
            LEFT JOIN cloud_spaces cs ON k.workspace_id = cs.id
            WHERE k.user_id = ?
            ORDER BY k.created_at DESC
        ");
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $keys = [];
        foreach ($rows as $r) {
            $keys[] = [
                "id"             => $r["id"],
                "display_name"   => $r["display_name"],
                "workspace_type" => $r["workspace_type"],
                "workspace_id"   => $r["workspace_id"],
                "workspace_name" => $r["workspace_name"] ?: ($r["workspace_type"] === "TEAM" ? "Team Space" : "Personal Space"),
                "team_id"        => $r["team_id"],
                "key_prefix"     => $r["key_prefix"],
                "masked_key"     => $r["key_prefix"] . "_••••••••",
                "scopes"         => json_decode($r["scopes"] ?? "[]", true) ?: [],
                "created_at"     => $r["created_at"],
                "last_used_at"   => $r["last_used_at"],
                "expires_at"     => $r["expires_at"],
                "revoked_at"     => $r["revoked_at"],
                "is_active"      => empty($r["revoked_at"]) && (empty($r["expires_at"]) || strtotime($r["expires_at"]) > time()),
            ];
        }

        Router::json([
            "ok"   => true,
            "keys" => $keys,
        ]);
    }

    /**
     * DELETE /api/v1/ai/keys/{id} or POST /api/v1/ai/keys/{id}/revoke
     * Immediately revoke an AI Access Key
     */
    public static function revokeKey(array $params, array $body): void {
        $user = CloudAuthHelper::getCurrentUser();
        if (!$user) {
            Router::error("Authentication required", 401, "UNAUTHORIZED");
        }

        $keyId = $params["id"] ?? "";
        $userId = (string)$user["id"];

        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT id, user_id FROM ai_access_keys WHERE id = ? LIMIT 1");
        $stmt->execute([$keyId]);
        $key = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$key) {
            Router::error("Key not found", 404);
        }

        if ($key["user_id"] !== $userId && !CloudAuthHelper::isAdmin()) {
            Router::error("Access denied", 403, "FORBIDDEN");
        }

        $upd = $db->prepare("UPDATE ai_access_keys SET revoked_at = NOW() WHERE id = ?");
        $upd->execute([$keyId]);

        Router::json([
            "ok"      => true,
            "success" => true,
            "message" => "AI Access Key revoked successfully. Any active API requests using this key will immediately fail.",
        ]);
    }

    /**
     * GET /api/v1/ai/fs/list
     * List folder contents strictly jailed under AI Inputs/
     */
    public static function fsList(array $params, array $body): void {
        $auth = self::resolveAuthContext("LIST");
        $workspaceId = $auth["workspace_id"];
        $rootFolderId = $auth["root_folder_id"];

        $relPath = $_GET["path"] ?? ($body["path"] ?? "");
        $pathCheck = CloudAuthHelper::validateJailedPath($relPath);
        if (!$pathCheck["valid"]) {
            Router::error("Invalid or escaping path: " . $pathCheck["error"], 403, "PATH_TRAVERSAL_BLOCKED");
        }

        $resolved = self::resolveFolderHierarchy($workspaceId, $rootFolderId, $pathCheck["segments"], false);
        if (!$resolved) {
            Router::error("Directory not found: " . $pathCheck["normalized"], 404);
        }

        $targetFolderId = $resolved["folder_id"];
        $db = Database::getConnection();

        // Fetch subfolders
        $fldStmt = $db->prepare("
            SELECT id, name, created_at, updated_at
            FROM cloud_folders
            WHERE cloud_space_id = ? AND parent_id = ? AND deleted_at IS NULL
            ORDER BY name ASC
        ");
        $fldStmt->execute([$workspaceId, $targetFolderId]);
        $subfolders = $fldStmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch files
        $fileStmt = $db->prepare("
            SELECT id, filename, extension, mime_type, size_bytes, checksum_sha256, status, created_at, updated_at
            FROM cloud_files
            WHERE cloud_space_id = ? AND folder_id = ? AND status = 'ACTIVE' AND deleted_at IS NULL
            ORDER BY filename ASC
        ");
        $fileStmt->execute([$workspaceId, $targetFolderId]);
        $files = $fileStmt->fetchAll(PDO::FETCH_ASSOC);

        Router::json([
            "ok"             => true,
            "workspace_id"   => $workspaceId,
            "path"           => $pathCheck["normalized"],
            "folder_id"      => $targetFolderId,
            "root_folder_id" => $rootFolderId,
            "folders"        => $subfolders,
            "files"          => $files,
            "total_items"    => count($subfolders) + count($files),
        ]);
    }

    /**
     * POST /api/v1/ai/fs/mkdir
     * Create folder/subfolder strictly jailed under AI Inputs/
     */
    public static function fsMkdir(array $params, array $body): void {
        $auth = self::resolveAuthContext("CREATE_FOLDER");
        $workspaceId = $auth["workspace_id"];
        $rootFolderId = $auth["root_folder_id"];
        $userId = $auth["user_id"];

        $relPath = trim($body["path"] ?? ($body["folder_name"] ?? ""));
        if (empty($relPath)) {
            Router::error("path is required", 400);
        }

        $pathCheck = CloudAuthHelper::validateJailedPath($relPath);
        if (!$pathCheck["valid"] || empty($pathCheck["segments"])) {
            Router::error("Invalid or escaping path: " . ($pathCheck["error"] ?? "Empty path"), 403, "PATH_TRAVERSAL_BLOCKED");
        }

        $resolved = self::resolveFolderHierarchy($workspaceId, $rootFolderId, $pathCheck["segments"], true, $userId);

        Router::json([
            "ok"        => true,
            "success"   => true,
            "path"      => $pathCheck["normalized"],
            "folder_id" => $resolved["folder_id"],
            "message"   => "Directory created or already exists.",
        ], 201);
    }

    /**
     * POST /api/v1/ai/fs/upload
     * Upload a file into a jailed path under AI Inputs/
     */
    public static function fsUpload(array $params, array $body): void {
        $auth = self::resolveAuthContext("UPLOAD");
        $workspaceId = $auth["workspace_id"];
        $rootFolderId = $auth["root_folder_id"];
        $userId = $auth["user_id"];

        $targetPath = trim($_POST["path"] ?? ($body["path"] ?? ""));
        if (empty($targetPath)) {
            Router::error("path is required (e.g. Japan_001/script.txt)", 400);
        }

        $pathCheck = CloudAuthHelper::validateJailedPath($targetPath);
        if (!$pathCheck["valid"] || empty($pathCheck["segments"])) {
            Router::error("Invalid or escaping path: " . ($pathCheck["error"] ?? "Empty path"), 403, "PATH_TRAVERSAL_BLOCKED");
        }

        $segments = $pathCheck["segments"];
        $filename = array_pop($segments);

        if (empty($filename)) {
            Router::error("Missing filename in path", 400);
        }

        // Resolve destination folder, auto-creating subdirectories if needed
        $resolved = self::resolveFolderHierarchy($workspaceId, $rootFolderId, $segments, true, $userId);
        $folderId = $resolved["folder_id"];

        // Get file content
        $tmpFilePath = null;
        $fileSize = 0;
        $content = null;

        if (!empty($_FILES["file"]["tmp_name"]) && is_uploaded_file($_FILES["file"]["tmp_name"])) {
            $tmpFilePath = $_FILES["file"]["tmp_name"];
            $fileSize = (int)$_FILES["file"]["size"];
            $filename = trim($_FILES["file"]["name"]) ?: $filename;
        } elseif (isset($body["content"])) {
            $content = (string)$body["content"];
            $fileSize = strlen($content);
        } elseif (isset($body["content_base64"])) {
            $content = base64_decode((string)$body["content_base64"]);
            $fileSize = strlen($content);
        } else {
            $rawInput = file_get_contents("php://input");
            if (!empty($rawInput)) {
                $content = $rawInput;
                $fileSize = strlen($rawInput);
            }
        }

        if ($fileSize <= 0 && $content === null && empty($tmpFilePath)) {
            Router::error("No file data provided or empty file", 400);
        }

        $db = Database::getConnection();

        // Check space quota
        $qm = new CloudQuotaManager($db);
        $allocator = new StorageAllocator($db);
        $storageAccount = $allocator->selectAccount($fileSize);

        $cloudFileId = "cf_" . bin2hex(random_bytes(12));
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $sha256 = $content !== null ? hash("sha256", $content) : hash_file("sha256", $tmpFilePath);
        $mimeType = "application/octet-stream";
        if ($ext === "json") $mimeType = "application/json";
        elseif (in_array($ext, ["txt", "srt", "ass", "vtt"])) $mimeType = "text/plain";
        elseif (in_array($ext, ["png", "jpg", "jpeg", "webp"])) $mimeType = "image/" . ($ext === "jpg" ? "jpeg" : $ext);
        elseif ($ext === "mp4") $mimeType = "video/mp4";
        elseif ($ext === "mp3") $mimeType = "audio/mpeg";

        // Check if file already exists in this folder with same name
        $existStmt = $db->prepare("SELECT id FROM cloud_files WHERE cloud_space_id = ? AND folder_id = ? AND filename = ? AND status = 'ACTIVE' LIMIT 1");
        $existStmt->execute([$workspaceId, $folderId, $filename]);
        $existing = $existStmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            $cloudFileId = $existing["id"];
        }

        // Store file physically in local storage directory
        $providerFileId = "local_" . $cloudFileId;
        $localPhysicalPath = self::getLocalStorePath($storageAccount["id"], $cloudFileId);

        if ($tmpFilePath) {
            @copy($tmpFilePath, $localPhysicalPath);
        } else {
            @file_put_contents($localPhysicalPath, $content);
        }

        // Insert or update cloud_files
        $ins = $db->prepare("
            INSERT INTO cloud_files (
                id, cloud_space_id, folder_id, created_by_user_id, app_id,
                filename, extension, mime_type, size_bytes, checksum_sha256,
                storage_account_id, provider_file_id, status, created_at, updated_at
            ) VALUES (
                ?, ?, ?, ?, 'AI_GATEWAY',
                ?, ?, ?, ?, ?,
                ?, ?, 'ACTIVE', NOW(), NOW()
            ) ON DUPLICATE KEY UPDATE
                size_bytes = VALUES(size_bytes),
                checksum_sha256 = VALUES(checksum_sha256),
                provider_file_id = VALUES(provider_file_id),
                status = 'ACTIVE',
                updated_at = NOW()
        ");
        $ins->execute([
            $cloudFileId, $workspaceId, $folderId, $userId,
            $filename, $ext, $mimeType, $fileSize, $sha256,
            $storageAccount["id"], $providerFileId
        ]);

        // Commit quota
        try {
            $qm->adjustUsedBytes($workspaceId, $fileSize);
        } catch (Throwable $e) {}

        Router::json([
            "ok"              => true,
            "success"         => true,
            "file_id"         => $cloudFileId,
            "filename"        => $filename,
            "path"            => $pathCheck["normalized"],
            "folder_id"       => $folderId,
            "size_bytes"      => $fileSize,
            "checksum_sha256" => $sha256,
            "message"         => "File uploaded successfully to AI Inputs bundle.",
        ], 201);
    }

    /**
     * GET /api/v1/ai/fs/read
     * Read file content or metadata inside AI Inputs/
     */
    public static function fsRead(array $params, array $body): void {
        $auth = self::resolveAuthContext("READ");
        $workspaceId = $auth["workspace_id"];
        $rootFolderId = $auth["root_folder_id"];

        $fileId = $_GET["file_id"] ?? "";
        $relPath = $_GET["path"] ?? "";

        $db = Database::getConnection();

        if (!empty($fileId)) {
            $stmt = $db->prepare("SELECT * FROM cloud_files WHERE id = ? AND cloud_space_id = ? AND status = 'ACTIVE' LIMIT 1");
            $stmt->execute([$fileId, $workspaceId]);
            $file = $stmt->fetch(PDO::FETCH_ASSOC);
        } elseif (!empty($relPath)) {
            $pathCheck = CloudAuthHelper::validateJailedPath($relPath);
            if (!$pathCheck["valid"] || empty($pathCheck["segments"])) {
                Router::error("Invalid path: " . ($pathCheck["error"] ?? "Empty path"), 403, "PATH_TRAVERSAL_BLOCKED");
            }
            $segments = $pathCheck["segments"];
            $filename = array_pop($segments);
            $resolved = self::resolveFolderHierarchy($workspaceId, $rootFolderId, $segments, false);
            if (!$resolved) {
                Router::error("Directory not found for path: " . $pathCheck["normalized"], 404);
            }
            $stmt = $db->prepare("SELECT * FROM cloud_files WHERE cloud_space_id = ? AND folder_id = ? AND filename = ? AND status = 'ACTIVE' LIMIT 1");
            $stmt->execute([$workspaceId, $resolved["folder_id"], $filename]);
            $file = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            Router::error("file_id or path is required", 400);
        }

        if (!$file) {
            Router::error("File not found", 404);
        }

        // Verify that the file resides within the AI Inputs folder subtree
        if (!self::isFolderDescendantOf($workspaceId, $file["folder_id"], $rootFolderId)) {
            Router::error("Access denied: File is outside authorized AI Inputs root", 403, "FORBIDDEN_OUTSIDE_ROOT");
        }

        $onlyMetadata = !empty($_GET["metadata"]) || !empty($_GET["info"]);
        if ($onlyMetadata) {
            Router::json([
                "ok"              => true,
                "file_id"         => $file["id"],
                "filename"        => $file["filename"],
                "extension"       => $file["extension"],
                "mime_type"       => $file["mime_type"],
                "size_bytes"      => (int)$file["size_bytes"],
                "checksum_sha256" => $file["checksum_sha256"],
                "created_at"      => $file["created_at"],
                "updated_at"      => $file["updated_at"],
            ]);
            return;
        }

        // Read physical content
        $localPhysicalPath = self::getLocalStorePath($file["storage_account_id"] ?? "", $file["id"]);
        if (file_exists($localPhysicalPath)) {
            header("Content-Type: " . ($file["mime_type"] ?: "application/octet-stream"));
            header("Content-Length: " . (int)$file["size_bytes"]);
            header('Content-Disposition: inline; filename="' . basename($file['filename']) . '"');
            readfile($localPhysicalPath);
            exit;
        }

        // Fallback to Google Drive if credentials exist
        require_once __DIR__ . "/CloudDownloadController.php";
        CloudDownloadController::download(["id" => $file["id"]], []);
    }

    /**
     * POST /api/v1/ai/bundle/register
     * Validate an uploaded Input Bundle inside AI Inputs/ and return its parsed scene plan
     */
    public static function bundleRegister(array $params, array $body): void {
        $auth = self::resolveAuthContext("READ");
        $workspaceId = $auth["workspace_id"];
        $rootFolderId = $auth["root_folder_id"];

        $bundlePath = trim($body["path"] ?? ($body["bundle_path"] ?? ($body["folder"] ?? "")));
        if (empty($bundlePath)) {
            Router::error("path is required (e.g. Japan_001)", 400);
        }

        $pathCheck = CloudAuthHelper::validateJailedPath($bundlePath);
        if (!$pathCheck["valid"] || empty($pathCheck["segments"])) {
            Router::error("Invalid bundle path: " . ($pathCheck["error"] ?? "Empty path"), 403, "PATH_TRAVERSAL_BLOCKED");
        }

        $resolved = self::resolveFolderHierarchy($workspaceId, $rootFolderId, $pathCheck["segments"], false);
        if (!$resolved) {
            Router::error("Bundle folder not found: " . $pathCheck["normalized"], 404);
        }

        $bundleFolderId = $resolved["folder_id"];
        $db = Database::getConnection();

        // Enumerate files in bundle
        $stmt = $db->prepare("SELECT id, filename, size_bytes, storage_account_id FROM cloud_files WHERE cloud_space_id = ? AND folder_id = ? AND status = 'ACTIVE'");
        $stmt->execute([$workspaceId, $bundleFolderId]);
        $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $fileMap = [];
        foreach ($files as $f) {
            $fileMap[strtolower($f["filename"])] = $f;
        }

        // Read manifest or auto-detect
        $manifest = null;
        if (isset($fileMap["2toolne.json"])) {
            $manifestContent = self::readFileContent($fileMap["2toolne.json"]);
            $manifest = json_decode($manifestContent ?: "", true);
        }

        // Read prompts
        $promptsData = null;
        $promptsFilename = $manifest["prompts"] ?? "prompts.json";
        if (isset($fileMap[strtolower($promptsFilename)])) {
            $promptsContent = self::readFileContent($fileMap[strtolower($promptsFilename)]);
            $promptsData = json_decode($promptsContent ?: "", true);
        }

        // Read characters
        $charactersData = null;
        $charactersFilename = $manifest["characters"] ?? "characters.json";
        if (isset($fileMap[strtolower($charactersFilename)])) {
            $charContent = self::readFileContent($fileMap[strtolower($charactersFilename)]);
            $charactersData = json_decode($charContent ?: "", true);
        }

        // Parse scenes
        $scenes = $promptsData["scenes"] ?? [];
        $projectName = $manifest["project_name"] ?? ($promptsData["project_name"] ?? basename($pathCheck["normalized"]));

        Router::json([
            "ok"               => true,
            "bundle_id"        => "bndl_" . substr($bundleFolderId, 4),
            "workspace_id"     => $workspaceId,
            "bundle_path"      => $pathCheck["normalized"],
            "folder_id"        => $bundleFolderId,
            "project_name"     => $projectName,
            "has_manifest"     => $manifest !== null,
            "has_prompts"      => $promptsData !== null,
            "has_characters"   => $charactersData !== null,
            "has_script"       => isset($fileMap["script.txt"]),
            "has_tts"          => isset($fileMap["tts.json"]) || isset($fileMap["tts.txt"]),
            "scenes_count"     => count($scenes),
            "characters_count" => count($charactersData["characters"] ?? []),
            "files_count"      => count($files),
            "files"            => array_map(function($f) { return $f["filename"]; }, $files),
            "scenes"           => $scenes,
            "characters"       => $charactersData["characters"] ?? [],
            "validated_at"     => date("c"),
        ]);
    }

    // =========================================================================
    // Internal Helpers
    // =========================================================================

    /**
     * Resolve and validate authenticated context (via AI Key or Web Session)
     */
    private static function resolveAuthContext(string $requiredScope): array {
        $user = CloudAuthHelper::getCurrentUser();
        if (!$user) {
            Router::error("Authentication required", 401, "UNAUTHORIZED");
        }

        $userId = (string)$user["id"];

        if (!empty($user["ai_access_key"])) {
            $key = $user["ai_access_key"];
            $scopes = $key["scopes"] ?? [];
            if (!in_array($requiredScope, $scopes, true) && !in_array("*", $scopes, true)) {
                Router::error("Scope {$requiredScope} is not granted for this AI Access Key", 403, "SCOPE_UNAUTHORIZED");
            }

            $workspaceId = $key["workspace_id"];
            $rootFolderId = $key["root_folder_id"] ?: self::ensureAiInputsFolder($workspaceId, $userId);

            return [
                "user_id"        => $userId,
                "workspace_id"   => $workspaceId,
                "root_folder_id" => $rootFolderId,
                "is_ai_key"      => true,
                "key_id"         => $key["id"],
            ];
        }

        // Regular session / Bearer token
        $workspaceId = $_GET["workspace_id"] ?? ($_POST["workspace_id"] ?? "");
        if (empty($workspaceId)) {
            // Default to personal space
            $db = Database::getConnection();
            $stmt = $db->prepare("SELECT id FROM cloud_spaces WHERE owner_type = 'USER' AND owner_id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $workspaceId = $stmt->fetchColumn() ?: "";
        }

        if (empty($workspaceId)) {
            Router::error("workspace_id is required", 400);
        }

        $auth = CloudAuthHelper::authorizeSpaceAccess($workspaceId, $userId);
        if (!$auth["allowed"]) {
            Router::error("Access denied to workspace", 403, "FORBIDDEN");
        }

        $rootFolderId = self::ensureAiInputsFolder($workspaceId, $userId);

        return [
            "user_id"        => $userId,
            "workspace_id"   => $workspaceId,
            "root_folder_id" => $rootFolderId,
            "is_ai_key"      => false,
            "key_id"         => null,
        ];
    }

    /**
     * Ensure the root "AI Inputs" folder exists in the target cloud space
     */
    public static function ensureAiInputsFolder(string $spaceId, string $userId): string {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT id FROM cloud_folders WHERE cloud_space_id = ? AND parent_id IS NULL AND name = 'AI Inputs' AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([$spaceId]);
        $folderId = $stmt->fetchColumn();

        if ($folderId) {
            return (string)$folderId;
        }

        $folderId = "cfl_" . bin2hex(random_bytes(8));
        $ins = $db->prepare("INSERT INTO cloud_folders (id, cloud_space_id, parent_id, name, created_by_user_id, created_at, updated_at) VALUES (?, ?, NULL, 'AI Inputs', ?, NOW(), NOW())");
        $ins->execute([$folderId, $spaceId, $userId]);

        return $folderId;
    }

    /**
     * Resolve or recursively create folder hierarchy under rootFolderId
     */
    private static function resolveFolderHierarchy(string $spaceId, string $rootFolderId, array $segments, bool $createMissing, string $userId = ""): ?array {
        $currentFolderId = $rootFolderId;
        $db = Database::getConnection();

        foreach ($segments as $name) {
            $name = trim($name);
            if ($name === "") continue;

            $stmt = $db->prepare("SELECT id FROM cloud_folders WHERE cloud_space_id = ? AND parent_id = ? AND name = ? AND deleted_at IS NULL LIMIT 1");
            $stmt->execute([$spaceId, $currentFolderId, $name]);
            $foundId = $stmt->fetchColumn();

            if ($foundId) {
                $currentFolderId = (string)$foundId;
            } else {
                if (!$createMissing) {
                    return null;
                }
                $newFolderId = "cfl_" . bin2hex(random_bytes(8));
                $ins = $db->prepare("INSERT INTO cloud_folders (id, cloud_space_id, parent_id, name, created_by_user_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())");
                $ins->execute([$newFolderId, $spaceId, $currentFolderId, $name, $userId]);
                $currentFolderId = $newFolderId;
            }
        }

        return ["folder_id" => $currentFolderId];
    }

    /**
     * Check if a folder is equal to or a descendant of rootFolderId (depth limit: 50)
     */
    private static function isFolderDescendantOf(string $spaceId, ?string $folderId, string $rootFolderId): bool {
        if ($folderId === $rootFolderId) return true;
        if (empty($folderId)) return false;

        $db = Database::getConnection();
        $curr = $folderId;
        $depth = 0;

        while (!empty($curr) && $depth < 50) {
            if ($curr === $rootFolderId) return true;
            $stmt = $db->prepare("SELECT parent_id FROM cloud_folders WHERE id = ? AND cloud_space_id = ? LIMIT 1");
            $stmt->execute([$curr, $spaceId]);
            $curr = $stmt->fetchColumn();
            $depth++;
        }

        return false;
    }

    /**
     * Helper to resolve local physical storage path within open_basedir
     */
    private static function getLocalStorePath(string $accountId, string $fileId): string {
        $candidates = [
            dirname(__DIR__, 3) . '/storage/cloud_storage/' . $accountId,
            dirname(__DIR__, 3) . '/uploads/cloud_storage/' . $accountId,
        ];
        foreach ($candidates as $dir) {
            if (is_dir($dir) && file_exists($dir . '/' . $fileId)) {
                return $dir . '/' . $fileId;
            }
        }
        $primary = dirname(__DIR__, 3) . '/storage/cloud_storage/' . $accountId;
        if (!is_dir($primary)) {
            @mkdir($primary, 0775, true);
        }
        return $primary . '/' . $fileId;
    }

    /**
     * Helper to read small text/json file from local or cloud storage
     */
    private static function readFileContent(array $fileRecord): ?string {
        $localPath = self::getLocalStorePath($fileRecord["storage_account_id"] ?? "", $fileRecord["id"]);
        if (file_exists($localPath)) {
            return file_get_contents($localPath);
        }
        return null;
    }
}
