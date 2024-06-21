<?php
require_once 'TransformersApiClient.php';

class Airpanels extends PluginBase
{
    /**
     * @var string
     */
    static protected $description = 'Airpanels Plugin';

    /**
     * @var string
     */
    static protected $name = 'Airpanels';

    /**
     * @var string
     */
    protected $storage = 'DbStorage';

    protected $settings = array(
        'information' => array(
            'type' => 'info',
            'content' => '',
            'default' => false
        ),
        'api_key' => array(
            'type' => 'password',
            'label' => 'API Key Transformers group'
        ),
        'api_url' => array(
            'type' => 'text',
            'label' => 'API URL Transformers group',
            'default' => 'https://api.acc.airpanels.wearetransformers.nl'
        ),
    );


    public function init()
    {
        $this->subscribe('newUnsecureRequest');
        $this->subscribe('beforeControllerAction');
    }

    public function newUnsecureRequest()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new CHttpException(405, 'Only POST requests are allowed.');
        }
        //handle the request
        $target = Yii::app()->request->getQuery('target');
        $function = Yii::app()->request->getQuery('function');
        if ($target == "Airpanels") {
            switch ($function) {
                case 'addQuestion':
                    $this->actionAddQuestion();
                    break;
                case 'CreateQuestionsByDescription':
                    $this->handleCreateQuestionsByDescription();
                    break;
            }
        }
    }

    private function parseJsonPostRequest()
    {
         // Retrieve the JSON content from the request body
         $jsonContent = Yii::app()->request->getRawBody();
         if (empty($jsonContent)) {
             $this->sendErrorResponse(400, 'No JSON content provided.');
         }
         // Decode the JSON content
         $data = json_decode($jsonContent, true);
         if (json_last_error() !== JSON_ERROR_NONE) {
             $this->sendErrorResponse(400, 'Invalid JSON content provided.');
         }
         return $data;
    }

    private function handleCreateQuestionsByDescription()
    {
        $data = $this->parseJsonPostRequest();
        $surveyId = $data['surveyId'] ?? null;
        //check if the user has the permission to add questions to this survey
        if (!Permission::model()->hasSurveyPermission($surveyId, 'surveycontent', 'create')) {
            throw new CHttpException(403, 'You do not have the permission to add questions to this survey.');
        }

        $oSurvey = Survey::model()->findByPk($surveyId);

        $description = isset($data['description']) ? strip_tags($data['description']) : null;
        if (empty($description)) {
            $this->sendErrorResponse(400, 'Missing description.');
        }

        $api_client = new TransformersApiClient($this->get('api_key'), $this->get('api_url'));
        // Start the streaming response
        try {
            // Get the streaming response from the FastAPI backend
            header('Content-Type: application/x-ndjson', true, 200);
            $api_client->createSurvey($description, $oSurvey->language);

        } catch (Exception $e) {
            $this->sendErrorResponse(500, 'Error while streaming response: ' . $e->getMessage());
        }


    }


    private function sendErrorResponse($statusCode, $message)
    {
        header('Content-Type: application/json', true, $statusCode);
        echo json_encode(['error' => $message]);
        Yii::app()->end();
    }

    private function sendSuccessResponse($data)
    {
        header('Content-Type: application/json', true, 200);
        echo json_encode($data);
        Yii::app()->end();
    }


    private function actionAddQuestion()
    {
        // Retrieve survey ID and group ID from the query parameters
        $surveyId = Yii::app()->request->getQuery('surveyId');
        $groupId = Yii::app()->request->getQuery('groupId');

        if (empty($surveyId) || empty($groupId)) {
            throw new CHttpException(400, 'Missing surveyId or groupId.');
        }

        //check if the user has the permission to add questions to this survey
        if (!Permission::model()->hasSurveyPermission($surveyId, 'surveycontent', 'create')) {
            throw new CHttpException(403, 'You do not have the permission to add questions to this survey.');
        }

        // Retrieve the XML content from the request body
        $xmlContent = Yii::app()->request->getRawBody();
        if (empty($xmlContent)) {
            throw new CHttpException(400, 'No XML content provided.');
        }

        // Create a temporary file to store the XML content
        $tempFilePath = tempnam(sys_get_temp_dir(), 'lsq');
        file_put_contents($tempFilePath, $xmlContent);

        // Import the question using the XMLImportQuestion function
        Yii::import('application.helpers.admin.import_helper', true);
        try {
            $options = array('autorename' => true, 'translinkfields' => true);
            $importResult = XMLImportQuestion($tempFilePath, $surveyId, $groupId, $options);
            unlink($tempFilePath); // Clean up the temporary file
        } catch (Exception $e) {
            unlink($tempFilePath); // Clean up the temporary file
            throw new CHttpException(500, 'Error importing question: ' . $e->getMessage());
        }

        // Respond with the import result
        header('Content-Type: application/json');
        echo json_encode($importResult);
        Yii::app()->end();
    }



    /**
     * Update the information content to show the good link
     * @params getValues
     */
    public function getPluginSettings($getValues = true)
    {
        if (!Permission::model()->hasGlobalPermission('settings', 'read')) {
            throw new CHttpException(403, 'You do not have the permission to access this page');
        }
        $settings = parent::getPluginSettings($getValues);
        $url = Yii::app()->createUrl('plugins/unsecure', ['plugin' => 'Airpanels', 'target' => 'Airpanels', 'function' => 'addQuestion']);
        $settings['information']['content'] = 'To add a question to a survey, send a POST request to the following URL: <a href="' . $url . '">' . $url . '</a> ';
        return $settings;
    }

    public function beforeControllerAction()
    {
        $event = $this->getEvent();
        // Check if the current controller and action are the ones we want to modify
        if ($event->get('controller') === 'surveyAdministration' && $event->get('action') === 'newSurvey') {
            // Add the new tab HTML
            $this->addNewTab();
        }
    }

    private function addNewTab()
    {
        // Add the new tab to the survey creation page
        App()->clientScript->registerScript('add-new-tab', "
            $(document).ready(function() {
                var newTab = '<li class=\"nav-item\" role=\"presentation\">' +
                             '<a class=\"nav-link\" role=\"tab\" data-bs-toggle=\"tab\" href=\"#ai-widget\">AI Widget</a>' +
                             '</li>';
                $('#create-import-copy-survey').append(newTab);

                var newTabContent = '<div class=\"tab-pane fade\" id=\"ai-widget\" role=\"tabpanel\">' +
                                    '<div id=\"ai-question-widget\"></div>' +
                                    '</div>';
                $('.tab-content').append(newTabContent);
                window.mountReactApp('ai-question-widget');
            });
        ", CClientScript::POS_END);
        App()->clientScript->registerScriptFile(App()->assetManager->publish(dirname(__FILE__) . '/js/index.js'), CClientScript::POS_HEAD);

    }
}