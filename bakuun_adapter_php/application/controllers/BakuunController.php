<?php
/**
 * The endpoint Bakuun pushes to: POST /bakuun (any path below it works as well).
 */
class BakuunController extends Bakuun_Controller_Json
{
    public function indexAction()
    {
        if (!$this->getRequest()->isPost()) {
            $this->getResponse()->setHeader('Allow', 'POST');
            $this->_json(405, array('success' => false, 'errors' => array(array('code' => Bakuun_Code::BAD_REQUEST, 'message' => 'method not allowed'))));
            return;
        }
        $body = $this->_resource('bakuunService')->handle(UnitTravel_Json::decode($this->getRequest()->getRawBody()));
        $this->_json(Bakuun_Response::httpStatus($body), $body);
    }
}
