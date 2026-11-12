<?php
/**
 * A failed call to Bakuun. Code 0 means Bakuun was not reached.
 */
class Bakuun_Exception extends Exception
{
    protected $_errorCode;
    protected $_httpStatus;

    public function __construct($errorCode, $message, $httpStatus = 0, $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->_errorCode = (int) $errorCode;
        $this->_httpStatus = (int) $httpStatus;
    }

    public function getErrorCode()
    {
        return $this->_errorCode;
    }

    public function getHttpStatus()
    {
        return $this->_httpStatus;
    }

    /**
     * Whether retrying later may succeed (Bakuun or the network was down, not the request wrong).
     */
    public function isTransient()
    {
        return Bakuun_Code::isTransient($this->_errorCode)
            || ($this->_errorCode === 0 && ($this->_httpStatus === 0 || $this->_httpStatus >= 500));
    }

    public function __toString()
    {
        return 'Bakuun ' . $this->_errorCode . ': ' . $this->getMessage();
    }
}
