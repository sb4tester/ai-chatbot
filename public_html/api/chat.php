<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

error_log("Received request: " . file_get_contents('php://input'));

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../../private/src/Config.php';
require_once __DIR__ . '/../../private/vendor/autoload.php';

use Google\Cloud\Dialogflow\V2\SessionsClient;
use Google\Cloud\Dialogflow\V2\TextInput;
use Google\Cloud\Dialogflow\V2\QueryInput;

try {
    // Check request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Method not allowed', 405);
    }

    // Get request body
    $input = json_decode(file_get_contents('php://input'), true);
    if (!isset($input['message'])) {
        throw new Exception('Message is required', 400);
    }

    // Set credentials
    putenv("GOOGLE_APPLICATION_CREDENTIALS=" . Config::GOOGLE_APPLICATION_CREDENTIALS);

    // Create session client
    $sessionsClient = new SessionsClient();
    $sessionId = isset($input['session_id']) ? $input['session_id'] : uniqid();
    $projectId = Config::DIALOGFLOW_PROJECT_ID;
    $sessionPath = $sessionsClient->sessionName($projectId, $sessionId);

    // Create text input
    $textInput = new TextInput();
    $textInput->setText($input['message']);
    $textInput->setLanguageCode('th-TH');

    // Create query input
    $queryInput = new QueryInput();
    $queryInput->setText($textInput);

    // Get response from Dialogflow
    $response = $sessionsClient->detectIntent($sessionPath, $queryInput);
    $queryResult = $response->getQueryResult();

    // Prepare response
    $result = [
        'success' => true,
        'data' => [
            'session_id' => $sessionId,
            'text' => $queryResult->getFulfillmentText(),
            'intent' => $queryResult->getIntent()->getDisplayName(),
            'confidence' => $queryResult->getIntentDetectionConfidence()
        ]
    ];

    // Close client
    $sessionsClient->close();

    // Send response
    echo json_encode($result, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code($e->getCode() >= 400 ? $e->getCode() : 500);
    echo json_encode([
        'success' => false,
        'error' => [
            'message' => $e->getMessage(),
            'code' => $e->getCode()
        ]
    ], JSON_UNESCAPED_UNICODE);
}