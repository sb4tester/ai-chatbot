<?php
// /home/bot.dailymu.com/public_html/api/test-line.php
header('Content-Type: application/json');

require_once __DIR__ . '/../../private/src/Config.php';

try {
    // Display Line Bot Configuration
    echo json_encode([
        'success' => true,
        'webhook_url' => 'https://bot.dailymu.com/api/line-webhook',
        'channel_access_token_length' => strlen(Config::LINE_CHANNEL_ACCESS_TOKEN),
        'channel_secret_length' => strlen(Config::LINE_CHANNEL_SECRET),
        'test_connection' => [
            'method' => $_SERVER['REQUEST_METHOD'],
            'headers' => getallheaders(),
            'raw_post' => file_get_contents('php://input')
        ]
    ], JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}