<?php
/**
 * Asks Exely to call get-bookings now instead of at its next 10-minute poll.
 */
class Exely_Notifier
{
    protected $_url;
    protected $_adapter;
    protected $_log;

    /**
     * @param string                                  $url     empty disables notifications
     * @param Zend_Http_Client_Adapter_Interface|null $adapter
     * @param Zend_Log|null                           $log
     */
    public function __construct($url, $adapter = null, Zend_Log $log = null)
    {
        $this->_url = (string) $url;
        $this->_adapter = $adapter;
        $this->_log = $log;
    }

    /**
     * Best effort: Exely polls every 10 minutes anyway, so failures are only logged.
     */
    public function notify()
    {
        if ($this->_url === '') {
            return;
        }
        try {
            $adapter = $this->_adapter ? $this->_adapter : Exely_UnitTravel_Client::secureAdapter(5);
            $client = new Zend_Http_Client($this->_url, array('adapter' => $adapter, 'timeout' => 5));
            $status = $client->request('GET')->getStatus();
            if ($status !== 200 && $this->_log) {
                $this->_log->warn("Exely booking notification answered $status");
            }
        } catch (Zend_Exception $e) {
            if ($this->_log) {
                $this->_log->warn('Exely booking notification failed: ' . $e->getMessage());
            }
        }
    }
}
