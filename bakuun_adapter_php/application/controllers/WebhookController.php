<?php
/**
 * Unit.Travel webhooks: POST /webhooks/unit-travel (also /webhook/unit-travel)
 */
class WebhookController extends Bakuun_Controller_Json
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
        if (!UnitTravel_Signature::verify($secret, $body, $request->getHeader(UnitTravel_Signature::HEADER))) {
            $this->_json(401, array('error' => 'invalid signature'));
            return;
        }
        $event = UnitTravel_Json::decode($body);
        if (!is_array($event)) {
            $this->_json(400, array('error' => 'invalid JSON'));
            return;
        }
        $kind = isset($event['event']) ? (string) $event['event'] : '';
        if (strpos($kind, 'reservation.') === 0) {
            $reservation = isset($event['reservation']) && is_array($event['reservation']) ? $event['reservation'] : array();
            try {
                $result = $this->_resource('bakuunBookingSync')->process($reservation);
            } catch (UnitTravel_InvalidReservationException $e) {
                $this->_json(422, array('error' => 'invalid reservation'));
                return;
            }
            if ($result === Bakuun_BookingSync::RETRY) {
                // Unit.Travel then delivers the webhook again, card data included.
                $this->_json(503, array('status' => 'retry'));
                return;
            }
        }
        // connection.* events need no action: connections are looked up when they are used.
        $this->_json(200, array('status' => 'ok'));
    }
}
