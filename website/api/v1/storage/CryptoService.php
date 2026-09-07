<?php
/**
 * 2TOOLNE CLOUD — CRYPTO SERVICE (AES-256-GCM)
 * Provides authenticated encryption for OAuth refresh tokens, credentials, and sensitive metadata.
 * Compatible with PHP 7.4.33 & PHP 8.x
 */

declare(strict_types=1);

class CryptoService {
    private const CIPHER_METHOD = 'aes-256-gcm';
    private const IV_LENGTH = 12; // 96-bit IV recommended for GCM
    private const TAG_LENGTH = 16; // 128-bit authentication tag

    /**
     * Get 256-bit encryption key derived from CLOUD_MASTER_KEY
     */
    private static function getKey(?string $overrideKey = null): string {
        $rawKey = $overrideKey ?: (defined('CLOUD_MASTER_KEY') ? CLOUD_MASTER_KEY : '2toolne_cloud_master_aes256_secret_key_2026_salt');
        return hash('sha256', $rawKey, true);
    }

    /**
     * Encrypt a plaintext string using AES-256-GCM
     * Output format: base64(IV[12] . TAG[16] . CIPHERTEXT)
     */
    public static function encrypt(string $plaintext, ?string $key = null): string {
        $encryptionKey = self::getKey($key);
        $iv = openssl_random_pseudo_bytes(self::IV_LENGTH);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER_METHOD,
            $encryptionKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH
        );

        if ($ciphertext === false) {
            throw new RuntimeException('Encryption failed: ' . openssl_error_string());
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Decrypt an AES-256-GCM encrypted string
     */
    public static function decrypt(string $encryptedPayload, ?string $key = null): string {
        $encryptionKey = self::getKey($key);
        $decoded = base64_decode($encryptedPayload, true);

        if ($decoded === false || strlen($decoded) < (self::IV_LENGTH + self::TAG_LENGTH)) {
            throw new InvalidArgumentException('Invalid encrypted payload format or length.');
        }

        $iv = substr($decoded, 0, self::IV_LENGTH);
        $tag = substr($decoded, self::IV_LENGTH, self::TAG_LENGTH);
        $ciphertext = substr($decoded, self::IV_LENGTH + self::TAG_LENGTH);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER_METHOD,
            $encryptionKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext === false) {
            throw new RuntimeException('Decryption failed or data integrity check failed (invalid authentication tag).');
        }

        return $plaintext;
    }

    /**
     * Helper to encrypt array/JSON data
     */
    public static function encryptJson(array $data, ?string $key = null): string {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new InvalidArgumentException('Failed to encode data to JSON for encryption.');
        }
        return self::encrypt($json, $key);
    }

    /**
     * Helper to decrypt array/JSON data
     */
    public static function decryptJson(string $encryptedPayload, ?string $key = null): array {
        $json = self::decrypt($encryptedPayload, $key);
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new RuntimeException('Decrypted data is not valid JSON array.');
        }
        return $data;
    }
}
