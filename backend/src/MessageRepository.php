<?php

require_once __DIR__ . '/Database.php';

class MessageRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $database = new Database();
        $this->pdo = $database->getConnection();
    }

    public function findUserId(string $nickname): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM users WHERE nickname = ? ORDER BY id LIMIT 1'
        );

        $stmt->execute([$nickname]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    
    public function findOrCreateUser(string $nickname): int
    {
        // Tìm người dùng đã tồn tại
        $userId = $this->findUserId($nickname);

        if ($userId !== null) {
            return $userId;
        }

        // Nếu chưa tồn tại, tạo người dùng mới
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (nickname) VALUES (?)'
        );

        $stmt->execute([$nickname]);

        return (int) $this->pdo->lastInsertId();
    }


    public function roomExists(int $roomId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM rooms WHERE id = ? LIMIT 1'
        );

        $stmt->execute([$roomId]);

        return $stmt->fetchColumn() !== false;
    }

    public function saveMessage(
        int $roomId,
        int $userId,
        string $content
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO messages (room_id, user_id, content)
             VALUES (?, ?, ?)'
        );

        $stmt->execute([$roomId, $userId, $content]);

        return (int) $this->pdo->lastInsertId();
    }

    public function verifyRoomPassword(
        int $roomId,
        string $password
    ): bool {
        $stmt = $this->pdo->prepare(
            'SELECT is_private, password_hash
            FROM rooms
            WHERE id = ?
            LIMIT 1'
        );

        $stmt->execute([$roomId]);

        $room = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$room) {
            return false;
        }

        // Phòng công khai không cần mật khẩu
        if (!(bool) $room['is_private']) {
            return true;
        }

        // Phòng riêng phải có hash hợp lệ
        $hash = $room['password_hash'];

        if (!is_string($hash) || $hash === '') {
            return false;
        }

        return password_verify($password, $hash);
    }


    public function getRoomMessages(int $roomId, int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT
                m.id,
                m.room_id,
                m.user_id,
                u.nickname AS username,
                m.content,
                DATE_FORMAT(m.created_at, "%H:%i") AS time
            FROM messages m
            JOIN users u ON m.user_id = u.id
            WHERE m.room_id = ?
            ORDER BY m.id DESC
            LIMIT ?'
        );

        $stmt->bindValue(1, $roomId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
    }


    public function editMessage(
        int $messageId,
        int $userId,
        int $roomId,
        string $content
    ): bool {
        $stmt = $this->pdo->prepare(
            'UPDATE messages
            SET content = ?
            WHERE id = ?
            AND user_id = ?
            AND room_id = ?'
        );

        $stmt->execute([
            $content,
            $messageId,
            $userId,
            $roomId
        ]);

        return $stmt->rowCount() > 0;
    }

    public function deleteMessage(
        int $messageId,
        int $userId,
        int $roomId
    ): bool {
        $stmt = $this->pdo->prepare(
            'DELETE FROM messages
            WHERE id = ?
            AND user_id = ?
            AND room_id = ?'
        );

        $stmt->execute([
            $messageId,
            $userId,
            $roomId
        ]);

        return $stmt->rowCount() > 0;
    }

}
