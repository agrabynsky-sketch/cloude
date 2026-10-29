<?php
defined('APPLICATION_PATH')
    || define('APPLICATION_PATH', realpath(dirname(__FILE__) . '/../application'));
defined('APPLICATION_ENV')
    || define('APPLICATION_ENV', getenv('APPLICATION_ENV') ? getenv('APPLICATION_ENV') : 'production');

// Composer puts ZF1 on the include path; without it ZF1 must already be there (php.ini or ZF1_PATH).
if (file_exists(APPLICATION_PATH . '/../vendor/autoload.php')) {
    require_once APPLICATION_PATH . '/../vendor/autoload.php';
}
set_include_path(implode(PATH_SEPARATOR, array_filter(array(
    realpath(APPLICATION_PATH . '/../library'),
    getenv('ZF1_PATH'),
    get_include_path(),
))));

require_once 'Zend/Application.php';

$application = new Zend_Application(APPLICATION_ENV, APPLICATION_PATH . '/configs/application.ini');
$application->bootstrap()->run();
