<?php
require_once (dirname(__FILE__)."/config.inc");

function pdo_myload($sql, $params, $dbhost='', $dbuser='', $dbpass='', $dbdatabase='')
{
    global $config;
    
    $a_rs = array();
	
    $dbhost = empty($dbhost) ? $config['dbhost'] : $dbhost;
    $dbuser = empty($dbuser) ? $config['dbuser'] : $dbuser;
    $dbpass = empty($dbpass) ? $config['dbpass'] : $dbpass;
    $dbdatabase = empty($dbdatabase) ? $config['dbdatabase'] : $dbdatabase;

    // maybe handle this in application global exception handler (set_exception_handler())
    try {  
	   // persistance might cause issues in 5.4 and up
	   $dbh = new PDO('mysql:host='.$dbhost.';dbname='.$dbdatabase, $dbuser, $dbpass, array(PDO::ATTR_PERSISTENT => false));
       
       // use prepare to avoid SQL injection attempts
       $stmt = $dbh->prepare($sql);   /* format = SELECT * FROM table WHERE name = ? */
       if ($stmt->execute($params)) {
		  while ($row = $stmt->fetch()) {
             $a_rs[] = $row;
          }
       }

	   // and now we're done; close it
       $dbh = null;
    } catch (PDOException $e) {
	   return array(-1, "Connect failed: %s\n", $e->getMessage());
	}
	return $a_rs;
}


// Old way of connecting to MySQL
function myload($sql, $dbhost='', $dbuser='', $dbpass='', $dbdatabase='')
{
    global $config;
    
    $a_rs = array();
	
    $dbhost = empty($dbhost) ? $config['dbhost'] : $dbhost;
    $dbuser = empty($dbuser) ? $config['dbuser'] : $dbuser;
    $dbpass = empty($dbpass) ? $config['dbpass'] : $dbpass;
    $dbdatabase = empty($dbdatabase) ? $config['dbdatabase'] : $dbdatabase;

    $dbconnection = mysql_connect($dbhost,$dbuser,$dbpass);
                                    
    if (!$dbconnection) {
      return array(-1, "Connect failed: %s\n", mysql_connect_error());
    }
    $db = mysql_select_db($dbdatabase, $dbconnection);
    if (!$db) {
	   return array(-1, "could NOT select database");
	}
	
    if ($rs = mysql_query($sql, $dbconnection)) {
	   if (mysql_num_rows($rs)) {
		  while ($r = mysql_fetch_assoc($rs)) {
             $a_rs[] = $r;
          }
	   } else {
		  $a_rs = NULL;
	   }
	   mysql_close( $dbconnection );
	} else {
	   return array(-1,"<pre>".mysql_error($dbconnection)."\n\n".$sql."</pre>");
	}
	return $a_rs;
}


?>
