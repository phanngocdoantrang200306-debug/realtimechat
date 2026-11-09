<?php

require_once __DIR__ . '/../src/Database.php';

try {
    $database = new Database();
    $pdo = $database->getConnection();

    echo "Ket noi MySQL thanh cong!\n\n";

    $sql = "
        SELECT
            u.nickname,
            r.name AS room_name,
            m.content,
            m.created_at
        FROM messages m
        JOIN users u ON m.user_id = u.id
        JOIN rooms r ON m.room_id = r.id
        ORDER BY m.id DESC
        LIMIT 5
    ";

    $stmt = $pdo->query($sql);
    $messages = $stmt->fetchAll();

    foreach ($messages as $message) {
        echo "User: " . $message['nickname'] . "\n";
        echo "Room: " . $message['room_name'] . "\n";
        echo "Message: " . $message['content'] . "\n";
        echo "Time: " . $message['created_at'] . "\n\n";
    }

} catch (PDOException $e) {
    echo "Ket noi hoac truy van MySQL that bai.\n";
    error_log($e->getMessage());
    exit(1);
}
