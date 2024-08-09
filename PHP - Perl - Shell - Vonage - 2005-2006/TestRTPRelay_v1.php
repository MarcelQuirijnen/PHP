<?php

$StartStep = 0;
$Publisher = 'xx.xx.xx.xx';
# test proxy 1
#$TestProxy_priv = 'xx.xx.xx.xx9';
#$TestProxy_pub = 'xx.xx.xx.xx';
# test proxy 2
$TestProxy_priv = 'xx.xx.xx.xx';
$TestProxy_pub = 'xx.xx.xx.xx';
$TestGateway = 'xx.xx.xx.xx';
$NOC = '....';
$NOC_PASSWD = '.....';
$CALLPROC = '....';
$CALLPROC_PASSWD='...';
$RTP_PORT = 14000;
$LOCK_FILE = '/var/www/tmp/RTPTest';
$RTP_STATUS = '/opt/rtprelay/utils/rtpstatus';
#$MAIL_LIST = 'marcel.quirijnen@vonage.com';
$MAIL_LIST = 'xxxx@vonage.com';

$TestSteps = array('Step0', 'Step1', 'Step2', 'Step3', 'Step4', 'Step5',
                   'Step6', 'Step7', 'Step8', 'Step9', 'Step10', 'Step11',
                   'Step12', 'Step13', 'Step14', 'Step15', 'Step16'
                  );

function BuildSubmitStr($Step, $Error)
{
   return "?Step=" . $Step . 
          "&File=" . $_REQUEST['File'] . 
          "&Rtp=" . $_REQUEST['Rtp'] .
          "&Email=" . $_REQUEST['Email'] .
          "&Error=" . $Error .
          "&CallID=" . $_SESSION['CALLID'] .
          "&Gateways=" . $_SESSION['Gateways'] .
          "&Phone=" . $_REQUEST['Phone'];
}

function Step0($Rtp, $File, $Error)
{
   global $LOCK_FILE;

   if (file_exists($LOCK_FILE)) {
      echo '<br>Sorry, this tool is already in use.<br>';
      $Error = 1;
   } else {
      #touch($LOCK_FILE);
      $fd = fopen($File,"w+");
      fwrite($fd, '<strong>Checking basic requirements.</strong><br><br>');
      fwrite($fd, '1. Is RTP in production (mapped) ? ..');
      fclose($fd);
   }
   $_SESSION['Gateways'] = $_REQUEST['Gateways'];
   return($Error);
}

function Step1($Rtp, $File, $Error)
{
   global $Publisher, $CALLPROC, $CALLPROC_PASSWD, $RTP_PORT;

   $db = mysql_connect($Publisher, $CALLPROC, $CALLPROC_PASSWD);
   if (!$db) {
      die("Could not connect to $Publisher: " . mysql_error());
   }
   $db_selected = mysql_select_db('xxxxxx', $db);
   if (!$db_selected){
      mysql_close($db);
      die("Could not select xxxxxx: " . mysql_error());
   }
   $_SESSION['RTP'] = $Rtp;
   $sql = "SELECT DISTINCT(RTPRelay) FROM RTPRelayMapping where RTPRelay='" . $Rtp . ':' . $RTP_PORT . "'";
   $query = mysql_query($sql, $db);
   $fd = fopen($File,"a+");
   if (mysql_num_rows($query)) {
      fwrite($fd, '. yep.<br>');
   } else {
      fwrite($fd, '. nope.<br>');
   }
   fwrite($fd, '2. Is RTP pingable ? ..');
   fclose($fd);
   mysql_close($db);
   return($Error);
}

function Step2($Rtp, $File, $Error)
{
   $fd = fopen($File,"a+");
   $PingResult = shell_exec("/bin/ping -c 2 -w 2 $Rtp");
   if (ereg("0 received", $PingResult)) {
      fwrite($fd, '. nope.<br>');
   } else {
      fwrite($fd, '. yep.<br>');
   }
   fwrite($fd, '3. Is RTP reachable ? ..');
   fclose($fd);
   return($Error);
}

function Step3($Rtp, $File, $Error)
{  
   global $NOC, $LOCK_FILE;
   $fd = fopen($File,"a+");
   fwrite($fd, '. skipped (hangs too often).<br>');
   fwrite($fd, "4. Can user '$NOC' login to the box ? ..");
   #$TracerouteResult = shell_exec("/bin/traceroute -m 4 -w 4 $Rtp");
   #$TracerouteResult = shell_exec("/bin/traceroute $Rtp");
   #if (ereg("$Rtp", $TracerouteResult)) {
   #   fwrite($fd, '. yep.<br>');
   #   fwrite($fd, "4. Can user '$NOC' login to the box ? ..");
   #} else {
   #   fwrite($fd, '. nope. Testing cannot continue.<br>');
   #   $Error = 1;
   #   unlink($LOCK_FILE);
   #}
   fclose($fd);
   return($Error);
}

function Step4($Rtp, $File, $Error)
{ 
   global $LOCK_FILE, $NOC, $NOC_PASSWD;

   $fd = fopen($File,"a+");
   $connection = ssh2_connect($Rtp);
   if (ssh2_auth_password($connection, $NOC, $NOC_PASSWD)) {
      fwrite($fd, '. yep.<br>');
      fwrite($fd, "5. Is RTP package installed on this box ? ..");
   } else {
      fwrite($fd, '. nope. Testing cannot continue.<br>');
      $Error = 1;
      unlink($LOCK_FILE);
   }
   fclose($fd);
   return($Error);
}

function Step5($Rtp, $File, $Error)
{
   global $LOCK_FILE, $NOC, $NOC_PASSWD;

   $fd = fopen($File,"a+");
   $conID = ssh2_connect($Rtp);
   if (ssh2_auth_password($conID, $NOC, $NOC_PASSWD)) {
      $stream = ssh2_exec($conID, "/bin/rpm -qa|/bin/grep rtp\n");
      stream_set_blocking($stream, true);
      $result = fread($stream, 255);
      if (ereg('rtprelay', $result)) {
         fwrite($fd, '. yep.<br>');
         fwrite($fd, "6. Is RTP process running this box ? ..");
      } else {
         fwrite($fd, '. nope.<br>');
         $Error = 1;
         unlink($LOCK_FILE);
      }
   } else {
      fwrite($fd, ". oops, can't get into the box.<br>");
      $Error = 1;
      unlink($LOCK_FILE);
   }
   fclose($fd);
   return($Error);
}

function Step6($Rtp, $File, $Error)
{
   global $LOCK_FILE, $NOC, $NOC_PASSWD;
   
   $fd = fopen($File,"a+");
   $conID = ssh2_connect($Rtp);
   if (ssh2_auth_password($conID, $NOC, $NOC_PASSWD)) {
      $stream = ssh2_exec($conID, "/bin/ps -ef|/bin/grep rtp|/bin/grep -v grep|/usr/bin/tail -1\n");
      stream_set_blocking($stream, true);
      $result = fread($stream, 2048);
      if (ereg('RTPRELAY', $result)) {
         fwrite($fd, '. yep.<br>');
         fwrite($fd, "7. RTP status results ..");
      } else {
         fwrite($fd, '. nope. Testing cannot continue.<br>');
         $Error = 1;
         unlink($LOCK_FILE);
      }
   } else {
      fwrite($fd, ". oops, can't get into the box.<br>");
      $Error = 1;
      unlink($LOCK_FILE);
   }
   fclose($fd);
   return($Error);
}

function Step7($Rtp, $File, $Error)
{
   global $LOCK_FILE, $NOC, $NOC_PASSWD, $RTP_STATUS, $RTP_PORT;

   $fd = fopen($File,"a+");
   $conID = ssh2_connect($Rtp);
   if (ssh2_auth_password($conID, $NOC, $NOC_PASSWD)) {
      $stream = ssh2_exec($conID, "$RTP_STATUS localhost $RTP_PORT\n");
      stream_set_blocking($stream, true);
      $result = fread($stream, 2048);
      fwrite($fd, ". <pre>$result</pre>");
      fwrite($fd, '<br><strong>OK. Our basic requirements are met.</strong><br>');
   } else {
      fwrite($fd, ". oops, can't get into the box.<br>");
      $Error = 1;
      unlink($LOCK_FILE);
   }
   fclose($fd);
   return($Error);
}

function Step8($Rtp, $File, $Error)
{
   global $TestProxy_pub;

   $fd = fopen($File,"a+");

   $Phone = $_REQUEST['Phone'];
   $Phone = ereg_replace("-", "", $Phone);
   if (! ereg("^1", $Phone)) {
      $Phone = '1' . $Phone;
   }
   $_SESSION['PHONE'] = $Phone;
   fwrite($fd, '<strong>Please register your phone (' . 
               $_SESSION['PHONE'] . 
               ') to Testproxy (' . 
               $TestProxy_pub . 
               ').</strong><br><br>'
         );
   fwrite($fd, '8. Backing up RTPMappings on TestProxy ..' );
   fclose($fd);
   return($Error);
}

function Step9($Rtp, $File, $Error)
{
   global $LOCK_FILE, $NOC, $NOC_PASSWD, $TestProxy_priv, $CALLPROC, $CALLPROC_PASSWD;

   $fd = fopen($File,"a+");
   $conID = ssh2_connect($TestProxy_priv);
   if (ssh2_auth_password($conID, $NOC, $NOC_PASSWD)) {
      $myfile = '/tmp/rtpmapping.php.' . getmypid();
      $_SESSION['DUMPFILE'] = $myfile;
      ssh2_exec($conID, "echo 'delete from RTPRelayMapping;' >$myfile\n");
      if (ssh2_exec($conID, "/usr/bin/mysqldump -u$CALLPROC -p$CALLPROC_PASSWD CentralDB -t RTPRelayMapping >>$myfile\n")) {
         fwrite($fd, ". successful.<br>&nbsp;&nbsp;&nbsp;Data can be found in <a href=http://$NOC:$NOC_PASSWD@$TestProxy_priv$myfile>$myfile</a><br>");
         fwrite($fd, '9. Updating RTPMappings table with given RTP ..');
      } else {
         fwrite($fd, '. oops. Something went wrong.<br>');
         $Error = 1;
         unlink($LOCK_FILE);
      }
   } else {
      fwrite($fd, ". oops, can't get into the box.<br>");
      $Error = 1;
      unlink($LOCK_FILE);
   }
   fclose($fd);
   return($Error);
}

function Step10($Rtp, $File, $Error)
{
   global $LOCK_FILE, $RTP_PORT, $TestGateway, $NOC, $NOC_PASSWD, $TestProxy_priv, $CALLPROC, $CALLPROC_PASSWD;

   $fd = fopen($File,"a+");
   $db = mysql_connect($TestProxy_priv, $CALLPROC, $CALLPROC_PASSWD);
   if (!$db) {
      fwrite($fd, "Could not connect to $TestProxy_priv: " . mysql_error());
      die("Could not connect to $TestProxy_priv: " . mysql_error());
   }
   $db_selected = mysql_select_db('CentralDB', $db);
   if (!$db_selected){
      mysql_close($db);
      fwrite($fd, "Could not select CentralDB:" . mysql_error());
      die("Could not select CentralDB: " . mysql_error());
   }  
   $sql_insert = "REPLACE INTO RTPRelayMapping VALUES('" . $Rtp . ':' . $RTP_PORT . "', '" . $TestGateway . "', 10)";
   #fwrite($fd, '<br>' . $sql_insert . '<br>');
   if (mysql_query($sql_insert)) {
      $sql_delete = "DELETE FROM RTPRelayMapping WHERE RTPRelay <> '" . $Rtp . ':' . $RTP_PORT . "'";
      #fwrite($fd, '<br>' . $sql_delete . '<br>');
      if (mysql_query($sql_delete)) {
         fwrite($fd, '. Great!<br>&nbsp;&nbsp;&nbsp;RTPRelayMappings table is updated. Please make test call.<br>');
      } else {
         fwrite($fd, ". oops. Could not delete previous RTP info (' . $sql_delete . ') from table " . mysql_error() . '<br>');
         $Error = 99;
      }
   } else {
      fwrite($fd, ". oops. Could not insert RTP info (' . $sql_insert . ') in table " . mysql_error() . '<br>');
      $Error = 1;
      unlink($LOCK_FILE);
   }

   fclose($fd);
   mysql_close($db);
   return($Error);
}

function Step11($Rtp, $File, $Error)
{
   $fd = fopen($File,"a+");
   fwrite($fd, '10. Retrieving Call-ID from RTP ..');
   fclose($fd);
?>    
   <br>
   <FORM action="<?php echo $_SERVER['PHP_SELF']; ?>" method="post">
      <INPUT Type="hidden" Name="Rtp" Size=15 Value="<?php echo $Rtp?>" />
      <INPUT Type="hidden" Name="Phone" Size=15 Value="<?php echo $_REQUEST['Phone']?>" />
      <INPUT Type="hidden" Name="Email" Size=20 Value="<?php echo $_REQUEST['Email']?>" />
      <INPUT type="hidden" name="File" Value="<?php echo $File?>" />
      <INPUT type="hidden" name="Step" Value="12" />
      <INPUT type="hidden" name="Error" Value="0" />
      <INPUT type="hidden" name="CallID" Value="0" />
      <INPUT Type="SUBMIT" Value=" :: Test call has been made. :: " />
   </FORM>
<?php
   return(-11);
}

function Step12($Rtp, $File, $Error)
{
   global $NOC, $NOC_PASSWD, $TestProxy_pub, $TestProxy_priv;
   $results = array();

   $cmd = "/opt/configs/getCallIDfromRTP.pl -rtp $Rtp -call $TestProxy_pub";
   $CallID = exec($cmd, $results, $rc);

   $fd = fopen($File,"a+");

   if ($CallID && ! $rc) {
      fwrite($fd, '. OK. <br>&nbsp;&nbsp;&nbsp;<strong>' . $CallID . '</strong><br>');
      $_SESSION['CALLID'] = $CallID;
      fwrite($fd, "11. Retrieving SIP trace from proxy ($TestProxy_pub / $TestProxy_priv) ..");
   } else {
      fwrite($fd, '. oops. Something went wrong : return code = ' . $rc . '<br>');
      $Error = 99;
   }
   fclose($fd);
   return($Error);
}  

function Step13($Rtp, $File, $Error)
{
   global $TestProxy_pub, $TestProxy_priv;
   $results = array();

   $fd = fopen($File,"a+");
   $cmd = "/opt/configs/getSIPTracefromTest.pl -rtp $Rtp -proxy $TestProxy_priv -callid " . $_SESSION['CALLID'];
   if (strlen($_REQUEST['Email'])) {
      $cmd .= ' -email ' . $_REQUEST['Email'];
   }
   $SIPTrace = exec($cmd, $results, $rc);

   if (! $rc) {
      fwrite($fd, '. OK. <br>');
      fwrite($fd, '&nbsp;&nbsp;&nbsp;&nbsp;Test completed successful.<br>');
      #foreach ($results as $sipLine) {
      #   fwrite($fd, $sipLine . '<br>');
      #}
   } else {
      fwrite($fd, '. oops. Something went wrong : return code = ' . $rc . '<br>');
      $Error = 99;
   }
   fclose($fd);
   return($Error);
}

function Step14($Rtp, $File, $Error)
{
   global $MAIL_LIST; 

   $fd = fopen($File,"a+");
   fwrite($fd, '12. TestProxy values are being restored from ' . $_SESSION['DUMPFILE'] . ' ..');
   fclose($fd);
   return($Error);
}

function Step15($Rtp, $File, $Error)
{
   global $RTP_PORT, $LOCK_FILE, $NOC, $NOC_PASSWD, $TestProxy_priv, $CALLPROC, $CALLPROC_PASSWD;

   $fd = fopen($File,"a+");
   $conID = ssh2_connect($TestProxy_priv);
   if (ssh2_auth_password($conID, $NOC, $NOC_PASSWD)) {
      $myfile = $_SESSION['DUMPFILE'];
      if (ssh2_exec($conID, "/usr/bin/mysql -u$CALLPROC -p$CALLPROC_PASSWD CentralDB < $myfile\n")) {
         fwrite($fd, ". successful.<br>");
         if ($Error != 99) {
            fwrite($fd, '<br><strong>RTP ' . $Rtp . ' is ready for production</strong><br><br>');
            if (isset($_REQUEST['Gateways'])) {
               fwrite($fd, 'Execute the following SQL statements to map the RTPs :<br>');
               $gws = preg_split('/[\s,]+/', $_REQUEST['Gateways']);
               foreach ($gws as $gw) {
                  $sql_insert = "INSERT INTO RTPRelayMapping VALUES('$Rtp:$RTP_PORT', '$gw', 10)";
                  fwrite($fd, $sql_insert . '<br>');
               }
               fwrite($fd, '<br><small><i>Please ignore awkward chars introduced by Exchange/Outlook</i></small>');
               fwrite($fd, '<br><small><i>The attachement contains the proper statements.</i></small><br><br>');
               fwrite($fd, '13. Test results are being emailed to ' . $_REQUEST['Email'] . '<br>');
            } else {
               fwrite($fd, 'No gateways-to-be-mapped specified.<br>');
            }
         }
      } else {
         fwrite($fd, '. oops. Something went wrong.<br>');
         $Error = 1;
      }
   } else {
      fwrite($fd, ". oops, can't get into the box.<br>");
      $Error = 1;
   }
   fclose($fd);
   return($Error);
}

function Step16($Rtp, $File, $Error)
{
   global $LOCK_FILE, $MAIL_LIST;
   require("class.phpmailer.php");

   $fd = fopen($File,"a+");
   fwrite($fd, 'Done.<br>');
   fclose($fd);
   $subject = "RTP ($Rtp) test results";
   $results = file_get_contents($File);

   if (isset($_REQUEST['Email'])) {
      $Email = $_REQUEST['Email'];
   } else {
      $Email = $MAIL_LIST;
   }

   $mail = new PHPMailer();
   $mail->From = "RTPtester@xxx.vonage.net";
   $mail->FromName = "Mr. RTP Tester";
   $mail->AddAddress($Email);
   $mail->AddReplyTo("xxx@vonage.com", "Production Call Processing");
   $mail->AddAttachment($File, "$File.html");
   $mail->IsHTML(true);

   $mail->Subject = $subject;
   $mail->Body    = $results;
   $mail->AltBody = "Test results are attached.";

   if(!$mail->Send()) {
      echo "Message could not be sent. Error: " . $mail->ErrorInfo;
      exit;
   }

   unlink($File);
   unlink($LOCK_FILE);
   return(0);
}


##################
# Start of code
##################
if (isset($_REQUEST['File']) && ! $_REQUEST['Error'] && $_REQUEST['Step'] < sizeof($TestSteps)) {
   if (empty($_REQUEST['Rtp']) || empty($_REQUEST['Phone'])) {
      echo '<br>Yo, dude/dudette .. wake up.<br>You did not enter any test information.<br>';
   } else {
      session_start();
      $File = $_REQUEST['File'];
      if (!file_exists($File)) {
         touch ($File);
      } else {
         echo file_get_contents($File);
      }
      $Step = $_REQUEST['Step'];

      $Error = $TestSteps[$Step]($_REQUEST['Rtp'], $File, $_REQUEST['Error']);
   
      if (! $Error) {
         $Step++;
      }
      session_write_close();
   
      if ($Error != 0 - $Step) {
         echo "<meta http-equiv=refresh content='1;url=" . $_SERVER['PHP_SELF'] . BuildSubmitStr($Step, $Error) . "'>";
      }
   }
} elseif ($_REQUEST['Error'] == 99) {
   session_start();
   $fd = fopen($_REQUEST['File'],"a+"); 
   fwrite($fd, 'TestProxy values are being restored from ' . $_SESSION['DUMPFILE'] . ' ..');
   fclose($fd);

   $Error = $TestSteps[15]($_REQUEST['Rtp'], $File, $_REQUEST['Error']);

   session_write_close();

   echo "<meta http-equiv=refresh content='1;url=" . $_SERVER['PHP_SELF'] . BuildSubmitStr(sizeof($TestSteps)+1, 1) . "'>";
   unlink($LOCK_FILE);
   unlink ($File);

} else {
   if (isset($_REQUEST['File']) && file_exists($_REQUEST['File'])) {
      echo file_get_contents($_REQUEST['File']);
   }
?>
  <center><H1>.:: Call Proc RTP Test Tool ::.</H1></center><small>* : Mandatory</small><br><br><br>
  <FORM action="<?php echo $_SERVER['PHP_SELF']; ?>" method="post">
     * RTP Relay to test (public IP) : <INPUT Type="TEXT" Name="Rtp" Size=15 /><BR><BR>
     * Call from phone no : <INPUT Type="TEXT" Name="Phone" Size=15 /> (regular phone number format 123-456-7890)<BR><br>
     Send results to : <INPUT Type="TEXT" Name="Email" Size=20 /> (your email address for instance)<BR><br>
     Gateways to be mapped : <TEXTAREA Name='Gateways' ROWS='4' COLS='15'></TEXTAREA>&nbsp; (separate with blank or comma, newline or ENTER-key does NOT count as separator)<br><br>
     <INPUT type="hidden" name="File" Value="/tmp/<?php echo 'rtptest'. getmypid()?>" />
     <INPUT type="hidden" name="Step" Value="<?php echo $StartStep?>" />
     <INPUT type="hidden" name="Error" Value="0" />
     <INPUT type="hidden" name="CallID" Value="0" />
     <INPUT Type="SUBMIT" Value=" .:: Test da RTP ::. " />
  </FORM>
<?php
   $filename = '/tmp/rtptest'.getmypid();
   if (file_exists($filename)) {
      unlink ($filename);
   }
}
?>
