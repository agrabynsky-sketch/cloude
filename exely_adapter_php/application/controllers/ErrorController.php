<?php
/**
 * JSON errors instead of ZF1's HTML error page; details go to the log, not to the caller.
 */
class ErrorController extends Exely_Controller_Json
{
    public function errorAction()
    {
        $errors = $this->_getParam('error_handler');
        $notFound = $errors && in_array($errors->type, array(
            Zend_Controller_Plugin_ErrorHandler::EXCEPTION_NO_ROUTE,
            Zend_Controller_Plugin_ErrorHandler::EXCEPTION_NO_CONTROLLER,
            Zend_Controller_Plugin_ErrorHandler::EXCEPTION_NO_ACTION,
        ), true);
        if (!$notFound && $errors) {
            $this->_resource('exelyLog')->err('Unhandled ' . get_class($errors->exception) . ': ' . $errors->exception->getMessage());
        }
        $this->_json($notFound ? 404 : 500, array('error' => $notFound ? 'not found' : 'internal error'));
    }
}
