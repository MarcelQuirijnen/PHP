<?php
//<meta name="author" content="Marcel Quirijnen">
//<meta name="company" content="Little Sugar Creek Technology Services, Inc."

error_reporting(E_ALL ^ E_NOTICE);

//include XML Header (as response will be in xml format)
header("Content-type: text/xml");

//encoding may be different in your case
echo('<?xml version="1.0" encoding="utf-8"?>'); 
   
include_once 'siteconfig.php';
//include_once $_SERVER['DOCUMENT_ROOT'] . 'include/debug.inc';

//mysql> desc TNnewspapers;
//+-----------------------+--------------+------+-----+------------+----------------+
//| Field                 | Type         | Null | Key | Default    | Extra          |
//+-----------------------+--------------+------+-----+------------+----------------+
//| id                    | int(10)      | NO   | PRI | NULL       | auto_increment | 
//| newspaper             | varchar(250) | NO   | MUL | NULL       |                | 
//| county                | varchar(100) | NO   | MUL | NULL       |                | 
//| seat                  | varchar(50)  | NO   |     | NULL       |                | 
//| pubdates              | varchar(500) | NO   |     | NULL       |                | 
//| deadline              | varchar(500) | NO   |     | NULL       |                | 
//| pops                  | varchar(50)  | NO   |     |            |                | 
//| door                  | varchar(120) | NO   |     |            |                | 
//| officer               | varchar(50)  | NO   |     | NULL       |                | 
//| memorial_day_info     | varchar(500) | NO   |     |            |                | 
//| independence_day_info | varchar(500) | NO   |     |            |                | 
//| labor_day_info        | varchar(500) | NO   |     |            |                | 
//| thanksgiving_day_info | varchar(500) | NO   |     |            |                | 
//| veterans_day_info     | varchar(500) | NO   |     |            |                | 
//| xmas_day_info         | varchar(500) | NO   |     |            |                | 
//| newyears_day_info     | varchar(500) | NO   |     |            |                |
//| geo                   | varchar(10)  | NO   |     | east       |                | 
//| tz                    | varchar(10)  | NO   |     | CST        |                | 
//| coords                | varchar(255) | NO   |     |            |                | 
//| independence_day      | date         | NO   |     | 2010-07-04 |                | 
//| memorial_day          | date         | NO   |     | 2010-05-31 |                | 
//| labor_day             | date         | NO   |     | 2010-09-06 |                | 
//| veterans_day          | date         | NO   |     | 2010-11-11 |                | 
//| thanksgiving_day      | date         | NO   |     | 2010-11-25 |                | 
//| xmas_day              | date         | NO   |     | 2010-12-24 |                |
//| newyears_day          | date         | NO   |     | 2010-12-24 |                | 
//+-----------------------+--------------+------+-----+------------+----------------+
//26 rows in set (0.00 sec)


if (isset($_REQUEST['action']) && $_REQUEST['action'] == 'select') {

   //start output of data
   echo '<rows id="0">';

   //output data from DB as XML
   $sql = "SELECT * FROM TNnewspapers ORDER BY county ASC";
   $res = mysql_query ($sql, $link);
		
   if ($res) {
	  while ($row = mysql_fetch_array($res)) {
 	 	 //create xml tag for grid's row
		 echo ("<row id='" . $row['id']."'>");
		 print("<cell><![CDATA[" . $row['newspaper']             . "]]></cell>");
		 print("<cell><![CDATA[" . $row['county']                . "]]></cell>");
		 print("<cell><![CDATA[" . $row['geo']                   . "]]></cell>");
		 print("<cell><![CDATA[" . $row['seat']                  . "]]></cell>");
		 print("<cell><![CDATA[" . $row['pubdates']              . "]]></cell>");
		 print("<cell><![CDATA[" . $row['deadline']              . "]]></cell>");
		 print("<cell><![CDATA[" . $row['pops']                  . "]]></cell>");
		 print("<cell><![CDATA[" . $row['door']                  . "]]></cell>");
		 print("<cell><![CDATA[" . $row['officer']               . "]]></cell>");
         print("<cell><![CDATA[" . $row['tz']                    . "]]></cell>");
		 print("<cell><![CDATA[" . $row['memorial_day_info']     . "]]></cell>");
         print("<cell><![CDATA[" . $row['independence_day_info'] . "]]></cell>");
         print("<cell><![CDATA[" . $row['labor_day_info']        . "]]></cell>");
         print("<cell><![CDATA[" . $row['thanksgiving_day_info'] . "]]></cell>");
         print("<cell><![CDATA[" . $row['veterans_day_info']     . "]]></cell>");
         print("<cell><![CDATA[" . $row['xmas_day_info']         . "]]></cell>");
         print("<cell><![CDATA[" . $row['newyears_day_info']     . "]]></cell>");
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
        $sql = sprintf("UPDATE TNnewspapers 
                        SET newspaper='%s', county='%s', geo='%s', seat='%s', pubdates='%s', deadline='%s', pops='%s', 
                            door='%s', officer='%s', tz='%s',memorial_day_info='%s', independence_day_info='%s', 
                            labor_day_info='%s', thanksgiving_day_info='%s', veterans_day_info='%s', xmas_day_info='%s', newyears_day_info='%s'
                        WHERE id=%d", 
                        mysql_real_escape_string($_GET['newspaper']), mysql_real_escape_string($_GET['county']),
                        mysql_real_escape_string($_GET['geo']), mysql_real_escape_string($_GET['seat']),
                        mysql_real_escape_string($_GET['pubdates']), mysql_real_escape_string($_GET['deadline']),
                        mysql_real_escape_string($_GET['pops']), mysql_real_escape_string($_GET['door']),
                        mysql_real_escape_string($_GET['officer']), mysql_real_escape_string($_GET['tz']),
                        mysql_real_escape_string($_GET['memorial_day_info']),mysql_real_escape_string($_GET['independence_day_info']),
                        mysql_real_escape_string($_GET['labor_day_info']),mysql_real_escape_string($_GET['thanksgiving_day_info']),
                        mysql_real_escape_string($_GET['veterans_day_info']),mysql_real_escape_string($_GET['xmas_day_info']),
                        mysql_real_escape_string($_GET['newyears_day_info']),
                        mysql_real_escape_string($rowId)
                      );
                      
        //debug_(__FILE__, __LINE__, "sql", $sql);
        
        $res = mysql_query($sql) ? 'OK' : 'NOK';
        $action = 'update';
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
