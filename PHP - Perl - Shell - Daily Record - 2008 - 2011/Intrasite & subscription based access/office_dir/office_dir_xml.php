<?php
//<meta name="author" content="Marcel Quirijnen">
//<meta name="company" content="Little Sugar Creek Technology Services, Inc."

error_reporting(E_ALL ^ E_NOTICE);

//include XML Header (as response will be in xml format)
header("Content-type: text/xml");

//encoding may be different in your case
echo('<?xml version="1.0" encoding="utf-8"?>'); 

include_once 'siteconfig.php';

//include_once $_SERVER['DOCUMENT_ROOT'] . '/include/debug.inc';
//init_debug_file();

   $mode = $_GET["!nativeeditor_status"]; //get request mode
   //debug_(__FILE__, __LINE__, "mode55", $mode);
   //$mode = $_REQUEST["!nativeeditor_status"];
   //debug_(__FILE__, __LINE__, "mode255", $mode);


if (isset($_REQUEST['action']) && $_REQUEST['action'] == 'select') {

   //start output of data
   echo '<rows id="0">';

   //output data from DB as XML
   $sql = "SELECT id, name, spouse, address, zip, city, state, date_format(bdate,'%m/%d/%Y') as bdate, phone, cell, eighthundred, fax, ext, email, company
           FROM office_dir 
           ORDER BY id ASC";
   $res = mysql_query ($sql, $link);
   
   //debug_(__FILE__, __LINE__, "SELECT sql", $sql);
		
   if ($res) {
      
      //debug_(__FILE__, __LINE__, "we might have results (incl. empty list)\n");
      
	  while ($row = mysql_fetch_array($res)) {
 	 	 //create xml tag for grid's row
 	 	 //debug_(__FILE__, __LINE__, "name", $row['name']);
		 echo ("<row id='"       . $row['id']      . "'>");
		 print("<cell><![CDATA[" . $row['name']    . "]]></cell>");
		 print("<cell><![CDATA[" . $row['spouse']  . "]]></cell>");
		 print("<cell><![CDATA[" . $row['address'] . "]]></cell>");
		 print("<cell><![CDATA[" . $row['zip']     . "]]></cell>");
		 print("<cell><![CDATA[" . $row['city']    . "]]></cell>");
		 print("<cell><![CDATA[" . $row['state']   . "]]></cell>");
		 print("<cell><![CDATA[" . $row['bdate']   . "]]></cell>");
		 print("<cell><![CDATA[" . $row['phone']   . "]]></cell>");
		 print("<cell><![CDATA[" . $row['cell']    . "]]></cell>");
		 print("<cell><![CDATA[" . $row['eighthundred']    . "]]></cell>");
         print("<cell><![CDATA[" . $row['fax']     . "]]></cell>");
		 print("<cell><![CDATA[" . $row['ext']     . "]]></cell>");
         print("<cell><![CDATA[" . $row['email']   . "]]></cell>");
         print("<cell><![CDATA[" . $row['company'] . "]]></cell>");
		 print("</row>");
	  }
   } else {
      //debug_(__FILE__, __LINE__, "error " . mysql_errno());
      //error occurs
	  echo mysql_errno() . ": " . mysql_error() . " at " . __LINE__ . " line in " . __FILE__ . " file<br>";
   }
   echo '</rows>';

} elseif (isset($_REQUEST['action']) && $_REQUEST['action'] == 'update') {
   
   $mode = $_GET["!nativeeditor_status"]; //get request mode
   //debug_(__FILE__, __LINE__, "mode", $mode);
   //$mode = $_REQUEST["!nativeeditor_status"];
   //debug_(__FILE__, __LINE__, "mode2", $mode);
   $rowId = $_REQUEST['gr_id'];
   
   //debug_(__FILE__, __LINE__, "rowId", $rowId);
   //debug_(__FILE__, __LINE__, "mode", $mode);
   
   switch($mode){
	 case "inserted":
		//row adding request, not used for now
        $sql = sprintf("INSERT INTO office_dir VALUES (null, '%s','%s','%s','%s','%s','%s',str_to_date('%s','%s'),'%s','%s','%s','%s','%s','%s','%s')",
                        mysql_real_escape_string($_GET['name']), mysql_real_escape_string($_GET['spouse']),
                        mysql_real_escape_string($_GET['address']), mysql_real_escape_string($_GET['zip']),
                        mysql_real_escape_string($_GET['city']), mysql_real_escape_string($_GET['state']),
                        mysql_real_escape_string($_GET['bdate']), '%m/%d/%Y',
                        mysql_real_escape_string($_GET['phone']), mysql_real_escape_string($_GET['cell']),
                        mysql_real_escape_string($_GET['eighthundred']), mysql_real_escape_string($_GET['fax']),
                        mysql_real_escape_string($_GET['ext']),mysql_real_escape_string($_GET['email']),
                        mysql_real_escape_string($_GET['company'])
                      );
        //debug_(__FILE__, __LINE__, "INSERT sql", $sql);
	    $res = mysql_query($sql);
	    //set value to use in response
	    $rowId = mysql_insert_id();
	    //debug_(__FILE__, __LINE__, "inserted id : ", $rowId);
		$action='insert';
	    break;
	 case "deleted":
		//row deleting request, not used for now
		//debug_(__FILE__, __LINE__, "deleting ", $rowId);
		$sql = sprintf("DELETE FROM office_dir WHERE id=%d", mysql_real_escape_string($rowId));
		//debug_(__FILE__, __LINE__, "DELETE sql ", $sql);
	    $res = mysql_query($sql);
		$action='delete';
	    break;
	 default:
		//row updating request
        $sql = sprintf("UPDATE office_dir 
                        SET name='%s', spouse='%s', address='%s', zip='%s', city='%s', state='%s', bdate=str_to_date('%s','%s'),  
                            phone='%s', cell='%s', eighthundred='%s', fax='%s',ext='%s', email='%s', company='%s' 
                        WHERE id=%d", 
                        mysql_real_escape_string($_GET['name']), mysql_real_escape_string($_GET['spouse']),
                        mysql_real_escape_string($_GET['address']), mysql_real_escape_string($_GET['zip']),
                        mysql_real_escape_string($_GET['city']), mysql_real_escape_string($_GET['state']),
                        mysql_real_escape_string($_GET['bdate']), '%m/%d/%Y',
                        mysql_real_escape_string($_GET['phone']), mysql_real_escape_string($_GET['cell']),
                        mysql_real_escape_string($_GET['eighthundred']), mysql_real_escape_string($_GET['fax']),
                        mysql_real_escape_string($_GET['ext']),mysql_real_escape_string($_GET['email']),
                        mysql_real_escape_string($_GET['company']), mysql_real_escape_string($rowId)
                      );
                      
        //debug_(__FILE__, __LINE__, "UPDATE sql", $sql);
        
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
