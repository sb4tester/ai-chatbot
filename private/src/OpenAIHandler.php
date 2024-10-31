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
   
   $messages = [
       ['role' => 'system', 'content' => Config::MIRA_PROMPT],
       ['role' => 'system', 'content' => $context]
   ];

   switch ($type) {
       case 'daily':
           $messages[] = ['role' => 'user', 'content' => "ช่วยทำนายดวงประจำวันให้หน่อย"];
           break;
       case 'zodiac':
           $zodiac = $params['zodiac'] ?? $user['zodiac'];
           $messages[] = ['role' => 'user', 'content' => "ช่วยทำนายดวงราศี{$zodiac}ให้หน่อย"];
           break;
   }

   $response = $this->callOpenAI($messages);
   $this->savePredictionHistory($userId, $type, $response, $params);
   $this->tagHandler->analyzeConversation($userId, $response);

   $this->cache->set($cacheKey, $response);
   return $response;
}
/*
    public function getFortunePrediction($type, $userId, $params = []) {
        $user = $this->userHandler->getUserProfile($userId);
        $context = $this->buildFortuneContext($user, $type, $params);
        
        $messages = [
            ['role' => 'system', 'content' => Config::MIRA_PROMPT],
            ['role' => 'system', 'content' => $context]
        ];

        // Add specific fortune request based on type
        switch ($type) {
            case 'daily':
                $messages[] = ['role' => 'user', 'content' => "ช่วยทำนายดวงประจำวันให้หน่อย"];
                break;
            case 'zodiac':
                $zodiac = $params['zodiac'] ?? $user['zodiac'];
                $messages[] = ['role' => 'user', 'content' => "ช่วยทำนายดวงราศี{$zodiac}ให้หน่อย"];
                break;
        }

        $response = $this->callOpenAI($messages);
        $this->savePredictionHistory($userId, $type, $response, $params);
        $this->tagHandler->analyzeConversation($userId, $response);

        return $response;
    }
*/
    private function buildFortuneContext($user, $type, $params) {
        $context = "คุณกำลังเป็นนักพยากรณ์ที่ชื่อมิระ กำลังทำนายดวงให้";
        
        if ($user['nickname']) {
            $context .= "คุณ{$user['nickname']} ";
        }

        // Add relevant user info
        if ($user['birth_date']) {
            $context .= "\nวันเกิด: {$user['birth_date']}";
        }
        if ($user['zodiac']) {
            $context .= "\nราศี: {$user['zodiac']}";
        }

        // Add prediction history
        $history = $this->getPredictionHistory($user['id'], $type);
        if ($history) {
            $context .= "\nการทำนายครั้งก่อน: {$history['prediction']}";
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

    private function savePredictionHistory($userId, $type, $prediction, $params) {
        try {
            $db = DatabaseHandler::getInstance();
            $db->query(
                "INSERT INTO fortune_history 
                (user_id, fortune_type, fortune_result, additional_info) 
                VALUES (?, ?, ?, ?)",
                [
                    $userId,
                    $type,
                    $prediction,
                    json_encode([
                        'params' => $params,
                        'timestamp' => time()
                    ])
                ]
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