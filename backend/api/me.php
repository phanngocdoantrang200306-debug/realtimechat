<?php
header('Content-Type: application/json; charset=utf-8');

session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);

    echo json_encode([
        'success' => false,
        'message' => 'Chưa đăng nhập.'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

echo json_encode([
    'success' => true,
    'user' => [
        'id' => (int) $_SESSION['user_id'],
        'nickname' => $_SESSION['nickname']
    ]
], JSON_UNESCAPED_UNICODE);
