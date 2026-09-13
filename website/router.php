<?php
// Local dev router for php -S to mirror production Apache security rules
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';

// Block direct access to storage directory or sensitive files
if (preg_match('#^/storage(/|$)#i', $uri) || preg_match('#\.(txt|log|lock|env|git|sh|bat)$#i', $uri)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "403 Forbidden: Direct access to protected resources is denied.";
    exit;
}

// Serve existing static files as-is
$filePath = __DIR__ . $uri;
if ($uri !== '/' && file_exists($filePath) && !is_dir($filePath)) {
    return false;
}

// Route root to index.php
if ($uri === '/' || $uri === '') {
    require __DIR__ . '/index.php';
    exit;
}

return false;
