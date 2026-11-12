<?php
/**
 * Sends ARI parts to Unit.Travel and waits for the result (POST /ari is asynchronous).
 */
class UnitTravel_AriSender
{
    protected static $_finalStatuses = array('applied', 'failed', 'skipped');

    protected static $_defaults = array(
        'statusWaitSeconds' => 10,
        'statusPollInterval' => 0.5,
        'maxItemsPerMessage' => 1000,
        // message_uid prefix, naming the channel the update came from
        'uidPrefix' => 'ari',
    );

    protected $_client;
    protected $_options;
    protected $_lastRevision = 0;

    public function __construct(UnitTravel_Client $client, array $options = array())
    {
        $this->_client = $client;
        $this->_options = array_merge(self::$_defaults, $options);
    }

    /**
     * @return string[] message uids Unit.Travel accepted but had not applied when waiting ended
     * @throws UnitTravel_AriFailedException when Unit.Travel failed to apply a message
     * @throws UnitTravel_Exception          when a call to Unit.Travel failed
     */
    public function send($propertyId, UnitTravel_AriParts $parts)
    {
        $pending = array();
        foreach ($parts->chunks($this->_options['maxItemsPerMessage']) as $chunk) {
            $uid = $this->_options['uidPrefix'] . '-' . $propertyId . '-' . str_replace('-', '', UnitTravel_Uuid::v4());
            $message = array_merge(array(
                'message_uid' => $uid,
                // Updates arrive one after another, so arrival order is the order of change.
                'revision' => $this->_nextRevision(),
                'property_id' => (int) $propertyId,
            ), $chunk);
            $status = $this->_client->postAri($message);
            if (!self::_isFinal($status)) {
                $status = $this->_wait($uid, $status);
            }
            if (isset($status['status']) && $status['status'] === 'failed') {
                throw new UnitTravel_AriFailedException(isset($status['errors']) ? (array) $status['errors'] : array());
            }
            if (!self::_isFinal($status)) {
                $pending[] = $uid;
            }
        }
        return $pending;
    }

    protected function _wait($uid, $status)
    {
        $deadline = microtime(true) + (float) $this->_options['statusWaitSeconds'];
        while (microtime(true) < $deadline) {
            usleep((int) ((float) $this->_options['statusPollInterval'] * 1000000));
            $status = $this->_client->getAriStatus($uid);
            if (self::_isFinal($status)) {
                break;
            }
        }
        return $status;
    }

    /**
     * Strictly increasing millisecond timestamps for ARI `revision` (last-write-wins).
     */
    protected function _nextRevision()
    {
        $this->_lastRevision = max($this->_lastRevision + 1, (int) floor(microtime(true) * 1000));
        return $this->_lastRevision;
    }

    protected static function _isFinal($status)
    {
        return is_array($status) && isset($status['status']) && in_array($status['status'], self::$_finalStatuses, true);
    }
}
