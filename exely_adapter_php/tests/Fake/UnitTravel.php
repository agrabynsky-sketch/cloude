<?php
/**
 * In-memory Partner API following openapi_3.json closely enough for the adapter.
 */
class Test_Fake_UnitTravel
{
    const API_KEY = 'ut-key';
    const BASE_URL = 'https://ut.test/partner/v1';

    public $knownProperties = array(123, 456);
    public $connections = array();
    public $rooms = array();
    public $ratePlans = array();
    public $ariMessages = array();
    public $ariOutcome = array('status' => 'applied', 'errors' => array());
    /** @var array|null array($status, $body, $headers) returned for POST /ari */
    public $ariError;
    public $reservations = array();
    public $acks = array();
    public $calls = array();

    public function __construct()
    {
        $this->rooms[123] = Test_Fixtures::rooms();
        $this->ratePlans[123] = Test_Fixtures::ratePlans();
    }

    public function activate($propertyId, array $scope = array())
    {
        $this->connections[$propertyId] = array(
            'connection_id' => 'conn_' . $propertyId,
            'property_id' => $propertyId,
            'status' => 'active',
            'scope' => array_merge(array('rates' => true, 'availability' => true, 'restrictions' => true), $scope),
        );
    }

    public function adapter()
    {
        return new Test_Fake_HttpAdapter(array($this, 'handle'));
    }

    public function client()
    {
        return new Exely_UnitTravel_Client(self::BASE_URL, self::API_KEY, array('adapter' => $this->adapter()));
    }

    public function handle($method, $url, array $headers, $body)
    {
        if (!isset($headers['x-api-key']) || $headers['x-api-key'] !== self::API_KEY) {
            return self::_error(401, 'unauthorized', 'invalid API key');
        }
        $parts = parse_url($url);
        $path = substr($parts['path'], strlen('/partner/v1'));
        $query = array();
        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        $data = $body === '' ? null : Exely_Json::decode($body);
        $this->calls[] = "$method $path";

        if ($path === '/connections' && $method === 'POST') {
            $propertyId = $data['property_id'];
            if (!in_array($propertyId, $this->knownProperties, true)) {
                return self::_error(404, 'not_found', "property $propertyId not found");
            }
            if (isset($this->connections[$propertyId])) {
                return array(200, $this->connections[$propertyId]);
            }
            $this->connections[$propertyId] = array(
                'connection_id' => 'conn_' . $propertyId, 'property_id' => $propertyId, 'status' => 'pending',
            );
            return array(201, $this->connections[$propertyId]);
        }
        if ($path === '/connections' && $method === 'GET') {
            $found = array();
            foreach ($this->connections as $connection) {
                if ((!isset($query['status']) || $connection['status'] === $query['status'])
                    && (!isset($query['property_id']) || $connection['property_id'] === (int) $query['property_id'])
                ) {
                    $found[] = $connection;
                }
            }
            return array(200, $found);
        }
        if (preg_match('#^/properties/(\d+)/(rooms|rate-plans)$#', $path, $m)) {
            $propertyId = (int) $m[1];
            if (!isset($this->connections[$propertyId]) || $this->connections[$propertyId]['status'] !== 'active') {
                return self::_error(409, 'connection_not_active', "connection for property $propertyId is not active");
            }
            $catalog = $m[2] === 'rooms' ? $this->rooms : $this->ratePlans;
            return array(200, isset($catalog[$propertyId]) ? $catalog[$propertyId] : array());
        }
        if ($path === '/ari' && $method === 'POST') {
            if ($headers['idempotency-key'] !== $data['message_uid']) {
                return self::_error(400, 'idempotency_mismatch', 'Idempotency-Key differs from message_uid');
            }
            if ($this->ariError) {
                return $this->ariError;
            }
            $this->ariMessages[] = $data;
            return array(202, array('message_uid' => $data['message_uid'], 'status' => 'received'));
        }
        if (preg_match('#^/ari/messages/(.+)$#', $path, $m)) {
            return array(200, array_merge(array('message_uid' => rawurldecode($m[1])), $this->ariOutcome));
        }
        if ($path === '/reservations') {
            $acked = array();
            foreach ($this->acks as $ack) {
                $acked[] = $ack[0];
            }
            $items = array();
            $propertyId = (int) $query['property_id'];
            $listed = isset($this->reservations[$propertyId]) ? $this->reservations[$propertyId] : array();
            foreach ($listed as $reservation) {
                if (!in_array($reservation['reservation_id'], $acked, true)) {
                    $items[] = $reservation;
                }
            }
            return array(200, array('items' => $items, 'next_cursor' => null));
        }
        if (preg_match('#^/reservations/(.+)/acknowledge$#', $path, $m)) {
            $this->acks[] = array(rawurldecode($m[1]), $data);
            return array(200, array('status' => 'ok'));
        }
        return self::_error(404, 'not_found', $path);
    }

    protected static function _error($status, $code, $message)
    {
        return array($status, array('code' => $code, 'message' => $message));
    }
}
