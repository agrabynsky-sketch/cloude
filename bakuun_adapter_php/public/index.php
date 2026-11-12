<?php
require_once dirname(__FILE__) . '/../scripts/environment.php';

$application = new Zend_Application(APPLICATION_ENV, APPLICATION_PATH . '/configs/application.ini');
$application->bootstrap()->run();
