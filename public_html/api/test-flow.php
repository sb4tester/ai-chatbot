<?php
// test-flow.php
require_once __DIR__ . '/../../private/vendor/autoload.php';
require_once __DIR__ . '/../../private/src/Config.php';
require_once __DIR__ . '/../../private/src/DialogflowHandler.php';
require_once __DIR__ . '/../../private/src/UserHandler.php';
require_once __DIR__ . '/../../private/src/OpenAIHandler.php';

// สร้าง test user
$userHandler = new UserHandler();
$testUser = $userHandler->getOrCreateUser('test', 'test_user_1');

// สร้าง DialogflowHandler
$dialogflow = new DialogflowHandler();

// ทดสอบ flow การสนทนา
$tests = [
    // 1. ทักทาย
    "สวัสดี",
    
    // 2. บอกชื่อ
    "วิชัย รักษาดี",
    
    // 3. บอกวันเกิด
    "1/1/2530",
    
    // 4. เลือกดูดวง
    1  // เลือกดูดวงประจำวัน
];

echo "เริ่มทดสอบ Flow:\n";
echo "----------------\n";

foreach ($tests as $index => $message) {
    echo "\nStep " . ($index + 1) . ": ส่งข้อความ - " . $message . "\n";
    try {
        $result = $dialogflow->detectIntent($message, $testUser['id']);
        echo "Response: ";
        print_r($result);
        
        // พักเล็กน้อยระหว่างคำขอ
        sleep(1);
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
}

// ตรวจสอบข้อมูลผู้ใช้
echo "\nข้อมูลผู้ใช้หลังทดสอบ:\n";
$updatedUser = $userHandler->getUserProfile($testUser['id']);
print_r($updatedUser);