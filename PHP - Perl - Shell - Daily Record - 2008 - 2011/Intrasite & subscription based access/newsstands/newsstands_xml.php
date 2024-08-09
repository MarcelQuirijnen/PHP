<?php
//<meta name="author" content="Marcel Quirijnen">
//<meta name="company" content="Little Sugar Creek Technology Services, Inc."

error_reporting(E_ALL ^ E_NOTICE);

//include XML Header (as response will be in xml format)
header("Content-type: text/xml");

//encoding may be different in your case
echo('<?xml version="1.0" encoding="utf-8"?>'); 

include_once 'siteconfig.php'; 

//include_once $_SERVER['DOCUMENT_ROOT'] . 'include/siteconfig.php';
//include_once $_SERVER['DOCUMENT_ROOT'] . 'include/debug.inc';

//mysql> desc newsstands;
//+-----------+--------------+------+-----+-----------+----------------+
//| Field     | Type         | Null | Key | Default   | Extra          |
//+-----------+--------------+------+-----+-----------+----------------+
//| id        | int(10)      | NO   | PRI | NULL      | auto_increment | 
//| location  | varchar(200) | NO   | MUL | NULL      |                | 
//| address   | varchar(100) | NO   |     | NULL      |                | 
//| zip       | varchar(5)   | NO   |     | NULL      |                | 
//| city      | varchar(50)  | NO   |     | NULL      |                | 
//| state     | varchar(2)   | NO   |     | AR        |                | 
//| county    | varchar(50)  | NO   |     |           |                | 
//| contact   | varchar(200) | NO   |     |           |                | 
//| latitude  | float(7,5)   | NO   |     | 34.74740  |                | 
//| longitude | float(7,5)   | NO   |     | -92.28095 |                | 
//+-----------+--------------+------+-----+-----------+----------------+
//10 rows in set (0.00 sec)


if (isset($_REQUEST['action']) && $_REQUEST['action'] == 'select') {

   //start output of data
   echo '<rows id="0">';

   //output data from DB as XML
   $sql = "SELECT * FROM newsstands";
   $res = mysql_query ($sql, $link);
		
   if ($res) {
	  while ($row = mysql_fetch_array($res)) {
 	 	 //create xml tag for grid's row
		 echo ("<row id='" . $row['id']."'>");
		 print("<cell><![CDATA[" . $row['county']    . "]]></cell>");
		 print("<cell><![CDATA[" . $row['location']  . "]]></cell>");
		 print("<cell><![CDATA[" . $row['address']   . "]]></cell>");
		 print("<cell><![CDATA[" . $row['city']      . "]]></cell>");
		 print("<cell><![CDATA[" . $row['state']     . "]]></cell>");
		 print("<cell><![CDATA[" . $row['zip']       . "]]></cell>");
		 print("<cell><![CDATA[" . $row['contact']   . "]]></cell>");
		 print("<cell><![CDATA[" . $row['latitude']  . "]]></cell>");
         print("<cell><![CDATA[" . $row['longitude'] . "]]></cell>");
		 print("</row>");
	  }
   } else {
      //error occurs
	  echo mysql_errno() . ": " . mysql_error() . " at " . __LINE__ . " line in " . __FILE__ . " file<br>";
   }
   echo '</rows>';

} elseif (isset($_REQUEST['action']) && $_REQUEST['action'] == 'update') {

   //init_debug_file();
   
   $mode = $_GET["!nativeeditor_status"]; //get request mode
   $rowId = $_REQUEST['gr_id'];
   
   //debug_(__FILE__, __LINE__, "rowId", $rowId);
   //debug_(__FILE__, __LINE__, "mode", $mode);
   
   switch($mode){
	 case "inserted":
		//row adding request, not used for now
		$action='insert';
	    break;
	 case "deleted":
		//row deleting request, not used for now
		$action='delete';
	    break;
	 default:
		//row updating request
        $sql = sprintf("UPDATE newsstands 
                        SET location='%s', county='%s', address='%s', city='%s', zip='%s', state='%s', contact='%s', latitude='%s', longitude='%s' 
                        WHERE id=%d", 
                        mysql_real_escape_string($_GET['location']),  mysql_real_escape_string($_GET['county']),
                        mysql_real_escape_string($_GET['address']),   mysql_real_escape_string($_GET['city']),
                        mysql_real_escape_string($_GET['zip']),       mysql_real_escape_string($_GET['state']),
                        mysql_real_escape_string($_GET['contact']),   mysql_real_escape_string($_GET['latitude']),
                        mysql_real_escape_string($_GET['longitude']), mysql_real_escape_string($rowId)
                      );
                      
        //debug_(__FILE__, __LINE__, "sql", $sql);
        
        $res = mysql_query($sql) ? 'OK' : 'NOK';
        $action='update';
	    break;
   }

   //output update results
   echo "<data>";
   echo "<action type='".$action."' sid='".$rowId."' tid='".$rowId."'/>";
   echo "</data>";

} else {
   echo "<data>";
   echo "<action type='Void' />";
   echo "</data>";
}
?>
