<?php
/**
 * 2TOOLNE CLOUD — STORAGE PROVIDER ABSTRACTION INTERFACE
 * Standard contract for cloud storage adapters (Google Drive, AWS S3, Cloudflare R2, Backblaze B2)
 * Compatible with PHP 7.4.33 & PHP 8.x
 */

declare(strict_types=1);

interface StorageProviderInterface {
    /**
     * Get provider unique identifier (e.g., 'GOOGLE_DRIVE')
     */
    public function getProviderName(): string;

    /**
     * Create a direct resumable upload session on the physical provider
     *
     * @param string $accountId Storage account identifier
     * @param array $credentials Decrypted credentials/tokens
     * @param string $fileName Target file name
     * @param int $fileSizeBytes Expected file size in bytes
     * @param string $mimeType MIME type of the file
     * @param string|null $parentFolderId Physical folder ID on provider (e.g. root_folder_id)
     * @return array ['session_url' => string, 'expires_at' => string]
     */
    public function createUploadSession(
        string $accountId,
        array $credentials,
        string $fileName,
        int $fileSizeBytes,
        string $mimeType,
        ?string $parentFolderId = null
    ): array;

    /**
     * Verify that an uploaded file exists and matches the expected size on provider
     *
     * @param string $accountId Storage account identifier
     * @param array $credentials Decrypted credentials/tokens
     * @param string $providerFileId Provider unique file ID (e.g. Google Drive file ID)
     * @param int $expectedSizeBytes Expected size in bytes
     * @return array ['verified' => bool, 'actual_size' => int, 'provider_file_id' => string, 'md5' => string|null]
     */
    public function verifyUploadedFile(
        string $accountId,
        array $credentials,
        string $providerFileId,
        int $expectedSizeBytes
    ): array;

    /**
     * Query physical storage capacity and usage from the provider API
     *
     * @param string $accountId Storage account identifier
     * @param array $credentials Decrypted credentials/tokens
     * @return array ['total_bytes' => int, 'used_bytes' => int, 'free_bytes' => int]
     */
    public function getCapacityUsage(string $accountId, array $credentials): array;

    /**
     * Perform an active health check ping against provider API
     *
     * @param string $accountId Storage account identifier
     * @param array $credentials Decrypted credentials/tokens
     * @return array ['status' => 'HEALTHY'|'DEGRADED'|'UNHEALTHY'|'ERROR', 'response_time_ms' => int, 'error_message' => string|null]
     */
    public function healthCheck(string $accountId, array $credentials): array;

    /**
     * Delete a file permanently on the physical provider
     *
     * @param string $accountId Storage account identifier
     * @param array $credentials Decrypted credentials/tokens
     * @param string $providerFileId Provider file ID
     * @return bool
     */
    public function deleteFile(string $accountId, array $credentials, string $providerFileId): bool;

    /**
     * Get or pipe download stream from provider
     *
     * @param string $accountId Storage account identifier
     * @param array $credentials Decrypted credentials/tokens
     * @param string $providerFileId Provider file ID
     * @param array $headers Optional HTTP request headers (e.g. Range)
     * @return array ['stream_url' => string, 'headers' => array]
     */
    public function getDownloadStream(string $accountId, array $credentials, string $providerFileId, array $headers = []): array;
}
