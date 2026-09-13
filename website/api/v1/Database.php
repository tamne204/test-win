<?php
/**
 * 2TOOLNE UPSCALE — DATABASE SINGLETON (PDO)
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

class Database {
    private static ?PDO $instance = null;

    public static function getConnection(): PDO {
        if (self::$instance === null) {
            if (getenv('USE_SQLITE') === '1' || (defined('DB_HOST') && DB_HOST === 'sqlite')) {
                if (!function_exists('get_db')) {
                    @include_once __DIR__ . '/../../storage/db.php';
                }
                if (function_exists('get_db')) {
                    self::$instance = get_db();
                    return self::$instance;
                }
            }

            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 2,
            ];
            if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
                @$options[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = true;
            }

            try {
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
            } catch (PDOException $e) {
                if (!function_exists('get_db')) {
                    @include_once __DIR__ . '/../../storage/db.php';
                }
                if (function_exists('get_db')) {
                    try {
                        self::$instance = get_db();
                        return self::$instance;
                    } catch (Throwable $t) {
                        // ignore
                    }
                }
                error_log("[Database::getConnection] " . $e->getMessage());
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'error' => 'Không thể kết nối đến cơ sở dữ liệu. Vui lòng thử lại.',
                    'message' => 'Không thể kết nối đến cơ sở dữ liệu. Vui lòng thử lại.',
                    'code' => 'DATABASE_CONNECTION_ERROR',
                    'error_code' => 'DATABASE_CONNECTION_ERROR'
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
        return self::$instance;
    }
}
