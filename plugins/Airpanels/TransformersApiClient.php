<?php

class TransformersApiClient
{
    private $apiKey;
    private $apiUrl;

    public function __construct($apiKey, $apiUrl)
    {
        $this->apiKey = $apiKey;
        $this->apiUrl = rtrim($apiUrl, '/');
    }

    private function sendRequest($method, $endpoint, $data = null, $stream = false)
    {
        $url = $this->apiUrl . $endpoint;

        $headers = [
            "Content-Type: application/json",
            "Authorization: Bearer {$this->apiKey}",
        ];

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => !$stream,
        ];

        if ($data) {
            $options[CURLOPT_POSTFIELDS] = json_encode($data);
        }

        $ch = curl_init();
        curl_setopt_array($ch, $options);

        if ($stream) {
            if (!ob_get_level()) {
                ob_start();
            }
            curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) {
                echo $data;
                ob_flush();
                flush();
                return strlen($data);
            });
            curl_exec($ch);
            if (curl_errno($ch)) {
                throw new Exception(curl_error($ch));
            }
            curl_close($ch);
        } else {
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return ['response' => json_decode($response, true), 'httpCode' => $httpCode];
        }
    }


    public function getStatus()
    {
        return $this->sendRequest('GET', '/status');
    }

    public function splitSurvey($surveyContent)
    {
        $data = ['content' => $surveyContent];
        return $this->sendRequest('POST', '/split', $data);
    }

    public function convertToLimesurvey($question)
    {
        $data = ['question' => $question];
        return $this->sendRequest('POST', '/convert', $data);
    }

    public function createSurvey($surveyContent, $language)
    {
        $data = ['content' => $surveyContent, 'language'=> $language];
        return $this->sendRequest('POST', '/create', $data, true);
    }

    public function unstructuredToLimesurvey($unstructuredContent)
    {
        $data = ['content' => $unstructuredContent];
        return $this->sendRequest('POST', '/unstructured', $data);
    }
}