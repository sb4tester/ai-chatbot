<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../../private/src/Config.php';
require_once __DIR__ . '/../../private/src/DatabaseHandler.php';
require_once __DIR__ . '/../../private/src/UserHandler.php';
require_once __DIR__ . '/../../private/src/TagHandler.php';
//require_once __DIR__ . '/../../private/src/FortuneHandler.php';

class OpenAIHandler {
    private $apiKey;
    private $model;
    private $cache;
    private $userHandler;
    private $tagHandler;
    private const MAX_HISTORY = 5;

    public function __construct() {
        $this->apiKey = Config::OPENAI_API_KEY;
        $this->model = Config::OPENAI_MODEL;
        $this->cache = new CacheHandler();
        $this->userHandler = new UserHandler();
        $this->tagHandler = new TagHandler();
    }
public function getFortunePrediction($type, $userId, $params = []) {
   $cacheKey = "fortune_{$type}_{$userId}_" . date('Y-m-d');
   if ($cached = $this->cache->get($cacheKey)) {
       return $cached;
   }

   $user = $this->userHandler->getUserProfile($userId);
   $context = $this->buildFortuneContext($user, $type, $params);
   
   // สร้างคำถามตามประเภทดวง
   $prompt = $this->buildFortunePrompt($type, $user);
   
   $messages = [
       ['role' => 'system', 'content' => Config::MIRA_PROMPT],
       ['role' => 'system', 'content' => $context],
       ['role' => 'user', 'content' => $prompt]
   ];

   $response = $this->callOpenAI($messages);
   $this->savePredictionHistory($userId, $type, $response, $params);
   $this->tagHandler->analyzeConversation($userId, $response);

   $this->cache->set($cacheKey, $response);
   return $response;
}

private function buildFortunePrompt($type, $user) {
    switch($type) {
        case 'daily':
            return "ช่วยทำนายดวงประจำวันให้คุณ{$user['nickname']} วันที่ " . date('d/m/Y') . " โดยวิเคราะห์ดังนี้:\n" .
                   "1. ดวงโดยรวม\n2. ด้านความรัก\n3. ด้านการงาน\n4. ด้านการเงิน\n5. สุขภาพ\n" .
                   "พร้อมทั้งบอกเลขนำโชค และ สีมงคลประจำวัน";

        case 'love':
            return "ช่วยทำนายดวงความรักให้คุณ{$user['nickname']} โดยวิเคราะห์:\n" .
                   "1. สถานะความรักปัจจุบัน\n2. แนวโน้มความรัก\n3. คำแนะนำเรื่องความรัก\n" .
                   "4. ช่วงเวลาที่ดีสำหรับความรัก";

        case 'career':
            return "ช่วยทำนายดวงการงานให้คุณ{$user['nickname']} โดยวิเคราะห์:\n" .
                   "1. สถานะการงานปัจจุบัน\n2. โอกาสความก้าวหน้า\n3. อุปสรรคที่ต้องระวัง\n" .
                   "4. คำแนะนำในการทำงาน";

        case 'finance':
            return "ช่วยทำนายดวงการเงินให้คุณ{$user['nickname']} โดยวิเคราะห์:\n" .
                   "1. สถานะการเงินปัจจุบัน\n2. แนวโน้มรายรับ-รายจ่าย\n3. โชคลาภ\n" .
                   "4. คำแนะนำการเงิน";

        case 'zodiac':
            $zodiac = $user['zodiac'] ?? 'ไม่ระบุ';
            return "ช่วยทำนายดวงชะตาของ{$zodiac}ให้คุณ{$user['nickname']} โดยวิเคราะห์:\n" .
                   "1. ลักษณะนิสัยและจุดเด่น\n2. ดวงโดยรวม\n3. ความรัก\n4. การงาน\n" .
                   "5. การเงิน\n6. สุขภาพ";

        default:
            return "ช่วยทำนายดวงชะตาให้คุณ{$user['nickname']}";
    }
}

    private function buildFortuneContext($user, $type, $params) {
    $context = "คุณกำลังเป็นนักพยากรณ์ที่ชื่อมิระ กำลังทำนายดวงให้คุณ{$user['nickname']}\n";
    
    // เพิ่มข้อมูลพื้นฐาน
    $context .= "ข้อมูลผู้รับคำทำนาย:\n";
    if ($user['birth_date']) {
        $context .= "- วันเกิด: {$user['birth_date']}\n";
    }
    if ($user['zodiac']) {
        $context .= "- ราศี: {$user['zodiac']}\n";
    }

    // เพิ่มประวัติการทำนายล่าสุด
    $history = $this->getPredictionHistory($user['id'], $type);
    if ($history) {
        $context .= "\nการทำนายครั้งก่อน (" . $history['created_at'] . "):\n";
        $context .= $history['fortune_result'] . "\n";
    }

    // เพิ่ม tags ที่เกี่ยวข้อง
    $userTags = $this->tagHandler->getUserTags($user['id']);
    if (!empty($userTags)) {
        $context .= "\nข้อมูลเพิ่มเติม:\n";
        foreach ($userTags as $tag) {
            $context .= "- {$tag['tag_key']}: {$tag['tag_value']}\n";
        }
    }

    return $context;
}

    public function getResponse($message, $context, $sessionId) {
        $messages = [
            ['role' => 'system', 'content' => Config::MIRA_PROMPT],
            ['role' => 'system', 'content' => $context]
        ];

        // Add conversation history
        $history = $this->getConversationHistory($sessionId);
        $messages = array_merge($messages, $history);
        $messages[] = ['role' => 'user', 'content' => $message];

        $response = $this->callOpenAI($messages);
        $this->saveConversationHistory($sessionId, $message, $response);

        return $response;
    }

    private function callOpenAI($messages) {
        $data = [
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => 0.7,
            'max_tokens' => 1000
        ];

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception('OpenAI API Error: ' . $response);
        }

        $result = json_decode($response, true);
        return $result['choices'][0]['message']['content'] ?? 'ขออภัย ไม่สามารถประมวลผลได้';
    }

    private function getConversationHistory($sessionId) {
        $cacheKey = "chat_history_{$sessionId}";
        return $this->cache->get($cacheKey) ?? [];
    }

    private function saveConversationHistory($sessionId, $message, $response) {
        $cacheKey = "chat_history_{$sessionId}";
        $history = $this->getConversationHistory($sessionId);
        
        array_push($history, 
            ['role' => 'user', 'content' => $message],
            ['role' => 'assistant', 'content' => $response]
        );

        while (count($history) > self::MAX_HISTORY * 2) {
            array_shift($history);
        }

        $this->cache->set($cacheKey, $history);
    }

    private function savePredictionHistory($userId, $type, $prediction, $params = []) {
    try {
        $db = DatabaseHandler::getInstance();
        $data = [
            'user_id' => $userId,
            'fortune_type' => $type,
            'fortune_result' => $prediction,
            'additional_info' => json_encode([
                'params' => $params,
                'timestamp' => time()
            ])
        ];
        
        $db->query(
            "INSERT INTO fortune_history 
            (user_id, fortune_type, fortune_result, additional_info) 
            VALUES (?, ?, ?, ?)",
            array_values($data)
        );

    } catch (Exception $e) {
        error_log("Error saving prediction: " . $e->getMessage());
    }
}

    private function getPredictionHistory($userId, $type) {
        try {
            $db = DatabaseHandler::getInstance();
            return $db->query(
                "SELECT * FROM fortune_history 
                WHERE user_id = ? AND fortune_type = ? 
                ORDER BY created_at DESC LIMIT 1",
                [$userId, $type]
            )->fetch();
        } catch (Exception $e) {
            error_log("Error getting prediction history: " . $e->getMessage());
            return null;
        }
    }
}