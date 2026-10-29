<?php
/**
 * Unit.Travel webhooks: POST /webhooks/unit-travel (also /webhook/unit-travel)
 */
class WebhookController extends Exely_Controller_Json
{
    public function unitTravelAction()
    {
        $request = $this->getRequest();
        $options = array_change_key_case((array) $this->getInvokeArg('bootstrap')->getOption('unittravel'), CASE_LOWER);
        $secret = isset($options['webhooksecret']) ? (string) $options['webhooksecret'] : '';
        if ($secret === '' || !$request->isPost()) {
            $this->_json(404, array('error' => 'not found'));
            return;
        }
        $body = (string) $request->getRawBody();
        if (!Exely_Signature::verify($secret, $body, $request->getHeader(Exely_Signature::HEADER))) {
            $this->_json(401, array('error' => 'invalid signature'));
            return;
        }
        $event = Exely_Json::decode($body);
        if (!is_array($event)) {
            $this->_json(400, array('error' => 'invalid JSON'));
            return;
        }
        $kind = isset($event['event']) ? (string) $event['event'] : '';
        if (strpos($kind, 'reservation.') === 0) {
            $reservation = isset($event['reservation']) && is_array($event['reservation']) ? $event['reservation'] : array();
            try {
                $changed = $this->_resource('exelyService')->acceptReservation($reservation);
            } catch (Exely_InvalidReservationException $e) {
                $this->_json(422, array('error' => 'invalid reservation'));
                return;
            }
            if ($changed) {
                $this->_resource('exelyNotifier')->notify();
            }
        }
        // connection.* events need no action: the connection is looked up on every Exely call.
        $this->_json(200, array('status' => 'ok'));
    }
}
