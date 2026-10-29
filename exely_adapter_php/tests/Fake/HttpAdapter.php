<?php
/**
 * Zend_Http_Client adapter that hands requests to a PHP callback instead of the network.
 *
 * The callback gets ($method, $url, array $headers, $body) and returns
 * array($status, $decodedJsonBody|null, array $headers).
 */
class Test_Fake_HttpAdapter implements Zend_Http_Client_Adapter_Interface
{
    protected $_handler;
    protected $_response;

    public function __construct($handler)
    {
        $this->_handler = $handler;
    }

    public function setConfig($config = array())
    {
    }

    public function connect($host, $port = 80, $secure = false)
    {
    }

    public function write($method, $url, $http_ver = '1.1', $headers = array(), $body = '')
    {
        $parsed = array();
        foreach ($headers as $header) {
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) {
                $parsed[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }
        $this->_response = call_user_func($this->_handler, $method, (string) $url, $parsed, (string) $body);
        return '';
    }

    public function read()
    {
        $status = $this->_response[0];
        $content = $this->_response[1] === null ? '' : Exely_Json::encode($this->_response[1]);
        $raw = "HTTP/1.1 $status " . Zend_Http_Response::responseCodeAsText($status) . "\r\n"
            . "Content-Type: application/json\r\n"
            . 'Content-Length: ' . strlen($content) . "\r\n";
        $headers = isset($this->_response[2]) ? $this->_response[2] : array();
        foreach ($headers as $name => $value) {
            $raw .= "$name: $value\r\n";
        }
        return $raw . "\r\n" . $content;
    }

    public function close()
    {
    }
}
