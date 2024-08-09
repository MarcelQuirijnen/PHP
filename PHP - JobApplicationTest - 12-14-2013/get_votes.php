<?php
header('Content-type: application/json');

require_once (dirname(__FILE__)."/includes/dbfunctions.inc");
$params = array();

$colorid = (isset($_POST['colorid']) && 
            $_POST['colorid'] != '' && 
            is_numeric($_POST['colorid'])) ? mysql_real_escape_string($_POST['colorid'])
                                           : 0;

$sql = "SELECT SUM(vot_noof_votes) AS SumVotes FROM Votes WHERE vot_col_ID = :color_ID";
$params = array(':color_ID' => $colorid);

$votes = pdo_myload($sql, $params);
//var_dump($votes);
if (count($votes)) {
  print json_encode($votes);
}

?>
