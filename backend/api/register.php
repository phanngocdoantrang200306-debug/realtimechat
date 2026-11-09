<?php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../src/Database.php';

function respond(int $status, array $data): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, [
        'success' => false,
        'message' => 'Chỉ hỗ trợ phương thức POST.'
    ]);
}

try {
    $data = json_decode(
        file_get_contents('php://input'),
        true
    );

    if (!is_array($data)) {
        respond(400, [
            'success' => false,
            'message' => 'Dữ liệu JSON không hợp lệ.'
        ]);
    }

    $nickname = trim((string) ($data['nickname'] ?? ''));
    $password = (string) ($data['password'] ?? '');

    if (mb_strlen($nickname) < 2 || mb_strlen($nickname) > 24) {
        respond(400, [
            'success' => false,
            'message' => 'Biệt danh phải có từ 2 đến 24 ký tự.'
        ]);
    }

    if (strlen($password) < 8 || strlen($password) > 72) {
        respond(400, [
            'success' => false,
            'message' => 'Mật khẩu phải có từ 8 đến 72 byte.'
        ]);
    }

    $pdo = (new Database())->getConnection();

    // Không cho đăng ký trùng nickname đã tồn tại
    $stmt = $pdo->prepare(
        'SELECT id FROM users WHERE nickname = ? LIMIT 1'
    );

    $stmt->execute([$nickname]);

    if ($stmt->fetchColumn() !== false) {
        respond(409, [
            'success' => false,
            'message' => 'Biệt danh đã tồn tại.'
        ]);
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare(
        'INSERT INTO users (nickname, password_hash)
         VALUES (?, ?)'
    );

    $stmt->execute([$nickname, $hash]);

    respond(201, [
        'success' => true,
        'message' => 'Đăng ký tài khoản thành công.',
        'user' => [
            'id' => (int) $pdo->lastInsertId(),
            'nickname' => $nickname
        ]
    ]);

} catch (PDOException $e) {
    error_log($e->getMessage());

    respond(500, [
        'success' => false,
        'message' => 'Không thể đăng ký tài khoản.'
    ]);
}
