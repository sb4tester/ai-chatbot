<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../../private/src/Config.php';
require_once __DIR__ . '/../../private/src/FortuneHandler.php';
require_once __DIR__ . '/../../private/src/UserHandler.php';
require_once __DIR__ . '/../../private/src/OpenAIHandler.php';

use Google\Cloud\Dialogflow\V2\SessionsClient;
use Google\Cloud\Dialogflow\V2\TextInput;
use Google\Cloud\Dialogflow\V2\QueryInput;
use Google\Cloud\Dialogflow\V2\QueryParameters;


class DialogflowHandler {
    private $projectId;
    private $sessionClient;
    private $openai;
    private $cache;
    private $tagHandler;
    private $userHandler;
    private const CONFIDENCE_THRESHOLD = 0.7;
    private const CACHE_TTL = 300; // 5 minutes
    private $platform; // เพิ่มตัวแปรเก็บ platform

    public function __construct() {
        putenv('GOOGLE_APPLICATION_CREDENTIALS=' . Config::GOOGLE_APPLICATION_CREDENTIALS);
        $this->projectId = Config::DIALOGFLOW_PROJECT_ID;
        $this->sessionClient = new SessionsClient();
        $this->openai = new OpenAIHandler();
        $this->cache = new CacheHandler();
        $this->tagHandler = new TagHandler();
        $this->userHandler = new UserHandler();
    }

    public function detectIntent($text, $sessionId, $contexts = []) {
        try {
            // Call Dialogflow
            $response = $this->callDialogflow($text, $sessionId, $contexts);
            $queryResult = $response->getQueryResult();
            
            $intent = $queryResult->getIntent()->getDisplayName();
            $confidence = $queryResult->getIntentDetectionConfidence();
            $parameters = $this->extractParameters($queryResult);

            // Process intent based on confidence
            if ($confidence < self::CONFIDENCE_THRESHOLD) {
                return $this->handleOpenAIFallback($text, $sessionId, $contexts);
            } else {
                return $this->handleIntent($intent, $parameters, $sessionId, $queryResult);
            }

        } catch (Exception $e) {
            error_log("Error in DialogflowHandler: " . $e->getMessage());
            throw $e;
        }
    }

    private function handleIntent($intent, $parameters, $sessionId, $queryResult) {
    // เพิ่ม debug log
    error_log("Intent: " . $intent);
    error_log("Parameters: " . json_encode($parameters));
    error_log("QueryResult: " . json_encode($queryResult));
        
    $user = $this->userHandler->getUserProfile($sessionId);
    
    // ตรวจสอบ queryResult ก่อนใช้งาน
    if (!$queryResult) {
        error_log("QueryResult is null");
        return [
            'text' => "ขออภัยค่ะ ระบบขัดข้อง กรุณาลองใหม่อีกครั้งนะคะ",
            'intent' => 'error'
        ];
    }
    
    switch($intent) {
            case 'UserInfo.Name':
                $name = $parameters['fullname'] ?? '';
                $this->userHandler->updateUser($sessionId, ['nickname' => $name]);
                return [
                    'text' => "ยินดีที่ได้รู้จักคุณ {$name} ค่ะ 😊\nกรุณาบอกวัน/เดือน/ปีเกิด พ.ศ. (เช่น 1/1/2530) เพื่อการทำนายที่แม่นยำด้วยค่ะ",
                    'intent' => $intent
                ];
                break;

            case 'UserInfo.BirthDate':
            $birthdate = $parameters['birthdate'] ?? '';
            if ($birthdate) {
                error_log("Updating birthdate: " . $birthdate);
                $updated = $this->userHandler->updateUser($sessionId, ['birth_date' => $birthdate]);
                error_log("Update result: " . ($updated ? "success" : "failed"));

                // เพิ่มส่วนนี้สำหรับแสดงปุ่มเลือกดูดวง
                return [
                    'text' => "ขอบคุณที่บอกวันเกิดค่ะ 🌟 มิราสามารถดูดวงให้คุณได้หลายด้านค่ะ\n\n" .
                             "พิมพ์ เลข 1 ดวงประจำวัน 📅\n" .
                             "พิมพ์ เลข 2 ดวงความรัก ❤️\n" .
                             "พิมพ์ เลข 3 ดวงการงาน 💼\n" .
                             "พิมพ์ เลข 4 ดวงการเงิน 💰\n" .
                             "พิมพ์ เลข 5 ดวงตามราศี ⭐\n\n" .
                             "เลือกดูดวงด้านไหนก่อนดีคะ?",
                    'intent' => $intent,
                    'buttons' => [
                        [
                            'type' => 'fortune',
                            'options' => [
                                ['id' => 'daily', 'label' => '📅 ดวงประจำวัน'],
                                ['id' => 'love', 'label' => '❤️ ดวงความรัก'],
                                ['id' => 'career', 'label' => '💼 ดวงการงาน'],
                                ['id' => 'finance', 'label' => '💰 ดวงการเงิน'],
                                ['id' => 'zodiac', 'label' => '⭐ ดวงตามราศี']
                            ]
                        ]
                    ]
                ];
            }
            break;
            case 'Fortune.Daily':
            case 'Fortune.Love':
            case 'Fortune.Career':
            case 'Fortune.Finance':
            case 'Fortune.Zodiac':
                $fortuneType = strtolower(explode('.', $intent)[1]);
                return [
                    'text' => "มิรากำลังพิจารณาดวงชะตาของคุณ 🔮\nกรุณารอสักครู่นะคะ...",
                    'intent' => $intent,
                    'followed_by' => $this->openai->getFortunePrediction($fortuneType, $sessionId)
                ];

            default:
                return [
                    'text' => $queryResult->getFulfillmentText() ?? "ขออภัยค่ะ มิราไม่เข้าใจ กรุณาลองใหม่อีกครั้งนะคะ",
                    'intent' => $intent,
                    'confidence' => $queryResult->getIntentDetectionConfidence(),
                    'source' => 'dialogflow'
                ];
        }
}


    private function handleOpenAIFallback($text, $sessionId, $contexts) {
        $user = $this->userHandler->getUserProfile($sessionId);
        $userTags = $this->tagHandler->generateUserProfile($sessionId);
        
        // Build context for OpenAI
        $context = "ข้อมูลผู้ใช้:\n";
        if ($user['nickname']) {
            $context .= "ชื่อ: {$user['nickname']}\n";
        }
        if ($user['zodiac']) {
            $context .= "ราศี: {$user['zodiac']}\n";
        }
        if (!empty($userTags)) {
            $context .= "ข้อมูลเพิ่มเติม:\n";
            foreach ($userTags as $category => $tags) {
                foreach ($tags as $key => $data) {
                    $context .= "- {$key}: {$data['value']}\n";
                }
            }
        }

        // Get response from OpenAI
        $response = $this->openai->getResponse($text, $context, $sessionId);
        
        // Analyze conversation for new tags
        $this->tagHandler->analyzeConversation($sessionId, $text);
        
        return [
            'text' => $response,
            'intent' => 'OpenAI_Response',
            'confidence' => 1.0,
            'source' => 'openai',
            'contexts' => $contexts
        ];
    }


private function callDialogflow($text, $sessionId, $contexts = []) {
    $cacheKey = "dialogflow_{$sessionId}_" . md5($text);
    if ($cached = $this->cache->get($cacheKey)) {
        return $cached;
    }
    
    $sessionPath = $this->sessionClient->sessionName($this->projectId, $sessionId);
    $textInput = new TextInput();
    $textInput->setText($text);
    $textInput->setLanguageCode('th-TH');

    $queryInput = new QueryInput();
    $queryInput->setText($textInput);

    if (!empty($contexts)) {
        $queryParams = new QueryParameters();
        $queryParams->setContexts($contexts);
        $response = $this->sessionClient->detectIntent($sessionPath, $queryInput, ['queryParams' => $queryParams]);
    } else {
        $response = $this->sessionClient->detectIntent($sessionPath, $queryInput);
    }

    $this->cache->set($cacheKey, $response, self::CACHE_TTL);
    return $response;
}

    private function extractParameters($queryResult) {
        $parameters = [];
        $fields = $queryResult->getParameters()->getFields();
        foreach ($fields as $key => $value) {
            $parameters[$key] = $value->getStringValue();
        }
        return $parameters;
    }


}