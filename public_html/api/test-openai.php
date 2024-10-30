<?php
// /home/bot.dailymu.com/public_html/api/test-openai.php
header('Content-Type: application/json');

require_once __DIR__ . '/../../private/src/OpenAIHandler.php';

try {
    $openai = new OpenAIHandler();
    $result = $openai->testConnection();
    
    echo json_encode($result, JSON_PRETTY_PRINT);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}