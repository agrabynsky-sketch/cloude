<?php
error_reporting(E_ALL | E_STRICT);
date_default_timezone_set('UTC');

$root = realpath(dirname(__FILE__) . '/..');
defined('APPLICATION_PATH') || define('APPLICATION_PATH', $root . '/application');
defined('APPLICATION_ENV') || define('APPLICATION_ENV', 'testing');

// ZF1 comes from Composer, from ZF1_PATH, or is already on the include path.
if (file_exists($root . '/vendor/autoload.php')) {
    require_once $root . '/vendor/autoload.php';
}
set_include_path(implode(PATH_SEPARATOR, array_filter(array(
    $root . '/library',
    getenv('ZF1_PATH'),
    get_include_path(),
))));

require_once 'Zend/Loader/Autoloader.php';
Zend_Loader_Autoloader::getInstance()->registerNamespace('Exely_');

require_once dirname(__FILE__) . '/Fake/HttpAdapter.php';
require_once dirname(__FILE__) . '/Fake/UnitTravel.php';
require_once dirname(__FILE__) . '/Fake/Notifier.php';
require_once dirname(__FILE__) . '/Fixtures.php';
require_once dirname(__FILE__) . '/TestCase.php';
