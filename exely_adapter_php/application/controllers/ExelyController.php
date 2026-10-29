<?php
/**
 * The channel endpoint Exely Channel Manager posts every action to: POST /exely
 */
class ExelyController extends Exely_Controller_Json
{
    public function indexAction()
    {
        if (!$this->getRequest()->isPost()) {
            $this->getResponse()->setHeader('Allow', 'POST');
            $this->_json(405, array('error' => 'method not allowed'));
            return;
        }
        // Always answer in Exely's envelope, even for a body that is not JSON.
        $payload = Exely_Json::decode($this->getRequest()->getRawBody());
        $this->_json(200, $this->_resource('exelyService')->handle($payload));
    }
}
