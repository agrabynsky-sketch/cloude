<?php
/**
 * Fails a request from Bakuun with the given errors.
 */
class Bakuun_Failure extends Exception
{
    /** @var Bakuun_Issue[] */
    protected $_errors;

    /** @var Bakuun_Issue[] */
    protected $_warnings;

    /**
     * @param Bakuun_Issue[] $errors
     * @param Bakuun_Issue[] $warnings
     */
    public function __construct(array $errors, array $warnings = array())
    {
        $messages = array();
        foreach ($errors as $error) {
            $messages[] = $error->message;
        }
        parent::__construct(implode('; ', $messages));
        $this->_errors = $errors;
        $this->_warnings = $warnings;
    }

    /**
     * @return Bakuun_Failure
     */
    public static function of($code, $message)
    {
        return new self(array(new Bakuun_Issue($code, $message)));
    }

    public function getErrors()
    {
        return $this->_errors;
    }

    public function getWarnings()
    {
        return $this->_warnings;
    }
}
