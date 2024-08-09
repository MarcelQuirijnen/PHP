<?php
header('Content-type: application/json');

require_once (dirname(__FILE__)."/includes/dbfunctions.inc");

$sql = "SELECT * FROM phptest.Colors ORDER BY col_ID";
$params = array();

$colors = pdo_myload($sql, $params);
//var_dump($colors);
if (count($colors)) {
  print json_encode($colors);
}

?>
