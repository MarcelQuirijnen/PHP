<?php

// MQ : using native PHP session. Must be initiated before Slim is initiated.
//      Don't want Slim middleware layer as it uses persistance through an http cookie, which is limited to 4K
// see: http://docs.slimframework.com/sessions/native/
if (session_status() != PHP_SESSION_ACTIVE) {
    session_start();
    ini_set('session.use_cookies', 1);
}

// Recursive Glob function.
function rglob($pattern, $flags = 0, $only_files = false) {
    $files = glob($pattern, $flags);
    foreach (glob(dirname($pattern).'/*', GLOB_ONLYDIR|GLOB_NOSORT) as $dir) {
        $files = array_merge($files, rglob($dir.'/'.basename($pattern), $flags, $only_files));
    }
    if ($only_files) {
        foreach ($files as $key => $file) {
            if (!is_file($file)) {
                unset($files[$key]);
            }
        }
    }
    return $files;
}

//load twig ( DOCS: http://twig.sensiolabs.org/documentation )
require_once VZP_LIB . '/Twig/Autoloader.php';
Twig_Autoloader::register();
$template_loader = new Twig_Loader_Filesystem(VZP_TEMPLATES);
$template = new Twig_Environment($template_loader, array('debug' => true, 'cache' => false, 'autoescape' => false));
$template->addGlobal("session", $_SESSION);

// load slim ( DOCS: http://docs.slimframework.com/ )
require_once VZP_LIB .'/Slim/Slim.php';
\Slim\Slim::registerAutoloader();
$app = new \Slim\Slim();
//$app->add(new \Slim\Middleware\SessionCookie(array(
//    'expires' => '120 minutes',
//    'name' => 'portal_session',
//    'session.handler' => null
//)));

require_once VZP_LIB .'/Portal/classes/Services/AuthMiddleware.php';
$app->add(new \Portal\Services\AuthMiddleware());

// load idiorm ( DOCS: http://idiorm.readthedocs.org/en/latest/ )
require_once VZP_LIB .'/idiorm/idiorm.php';
ORM::configure('mysql:host='.DB_HOST.';dbname='.DB_NAME);
ORM::configure('username', DB_USER);
ORM::configure('password', DB_PASS);
ORM::configure('driver_options', array(PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8'));
ORM::configure('return_result_sets', true);
ORM::configure('id_column_overrides', array('SiteInfo' => 'SiteName', 'WizProgress' => 'SiteName',));

ORM::configure('mysql:host='.DB_HOST.';dbname='.DB_NAME_AUDIT, null, 'audit');
ORM::configure('username', DB_USER, 'audit');
ORM::configure('password', DB_PASS, 'audit');
ORM::configure('driver_options', array(PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8'), 'audit');
ORM::configure('return_result_sets', true, 'audit');

// load PHPMailer ( DOCS: https://github.com/PHPMailer/PHPMailer/ )
require VZP_LIB . '/PHPMailer/class.phpmailer.php';

// load ChromeLogger ( https://chrome.google.com/extensions/detail/noaneddfkdjfnfdakjjmocngnfkfehhd )
require VZP_LIB . '/ChromeLogger/ChromeLogger.php';

// load portal classes
function portal_autoload($class) {
    $class = ltrim($class, '\\Portal\\');
    $file  = '';
    $namespace = '';
    if ($lastNsPos = strrpos($class, '\\')) {
        $namespace = substr($class, 0, $lastNsPos);
        $class = substr($class, $lastNsPos + 1);
        $file  = str_replace('\\', DIRECTORY_SEPARATOR, $namespace) . DIRECTORY_SEPARATOR;
    }
    $file .= str_replace('_', DIRECTORY_SEPARATOR, $class) . '.php';
    
    $file = __DIR__ . '/classes/' . $file;
    if(file_exists($file)) {
        include_once $file;
    } else {
        return false;
    }
}
spl_autoload_register("portal_autoload");

// include the common functions
$fn_path = __DIR__ . '/functions/*';
$fn_files = rglob($fn_path);
foreach($fn_files as $fn_file) {
    if(is_file($fn_file)) {
        include_once $fn_file;
    }
}

// include the app routes
include_once 'routes.php';

// run the slim app
$app->run();
