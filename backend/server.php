<?php

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/src/ChatServer.php';

use Ratchet\App;

fwrite(STDERR, "Starting WebSocket Server...\n");

$allowedHosts = [
    'localhost',
    '127.0.0.1'
];

$publicOrigin = getenv('APP_ORIGIN');

if ($publicOrigin) {
    $host = parse_url($publicOrigin, PHP_URL_HOST);

    if ($host) {
        $allowedHosts[] = $host;
    }
}

$app = new App('127.0.0.1', 8080, '127.0.0.1');

$app->route('/chat', new ChatServer(), $allowedHosts);

fwrite(STDERR, "WebSocket running on port 8080\n");

$app->run();