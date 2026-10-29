<?php
/**
 * Client for the Unit.Travel Partner Inventory API (openapi_3.json, v1.9.0).
 *
 * The adapter is one provider in Unit.Travel: a single X-API-Key covers every property
 * connected through Exely. Responses are returned as decoded JSON arrays.
 */
class Exely_UnitTravel_Client
{
    const USER_AGENT = 'exely-adapter-php/0.1';

    protected $_baseUrl;
    protected $_apiKey;
    protected $_timeout;
    protected $_adapter;

    /**
     * @param string $baseUrl e.g. https://api.unit.travel/partner/v1
     * @param string $apiKey
     * @param array  $options timeout (seconds), caFile (CA bundle path), adapter (Zend_Http_Client_Adapter_Interface)
     */
    public function __construct($baseUrl, $apiKey, array $options = array())
    {
        $this->_baseUrl = rtrim($baseUrl, '/');
        $this->_apiKey = $apiKey;
        $this->_timeout = isset($options['timeout']) ? (int) $options['timeout'] : 30;
        $this->_adapter = isset($options['adapter'])
            ? $options['adapter']
            : self::secureAdapter($this->_timeout, isset($options['caFile']) ? $options['caFile'] : null);
    }

    /**
     * A cURL adapter that verifies TLS certificates. PHP 5 streams (the Socket adapter)
     * do not verify them by default, so the adapter never relies on them.
     *
     * @param  int         $timeout seconds for the whole request; ZF1 itself only limits connecting
     * @param  string|null $caFile
     * @return Zend_Http_Client_Adapter_Curl
     */
    public static function secureAdapter($timeout, $caFile = null)
    {
        $options = array(
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => (int) $timeout,
        );
        if ($caFile) {
            $options[CURLOPT_CAINFO] = $caFile;
        }
        $adapter = new Zend_Http_Client_Adapter_Curl();
        $adapter->setConfig(array('curloptions' => $options));
        return $adapter;
    }

    /**
     * Idempotent: returns the existing connection or files a new request the hotel confirms in Extranet.
     */
    public function createConnection($propertyId, $note = null)
    {
        $body = array('property_id' => (int) $propertyId);
        if ($note) {
            $body['note'] = $note;
        }
        return $this->_request('POST', '/connections', array(), $body);
    }

    public function listConnections($status = null, $propertyId = null)
    {
        $query = array();
        if ($status !== null) {
            $query['status'] = $status;
        }
        if ($propertyId !== null) {
            $query['property_id'] = (int) $propertyId;
        }
        return (array) $this->_request('GET', '/connections', $query);
    }

    public function getRooms($propertyId)
    {
        return (array) $this->_request('GET', '/properties/' . (int) $propertyId . '/rooms');
    }

    public function getRatePlans($propertyId)
    {
        return (array) $this->_request('GET', '/properties/' . (int) $propertyId . '/rate-plans');
    }

    /**
     * @return array the acceptance receipt (202) or, for an already seen message_uid, its status (200)
     */
    public function postAri(array $message)
    {
        return $this->_request('POST', '/ari', array(), $message, array('Idempotency-Key' => $message['message_uid']));
    }

    public function getAriStatus($messageUid)
    {
        return $this->_request('GET', '/ari/messages/' . rawurlencode($messageUid));
    }

    /**
     * Unacknowledged reservations of a property, all pages.
     *
     * @return array[]
     */
    public function listReservations($propertyId, $pageSize = 100)
    {
        $query = array('property_id' => (int) $propertyId, 'limit' => (int) $pageSize);
        $reservations = array();
        do {
            $page = $this->_request('GET', '/reservations', $query);
            if (isset($page['items']) && is_array($page['items'])) {
                $reservations = array_merge($reservations, $page['items']);
            }
            $query['cursor'] = isset($page['next_cursor']) ? $page['next_cursor'] : null;
        } while ($query['cursor']);
        return $reservations;
    }

    public function acknowledge($reservationId, array $details = null)
    {
        $this->_request('POST', '/reservations/' . rawurlencode($reservationId) . '/acknowledge', array(), $details);
    }

    /**
     * @return mixed decoded JSON body
     * @throws Exely_UnitTravel_Exception
     */
    protected function _request($method, $path, array $query = array(), $body = null, array $headers = array())
    {
        try {
            $client = new Zend_Http_Client($this->_baseUrl . $path, array(
                'adapter' => $this->_adapter,
                'timeout' => $this->_timeout,
                'useragent' => self::USER_AGENT,
            ));
            $client->setHeaders(array_merge(
                array('X-API-Key' => $this->_apiKey, 'Accept' => 'application/json'), $headers
            ));
            if ($query) {
                $client->setParameterGet($query);
            }
            if ($body !== null) {
                $client->setRawData(Exely_Json::encode($body), 'application/json');
            }
            $response = $client->request($method);
        } catch (Zend_Exception $e) {
            throw new Exely_UnitTravel_Exception(0, 'network_error', $e->getMessage(), array(), null, $e);
        }
        $decoded = Exely_Json::decode($response->getBody());
        if ($response->getStatus() >= 400) {
            throw Exely_UnitTravel_Exception::fromResponse(
                $response->getStatus(), $decoded, $response->getHeader('Retry-After')
            );
        }
        return $decoded;
    }
}
