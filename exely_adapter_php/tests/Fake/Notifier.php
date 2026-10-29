<?php
class Test_Fake_Notifier extends Exely_Notifier
{
    public $calls = 0;

    public function __construct()
    {
        parent::__construct('');
    }

    public function notify()
    {
        $this->calls++;
    }
}
