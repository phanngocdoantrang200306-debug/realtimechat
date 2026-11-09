<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../src/Database.php';

function respond(int $status, array $data): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, [
        'success' => false,
        'message' => 'Chỉ hỗ trợ POST.'
    ]);
}

try {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!is_array($data)) {
        respond(400, [
            'success' => false,
            'message' => 'JSON không hợp lệ.'
        ]);
    }

    $nickname = trim((string) ($data['nickname'] ?? ''));
    $password = (string) ($data['password'] ?? '');

    if ($nickname === '' || $password === '') {
        respond(400, [
            'success' => false,
            'message' => 'Vui lòng nhập đầy đủ thông tin.'
        ]);
    }

    $pdo = (new Database())->getConnection();

    $stmt = $pdo->prepare(
        'SELECT id, nickname, password_hash
         FROM users
         WHERE nickname = ?
         LIMIT 1'
    );

    $stmt->execute([$nickname]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !is_string($user['password_hash'])
        || !password_verify($password, $user['password_hash'])) {
        respond(401, [
            'success' => false,
            'message' => 'Sai biệt danh hoặc mật khẩu.'
        ]);
    }

    session_start();
    session_regenerate_id(true);

    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['nickname'] = $user['nickname'];

    respond(200, [
        'success' => true,
        'message' => 'Đăng nhập thành công.',
        'user' => [
            'id' => (int) $user['id'],
            'nickname' => $user['nickname']
        ]
    ]);

} catch (Throwable $e) {
    error_log($e->getMessage());

    respond(500, [
        'success' => false,
        'message' => 'Không thể đăng nhập.'
    ]);
}
