<?php
/**
 * 2TOOLNE UPSCALE — LIGHTWEIGHT ROUTER & REQUEST HANDLER
 */

declare(strict_types=1);

class Router {
    private array $routes = [];

    public function __construct() {
        // Enable CORS
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Device-Id, X-Requested-With, X-User-Id, X-Worker-Secret, X-API-Key');
        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit;
        }
    }

    public function addRoute(string $method, string $path, callable $handler): void {
        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => $path,
            'handler' => $handler,
        ];
    }

    public function get(string $path, callable $handler): void {
        $this->addRoute('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): void {
        $this->addRoute('POST', $path, $handler);
    }

    public function put(string $path, callable $handler): void {
        $this->addRoute('PUT', $path, $handler);
    }

    public function patch(string $path, callable $handler): void {
        $this->addRoute('PATCH', $path, $handler);
    }

    public function delete(string $path, callable $handler): void {
        $this->addRoute('DELETE', $path, $handler);
    }

    public function dispatch(): void {
        $requestMethod = $_SERVER['REQUEST_METHOD'];
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

        // Normalize URI: strip /api/v1 prefix if present
        $uri = preg_replace('#^/api/v1#', '', $uri);
        if ($uri === '') $uri = '/';

        foreach ($this->routes as $route) {
            if ($route['method'] === $requestMethod && $this->matchPath($route['path'], $uri, $params)) {
                try {
                    $body = json_decode(file_get_contents('php://input'), true) ?: [];
                    call_user_func_array($route['handler'], [$params, $body]);
                    return;
                } catch (Throwable $e) {
                    error_log("[Router::dispatch] " . $e->getMessage());
                    self::error('Lỗi hệ thống máy chủ. Vui lòng thử lại.', 500, 'SERVER_ERROR');
                    return;
                }
            }
        }

        self::error("Route not found: {$requestMethod} {$uri}", 404);
    }

    private function matchPath(string $routePath, string $uri, &$params): bool {
        $params = [];
        $pattern = preg_replace('#\{([a-zA-Z0-9_]+)\}#', '(?P<$1>[^/]+)', $routePath);
        $pattern = '#^' . $pattern . '$#';

        if (preg_match($pattern, $uri, $matches)) {
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = $value;
                }
            }
            return true;
        }
        return false;
    }

    public static function json($data, int $statusCode = 200): void {
        http_response_code($statusCode);
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function error(string $message, int $statusCode = 400, ?string $code = null): void {
        http_response_code($statusCode);
        echo json_encode([
            'ok' => false,
            'success' => false,
            'error' => $message,
            'message' => $message,
            'code' => $code ?: 'ERROR',
            'error_code' => $code ?: 'ERROR',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
