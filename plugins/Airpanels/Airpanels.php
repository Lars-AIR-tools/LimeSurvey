<?php

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
            'default'=> false
        ),
    );

    
    public function init() {
        $this->subscribe('newUnsecureRequest');
    }

    public function newUnsecureRequest() {
        //handle the request
        $target = Yii::app()->request->getQuery('target');
        $function = Yii::app()->request->getQuery('function');
        if ($target == "Airpanels" && $function == 'addQuestion') {
            $this->actionAddQuestion();
        }
    }

    private function actionAddQuestion() {
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
        $xmlContent = file_get_contents('php://input');
        if (empty($xmlContent)) {
            throw new CHttpException(400, 'No XML content provided.');
        }

        // Create a temporary file to store the XML content
        $tempFilePath = tempnam(sys_get_temp_dir(), 'lsq');
        file_put_contents($tempFilePath, $xmlContent);

        // Import the question using the XMLImportQuestion function
        Yii::import('application.helpers.admin.import_helper', true);
        try {
            $options = array('autorename' => true,'translinkfields' => true);
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
    public function getPluginSettings($getValues=true)
    {
        if(!Permission::model()->hasGlobalPermission('settings','read')) {
            throw new CHttpException(403, 'You do not have the permission to access this page');
        }
        $settings = parent::getPluginSettings($getValues);
        $url = Yii::app()->createUrl('plugins/unsecure', ['plugin' => 'Airpanels', 'target' => 'Airpanels', 'function' => 'addQuestion']);
        $settings['information']['content'] = 'To add a question to a survey, send a POST request to the following URL: <a href="' . $url . '">' . $url . '</a> ';
        return $settings;
    }
}