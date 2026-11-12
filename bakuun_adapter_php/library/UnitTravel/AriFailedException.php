<?php
/**
 * Unit.Travel accepted an ARI message but failed to apply it (AriMessageStatus "failed").
 */
class UnitTravel_AriFailedException extends Exception
{
    protected $_errors;

    /**
     * @param array $errors Error objects from AriMessageStatus.errors
     */
    public function __construct(array $errors)
    {
        parent::__construct('Unit.Travel failed to apply the ARI update');
        $this->_errors = $errors;
    }

    public function getErrors()
    {
        return $this->_errors;
    }
}
