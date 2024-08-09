<?php

$SPLIT       = '/usr/bin/tiffsplit';
$UPLOAD_PATH = '/array/DRdata/splits/';
$FCOUNTER = 0;

$KEY_FILE = '/var/www/.ssh/id_dsa';
$SCP      = '/usr/bin/scp';
$BGADD    = '/array/home/docindexer/di/bgadd';


function split_tif($temp_name, $file_name, $prefix)
{
   global $SPLIT, $UPLOAD_PATH, $SPLIT_DIR;

   echo "Split : upload path=", $UPLOAD_PATH, '<br>';
   $split_upload = preg_split("/\//", $temp_name);
   $split_file = preg_split("/_/", $file_name);
   $split_dir = preg_split("/\./", $split_file[ count($split_file) - 1 ]);

   $new_dir = $UPLOAD_PATH . $split_dir[0];
   $new_file = $split_file[ count($split_file)-1 ];
   echo "Split : new_dir =" . $new_dir . " &nbsp;&nbsp;&nbsp;&nbsp; new_file =" . $new_file . "<br>";

   shell_exec('/bin/mkdir ' . $new_dir);
   $SPLIT_DIR = $new_dir;
   sleep(1);
   if ($_REQUEST['whole'] == 'folder') {
   	echo "Copying whole folder <br>";
      shell_exec("/bin/cp $UPLOAD_PATH$temp_name $new_dir/$new_file");
   } else {
      echo "copying just 1 file " . $new_dir . " to " . $new_file . "<br>";
      move_uploaded_file($temp_name, $new_dir . '/' . $new_file);
   }

   echo 'cd ' . $new_dir . ' && ' . $SPLIT . ' ' . $new_file . ' ' . $prefix . ' 2>/dev/null' . "<br>";
   $err = shell_exec('cd ' . $new_dir . ' && ' . $SPLIT . ' ' . $new_file . ' ' . $prefix . ' 2>/dev/null'); 
   if ($err) {
   	echo "Error occured<br>";
   } else {
   	echo "Calling rename_tif<br>";
   }
   	
   $err = rename_tif($new_dir, $new_file, $prefix);

   # remove original file in subfolder
   if ($_REQUEST['whole'] == 'folder') {
      shell_exec("/bin/rm -f $new_dir/$new_file");
   }
       
   return $err;
}


function rename_tif($dir, $orig_file, $prefix)
{
   global $FCOUNTER;
   if ($handle = opendir($dir)) {
      $justfilename = preg_split("/\./", $orig_file);
      $adj_filename = str_pad($justfilename[0], 5, "0", STR_PAD_LEFT);
      $FCOUNTER = 0;
      $regexp = "/$adj_filename/";
      while (false !== ($tif_file = readdir($handle))) {
         if ($tif_file != "." && $tif_file != ".." && 
             $tif_file != '.DS_Store' && $tif_file != '.AppleDouble' && $tif_file != 'Thumbs.db' && 
             $tif_file != $orig_file && !preg_match($regexp, $tif_file)) {
            #$justfilename_prefix = preg_split("/\./", $tif_file);
            #$limitlength = substr($justfilename_prefix[0], -4);
            $FCOUNTER++;
            echo $FCOUNTER . '   ' . 'cd ' . $dir . ' && ' . '/bin/mv ' . $tif_file . '     ' . $adj_filename . '.' . $limitlength . '.tif'. '<br><br>';
            echo $FCOUNTER . '   ' . '/bin/mv ' . $tif_file . '     ' . $adj_filename . '.' . $tif_file . '<br><br>';
            $err = shell_exec('/bin/mv ' . $dir . '/' . $tif_file . ' ' . $dir . '/' . $adj_filename . '.' . $tif_file);
         }
      }
      closedir($handle);
   } 
   return $err;
}

function process_dir($dir, $bgadd, $recursive = FALSE) 
{
    if (is_dir($dir)) {
       echo "Reading " . $dir . "\n";
       for ($list = array(), $handle = opendir($dir); (FALSE !== ($file = readdir($handle)));) {
           if (($file != '.' && $file != '..' && 
                $file != '.AppleDouble' && $file != '.DS_Store' && $file != 'Thumbs.db') && 
               (file_exists($path = $dir.'/'.$file))) {
              echo $file . "\n"; 
              if (is_dir($path) && ($recursive)) {
                 $list = array_merge($list, process_dir($path, TRUE));
              } else {
                 echo $file . "\n";
                 #$entry = array('filename' => $file, 'dirpath' => $dir);
                 do if (!is_dir($path)) {
                    $path_parts = pathinfo($path);
                    $regexp = '/^[A-Za-z0-9]+\.[a-z]+\.tif/';
                    if (preg_match($regexp, $path_parts['basename'], $matches)) {
                       $scp_file = $path_parts['dirname'] . '/' . $matches[0];
                       echo $SCP . ' -i ' . $KEY_FILE . ' ' . $scp_file . ' www-data@xx.xx.xx.xx:' . $BGADD . '/' . $bgadd . "<br>";
                       $err = shell_exec($SCP . ' -i ' . $KEY_FILE . ' ' . $scp_file . ' www-data@xx.xx.xx.xx:' . $BGADD . '/' . $bgadd);
                    }
                 } while (FALSE);     
              }
           }
       }
       closedir($handle);
       print_r($list);
       return $list;
    }
    return FALSE;
}

if (isset($_REQUEST['action'])) {
   echo "action=", $_REQUEST['action'], '<br>';
   if ($_REQUEST['whole'] == 'folder') {
      echo "whole folder=", $_REQUEST['whole'], '<br>';
      $UPLOAD_PATH = $_REQUEST['folder'];
      if (substr($UPLOAD_PATH, -1) != "/") {
         $UPLOAD_PATH .= '/';
      }
      echo 'Upload path = ', $UPLOAD_PATH, '<br>';
      echo 'folder = ', $_REQUEST['folder'], '<br>';
      if ($handle = opendir($_REQUEST['folder'])) {
         while (false !== ($tif_file = readdir($handle))) {
            if ($tif_file != "." && $tif_file != ".." && 
                $tif_file != '.DS_Store' && $tif_file != '.AppleDouble' && $tif_file != 'Thumbs.db' && $tif_file != 'bat.ini') {
               echo 'File = ', $tif_file, '<br>';
               $err = split_tif($tif_file, $tif_file, $_REQUEST['prefix']);
            }
         }
         closedir($handle);
      }
   } else {
      $err = split_tif($_FILES['tfile']['tmp_name'], $_FILES['tfile']['name'], $_REQUEST['prefix']);
   }

   if ($err) {
      echo $err . '<br>';
   } else {
      if (isset($_REQUEST['bgadd'])) {
         $result = process_dir($UPLOAD_PATH, $_REQUEST['bgadd'], TRUE);
         # $result contains full path splitted filenames or nothing on error. Originals are filtered out of the list.
      }
      echo ' !! Splitting finished succesful !!<br><br>';
      echo 'Uploaded file (original) : ' . $UPLOAD_PATH . $_FILES['tfile']['name'] . '<br>';
      echo 'Splits moved to :' . $UPLOAD_PATH  . '*<br>';
      echo 'File split into ' . $FCOUNTER . ' pages<br>';
      if (isset($_REQUEST['bgadd'])) {
         echo '<br>Result files (should :-) have been copied to bgadd/'. $_REQUEST['bgadd'] . '<br>';
      }
      #$err = shell_exec('/bin/mv ./' . $_REQUEST['prefix'] . '* ' . $UPLOAD_PATH);
      echo '--------------------------------------<br><pre>' . $err . '</pre><br>'; 
   }
   #echo "<meta http-equiv=refresh content='15;url=" . $_SERVER['PHP_SELF'] . "'>";
} else {
?>
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" "http://www.w3.org/TR/html4/loose.dtd">
<html>
<head>
<title> split tifs </title>
</head>
<body onload="document.getElementById('submit').disabled=true;">
<script LANGUAGE="JavaScript">  


function PresetVals(x)
{
   var y = document.getElementById(x).value;
   // directory part of full pathname
   var ext = y.split('.');
   var ourFiletypes = new Array('tif', 'tiff', 'TIF', 'TIFF');

   // remove blanks from filename
   ext[0] = ext[0].replace(/ /g, ""); 
   for (var x = 0; x < ourFiletypes.length; x++) { 
      if (ourFiletypes[x] == ext[1]) {
         document.getElementById('submit').disabled=false;
      }
   } 
}

</script>

<div align="left">

<form action="<?php echo $_SERVER['PHP_SELF']; ?>" method="post" name="selectfile" id="selectfile" enctype="multipart/form-data">
   <input type="hidden" id="action" name="action" value="split">
   <input type="hidden" id="datadir" name="datadir" value="">
   <input type="hidden" name="whole" id="whole" value="file">
   <table border="1">
   <tr>
     <td>Split file:</td>
     <td><input type="file" name="tfile" size="60" id="tfile" onChange="PresetVals(this.id);"></td>
   </tr>
   <tr>
     <td>Whole folder:&nbsp;</td>
     <td><input type="checkbox" name="whole" id="whole" value="folder"> &nbsp; 
     <input type="text" name="folder" id="folder" value="/array/DRdata/splits" size="30"></td>
   </tr>
   <tr>
     <td>Prefix it with:&nbsp;</td>
     <td>&nbsp;<input type="text" name="prefix" id="prefix" value="a" size="5"></td>
   </tr>
   <tr>
     <td>Move to BgAdd on docindexer:&nbsp;</td>
     <td>&nbsp;<input type="text" name="bgadd" id="bgadd" value="" size="25"> (something like <b>pulaski/123456789-2)</b></td>
   </tr>
   <tr><td colspan="2" align="center"><input type="submit" id="submit" name="submit" value=".: Split It :."></td></tr>
   </table>
</form>

</div>

</body>
</html>
<?
}
?>
