<?php
// /home/bot.dailymu.com/private/src/DialogflowHandler.php

class DialogflowHandler {
    private $projectId;
    private $sessionClient;
    private $openai;
    private $cache;
    private const CONFIDENCE_THRESHOLD = 0.7;

    public function __construct() {
        putenv('GOOGLE_APPLICATION_CREDENTIALS=' . Config::GOOGLE_APPLICATION_CREDENTIALS);
        $this->projectId = Config::DIALOGFLOW_PROJECT_ID;
        $this->sessionClient = new SessionsClient();
        $this->openai = new OpenAIHandler();
        $this->cache = new CacheHandler();
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

            // If confidence is low or it's a fallback intent, use OpenAI
            if ($confidence < self::CONFIDENCE_THRESHOLD || $intent === 'Default Fallback Intent') {
                return $this->handleOpenAIFallback($text, $sessionId, $contexts);
            }

            // Process Dialogflow response
            $result = $this->processDialogflowResponse($queryResult);
            
            // Cache the result
            $this->cache->set($cacheKey, $result);
            
            return $result;

        } catch (Exception $e) {
            error_log("Error in DialogflowHandler: " . $e->getMessage());
            throw $e;
        }
    }

    private function callDialogflow($text, $sessionId, $contexts = []) {
        $sessionPath = $this->sessionClient->sessionName($this->projectId, $sessionId);
        
        $textInput = new TextInput();
        $textInput->setText($text);
        $textInput->setLanguageCode('th-TH');

        $queryInput = new QueryInput();
        $queryInput->setText($textInput);

        // Add contexts if any
        if (!empty($contexts)) {
            $queryParams = new QueryParameters();
            $queryParams->setContexts($contexts);
            return $this->sessionClient->detectIntent($sessionPath, $queryInput, ['queryParams' => $queryParams]);
        }

        return $this->sessionClient->detectIntent($sessionPath, $queryInput);
    }

    private function handleOpenAIFallback($text, $sessionId, $contexts) {
        // Prepare context for OpenAI
        $contextText = $this->formatContextsForOpenAI($contexts);
        
        // Get response from OpenAI
        $openaiResponse = $this->openai->getResponse($text, $contextText, $sessionId);
        
        return [
            'text' => $openaiResponse,
            'intent' => 'OpenAI_Response',
            'confidence' => 1.0,
            'source' => 'openai',
            'contexts' => $contexts
        ];
    }

    private function processDialogflowResponse($queryResult) {
        return [
            'text' => $queryResult->getFulfillmentText(),
            'intent' => $queryResult->getIntent()->getDisplayName(),
            'confidence' => $queryResult->getIntentDetectionConfidence(),
            'source' => 'dialogflow',
            'parameters' => $this->extractParameters($queryResult),
            'contexts' => $this->extractContexts($queryResult)
        ];
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

    private function formatContextsForOpenAI($contexts) {
        if (empty($contexts)) return '';
        
        $contextText = "Previous context:\n";
        foreach ($contexts as $context) {
            $contextText .= "- {$context['name']}: {$context['parameters']}\n";
        }
        return $contextText;
    }
}