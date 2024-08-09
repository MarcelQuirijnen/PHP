<?php

$config = array();
$serverName = exec('hostname');
$productionServers = array ('phptesthttp1','phptesthttp2');

if (in_array($serverName, $productionServers)) {
	/* Production */
} else {
	/* Development */
    //$config["memory_limit"]= '512M'; // use with ini_set('memory_limit', '512M');
	$config["dbdatabase"] = "phptest";
	$config["dbpass"]     = "phptest";
	$config["dbhost"]     = "localhost";
	$config["dbuser"]     = "phptest";
}

?>
