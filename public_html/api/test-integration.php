<?php
require_once __DIR__ . '/../../private/vendor/autoload.php';
require_once __DIR__ . '/../../private/src/Config.php';
require_once __DIR__ . '/../../private/src/DialogflowHandler.php';
require_once __DIR__ . '/../../private/src/OpenAIHandler.php';
require_once __DIR__ . '/../../private/src/UserHandler.php';

// Test cases
$testCases = [
    'ดูดวงวันนี้',
    'ดูดวงราศีมังกร',
    'ดูดวงความรัก',
    'สวัสดี มิระ',
    'ฉันเกิดวันที่ 1 มกราคม 1990'
];

$dialogflow = new DialogflowHandler();
$userId = "test_user_" . time();

foreach ($testCases as $test) {
    echo "\nTesting: $test\n";
    try {
        $result = $dialogflow->detectIntent($test, $userId);
        print_r($result);
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
    sleep(1); // Avoid rate limits
}
