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
/*
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
*/
    public function detectIntent($text, $sessionId, $contexts = []) {
    try {
        $user = $this->userHandler->getUserProfile($sessionId);
        error_log("User data: " . json_encode($user));

        // ถ้าเป็นตัวเลข 1-5 และมีข้อมูลครบ
        if (preg_match('/^[1-5]$/', $text) && 
            !empty($user['nickname']) && 
            !empty($user['birth_date'])) {
            
            error_log("Detected fortune number with complete user data");
            return [
                'text' => "มิรากำลังพิจารณาดวงชะตาของคุณ {$user['nickname']} 🔮\nกรุณารอสักครู่นะคะ...",
                'intent' => 'Fortune.Select',
                'followed_by' => $this->openai->getFortunePrediction(
                    $this->getFortuneTypeFromNumber($text),
                    $user['id']
                )
            ];
        }

        // ถ้าเป็นตัวเลข 1-5 แต่ข้อมูลไม่ครบ
        if (preg_match('/^[1-5]$/', $text)) {
            error_log("Detected fortune number but incomplete user data");
            return [
                'text' => "ขออภัยค่ะ มิราขอทราบข้อมูลเพิ่มเติมก่อนนะคะ เพื่อการทำนายที่แม่นยำ" . 
                         (empty($user['nickname']) ? "\nรบกวนขอทราบชื่อด้วยค่ะ" : "") .
                         (empty($user['birth_date']) ? "\nรบกวนขอทราบวันเดือนปีเกิด (พ.ศ.) ด้วยค่ะ เช่น 1/1/2530" : ""),
                'intent' => 'UserInfo.Required'
            ];
        }

        // ถ้าไม่ใช่ตัวเลข ใช้ Dialogflow ตามปกติ
        $response = $this->callDialogflow($text, $sessionId, $contexts);
        $queryResult = $response->getQueryResult();
        
        $intent = $queryResult->getIntent()->getDisplayName();
        $confidence = $queryResult->getIntentDetectionConfidence();
        $parameters = $this->extractParameters($queryResult);

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

/*
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
                $this->userHandler->updateUser($user['id'], ['nickname' => $name]);
                return [
                    'text' => "ยินดีที่ได้รู้จักคุณ {$name} ค่ะ 😊\nกรุณาบอกวัน/เดือน/ปีเกิด พ.ศ. (เช่น 1/1/2530) เพื่อการทำนายที่แม่นยำด้วยค่ะ",
                    'intent' => $intent
                ];
                break;

            case 'UserInfo.BirthDate':
            $birthdate = $parameters['birthdate'] ?? '';
            if ($birthdate) {
                error_log("Updating birthdate: " . $birthdate);
                $updated = $this->userHandler->updateUser($user['id'], ['birth_date' => $birthdate]);
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
                    'followed_by' => $this->openai->getFortunePrediction($fortuneType, $user['id'])
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
*/

private function handleIntent($intent, $parameters, $sessionId, $queryResult) {
    error_log("Intent: " . $intent);
    error_log("Parameters: " . json_encode($parameters));
    error_log("QueryResult: " . json_encode($queryResult));
        
    $user = $this->userHandler->getUserProfile($sessionId);
    error_log("User data: " . json_encode($user));
    
    // ตรวจสอบ queryResult ก่อนใช้งาน
    if (!$queryResult) {
        error_log("QueryResult is null");
        return [
            'text' => "ขออภัยค่ะ ระบบขัดข้อง กรุณาลองใหม่อีกครั้งนะคะ",
            'intent' => 'error'
        ];
    }
    
    // เช็คว่ามีข้อมูล user ครบไหม
    $hasUserInfo = !empty($user['nickname']) && !empty($user['birth_date']);
    
    switch($intent) {
        case 'Default Welcome Intent':
            if ($hasUserInfo) {
                return [
                    'text' => "สวัสดีค่ะคุณ {$user['nickname']} 😊\n" .
                             "วันนี้อยากดูดวงด้านไหนดีคะ?\n\n" .
                             "พิมพ์ เลข 1 ดวงประจำวัน 📅\n" .
                             "พิมพ์ เลข 2 ดวงความรัก ❤️\n" .
                             "พิมพ์ เลข 3 ดวงการงาน 💼\n" .
                             "พิมพ์ เลข 4 ดวงการเงิน 💰\n" .
                             "พิมพ์ เลข 5 ดวงตามราศี ⭐",
                    'intent' => $intent
                ];
            }

            if ($this->platform === 'line') {
                // ถ้าเป็น LINE และยังไม่มีวันเกิด
                if (empty($user['birth_date'])) {
                    return [
                        'text' => "สวัสดีค่ะคุณ {$user['nickname']} กรุณาบอกวัน/เดือน/ปีเกิด พ.ศ. (เช่น 1/1/2530) เพื่อการทำนายที่แม่นยำด้วยค่ะ",
                        'intent' => $intent
                    ];
                }                
            }

            return [
                'text' => "สวัสดีค่ะ มิรานักพยากรณ์ยินดีให้คำปรึกษาค่ะ 😊\nรบกวนขอทราบชื่อด้วยค่ะ",
                'intent' => $intent
            ];
            break;

        case 'UserInfo.Name':
            if ($hasUserInfo) {
                return [
                    'text' => "สวัสดีค่ะคุณ {$user['nickname']} 😊\n" .
                             "วันนี้อยากดูดวงด้านไหนดีคะ?\n\n" .
                             "พิมพ์ เลข 1 ดวงประจำวัน 📅\n" .
                             "พิมพ์ เลข 2 ดวงความรัก ❤️\n" .
                             "พิมพ์ เลข 3 ดวงการงาน 💼\n" .
                             "พิมพ์ เลข 4 ดวงการเงิน 💰\n" .
                             "พิมพ์ เลข 5 ดวงตามราศี ⭐",
                    'intent' => $intent
                ];
            }
            if ($this->platform === 'line') {
                return [
                    'text' => "กรุณาบอกวัน/เดือน/ปีเกิด พ.ศ. (เช่น 1/1/2530) เพื่อการทำนายที่แม่นยำด้วยค่ะ",
                    'intent' => $intent
                ];
            }

            $name = $parameters['fullname'] ?? '';
            $this->userHandler->updateUser($user['id'], ['nickname' => $name]);
            return [
                'text' => "ยินดีที่ได้รู้จักคุณ {$name} ค่ะ 😊\n" .
                         "กรุณาบอกวัน/เดือน/ปีเกิด พ.ศ. (เช่น 1/1/2530) เพื่อการทำนายที่แม่นยำด้วยค่ะ",
                'intent' => $intent
            ];

        case 'UserInfo.BirthDate':
            if ($hasUserInfo) {
                return [
                    'text' => "ขอบคุณค่ะ มิราขอทำนายดวงให้คุณนะคะ\n\n" .
                             "พิมพ์ เลข 1 ดวงประจำวัน 📅\n" .
                             "พิมพ์ เลข 2 ดวงความรัก ❤️\n" .
                             "พิมพ์ เลข 3 ดวงการงาน 💼\n" .
                             "พิมพ์ เลข 4 ดวงการเงิน 💰\n" .
                             "พิมพ์ เลข 5 ดวงตามราศี ⭐",
                    'intent' => $intent
                ];
            }
            $birthdate = $parameters['birthdate'] ?? '';
            if ($birthdate) {
                error_log("Updating birthdate: " . $birthdate);
                $updated = $this->userHandler->updateUser($user['id'], ['birth_date' => $birthdate]);
                error_log("Update result: " . ($updated ? "success" : "failed"));

                return [
                    'text' => "ขอบคุณที่บอกวันเกิดค่ะ 🌟 มิราสามารถดูดวงให้คุณได้หลายด้านค่ะ\n\n" .
                             "พิมพ์ เลข 1 ดวงประจำวัน 📅\n" .
                             "พิมพ์ เลข 2 ดวงความรัก ❤️\n" .
                             "พิมพ์ เลข 3 ดวงการงาน 💼\n" .
                             "พิมพ์ เลข 4 ดวงการเงิน 💰\n" .
                             "พิมพ์ เลข 5 ดวงตามราศี ⭐",
                    'intent' => $intent
                ];
            }
            break;

    case 'Fortune.Select':
        error_log("Handling Fortune.Select");  // เพิ่ม log
        error_log("Parameters: " . json_encode($parameters));

        if (!empty($user['nickname']) && !empty($user['birth_date'])) {
            $number = $parameters['fortune_type'] ?? $parameters['number'] ?? null;  // เช็คทั้งสองกรณี
            error_log("Fortune number: " . $number);  // เพิ่ม log
            
            if (is_float($number)) {
                $number = (int)$number;
            }
            
            $fortuneType = $this->getFortuneTypeFromNumber($number);
            error_log("Fortune type: " . $fortuneType);  // เพิ่ม log

            if (!$fortuneType) {
                return [
                    'text' => "ขออภัยค่ะ กรุณาเลือกตัวเลข 1-5 เพื่อดูดวงค่ะ",
                    'intent' => $intent
                ];
            }

            try {
                $prediction = $this->openai->getFortunePrediction($fortuneType, $user['id']);
                error_log("Got prediction: " . ($prediction ? 'yes' : 'no'));  // เพิ่ม log
                
                return [
                    'text' => "มิรากำลังพิจารณาดวงชะตาของคุณ {$user['nickname']} 🔮\nกรุณารอสักครู่นะคะ...",
                    'intent' => $intent,
                    'followed_by' => $prediction
                ];
            } catch (Exception $e) {
                error_log("Error getting fortune prediction: " . $e->getMessage());
                return [
                    'text' => "ขออภัยค่ะ มีข้อผิดพลาดในการทำนาย กรุณาลองใหม่อีกครั้งนะคะ",
                    'intent' => $intent
                ];
            }
        }
    break;
/*
        case 'Fortune.Select':
            if (!$hasUserInfo) {
                return [
                    'text' => "ขออภัยค่ะ รบกวนแนะนำตัวก่อนนะคะ",
                    'intent' => $intent
                ];
            }

            $number = $parameters['fortune_type'] ?? null;
            if (is_float($number)) {
                $number = (int)$number;
            }
            $fortuneType = $this->getFortuneTypeFromNumber($number);
            
            if (!$fortuneType) {
                return [
                    'text' => "ขออภัยค่ะ กรุณาเลือกตัวเลข 1-5 เพื่อดูดวงค่ะ",
                    'intent' => $intent
                ];
            }

            try {
                $prediction = $this->openai->getFortunePrediction($fortuneType, $user['id']);
                return [
                    'text' => "มิรากำลังพิจารณาดวงชะตาของคุณ {$user['nickname']} 🔮\nกรุณารอสักครู่นะคะ...",
                    'intent' => $intent,
                    'followed_by' => $prediction
                ];
            } catch (Exception $e) {
                error_log("Error getting fortune prediction: " . $e->getMessage());
                return [
                    'text' => "ขออภัยค่ะ มีข้อผิดพลาดในการทำนาย กรุณาลองใหม่อีกครั้งนะคะ",
                    'intent' => $intent
                ];
            }
            */

        case 'Fortune.Daily':
        case 'Fortune.Love':
        case 'Fortune.Career':
        case 'Fortune.Finance':
        case 'Fortune.Zodiac':
            $fortuneType = strtolower(explode('.', $intent)[1]);
            try {
                $prediction = $this->openai->getFortunePrediction($fortuneType, $user['id']);
                return [
                    'text' => "มิรากำลังพิจารณาดวงชะตาของคุณ {$user['nickname']} 🔮\nกรุณารอสักครู่นะคะ...",
                    'intent' => $intent,
                    'followed_by' => $prediction
                ];
            } catch (Exception $e) {
                error_log("Error in fortune prediction: " . $e->getMessage());
                return [
                    'text' => "ขออภัยค่ะ มีข้อผิดพลาดในการทำนาย กรุณาลองใหม่อีกครั้งนะคะ",
                    'intent' => $intent
                ];
            }

        default:
            return [
                'text' => $queryResult->getFulfillmentText() ?? "ขออภัยค่ะ มิราไม่เข้าใจ กรุณาลองใหม่อีกครั้งนะคะ",
                'intent' => $intent,
                'confidence' => $queryResult->getIntentDetectionConfidence(),
                'source' => 'dialogflow'
            ];
    }
}

private function getFortuneTypeFromNumber($number) {
    if (!$number) {
        error_log("No number provided");
        return null;
    }
    error_log("Converting number to fortune type: " . $number);    
    $types = [
        '1' => 'daily',
        '2' => 'love',
        '3' => 'Career',
        '4' => 'finance',
        '5' => 'zodiac'
    ];
    $result = $types[(string)$number] ?? null;
    error_log("Converted to type: " . $result);
    return $types[(string)$number] ?? null;
}


    private function handleOpenAIFallback($text, $sessionId, $contexts) {
        $user = $this->userHandler->getUserProfile($sessionId);
        $userTags = $this->tagHandler->generateUserProfile($user['id']);
        
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
        $response = $this->openai->getResponse($text, $context, $user['id']);
        
        // Analyze conversation for new tags
        $this->tagHandler->analyzeConversation($user['id'], $text);
        
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