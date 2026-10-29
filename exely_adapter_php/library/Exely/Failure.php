<?php
/**
 * Fails the whole Exely request: Exely shows the errors and sends the request again later.
 */
class Exely_Failure extends Exception
{
    /** @var Exely_Issue[] */
    protected $_errors;

    /** @var Exely_Issue[] */
    protected $_warnings;

    /**
     * @param Exely_Issue[] $errors
     * @param Exely_Issue[] $warnings
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
     * @param  int    $code
     * @param  string $message
     * @return Exely_Failure
     */
    public static function of($code, $message)
    {
        return new self(array(new Exely_Issue($code, $message)));
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
