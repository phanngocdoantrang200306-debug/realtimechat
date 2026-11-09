<?php

header('Content-Type: application/json; charset=utf-8');

session_start();

require_once __DIR__ . '/../src/Database.php';

function respond(int $status, array $data): void
{
    http_response_code($status);

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE
    );

    exit;
}

// Chỉ chấp nhận POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');

    respond(405, [
        'success' => false,
        'message' => 'Chỉ hỗ trợ phương thức POST.'
    ]);
}

// Kiểm tra người dùng đã đăng nhập
if (!isset($_SESSION['user_id'])) {
    respond(401, [
        'success' => false,
        'message' => 'Bạn cần đăng nhập để tạo phòng.'
    ]);
}

// ID lấy từ phiên đăng nhập, không lấy từ frontend
$userId = (int) $_SESSION['user_id'];

if ($userId <= 0) {
    respond(401, [
        'success' => false,
        'message' => 'Phiên đăng nhập không hợp lệ.'
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

    $name = trim((string) ($data['name'] ?? ''));
    $isPrivate = ($data['private'] ?? false) === true;
    $password = (string) ($data['password'] ?? '');

    // Kiểm tra tên phòng
    if ($name === '' || mb_strlen($name) > 40) {
        respond(400, [
            'success' => false,
            'message' => 'Tên phòng phải có từ 1 đến 40 ký tự.'
        ]);
    }

    // Kiểm tra mật khẩu phòng riêng
    if ($isPrivate && $password === '') {
        respond(400, [
            'success' => false,
            'message' => 'Phòng riêng cần có mật khẩu.'
        ]);
    }

    if ($isPrivate && strlen($password) > 72) {
        respond(400, [
            'success' => false,
            'message' => 'Mật khẩu phòng không được vượt quá 72 byte.'
        ]);
    }

    $pdo = (new Database())->getConnection();

    // Xác nhận tài khoản vẫn tồn tại
    $stmt = $pdo->prepare(
        'SELECT id
         FROM users
         WHERE id = ? AND password_hash IS NOT NULL
         LIMIT 1'
    );

    $stmt->execute([$userId]);

    if (!$stmt->fetchColumn()) {
        respond(401, [
            'success' => false,
            'message' => 'Tài khoản không hợp lệ.'
        ]);
    }

    // Mã hóa mật khẩu phòng riêng
    $passwordHash = $isPrivate
        ? password_hash($password, PASSWORD_DEFAULT)
        : null;

    // Lưu phòng với chủ phòng là tài khoản đăng nhập
    $stmt = $pdo->prepare(
        'INSERT INTO rooms
            (name, is_private, password_hash, created_by)
         VALUES (?, ?, ?, ?)'
    );

    $stmt->execute([
        $name,
        $isPrivate ? 1 : 0,
        $passwordHash,
        $userId
    ]);

    $roomId = (int) $pdo->lastInsertId();

    respond(201, [
        'success' => true,
        'message' => 'Tạo phòng thành công.',
        'room' => [
            'id' => $roomId,
            'name' => $name,
            'private' => $isPrivate,
            'created_by' => $userId,
            'online' => 0,
            'description' => $isPrivate
                ? 'Phòng riêng có mật khẩu'
                : 'Phòng chat công khai'
        ]
    ]);

} catch (Throwable $e) {
    error_log($e->getMessage());

    respond(500, [
        'success' => false,
        'message' => 'Không thể tạo phòng.'
    ]);
}
