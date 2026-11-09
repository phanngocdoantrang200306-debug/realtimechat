<?php
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');

    echo json_encode([
        'success' => false,
        'message' => 'Chỉ hỗ trợ POST.'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

session_start();

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();

    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $params['path'],
        'domain' => $params['domain'],
        'secure' => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => $params['samesite'] ?: 'Lax'
    ]);
}

session_destroy();

echo json_encode([
    'success' => true,
    'message' => 'Đăng xuất thành công.'
], JSON_UNESCAPED_UNICODE);
