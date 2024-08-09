<?php

// DOCUMENTATION : http://www.php.net/manual/en/book.soap.php

require_once (dirname(__FILE__)."/SOAP.php");

ini_set("max_execution_time", 7200);
ini_set("soap.wsdl_cache_enabled", 0);

$server_options = array (
    'soap_version'  => SOAP_1_2,
    'cache_wsdl'    => WSDL_CACHE_NONE
);

$server = new SoapServer('http://lrd1pwrdev/marcel/webservices/WSDL/power.wsdl', $server_options);
$server->setClass('SOAP');
$server->handle();

?>
