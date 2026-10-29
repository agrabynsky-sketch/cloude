<?php
/**
 * GET /health
 */
class HealthController extends Exely_Controller_Json
{
    public function indexAction()
    {
        $this->_json(200, array('status' => 'ok'));
    }
}
