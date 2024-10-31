<?php
require_once __DIR__ . '/../vendor/autoload.php';
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
            // Try cache first
            $cacheKey = "intent_{$sessionId}_" . md5($text);
            $cachedResponse = $this->cache->get($cacheKey);
            if ($cachedResponse !== null) {
                return $cachedResponse;
            }

            // Call Dialogflow
            $response = $this->callDialogflow($text, $sessionId, $contexts);
            $queryResult = $response->getQueryResult();
            
            $intent = $queryResult->getIntent()->getDisplayName();
            $confidence = $queryResult->getIntentDetectionConfidence();
            $parameters = $this->extractParameters($queryResult);

            // Process intent based on confidence
            if ($confidence < self::CONFIDENCE_THRESHOLD || $intent === 'Default Fallback Intent') {
                $result = $this->handleOpenAIFallback($text, $sessionId, $contexts);
            } else {
                $result = $this->handleIntent($intent, $parameters, $sessionId, $contexts);
            }
            
            // Cache the result
            $this->cache->set($cacheKey, $result);
            
            return $result;

        } catch (Exception $e) {
            error_log("Error in DialogflowHandler: " . $e->getMessage());
            throw $e;
        }
    }

    private function handleIntent($intent, $parameters, $sessionId, $contexts = []) {
        // Process specific intents
        if (strpos($intent, 'Fortune.') === 0) {
            return $this->handleFortuneIntent($intent, $parameters, $sessionId);
        }

        // Default to Dialogflow response
        return [
            'text' => $queryResult->getFulfillmentText(),
            'intent' => $intent,
            'confidence' => $queryResult->getIntentDetectionConfidence(),
            'source' => 'dialogflow',
            'parameters' => $parameters,
            'contexts' => $this->extractContexts($queryResult)
        ];
    }

    private function handleFortuneIntent($intent, $parameters, $sessionId) {
        $user = $this->userHandler->getUserProfile($sessionId);
        
        switch($intent) {
            case 'Fortune.Daily':
                return $this->openai->getFortunePrediction('daily', $sessionId);
                
            case 'Fortune.Zodiac':
                $zodiac = $parameters['zodiac'] ?? $user['zodiac'] ?? null;
                if (!$zodiac) {
                    return [
                        'text' => "กรุณาระบุราศีที่ต้องการดูดวงค่ะ หรือบอกวันเดือนปีเกิดเพื่อให้มิระคำนวณราศีให้",
                        'intent' => $intent,
                        'source' => 'dialogflow'
                    ];
                }
                return $this->openai->getFortunePrediction('zodiac', $sessionId, ['zodiac' => $zodiac]);
                
            default:
                return $this->handleOpenAIFallback($text, $sessionId, $contexts);
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
/*
    private function callDialogflow($text, $sessionId, $contexts = []) {
        $sessionPath = $this->sessionClient->sessionName($this->projectId, $sessionId);
        
        $textInput = new TextInput();
        $textInput->setText($text);
        $textInput->setLanguageCode('th-TH');

        $queryInput = new QueryInput();
        $queryInput->setText($textInput);

        if (!empty($contexts)) {
            $queryParams = new QueryParameters();
            $queryParams->setContexts($contexts);
            return $this->sessionClient->detectIntent($sessionPath, $queryInput, ['queryParams' => $queryParams]);
        }

        return $this->sessionClient->detectIntent($sessionPath, $queryInput);
    }
    */

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

    private function extractContexts($queryResult) {
        $contexts = [];
        foreach ($queryResult->getOutputContexts() as $context) {
            $contexts[] = [
                'name' => $context->getName(),
                'lifespanCount' => $context->getLifespanCount(),
                'parameters' => $context->getParameters()
            ];
        }
        return $contexts;
    }
}