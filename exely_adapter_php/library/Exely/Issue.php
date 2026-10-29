<?php
/**
 * One error or warning in an Exely response.
 */
class Exely_Issue
{
    /** @var int */
    public $code;

    /** @var string */
    public $message;

    public function __construct($code, $message)
    {
        $this->code = (int) $code;
        $this->message = (string) $message;
    }

    public function toArray()
    {
        return array('code' => $this->code, 'message' => $this->message);
    }
}
