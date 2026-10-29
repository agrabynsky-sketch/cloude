<?php
/**
 * A failed call to the Unit.Travel Partner API. Status 0 means the API was not reached.
 */
class Exely_UnitTravel_Exception extends Exception
{
    protected $_status;
    protected $_errorCode;
    protected $_details;
    protected $_retryAfter;

    public function __construct($status, $errorCode, $message, array $details = array(), $retryAfter = null, $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->_status = (int) $status;
        $this->_errorCode = (string) $errorCode;
        $this->_details = $details;
        $this->_retryAfter = $retryAfter;
    }

    /**
     * @param  int         $status
     * @param  mixed       $body       decoded JSON error body
     * @param  string|null $retryAfter Retry-After header
     * @return Exely_UnitTravel_Exception
     */
    public static function fromResponse($status, $body, $retryAfter = null)
    {
        if (!is_array($body)) {
            $body = array();
        }
        return new self(
            $status,
            !empty($body['code']) ? $body['code'] : 'http_' . $status,
            !empty($body['message']) ? $body['message'] : 'HTTP ' . $status,
            isset($body['details']) && is_array($body['details']) ? $body['details'] : array(),
            ctype_digit((string) $retryAfter) ? (int) $retryAfter : null
        );
    }

    public function getStatus()
    {
        return $this->_status;
    }

    public function getErrorCode()
    {
        return $this->_errorCode;
    }

    public function getDetails()
    {
        return $this->_details;
    }

    public function getRetryAfter()
    {
        return $this->_retryAfter;
    }

    public function __toString()
    {
        return $this->_status . ' ' . $this->_errorCode . ': ' . $this->getMessage();
    }
}
