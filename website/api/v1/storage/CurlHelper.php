<?php
/**
 * 2TOOLNE CLOUD — CURL SSL HELPER
 * Ensures cURL uses valid CA certificate bundle on Windows and shared hosting environments.
 * Zero security compromise: CURLOPT_SSL_VERIFYPEER remains strictly enabled.
 */

declare(strict_types=1);

class CurlHelper {
    private static $cachedCa = null;

    public static function getCaBundle(): ?string {
        if (self::$cachedCa !== null) {
            return self::$cachedCa;
        }

        $candidates = [
            dirname(__DIR__, 2) . '/storage/cacert.pem',
            'C:/wwwroot/2tamne.site/storage/cacert.pem',
            __DIR__ . '/cacert.pem',
        ];

        foreach ($candidates as $p) {
            if (file_exists($p)) {
                self::$cachedCa = $p;
                return $p;
            }
        }

        return null;
    }

    public static function applySslOptions($ch): void {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $ca = self::getCaBundle();
        if ($ca !== null) {
            curl_setopt($ch, CURLOPT_CAINFO, $ca);
        }
    }
}
