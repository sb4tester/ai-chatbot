<?php
// /home/bot.dailymu.com/public_html/api/test-dialogflow.php

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');

require_once __DIR__ . '/../../private/src/Config.php';
require_once __DIR__ . '/../../private/vendor/autoload.php';

use Google\Cloud\Dialogflow\V2\SessionsClient;
use Google\Cloud\Dialogflow\V2\TextInput;
use Google\Cloud\Dialogflow\V2\QueryInput;

try {
    // 1. Basic file check
    $credPath = Config::GOOGLE_APPLICATION_CREDENTIALS;
    $steps = [
        'credentials_check' => [
            'file_exists' => file_exists($credPath),
            'is_readable' => is_readable($credPath),
            'path' => $credPath
        ]
    ];

    // 2. Set environment variable
    putenv("GOOGLE_APPLICATION_CREDENTIALS=$credPath");
    $steps['environment'] = [
        'cred_path_set' => getenv('GOOGLE_APPLICATION_CREDENTIALS')
    ];

    // 3. Initialize Dialogflow client
    $sessionsClient = new SessionsClient();
    $steps['client_init'] = [
        'client_created' => ($sessionsClient instanceof SessionsClient)
    ];

    // 4. Prepare session path
    $projectId = Config::DIALOGFLOW_PROJECT_ID;
    $sessionId = uniqid();
    $sessionPath = $sessionsClient->sessionName($projectId, $sessionId);
    $steps['session'] = [
        'project_id' => $projectId,
        'session_id' => $sessionId,
        'session_path' => $sessionPath
    ];

    // 5. Create test request
    $textInput = new TextInput();
    $textInput->setText('สวัสดี');
    $textInput->setLanguageCode('th-TH');

    $queryInput = new QueryInput();
    $queryInput->setText($textInput);

    // 6. Send test request
    $response = $sessionsClient->detectIntent($sessionPath, $queryInput);
    $queryResult = $response->getQueryResult();

    $steps['test_request'] = [
        'intent_detected' => $queryResult->getIntent()->getDisplayName(),
        'fulfillment_text' => $queryResult->getFulfillmentText(),
        'confidence' => $queryResult->getIntentDetectionConfidence()
    ];

    // Close the client
    $sessionsClient->close();

    // Return success response
    echo json_encode([
        'success' => true,
        'message' => 'Dialogflow connection test completed successfully',
        'steps' => $steps
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => [
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ],
        'steps_completed' => $steps ?? []
    ], JSON_PRETTY_PRINT);
}