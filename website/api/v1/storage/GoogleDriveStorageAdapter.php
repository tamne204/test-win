<?php
/**
 * 2TOOLNE CLOUD — GOOGLE DRIVE STORAGE ADAPTER
 * Communicates with Google Drive v3 REST API via HTTPS cURL
 * Compatible with PHP 7.4.33 & PHP 8.x
 */

declare(strict_types=1);

require_once __DIR__ . '/StorageProviderInterface.php';
require_once __DIR__ . '/CurlHelper.php';

class GoogleDriveStorageAdapter implements StorageProviderInterface {
    private const OAUTH_TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const DRIVE_API_URL = 'https://www.googleapis.com/drive/v3';
    private const UPLOAD_API_URL = 'https://www.googleapis.com/upload/drive/v3/files';

    private function executeCurl($ch) {
        CurlHelper::applySslOptions($ch);
        return curl_exec($ch);
    }

    public function getProviderName(): string {
        return 'GOOGLE_DRIVE';
    }

    /**
     * Ensure a valid Google access token, refreshing via OAuth if needed
     */
    public function getValidAccessToken(array &$credentials): string {
        $now = time();
        if (!empty($credentials['access_token']) && !empty($credentials['expires_at']) && ($credentials['expires_at'] - 60) > $now) {
            return $credentials['access_token'];
        }

        if (empty($credentials['refresh_token']) || empty($credentials['client_id']) || empty($credentials['client_secret'])) {
            throw new InvalidArgumentException('Missing OAuth refresh credentials for Google Drive.');
        }

        // Call Google OAuth token endpoint
        $ch = curl_init(self::OAUTH_TOKEN_URL);
        $postFields = http_build_query([
            'client_id'     => $credentials['client_id'],
            'client_secret' => $credentials['client_secret'],
            'refresh_token' => $credentials['refresh_token'],
            'grant_type'    => 'refresh_token',
        ]);

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);

        $response = $this->executeCurl($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            throw new RuntimeException("Failed to refresh Google OAuth token (HTTP {$httpCode}): {$curlErr} {$response}");
        }

        $data = json_decode($response, true);
        if (!isset($data['access_token'])) {
            throw new RuntimeException('Google OAuth response did not contain access_token.');
        }

        $credentials['access_token'] = $data['access_token'];
        $credentials['expires_at']   = $now + (int)($data['expires_in'] ?? 3600);

        return $credentials['access_token'];
    }

    public function createUploadSession(
        string $accountId,
        array $credentials,
        string $fileName,
        int $fileSizeBytes,
        string $mimeType,
        ?string $parentFolderId = null
    ): array {
        $accessToken = $this->getValidAccessToken($credentials);

        $url = self::UPLOAD_API_URL . '?uploadType=resumable';
        $metadata = ['name' => $fileName];
        if (!empty($parentFolderId)) {
            $metadata['parents'] = [$parentFolderId];
        }

        $clientOrigin = $_SERVER['HTTP_ORIGIN'] ?? ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'www.2tamne.site'));
        if (empty($clientOrigin)) {
            $clientOrigin = 'https://www.2tamne.site';
        }

        $headers = [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json; charset=UTF-8',
            'X-Upload-Content-Type: ' . $mimeType,
            'X-Upload-Content-Length: ' . (string)$fileSizeBytes,
            'Origin: ' . $clientOrigin,
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($metadata),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        $response = $this->executeCurl($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($httpCode !== 200 || $response === false) {
            throw new RuntimeException("Failed to initiate Google Resumable Upload (HTTP {$httpCode}): {$curlErr} {$response}");
        }

        $headerText = substr($response, 0, $headerSize);
        $location = null;
        foreach (explode("\r\n", $headerText) as $line) {
            if (stripos($line, 'Location:') === 0) {
                $location = trim(substr($line, 9));
                break;
            }
        }

        if (empty($location)) {
            throw new RuntimeException('Google Drive API did not return resumable Location header.');
        }

        return [
            'session_url' => $location,
            'expires_at'  => date('Y-m-d H:i:s', time() + 86400),
        ];
    }

    public function verifyUploadedFile(
        string $accountId,
        array $credentials,
        string $providerFileId,
        int $expectedSizeBytes
    ): array {
        $accessToken = $this->getValidAccessToken($credentials);

        $url = self::DRIVE_API_URL . '/files/' . urlencode($providerFileId) . '?fields=id,name,size,md5Checksum,trashed';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
        ]);

        $response = $this->executeCurl($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || $response === false) {
            return [
                'verified'         => false,
                'actual_size'      => 0,
                'provider_file_id' => $providerFileId,
                'md5'              => null,
                'error'            => "File not found or inaccessible on Google Drive (HTTP {$httpCode})"
            ];
        }

        $data = json_decode($response, true);
        $actualSize = isset($data['size']) ? (int)$data['size'] : 0;
        $isTrashed = !empty($data['trashed']);

        return [
            'verified'         => ($actualSize === $expectedSizeBytes) && !$isTrashed,
            'actual_size'      => $actualSize,
            'provider_file_id' => $data['id'] ?? $providerFileId,
            'md5'              => $data['md5Checksum'] ?? null,
            'name'             => $data['name'] ?? null,
        ];
    }

    public function getCapacityUsage(string $accountId, array $credentials): array {
        $accessToken = $this->getValidAccessToken($credentials);

        $url = self::DRIVE_API_URL . '/about?fields=storageQuota';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
        ]);

        $response = $this->executeCurl($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || $response === false) {
            throw new RuntimeException("Failed to query Google Drive quota (HTTP {$httpCode})");
        }

        $data = json_decode($response, true);
        $quota = $data['storageQuota'] ?? [];

        $limit = isset($quota['limit']) ? (int)$quota['limit'] : 0;
        $usage = isset($quota['usage']) ? (int)$quota['usage'] : 0;
        $free  = max(0, $limit - $usage);

        return [
            'total_bytes' => $limit,
            'used_bytes'  => $usage,
            'free_bytes'  => $free,
        ];
    }

    public function healthCheck(string $accountId, array $credentials): array {
        $start = microtime(true);
        try {
            $accessToken = $this->getValidAccessToken($credentials);
            $url = self::DRIVE_API_URL . '/about?fields=user';
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
            ]);

            $response = $this->executeCurl($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $durationMs = (int)(round((microtime(true) - $start) * 1000));

            if ($httpCode === 200) {
                return [
                    'status'           => 'HEALTHY',
                    'response_time_ms' => $durationMs,
                    'error_message'    => null,
                ];
            }

            return [
                'status'           => 'UNHEALTHY',
                'response_time_ms' => $durationMs,
                'error_message'    => "HTTP {$httpCode}: {$response}",
            ];
        } catch (Throwable $e) {
            $durationMs = (int)(round((microtime(true) - $start) * 1000));
            return [
                'status'           => 'ERROR',
                'response_time_ms' => $durationMs,
                'error_message'    => $e->getMessage(),
            ];
        }
    }

    public function deleteFile(string $accountId, array $credentials, string $providerFileId): bool {
        $accessToken = $this->getValidAccessToken($credentials);

        $url = self::DRIVE_API_URL . '/files/' . urlencode($providerFileId);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
        ]);

        $response = $this->executeCurl($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($httpCode === 204 || $httpCode === 200 || $httpCode === 404);
    }

    public function getDownloadStream(string $accountId, array $credentials, string $providerFileId, array $headers = []): array {
        $accessToken = $this->getValidAccessToken($credentials);
        $url = self::DRIVE_API_URL . '/files/' . urlencode($providerFileId) . '?alt=media';

        $reqHeaders = ['Authorization: Bearer ' . $accessToken];
        if (!empty($headers['Range'])) {
            $reqHeaders[] = 'Range: ' . $headers['Range'];
        }

        return [
            'stream_url' => $url,
            'headers'    => $reqHeaders,
        ];
    }

    /**
     * Verify that a specific folder exists on Google Drive and is not trashed
     */
    public function verifyFolder(array &$credentials, string $folderId): bool {
        if (empty($folderId) || $folderId === 'root') {
            return true;
        }

        try {
            $accessToken = $this->getValidAccessToken($credentials);
            $url = self::DRIVE_API_URL . '/files/' . urlencode($folderId) . '?fields=id,name,trashed';
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
            ]);

            $response = $this->executeCurl($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200 && $response !== false) {
                $data = json_decode($response, true);
                return !empty($data['id']) && empty($data['trashed']);
            }
            return false;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Search for or create the dedicated storage root folder on Google Drive
     */
    public function findOrCreateRootFolder(array &$credentials, string $folderName = '2TOOLNE Cloud Storage'): string {
        $accessToken = $this->getValidAccessToken($credentials);

        // 1. Search for existing folder ('2TOOLNE Cloud Storage' or legacy '2toolne_cloud_storage_root')
        $query = "(name = '" . addslashes($folderName) . "' or name = '2toolne_cloud_storage_root') and mimeType = 'application/vnd.google-apps.folder' and trashed = false";
        $url = self::DRIVE_API_URL . '/files?q=' . urlencode($query) . '&fields=files(id,name)&spaces=drive';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
        ]);

        $response = $this->executeCurl($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $response !== false) {
            $data = json_decode($response, true);
            if (!empty($data['files']) && is_array($data['files']) && count($data['files']) > 0) {
                return (string)$data['files'][0]['id'];
            }
        }

        // 2. Not found, create dedicated folder
        $chCreate = curl_init(self::DRIVE_API_URL . '/files');
        $metadata = [
            'name'        => $folderName,
            'mimeType'    => 'application/vnd.google-apps.folder',
            'description' => 'Thư mục lưu trữ riêng biệt của hệ thống 2TOOLNE Cloud (Toàn bộ dữ liệu tải lên sẽ được cô lập an toàn tại đây).',
        ];

        curl_setopt_array($chCreate, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($metadata),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json; charset=UTF-8',
            ],
        ]);

        $createRes = $this->executeCurl($chCreate);
        $createCode = curl_getinfo($chCreate, CURLINFO_HTTP_CODE);
        curl_close($chCreate);

        if ($createCode !== 200 || $createRes === false) {
            throw new RuntimeException("Failed to create root folder {$folderName} on Google Drive (HTTP {$createCode}): {$createRes}");
        }

        $created = json_decode($createRes, true);
        if (empty($created['id'])) {
            throw new RuntimeException("Google Drive did not return folder id for {$folderName}");
        }

        return (string)$created['id'];
    }

    /**
     * Search for or create a subfolder inside parentFolderId
     */
    public function findOrCreateSubFolder(array &$credentials, string $folderName, string $parentFolderId): string {
        $accessToken = $this->getValidAccessToken($credentials);

        // 1. Search for existing subfolder inside parentFolderId
        $query = "name = '" . addslashes($folderName) . "' and '" . addslashes($parentFolderId) . "' in parents and mimeType = 'application/vnd.google-apps.folder' and trashed = false";
        $url = self::DRIVE_API_URL . '/files?q=' . urlencode($query) . '&fields=files(id,name)&spaces=drive';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
        ]);

        $response = $this->executeCurl($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $response !== false) {
            $data = json_decode($response, true);
            if (!empty($data['files']) && is_array($data['files']) && count($data['files']) > 0) {
                return (string)$data['files'][0]['id'];
            }
        }

        // 2. Not found, create subfolder inside parent
        $chCreate = curl_init(self::DRIVE_API_URL . '/files');
        $metadata = [
            'name'        => $folderName,
            'mimeType'    => 'application/vnd.google-apps.folder',
            'parents'     => [$parentFolderId],
            'description' => 'Thư mục thành viên trong 2TOOLNE Cloud Team Space.',
        ];

        curl_setopt_array($chCreate, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($metadata),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json; charset=UTF-8',
            ],
        ]);

        $createRes = $this->executeCurl($chCreate);
        $createCode = curl_getinfo($chCreate, CURLINFO_HTTP_CODE);
        curl_close($chCreate);

        if ($createCode !== 200 || $createRes === false) {
            throw new RuntimeException("Failed to create subfolder {$folderName} on Google Drive (HTTP {$createCode}): {$createRes}");
        }

        $created = json_decode($createRes, true);
        if (empty($created['id'])) {
            throw new RuntimeException("Google Drive did not return folder id for {$folderName}");
        }

        return (string)$created['id'];
    }
}

