<?php
/**
 * Base for the adapter's controllers: JSON in, JSON out, no views.
 */
abstract class Exely_Controller_Json extends Zend_Controller_Action
{
    public function init()
    {
        $this->_helper->viewRenderer->setNoRender(true);
    }

    protected function _resource($name)
    {
        return $this->getInvokeArg('bootstrap')->getResource($name);
    }

    protected function _json($status, $body)
    {
        $this->getResponse()
            ->setHttpResponseCode($status)
            ->setHeader('Content-Type', 'application/json; charset=utf-8', true)
            ->setBody(Exely_Json::encode($body));
    }
}
