<?php

require_once __DIR__ . '/MessageRepository.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class ChatServer implements MessageComponentInterface
{
    private SplObjectStorage $clients;
    private MessageRepository $messageRepository;

    public function __construct()
    {
        $this->messageRepository = new MessageRepository();
        $this->clients = new SplObjectStorage();

        $this->log("ChatServer initialized!");
    }

    private function log(string $message): void
    {
        // STDERR logging does not write HTTP response output.
        file_put_contents('php://stderr', "[ChatServer] {$message}\n");
    }

    private function getAuthenticatedUser(ConnectionInterface $conn): ?array
    {
        $request = $conn->httpRequest ?? null;
        if ($request === null) {
            $this->log('Handshake request missing');
            return null;
        }

        $origin = $request->getHeaderLine('Origin');

        $allowedOrigins = [
            'http://127.0.0.1:8000',
            'http://localhost:8000'
        ];

        $publicOrigin = getenv('APP_ORIGIN');

        if ($publicOrigin) {
            $allowedOrigins[] = rtrim($publicOrigin, '/');
        }

        if (!in_array($origin, $allowedOrigins, true)) {
            $this->log("Rejected Origin: {$origin}");
            return null;
        }

        $cookies = [];
        foreach (explode(';', $request->getHeaderLine('Cookie')) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) === 2) {
                $cookies[trim($pair[0])] = urldecode($pair[1]);
            }
        }

        $sessionId = $cookies[session_name()] ?? '';
        if (!is_string($sessionId) ||
            !preg_match('/^[a-zA-Z0-9,-]{16,128}$/D', $sessionId)) {
            $this->log('Missing or invalid PHP session cookie');
            return null;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        // Each WebSocket event must read the latest session from the shared store.
        $_SESSION = [];
        session_id($sessionId);

        if (!session_start([
            'use_strict_mode' => 1,
            'use_cookies' => 0,
            'cache_limiter' => '',
            'read_and_close' => true
        ])) {
            $this->log('Cannot open PHP session; check session.save_path');
            return null;
        }

        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $nickname = $_SESSION['nickname'] ?? null;
        if ($userId <= 0 || !is_string($nickname) || $nickname === '') {
            $this->log('Session opened but user_id/nickname missing');
            return null;
        }

        return ['user_id' => $userId, 'username' => $nickname];
    }

    public function onOpen(ConnectionInterface $conn)
    {
        $this->log("onOpen: {$conn->resourceId}");
        $user = $this->getAuthenticatedUser($conn);

        if ($user === null) {
            $this->log("Unauthorized connection: {$conn->resourceId}");
            $conn->close();
            return;
        }

        $this->clients->attach($conn, [
            'room_id' => null,
            'user_id' => $user['user_id'],
            'username' => $user['username']
        ]);

        $this->log("Authenticated: {$user['username']}");
    }

    public function onMessage(ConnectionInterface $from, $msg)
    {
        if (!$this->clients->contains($from)) {
            $from->close();
            return;
        }

        // Kiểm tra lại session khi nhận sự kiện
        $authenticatedUser = $this->getAuthenticatedUser($from);
        $clientInfo = $this->clients[$from];

        if (
            $authenticatedUser === null ||
            $authenticatedUser['user_id'] !== $clientInfo['user_id']
        ) {
            $this->log("Session invalid: {$from->resourceId}");
            $from->close();
            return;
        }

        $data = json_decode($msg, true);

        if (!is_array($data) || !isset($data['type'])) {
            $this->sendError($from, 'Invalid JSON message');
            return;
        }

        switch ($data['type']) {
            case 'join_room':
                $this->joinRoom($from, $data);
                break;

            case 'send_message':
                $this->sendMessage($from, $data);
                break;

            case 'edit_message':
                $this->editMessage($from, $data);
                break;

            case 'delete_message':
                $this->deleteMessage($from, $data);
                break;

            case 'leave_room':
                $this->leaveRoom($from);
                break;

            case 'room_created':
                $this->notifyRoomCreated($from, $data);
                break;

            case 'get_room_counts':
                $this->sendRoomCounts($from);
                break;

            case 'typing':
                $clientInfo = $this->clients[$from];
                $roomId = $clientInfo['room_id'] ?? null;

                if ($roomId === null) {
                    break;
                }

                $payload = json_encode([
                    'type' => 'typing',
                    'room_id' => $roomId,
                    'username' => $clientInfo['username'],
                    'is_typing' => ($data['is_typing'] ?? false) === true
                ], JSON_UNESCAPED_UNICODE);

                foreach ($this->clients as $client) {
                    if ($client === $from) {
                        continue;
                    }

                    $info = $this->clients[$client];

                    if ($info['room_id'] === $roomId) {
                        $client->send($payload);
                    }
                }
                break;
                case 'typing':
                    $this->broadcastTyping($from, true);
                    break;

                case 'stop_typing':
                    $this->broadcastTyping($from, false);
                    break;
            default:
                $this->sendError($from, 'Unknown event type');
        }
    }

    private function broadcastTyping(
        ConnectionInterface $from,
        bool $isTyping
    ): void {
        $clientInfo = $this->clients[$from];

        $roomId = $clientInfo['room_id'] ?? null;
        $username = $clientInfo['username'] ?? 'Someone';

        // Chỉ gửi cho những người cùng phòng
        foreach ($this->clients as $client) {
            $info = $this->clients[$client];

            if (
                $client !== $from &&
                ($info['room_id'] ?? null) === $roomId
            ) {
                $client->send(json_encode([
                    'type' => 'typing',
                    'username' => $username,
                    'is_typing' => $isTyping
                ], JSON_UNESCAPED_UNICODE));
            }
        }
    }

    private function sendRoomCounts(
        ConnectionInterface $conn
    ): void {
        $counts = [];

        foreach ($this->clients as $client) {
            $info = $this->clients[$client];
            $roomId = $info['room_id'] ?? null;

            if ($roomId !== null) {
                $counts[$roomId] = ($counts[$roomId] ?? 0) + 1;
            }
        }

        $conn->send(json_encode([
            'type' => 'room_counts',
            'counts' => $counts
        ], JSON_UNESCAPED_UNICODE));
    }

    private function joinRoom(
        ConnectionInterface $conn,
        array $data
    ): void {
        $roomId = filter_var(
            $data['room_id'] ?? null,
            FILTER_VALIDATE_INT
        );

        $clientInfo = $this->clients[$conn];

        // Danh tính lấy từ session, không lấy từ client
        $username = $clientInfo['username'];
        $userId = $clientInfo['user_id'];

        if (
            $roomId === false ||
            $roomId < 1 ||
            mb_strlen($username) < 2 ||
            mb_strlen($username) > 24
        ) {
            $this->sendError(
                $conn,
                'Invalid room or username'
            );
            return;
        }

        if (!$this->messageRepository->roomExists($roomId)) {
            $this->sendError($conn, 'Room not found');
            return;
        }
        
        // Kiểm tra nickname đã tồn tại trong phòng hay chưa
        foreach ($this->clients as $client) {
            // Không so sánh kết nối hiện tại với chính nó
            if ($client === $conn) {
                continue;
            }

            $info = $this->clients[$client];

            // Chỉ kiểm tra những người đang ở cùng phòng
            if ((int) ($info['room_id'] ?? 0) !== (int) $roomId) {
                continue;
            }

            // So sánh nickname không phân biệt chữ hoa/chữ thường
            if (mb_strtolower($info['username']) === mb_strtolower($username)) {
                $conn->send(json_encode([
                    'type' => 'join_denied',
                    'room_id' => $roomId,
                    'message' => 'Nickname này đã được sử dụng trong phòng.'
                ], JSON_UNESCAPED_UNICODE));

                return;
            }
        }


        $password = (string) ($data['password'] ?? '');

        if (!$this->messageRepository->verifyRoomPassword(
            $roomId,
            $password
        )) {
            $conn->send(json_encode([
                'type' => 'join_denied',
                'room_id' => $roomId,
                'message' => 'Mật khẩu phòng không chính xác.'
            ], JSON_UNESCAPED_UNICODE));

            return;
        }

        // Rời phòng cũ trước khi vào phòng mới
        $this->leaveRoom($conn);

        $this->clients[$conn] = [
            'room_id' => $roomId,
            'user_id' => $userId,
            'username' => $username
        ];

        try {
            $messages =
                $this->messageRepository->getRoomMessages($roomId);

            $conn->send(json_encode([
                'type' => 'chat_history',
                'room_id' => $roomId,
                'messages' => $messages
            ], JSON_UNESCAPED_UNICODE));

            $this->log(
                "Sent " . count($messages) .
                " messages to {$username}"
            );
        } catch (\PDOException $e) {
            $this->log($e->getMessage());

            $this->sendError(
                $conn,
                'Could not load chat history'
            );
        }

        $this->broadcast($roomId, [
            'type' => 'user_joined',
            'username' => $username
        ]);

        $this->broadcastOnlineUsers($roomId);
        $this->broadcastRoomCounts();

        $this->log("{$username} joined room {$roomId}");
    }

    private function sendMessage(
        ConnectionInterface $conn,
        array $data
    ): void {
        $client = $this->clients[$conn];

        $roomId = $client['room_id'] ?? null;
        $userId = $client['user_id'] ?? null;

        if ($roomId === null || $userId === null) {
            $this->sendError($conn, 'Join a room first');
            return;
        }

        $content = trim((string) ($data['content'] ?? ''));

        if ($content === '' || mb_strlen($content) > 2000) {
            $this->sendError(
                $conn,
                'Invalid message content'
            );
            return;
        }

        try {
            $messageId =
                $this->messageRepository->saveMessage(
                    $roomId,
                    $userId,
                    $content
                );

            $this->log("Saved message ID: {$messageId}");

            $this->broadcast($roomId, [
                'type' => 'new_message',
                'id' => $messageId,
                'user_id' => $userId,
                'room_id' => $roomId,
                'username' => $client['username'],
                'content' => $content,
                'time' => date('H:i')
            ]);
        } catch (\PDOException $e) {
            $this->log($e->getMessage());

            $this->sendError(
                $conn,
                'Could not save message'
            );
        }
    }

    private function editMessage(
        ConnectionInterface $conn,
        array $data
    ): void {
        $client = $this->clients[$conn];

        $roomId = $client['room_id'] ?? null;
        $userId = $client['user_id'] ?? null;

        $messageId = filter_var(
            $data['message_id'] ?? null,
            FILTER_VALIDATE_INT
        );

        $content = trim((string) ($data['content'] ?? ''));

        if ($roomId === null || $userId === null) {
            $this->sendError($conn, 'Join a room first');
            return;
        }

        if ($messageId === false || $messageId < 1) {
            $this->sendError($conn, 'Invalid message ID');
            return;
        }

        if ($content === '' || mb_strlen($content) > 2000) {
            $this->sendError($conn, 'Invalid message content');
            return;
        }

        try {
            $updated = $this->messageRepository->editMessage(
                $messageId,
                $userId,
                $roomId,
                $content
            );

            if (!$updated) {
                $this->sendError(
                    $conn,
                    'Message not found, unchanged, or permission denied'
                );
                return;
            }

            $this->broadcast($roomId, [
                'type' => 'message_edited',
                'message_id' => $messageId,
                'room_id' => $roomId,
                'content' => $content
            ]);

            $this->log("Edited message ID: {$messageId}");

        } catch (\PDOException $e) {
            $this->log($e->getMessage());
            $this->sendError($conn, 'Could not edit message');
        }
    }

    private function deleteMessage(
        ConnectionInterface $conn,
        array $data
    ): void {
        $client = $this->clients[$conn];

        $roomId = $client['room_id'] ?? null;
        $userId = $client['user_id'] ?? null;

        $messageId = filter_var(
            $data['message_id'] ?? null,
            FILTER_VALIDATE_INT
        );

        if ($roomId === null || $userId === null) {
            $this->sendError($conn, 'Join a room first');
            return;
        }

        if ($messageId === false || $messageId < 1) {
            $this->sendError($conn, 'Invalid message ID');
            return;
        }

        try {
            $deleted = $this->messageRepository->deleteMessage(
                $messageId,
                $userId,
                $roomId
            );

            if (!$deleted) {
                $this->sendError(
                    $conn,
                    'Message not found or permission denied'
                );
                return;
            }

            $this->broadcast($roomId, [
                'type' => 'message_deleted',
                'message_id' => $messageId,
                'room_id' => $roomId
            ]);

            $this->log("Deleted message ID: {$messageId}");

        } catch (\PDOException $e) {
            $this->log($e->getMessage());
            $this->sendError($conn, 'Could not delete message');
        }
    }

    private function leaveRoom(
        ConnectionInterface $conn
    ): void {
        if (!$this->clients->contains($conn)) {
            return;
        }

        $info = $this->clients[$conn];

        $roomId = $info['room_id'] ?? null;
        $username = $info['username'] ?? null;

        if ($roomId === null) {
            return;
        }

        // Chỉ xóa phòng hiện tại, giữ danh tính đăng nhập
        $this->clients[$conn] = [
            'room_id' => null,
            'user_id' => $info['user_id'],
            'username' => $info['username']
        ];

        $this->broadcast($roomId, [
            'type' => 'user_left',
            'username' => $username
        ]);

        $this->broadcastOnlineUsers($roomId);
        $this->broadcastRoomCounts();

        $this->log("{$username} left room {$roomId}");
    }

    private function broadcast(
        int $roomId,
        array $payload
    ): void {
        $json = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE
        );

        foreach ($this->clients as $client) {
            $info = $this->clients[$client];

            if ($info['room_id'] === $roomId) {
                $client->send($json);
            }
        }
    }

    private function broadcastOnlineUsers(
        int $roomId
    ): void {
        $users = [];

        foreach ($this->clients as $client) {
            $info = $this->clients[$client];

            if (($info['room_id'] ?? null) === $roomId) {
                $username = $info['username'] ?? null;

                if ($username !== null) {
                    $users[$username] = $username;
                }
            }
        }

        $this->broadcast($roomId, [
            'type' => 'online_users',
            'room_id' => $roomId,
            'users' => array_values($users),
            'count' => count($users)
        ]);
    }

    private function notifyRoomCreated(
        ConnectionInterface $conn,
        array $data
    ): void {
        $roomId = filter_var(
            $data['room_id'] ?? null,
            FILTER_VALIDATE_INT
        );

        if ($roomId === false || $roomId < 1) {
            $this->sendError($conn, 'Invalid room ID');
            return;
        }

        if (!$this->messageRepository->roomExists($roomId)) {
            $this->sendError($conn, 'Room not found');
            return;
        }

        $this->broadcastAll([
            'type' => 'room_created',
            'room_id' => $roomId
        ]);

        $this->log("Room created notification: {$roomId}");
    }

    private function broadcastAll(
        array $payload
    ): void {
        $json = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE
        );

        foreach ($this->clients as $client) {
            $client->send($json);
        }
    }

    private function sendError(
        ConnectionInterface $conn,
        string $message
    ): void {
        $conn->send(json_encode([
            'type' => 'error',
            'message' => $message
        ], JSON_UNESCAPED_UNICODE));
    }

    private function broadcastRoomCounts(): void
    {
        $counts = [];

        foreach ($this->clients as $client) {
            $info = $this->clients[$client];
            $roomId = $info['room_id'] ?? null;

            if ($roomId === null) {
                continue;
            }

            $counts[$roomId] = ($counts[$roomId] ?? 0) + 1;
        }

        $this->broadcastAll([
            'type' => 'room_counts',
            'counts' => $counts
        ]);
    }

    public function onClose(ConnectionInterface $conn)
    {
        $this->leaveRoom($conn);

        if ($this->clients->contains($conn)) {
            $this->clients->detach($conn);
        }

        $this->log("Disconnected: {$conn->resourceId}");
    }

    public function onError(
        ConnectionInterface $conn,
        \Exception $e
    ) {
        $this->log("Error: {$e->getMessage()}");
        $conn->close();
    }
}
