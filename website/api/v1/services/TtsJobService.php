<?php
/**
 * 2TOOLNE TTS — JOB QUEUE & STATE MACHINE SERVICE
 * MariaDB / MySQL is the single source of truth.
 * Handles job submission, concurrency control, atomic claiming, heartbeats, lazy recovery, and completion.
 */

declare(strict_types=1);

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/TtsBillingService.php';
require_once __DIR__ . '/TtsVoiceService.php';

class TtsJobService {

    /**
     * Create a new TTS synthesis job.
     */
    public static function createJob(array $user, array $data): array {
        $userId = (string)$user['id'];
        $db = Database::getConnection();

        // 1. Validate Text
        $text = trim($data['text'] ?? '');
        if (empty($text)) {
            throw new InvalidArgumentException('Text content is required');
        }
        $maxLen = defined('TTS_MAX_TEXT_LENGTH') ? TTS_MAX_TEXT_LENGTH : 5000;
        if (mb_strlen($text, 'UTF-8') > $maxLen) {
            throw new InvalidArgumentException("Text length exceeds maximum allowed length of {$maxLen} characters");
        }

        // 2. Check Idempotency Key
        $idempKey = !empty($data['idempotency_key']) ? trim($data['idempotency_key']) : null;
        if ($idempKey) {
            $stmt = $db->prepare('SELECT * FROM tts_jobs WHERE idempotency_key = ? LIMIT 1');
            $stmt->execute([$idempKey]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                return self::enrichJob($existing);
            }
        }

        // 3. Concurrency Limit Check
        $maxActive = defined('TTS_MAX_ACTIVE_JOBS_PER_USER') ? TTS_MAX_ACTIVE_JOBS_PER_USER : 5;
        $activeStmt = $db->prepare("
            SELECT COUNT(*) FROM tts_jobs
            WHERE user_id = ? AND status IN ('QUEUED', 'CLAIMED', 'PREPARING', 'GENERATING', 'POST_PROCESSING', 'UPLOADING', 'FINALIZING')
        ");
        $activeStmt->execute([$userId]);
        $activeCount = (int)$activeStmt->fetchColumn();
        if ($activeCount >= $maxActive) {
            throw new RuntimeException("Maximum concurrent active TTS jobs limit reached ({$maxActive}). Please wait for ongoing jobs to complete.");
        }

        // 4. Resolve and Authorize Target Cloud Space
        $spaceId = !empty($data['cloud_space_id']) ? trim($data['cloud_space_id']) : null;
        if (!empty($spaceId)) {
            $auth = CloudAuthHelper::authorizeSpaceAccess($spaceId, $userId, 'CONTRIBUTOR');
            if (!$auth['allowed']) {
                throw new RuntimeException("Access denied to cloud space '{$spaceId}'", 403);
            }
        } else {
            // Default to Personal Space
            $spStmt = $db->prepare('SELECT id, status FROM cloud_spaces WHERE owner_type = "USER" AND owner_id = ? LIMIT 1');
            $spStmt->execute([$userId]);
            $sp = $spStmt->fetch(PDO::FETCH_ASSOC);
            if ($sp) {
                $spaceId = $sp['id'];
            } else {
                if (file_exists(__DIR__ . '/../../../storage/db.php')) {
                    require_once __DIR__ . '/../../../storage/db.php';
                    if (function_exists('db_ensure_user_personal_space')) {
                        $spaceId = db_ensure_user_personal_space($userId);
                    }
                }
            }
        }

        if (empty($spaceId)) {
            throw new RuntimeException('Target cloud space could not be resolved for user', 400);
        }

        // Check space status
        $chkSpace = $db->prepare('SELECT status FROM cloud_spaces WHERE id = ? LIMIT 1');
        $chkSpace->execute([$spaceId]);
        $spRow = $chkSpace->fetch(PDO::FETCH_ASSOC);
        if (!$spRow || $spRow['status'] === 'OVER_QUOTA') {
            throw new RuntimeException('Cloud storage space is over quota or unavailable', 409);
        }

        $folderId = !empty($data['folder_id']) ? trim($data['folder_id']) : null;
        if (!empty($folderId)) {
            $chkFolder = $db->prepare('SELECT id FROM cloud_folders WHERE id = ? AND cloud_space_id = ? AND deleted_at IS NULL LIMIT 1');
            $chkFolder->execute([$folderId, $spaceId]);
            if (!$chkFolder->fetch()) {
                throw new InvalidArgumentException("Invalid folder_id '{$folderId}' for cloud space '{$spaceId}'", 400);
            }
        }

        // 5. Voice Resolution & Server-Side Engine Resolution
        $rawVoiceId = trim($data['voice_id'] ?? ($data['voice_profile_id'] ?? ''));
        $reqLanguage = !empty($data['language']) ? strtolower(trim($data['language'])) : null;

        if (!empty($rawVoiceId)) {
            $resolvedVoice = TtsVoiceService::resolveVoice($rawVoiceId, $userId);
        } else {
            // Default preset for language
            $language = $reqLanguage ?: 'en';
            $defaultPresetMap = [
                'en' => 'preset_en_ryan',
                'ja' => 'preset_ja_ono_anna',
                'ko' => 'preset_ko_sohee',
                'zh' => 'preset_zh_vivian',
                'vi' => 'preset_vi_lan',
            ];
            $voiceProfileId = $defaultPresetMap[$language] ?? 'preset_en_ryan';
            $resolvedVoice = TtsVoiceService::resolveVoice($voiceProfileId, $userId);
        }

        $voiceProfileId = $resolvedVoice['id'];
        $resolvedEngine = strtoupper($resolvedVoice['engine'] ?? 'QWEN3_TTS');
        $voiceType = $resolvedVoice['voice_type'] ?? 'QWEN_BUILTIN';
        $language = $reqLanguage ?: strtolower($resolvedVoice['language'] ?? 'en');

        // Server-Side Model Resolution
        if ($voiceType === 'QWEN_BUILTIN') {
            $requiredModel = 'QWEN_CUSTOMVOICE_1_7B';
        } elseif ($resolvedEngine === 'VOXCPM') {
            $requiredModel = 'VOXCPM_BASE';
        } else {
            $requiredModel = 'QWEN_BASE_1_7B';
        }

        // Validate client engine: client cannot override server-resolved engine. Reject mismatch!
        if (!empty($data['engine'])) {
            $clientEngine = strtoupper(trim($data['engine']));
            if ($clientEngine !== $resolvedEngine) {
                throw new InvalidArgumentException("Engine mismatch: voice '{$voiceProfileId}' requires engine '{$resolvedEngine}', but '{$clientEngine}' was provided. Client cannot override engine.");
            }
        }
        $engine = $resolvedEngine;

        // Check voice readiness & gating
        $voiceStatus = $resolvedVoice['status'] ?? 'ACTIVE';
        if ($voiceStatus === 'COMING_SOON') {
            throw new InvalidArgumentException("Voice '{$voiceProfileId}' is COMING_SOON (dedicated engine VOXCPM is planned) and cannot be used for generation.");
        }
        if ($voiceStatus === 'NOT_READY') {
            throw new InvalidArgumentException("Voice '{$voiceProfileId}' is NOT_READY (system reference audio not found or inactive in Cloud Space) and cannot be used for generation.");
        }
        if (in_array($voiceStatus, ['DISABLED', 'DELETED', 'FAILED'], true)) {
            throw new InvalidArgumentException("Voice '{$voiceProfileId}' is {$voiceStatus} and cannot be used for generation.");
        }

        // 5.1 Engine & Language Capability Validation
        $capabilities = TtsVoiceService::getEngineCapabilities();
        if (!isset($capabilities[$engine])) {
            throw new InvalidArgumentException("Unknown or unsupported TTS engine '{$engine}'");
        }

        $cap = $capabilities[$engine];
        if ($cap['status'] === 'PLANNED') {
            throw new InvalidArgumentException("TTS engine '{$engine}' is planned and not yet active in production");
        }

        // Explicit strict check: Vietnamese is DISABLED_FOR_QWEN until dedicated engine is integrated
        if ($engine === 'QWEN3_TTS' && $language === 'vi') {
            throw new InvalidArgumentException("Language 'vi' is disabled for QWEN3_TTS engine. Vietnamese requires dedicated Vietnamese engine (VOXCPM). Supported languages for Qwen3-TTS: " . implode(', ', $cap['engine_supported_languages'] ?? $cap['languages']));
        }

        if (!in_array($language, $cap['languages'], true)) {
            throw new InvalidArgumentException("Language '{$language}' is not supported by engine '{$engine}'. Supported: " . implode(', ', $cap['languages']));
        }

        // 6. Format, Engine, Output filename
        $format = strtolower(trim($data['output_format'] ?? 'wav'));
        if (!in_array($format, ['wav', 'mp3'], true)) {
            throw new InvalidArgumentException('Invalid output_format. Must be "wav" or "mp3"');
        }

        $filename = trim($data['output_filename'] ?? '');
        if (empty($filename)) {
            $filename = 'tts_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $format;
        }

        $settings = !empty($data['settings']) ? (is_string($data['settings']) ? $data['settings'] : json_encode($data['settings'])) : null;

        // 7. Billing Reserve
        $jobId = 'ttsjob_' . bin2hex(random_bytes(10));
        $cost = TtsBillingService::calculateCost($text, $engine, ['voice_clone' => !empty($voiceProfileId)]);
        $reserveResult = TtsBillingService::reserve($userId, $cost, $jobId);
        if (!$reserveResult['success']) {
            throw new RuntimeException('Insufficient credits to start TTS job');
        }

        // 8. Insert into Queue (Single Source of Truth)
        $now = date('Y-m-d H:i:s');
        $maxAttempts = defined('TTS_MAX_RETRY_ATTEMPTS') ? TTS_MAX_RETRY_ATTEMPTS : 3;

        $ins = $db->prepare('
            INSERT INTO tts_jobs (
                id, user_id, cloud_space_id, folder_id, voice_profile_id,
                text, language, output_format, output_filename, engine,
                required_model, model_version, settings_json, status, progress, attempt_count, max_attempts,
                idempotency_key, created_at, queued_at, updated_at
            ) VALUES (
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, NULL, ?, "QUEUED", 0, 0, ?,
                ?, ?, ?, ?
            )
        ');
        $ins->execute([
            $jobId, $userId, $spaceId, $folderId, $voiceProfileId,
            $text, $language, $format, $filename, $engine,
            $requiredModel, $settings, $maxAttempts,
            $idempKey, $now, $now, $now
        ]);

        return self::getJob($jobId, $userId);
    }

    /**
     * Retrieve single job by ID.
     */
    public static function getJob(string $jobId, ?string $userId = null): ?array {
        $db = Database::getConnection();
        $sql = 'SELECT * FROM tts_jobs WHERE id = ? LIMIT 1';
        $stmt = $db->prepare($sql);
        $stmt->execute([$jobId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        if ($userId !== null && (string)$row['user_id'] !== (string)$userId) {
            return null;
        }

        return self::enrichJob($row);
    }

    /**
     * List jobs with pagination and optional status filter.
     */
    public static function listJobs(string $userId, array $filters = []): array {
        $db = Database::getConnection();
        $limit = max(1, min(100, (int)($filters['limit'] ?? 20)));
        $offset = max(0, (int)($filters['offset'] ?? 0));

        $where = ['user_id = ?'];
        $params = [$userId];

        if (!empty($filters['active'])) {
            $where[] = "status IN ('QUEUED', 'CLAIMED', 'PREPARING', 'GENERATING', 'POST_PROCESSING', 'UPLOADING', 'FINALIZING')";
        } elseif (!empty($filters['status'])) {
            $where[] = 'status = ?';
            $params[] = $filters['status'];
        }

        $whereClause = implode(' AND ', $where);

        $cntStmt = $db->prepare("SELECT COUNT(*) FROM tts_jobs WHERE {$whereClause}");
        $cntStmt->execute($params);
        $total = (int)$cntStmt->fetchColumn();

        $sql = "SELECT * FROM tts_jobs WHERE {$whereClause} ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $items = array_map([self::class, 'enrichJob'], $rows);

        return [
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'jobs' => $items,
        ];
    }

    /**
     * Cancel an active or queued job.
     */
    public static function cancelJob(string $jobId, string $userId): array {
        $db = Database::getConnection();
        $job = self::getJob($jobId, $userId);
        if (!$job) {
            throw new RuntimeException('Job not found or unauthorized');
        }

        $status = $job['status'];
        $now = date('Y-m-d H:i:s');

        if (in_array($status, ['COMPLETED', 'FAILED', 'CANCELLED'], true)) {
            return [
                'success' => false,
                'message' => "Job is already in final state: {$status}",
                'job' => $job,
            ];
        }

        if ($status === 'QUEUED') {
            $upd = $db->prepare('UPDATE tts_jobs SET status = "CANCELLED", completed_at = ?, updated_at = ? WHERE id = ?');
            $upd->execute([$now, $now, $jobId]);
            TtsBillingService::release($userId, null);
            return [
                'success' => true,
                'status' => 'CANCELLED',
                'message' => 'Queued job cancelled immediately',
            ];
        }

        // In progress: request cancellation
        $upd = $db->prepare('UPDATE tts_jobs SET status = "CANCEL_REQUESTED", updated_at = ? WHERE id = ?');
        $upd->execute([$now, $jobId]);

        return [
            'success' => true,
            'status' => 'CANCEL_REQUESTED',
            'message' => 'Cancellation signal sent to worker',
        ];
    }

    /**
     * Lazy lease recovery: detect stale jobs whose lease expired.
     */
    public static function recoverStaleJobs(): int {
        $db = Database::getConnection();
        $now = date('Y-m-d H:i:s');

        // Find in-progress jobs with expired lease
        $activeStatuses = "('CLAIMED', 'PREPARING', 'GENERATING', 'POST_PROCESSING', 'UPLOADING', 'FINALIZING')";
        $stmt = $db->prepare("
            SELECT id, user_id, attempt_count, max_attempts FROM tts_jobs
            WHERE status IN {$activeStatuses} AND lease_until IS NOT NULL AND lease_until < ?
        ");
        $stmt->execute([$now]);
        $staleJobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $recovered = 0;
        foreach ($staleJobs as $job) {
            $attempts = (int)$job['attempt_count'];
            $maxAttempts = (int)$job['max_attempts'];

            if ($attempts >= $maxAttempts) {
                // Mark FAILED
                $upd = $db->prepare('
                    UPDATE tts_jobs
                    SET status = "FAILED", error_code = "LEASE_TIMEOUT_EXHAUSTED",
                        error_message = "Worker lease expired and max retry attempts reached",
                        completed_at = ?, lease_until = NULL, updated_at = ?
                    WHERE id = ?
                ');
                $upd->execute([$now, $now, $job['id']]);
                TtsBillingService::release((string)$job['user_id'], null);
            } else {
                // Re-queue for another worker
                $upd = $db->prepare('
                    UPDATE tts_jobs
                    SET status = "QUEUED", worker_id = NULL, lease_until = NULL, heartbeat_at = NULL, updated_at = ?
                    WHERE id = ?
                ');
                $upd->execute([$now, $job['id']]);
            }
            $recovered++;
        }

        return $recovered;
    }

    /**
     * Atomic Claim of next available job by a GPU Worker.
     */
    public static function claimJob(string $workerId, array $supportedEngines = ['QWEN3_TTS', 'MOCK'], int $leaseSeconds = 300, ?array $capabilities = null): ?array {
        $db = Database::getConnection();

        // 1. Recover any stale leases first
        self::recoverStaleJobs();

        if (!empty($capabilities['engines'])) {
            $supportedEngines = (array)$capabilities['engines'];
        }
        if (empty($supportedEngines)) {
            $supportedEngines = ['QWEN3_TTS', 'MOCK'];
        }

        // Derive allowed voice_types from capabilities
        $allowedVoiceTypes = null;
        if (!empty($capabilities['voice_types'])) {
            $allowedVoiceTypes = array_map('strtoupper', (array)$capabilities['voice_types']);
        } elseif (!empty($capabilities['models'])) {
            $allowedVoiceTypes = [];
            foreach ((array)$capabilities['models'] as $m) {
                $m = strtoupper($m);
                if (strpos($m, 'CUSTOM') !== false) {
                    $allowedVoiceTypes[] = 'QWEN_BUILTIN';
                }
                if (strpos($m, 'BASE') !== false) {
                    $allowedVoiceTypes[] = 'CLONED';
                    $allowedVoiceTypes[] = 'SYSTEM_CLONED';
                }
            }
            $allowedVoiceTypes = array_values(array_unique($allowedVoiceTypes));
        }

        // Derive allowed languages from capabilities
        $allowedLanguages = null;
        if (!empty($capabilities['languages'])) {
            $allowedLanguages = array_map('strtolower', (array)$capabilities['languages']);
        }

        // Derive allowed models from capabilities
        $allowedModels = null;
        if (!empty($capabilities['models'])) {
            $allowedModels = array_map('strtoupper', (array)$capabilities['models']);
        }

        $now = date('Y-m-d H:i:s');
        $leaseUntil = date('Y-m-d H:i:s', time() + $leaseSeconds);

        $isSqlite = ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite');

        // Placeholders for engines
        $placeholders = implode(',', array_fill(0, count($supportedEngines), '?'));

        $db->beginTransaction();
        try {
            $forUpdate = $isSqlite ? '' : ' FOR UPDATE';
            $sql = "SELECT id, engine, language, voice_profile_id, required_model FROM tts_jobs WHERE status = 'QUEUED' AND engine IN ({$placeholders}) ORDER BY created_at ASC LIMIT 50{$forUpdate}";
            $stmt = $db->prepare($sql);
            $stmt->execute(array_values($supportedEngines));
            $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($candidates)) {
                $db->commit();
                return null;
            }

            $claimedJobId = null;
            foreach ($candidates as $candidate) {
                // Filter by required_model capability
                if ($allowedModels !== null && !empty($candidate['required_model'])) {
                    if (!in_array(strtoupper($candidate['required_model']), $allowedModels, true)) {
                        continue;
                    }
                }

                // Filter by language capability
                if ($allowedLanguages !== null && !in_array(strtolower($candidate['language']), $allowedLanguages, true)) {
                    continue;
                }

                // Filter by voice_type capability
                if ($allowedVoiceTypes !== null) {
                    $voiceId = $candidate['voice_profile_id'];
                    $vType = 'QWEN_BUILTIN';
                    try {
                        $resolved = TtsVoiceService::resolveVoice($voiceId);
                        $vType = $resolved['voice_type'] ?? 'QWEN_BUILTIN';
                    } catch (Throwable $e) {
                        if (strpos($voiceId, 'voice_') === 0) {
                            $vType = 'CLONED';
                        }
                    }
                    if (!in_array($vType, $allowedVoiceTypes, true)) {
                        continue;
                    }
                }

                $upd = $db->prepare('
                    UPDATE tts_jobs
                    SET status = "CLAIMED",
                        worker_id = ?,
                        lease_until = ?,
                        heartbeat_at = ?,
                        started_at = COALESCE(started_at, ?),
                        attempt_count = attempt_count + 1,
                        updated_at = ?
                    WHERE id = ? AND status = "QUEUED"
                ');
                $upd->execute([$workerId, $leaseUntil, $now, $now, $now, $candidate['id']]);

                if ($upd->rowCount() > 0) {
                    $claimedJobId = $candidate['id'];
                    break;
                }
            }

            if (!$claimedJobId) {
                $db->commit();
                return null;
            }

            $db->commit();
            return self::getJob($claimedJobId);
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Worker Heartbeat: extends lease and checks for cancellation signal.
     */
    public static function heartbeat(string $jobId, string $workerId, int $extendSeconds = 300): array {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT id, status, worker_id FROM tts_jobs WHERE id = ? LIMIT 1');
        $stmt->execute([$jobId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$job || (string)$job['worker_id'] !== (string)$workerId) {
            return [
                'success' => false,
                'error' => 'LEASE_LOST',
                'should_cancel' => true,
            ];
        }

        if ($job['status'] === 'CANCEL_REQUESTED') {
            return [
                'success' => true,
                'status' => 'CANCEL_REQUESTED',
                'should_cancel' => true,
            ];
        }

        if (in_array($job['status'], ['COMPLETED', 'FAILED', 'CANCELLED'], true)) {
            return [
                'success' => true,
                'status' => $job['status'],
                'should_cancel' => true,
            ];
        }

        $now = date('Y-m-d H:i:s');
        $leaseUntil = date('Y-m-d H:i:s', time() + $extendSeconds);

        $upd = $db->prepare('UPDATE tts_jobs SET heartbeat_at = ?, lease_until = ?, updated_at = ? WHERE id = ? AND worker_id = ?');
        $upd->execute([$now, $leaseUntil, $now, $jobId, $workerId]);

        return [
            'success' => true,
            'status' => $job['status'],
            'should_cancel' => false,
            'lease_until' => $leaseUntil,
        ];
    }

    /**
     * Update progress and phase status by worker.
     */
    public static function updateProgress(string $jobId, string $workerId, int $progress, string $status): bool {
        $validStatuses = ['CLAIMED', 'PREPARING', 'GENERATING', 'POST_PROCESSING', 'UPLOADING', 'FINALIZING'];
        if (!in_array($status, $validStatuses, true)) {
            throw new InvalidArgumentException("Invalid job status update: {$status}");
        }

        $db = Database::getConnection();
        $now = date('Y-m-d H:i:s');
        $leaseUntil = date('Y-m-d H:i:s', time() + 300);

        $stmt = $db->prepare('
            UPDATE tts_jobs
            SET progress = ?, status = ?, heartbeat_at = ?, lease_until = ?, updated_at = ?
            WHERE id = ? AND worker_id = ? AND status NOT IN ("COMPLETED", "FAILED", "CANCELLED")
        ');
        $stmt->execute([max(0, min(100, $progress)), $status, $now, $leaseUntil, $now, $jobId, $workerId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Mark job COMPLETED and commit billing.
     */
    public static function completeJob(string $jobId, string $workerId, array $payload): array {
        $cloudFileId = trim($payload['cloud_file_id'] ?? '');
        if (empty($cloudFileId)) {
            throw new InvalidArgumentException('cloud_file_id is required to complete TTS job');
        }

        $db = Database::getConnection();

        // Verify cloud_files status is ACTIVE
        $cfStmt = $db->prepare('SELECT id, size_bytes, status FROM cloud_files WHERE id = ? LIMIT 1');
        $cfStmt->execute([$cloudFileId]);
        $cf = $cfStmt->fetch(PDO::FETCH_ASSOC);

        if (!$cf || $cf['status'] !== 'ACTIVE') {
            throw new RuntimeException('Referenced cloud_file is missing or not in ACTIVE status');
        }

        $duration = !empty($payload['duration_seconds']) ? (float)$payload['duration_seconds'] : null;
        $sizeBytes = !empty($payload['size_bytes']) ? (int)$payload['size_bytes'] : (int)$cf['size_bytes'];
        $modelVersion = !empty($payload['model_version']) ? trim($payload['model_version']) : null;
        if (empty($modelVersion) && !empty($payload['model_id'])) {
            $rev = !empty($payload['model_revision']) ? '@' . trim($payload['model_revision']) : '';
            $modelVersion = trim($payload['model_id']) . $rev;
        }
        $now = date('Y-m-d H:i:s');

        $upd = $db->prepare('
            UPDATE tts_jobs
            SET status = "COMPLETED", progress = 100, cloud_file_id = ?,
                duration_seconds = ?, size_bytes = ?, model_version = ?,
                completed_at = ?, lease_until = NULL, updated_at = ?
            WHERE id = ? AND worker_id = ?
        ');
        $upd->execute([$cloudFileId, $duration, $sizeBytes, $modelVersion, $now, $now, $jobId, $workerId]);

        if ($upd->rowCount() === 0) {
            throw new RuntimeException('Could not complete job (worker lease mismatch or job already ended)');
        }

        // Tag cloud_files with project_id and app_id = 'TTS'
        try {
            $updCf = $db->prepare('UPDATE cloud_files SET project_id = COALESCE(project_id, ?), app_id = "TTS" WHERE id = ?');
            $updCf->execute([$jobId, $cloudFileId]);
        } catch (Throwable $e) {
            // non-fatal
        }

        // Commit billing
        $job = self::getJob($jobId);
        if ($job) {
            TtsBillingService::commit((string)$job['user_id'], null, 0);
        }

        return $job ?: [];
    }

    /**
     * Mark job FAILED or re-queue if retryable.
     */
    public static function failJob(string $jobId, string $workerId, string $errorCode, string $errorMessage, bool $retryable = false): array {
        $db = Database::getConnection();
        $job = self::getJob($jobId);
        if (!$job || (string)$job['worker_id'] !== (string)$workerId) {
            throw new RuntimeException('Job not found or worker unauthorized');
        }

        $now = date('Y-m-d H:i:s');
        $attempts = (int)$job['attempt_count'];
        $maxAttempts = (int)$job['max_attempts'];

        if ($retryable && $attempts < $maxAttempts) {
            // Re-queue
            $upd = $db->prepare('
                UPDATE tts_jobs
                SET status = "QUEUED", worker_id = NULL, lease_until = NULL, heartbeat_at = NULL,
                    error_code = ?, error_message = ?, updated_at = ?
                WHERE id = ?
            ');
            $upd->execute([$errorCode, $errorMessage, $now, $jobId]);
            return self::getJob($jobId);
        }

        // Permanent failure
        $upd = $db->prepare('
            UPDATE tts_jobs
            SET status = "FAILED", error_code = ?, error_message = ?,
                completed_at = ?, lease_until = NULL, updated_at = ?
            WHERE id = ?
        ');
        $upd->execute([$errorCode, $errorMessage, $now, $now, $jobId]);

        TtsBillingService::release((string)$job['user_id'], null);
        return self::getJob($jobId);
    }

    /**
     * Enrich job representation with preview/download URLs and voice profile.
     */
    private static function enrichJob(array $job): array {
        $cloudFileId = $job['cloud_file_id'] ?? null;
        if (!empty($cloudFileId)) {
            $job['preview_url'] = "/api/v1/cloud/files/{$cloudFileId}/preview";
            $job['download_url'] = "/api/v1/cloud/files/{$cloudFileId}/download";
        } else {
            $job['preview_url'] = null;
            $job['download_url'] = null;
        }

        if (!empty($job['settings_json']) && is_string($job['settings_json'])) {
            $decoded = json_decode($job['settings_json'], true);
            $job['settings'] = (!empty($decoded) && is_array($decoded)) ? $decoded : (object)[];
        } else {
            $job['settings'] = !empty($job['settings_json']) ? $job['settings_json'] : (object)[];
        }

        // Canonical voice_id with backward compatibility for voice_profile_id
        $canonicalVoiceId = $job['voice_id'] ?? ($job['voice_profile_id'] ?? null);
        $job['voice_id'] = $canonicalVoiceId;
        $job['voice_profile_id'] = $canonicalVoiceId;

        if (!empty($canonicalVoiceId)) {
            $vp = TtsVoiceService::getVoiceProfile((string)$canonicalVoiceId);
            $job['voice_profile'] = $vp;
            $job['voice_name'] = $vp['display_name'] ?? ($vp['name'] ?? $canonicalVoiceId);
            $job['voice_type'] = $vp['voice_type'] ?? ($vp['type'] === 'cloned' ? 'CLONED' : 'QWEN_BUILTIN');
            $job['engine_voice_key'] = $vp['engine_voice_key'] ?? ($vp['name'] ?? null);
            $job['engine_speaker'] = ($job['voice_type'] === 'QWEN_BUILTIN') ? ($vp['engine_voice_key'] ?? ($vp['name'] ?? null)) : null;
            $job['clone_mode'] = $vp['clone_mode'] ?? (!empty($vp['reference_text']) ? 'ICL' : 'X_VECTOR_ONLY');
            $job['x_vector_only_mode'] = $vp['x_vector_only_mode'] ?? empty($vp['reference_text']);
        } else {
            $job['voice_profile'] = null;
            $job['voice_name'] = null;
            $job['voice_type'] = null;
            $job['engine_voice_key'] = null;
            $job['engine_speaker'] = null;
            $job['clone_mode'] = null;
            $job['x_vector_only_mode'] = false;
        }

        $job['required_model'] = $job['required_model'] ?? null;
        $job['model_version'] = $job['model_version'] ?? null;

        return $job;
    }
}
