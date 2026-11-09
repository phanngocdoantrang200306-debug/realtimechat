<?php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../src/Database.php';

try {
    $database = new Database();
    $pdo = $database->getConnection();

    $stmt = $pdo->query("
        SELECT
            id,
            name,
            is_private
        FROM rooms
        ORDER BY id DESC
    ");

    $rooms = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $rooms[] = [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'private' => (bool) $row['is_private'],
            'online' => 0,
            'description' => $row['is_private']
                ? 'Phòng riêng có mật khẩu'
                : 'Phòng chat công khai'
        ];
    }

    echo json_encode([
        'success' => true,
        'rooms' => $rooms
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log($e->getMessage());

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Không thể tải danh sách phòng.'
    ], JSON_UNESCAPED_UNICODE);
}
