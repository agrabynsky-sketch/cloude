<?php
/**
 * Supplies a rate plan's currency when an Exely price update omits currencyCode.
 */
class Exely_CurrencyResolver
{
    protected $_client;
    protected $_propertyId;
    protected $_ratePlans;

    public function __construct(Exely_UnitTravel_Client $client, $propertyId)
    {
        $this->_client = $client;
        $this->_propertyId = $propertyId;
    }

    /**
     * @param  int $ratePlanId
     * @return string
     * @throws Exely_Failure
     */
    public function currencyFor($ratePlanId)
    {
        if ($this->_ratePlans === null) {
            $this->_ratePlans = $this->_client->getRatePlans($this->_propertyId);
        }
        foreach ($this->_ratePlans as $plan) {
            if ((int) $plan['id'] === (int) $ratePlanId) {
                if (empty($plan['currency'])) {
                    throw Exely_Failure::of(
                        Exely_Code::INVALID_CURRENCY, "currencyCode is missing and rate plan $ratePlanId has no currency"
                    );
                }
                return $plan['currency'];
            }
        }
        throw Exely_Failure::of(Exely_Code::INVALID_RATE_PLAN, "Rate plan $ratePlanId does not exist in Unit.Travel");
    }
}
