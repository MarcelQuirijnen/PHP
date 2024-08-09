<?php

Namespace Portal\Controller;

class Misc {

   public static function misc_upload_profile_image_controller() {
       global $app;

       // again, $_SESSION['rules'][] is not available here or is being cleared out
       //$target_dir = $_SERVER['DOCUMENT_ROOT'] . $_SESSION['rules']['ProfileImageUpload'];
       //$allowed_ext = explode(',', $_SESSION['rules']['ProfileImageExt']);  //jpg,png,jpeg,tif,tiff
       //$allowed_size = $_SESSION['rules']['ProfileImageSize'];  //2Mb

       $businessRule = \ORM::for_table('business_rules')->raw_query("select rule_value from business_rules where rule_key ='ProfileImageUpload'")->find_one();
       $target_dir = $_SERVER['DOCUMENT_ROOT'] . $businessRule['rule_value'];
       $businessRule = \ORM::for_table('business_rules')->raw_query("select rule_value from business_rules where rule_key ='ProfileImageSize'")->find_one();
       $allowed_size = $businessRule['rule_value'];
       $businessRule = \ORM::for_table('business_rules')->raw_query("select rule_value from business_rules where rule_key ='ProfileImageExt'")->find_one();
       $allowed_ext = explode(',', $businessRule['rule_value']);

       $message = '';
       extract($_POST);

       if ($_POST['action'] == 'upload') {

           $imageFileType = pathinfo($_FILES["fileToUpload"]["name"], PATHINFO_EXTENSION);
           $target_file = $_SESSION['user']['id'] . '.' . $imageFileType;   // user_id.<WhateverFileExtWeUpload>

           // Check for errors - if this is true -> must be windoz box
           if ($_FILES['fileToUpload']['error'] > 0) {
               $message = 'An error ocurred while uploading.';
           }

           // Is this an image ?
           if (!getimagesize($_FILES['fileToUpload']['tmp_name'])) {
               $message = 'Please ensure you are uploading an image.';
           }

           // Check filetype
           if (!in_array($imageFileType, $allowed_ext)) {
               $message = 'Unsupported filetype uploaded.';
           }

           // Check filesize
           if ($_FILES['fileToUpload']['size'] > $allowed_size) {
               $message = 'File upload exceeds maximum upload size.';
           }

           // files exists
           if (file_exists($target_dir . $target_file)) {
               // we allow overwrite, not sure if system allows it .. remove to be on the sure side
               unlink($target_dir . $target_file);
           }

           // Upload file
           if (!move_uploaded_file($_FILES['fileToUpload']['tmp_name'], $target_dir . $target_file)) {
               $message = 'Error uploading file - check destination is writeable.';
           } else {
               $message = 'Profile image uploaded successfully.';
               $user = \ORM::for_table('users')->find_one($_SESSION['user']['id']);
               $user->image = $target_file;
               $user->save();
           }
       } else {

           // action = delete
           $row = \ORM::for_table('users')->find_one($_SESSION['user']['id']);
           $image = $row['image'];
           $row->image = '';
           $row->save();
           unlink($target_dir.$image);
           $message = 'Profile picture deleted successfully.';

       }

       $_SESSION['user']['message'] = $message;
       $app->response->redirect('/admin/profile', 303);
   }

   public static function misc_sitemap_controller() {
       global $template,$app;
       $content = array();
       
       $sitemap_file = VZP_LIB . '/Portal/Sitemap/sitemap.xml';
       if (file_exists($sitemap_file)) {
          $xml = simplexml_load_file($sitemap_file);   
          foreach ($xml->url as $value) {
             $content['locs'][] = $value->loc;
          }
       }
       $content['date'] = date("F j, Y, g:i a");
       print $template->render('html/sitemap.html.twig', $content);
   }

   public static function misc_notfound_controller() {
       global $template,$app;
       $app->response->setStatus(404);
       print $template->render('html/404.html.twig');
   }
   
   public static function misc_debug_controller() {
       global $template;
       //check_admin();
   
       $content = array();
   
       $tmp = \ORM::for_table('users')->where('username', 'bholtsclaw')->find_array();
       $row = $tmp[0];
       $content['output'] .= print_r($row, true);
   
       $person = \ORM::for_table('users')->where('username', 'bholtsclaw')->find_one();
       $row = $person->as_array();
       $content['output'] .= print_r($row, true);
   
       $person = \ORM::for_table('users')->where('username', 'bholtsclaw')->find_one()->as_array();
       $content['output'] .= print_r($person, true);
   
       ob_start();
       echo array_keys($GLOBALS);
       $content['output'] .= ob_get_contents();
       ob_end_clean();
   
       print $template->render('html/debug.html.twig', $content);
   }
   
   public static function misc_download_controller($loc, $filename, $temp_name="" ) {
     // Allow direct file download (hotlinking)?
     // Empty - allow hotlinking
     // If set to nonempty value (Example: example.com) will only allow downloads when referrer contains this text
     define('ALLOWED_REFERRER', '');
   
     // Download folder, i.e. folder where you keep all files for download.
     // MUST end with slash (i.e. "/" )
     if ($loc=='t'){
       define('BASE_DIR','/temp/' . $_SESSION['user']['username']);
     } else if ($loc=='s') {
       define('BASE_DIR',VZP_DIR . '/files/templates');
     } else if ($loc=='i') {
       define('BASE_DIR', VZP_FILES . '/node/'. $_SESSION['siteName'] .'/ispec');
     } else if ($loc=='m') {
       define('BASE_DIR', VZP_FILES . '/node/'. $_SESSION['siteName'] .'/mops');
     } else if ($loc=='l') {
       define('BASE_DIR', VZP_LOG);
     } else if ($loc=='e') {
       define('BASE_DIR', VZP_TMP);
     }
   
   
     // log downloads?  true/false
     define('LOG_DOWNLOADS',true);
   
     // log file name
     define('LOG_FILE', VZP_LOG . '/downloads.log');
   
     // Allowed extensions list in format 'extension' => 'mime type'
     // If myme type is set to empty string then script will try to detect mime type
     // itself, which would only work if you have Mimetype or Fileinfo extensions
     // installed on server.
     $allowed_ext = array (
   
       // archives
       'zip' => 'application/zip',
   
       // documents
       'pdf' => 'application/pdf',
       'doc' => 'application/msword',
       'xls' => 'application/vnd.ms-excel',
       'ppt' => 'application/vnd.ms-powerpoint',
       'txt' => 'text/plain',
       'log' => 'text/plain',
   
       // Newer office doctypes
       "xlsx" => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
       "xltx" => 'application/vnd.openxmlformats-officedocument.spreadsheetml.template',
       "potx" => 'application/vnd.openxmlformats-officedocument.presentationml.template',
       "ppsx" => 'application/vnd.openxmlformats-officedocument.presentationml.slideshow',
       "pptx" => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
       "sldx" => 'application/vnd.openxmlformats-officedocument.presentationml.slide',
       "docx" => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
       "dotx" => 'application/vnd.openxmlformats-officedocument.wordprocessingml.template',
       "xlam" => 'application/vnd.ms-excel.addin.macroEnabled.12',
       "xlsb" => 'application/vnd.ms-excel.sheet.binary.macroEnabled.12',
   
       // executables
       'exe' => 'application/octet-stream',
   
       // images
       'gif' => 'image/gif',
       'png' => 'image/png',
       'jpg' => 'image/jpeg',
       'jpeg' => 'image/jpeg',
   
       // audio
       'mp3' => 'audio/mpeg',
       'wav' => 'audio/x-wav',
   
       // video
       'mpeg' => 'video/mpeg',
       'mpg' => 'video/mpeg',
       'mpe' => 'video/mpeg',
       'mov' => 'video/quicktime',
       'avi' => 'video/x-msvideo'
     );
   
     ####################################################################
     ###  DO NOT CHANGE BELOW
     ####################################################################
   
     // If hotlinking not allowed then make hackers think there are some server problems
     if (ALLOWED_REFERRER !== ''
     && (!isset($_SERVER['HTTP_REFERER']) || strpos(strtoupper($_SERVER['HTTP_REFERER']),strtoupper(ALLOWED_REFERRER)) === false)
     ) {
       die("Internal server error. Please contact system administrator.");
     }
   
     // Make sure program execution doesn't time out
     // Set maximum script execution time in seconds (0 means no limit)
     set_time_limit(0);
   
     if (!isset($filename) || empty($filename)) {
       die("Please specify file name for download.");
     }
   
     // Nullbyte hack fix
     if (strpos($filename, "\0") !== FALSE) die('');
   
     // Get real file name.
     // Remove any path info to avoid hacking by adding relative path, etc.
     $fname = basename($filename);
   
     // Check if the file exists
     // Check in subfolders too
     function find_file ($dirname, $fname, &$file_path) {
   
       $dir = opendir($dirname);
   
       while ($file = readdir($dir)) {
         if (empty($file_path) && $file != '.' && $file != '..') {
           if (is_dir($dirname.'/'.$file)) {
             find_file($dirname.'/'.$file, $fname, $file_path);
           }
           else {
             if (file_exists($dirname.'/'.$fname)) {
               $file_path = $dirname.'/'.$fname;
               return;
             }
           }
         }
       }
     } // find_file
   
     // get full file path (including subfolders)
     $file_path = '';
     find_file(BASE_DIR, $fname, $file_path);
     //echo BASE_DIR . "<br />";
     //echo $fname . "<br />";
     //echo $file_path . "<br />";
     if (!is_file($file_path)) {
       die("File does not exist. Make sure you specified correct file name.");
     }
   
     // file size in bytes
     $fsize = filesize($file_path);
   
     // file extension
     $fext = strtolower(end(explode(".",$fname)));
     if (empty($fext)) {
       $fext = "";
     }
     //echo var_dump($fext) . "<br />";
     // check if allowed extension
     if (!array_key_exists($fext, $allowed_ext)) {
       // die("Not allowed file type.");
     }
   
     // get mime type
     // if ($allowed_ext[$fext] == '') {
       $mtype = '';
       // mime type is not set, get from server settings
       if (function_exists('mime_content_type')) {
         $mtype = mime_content_type($file_path);
       }
       else if (function_exists('finfo_file')) {
         $finfo = finfo_open(FILEINFO_MIME); // return mime type
         $mtype = finfo_file($finfo, $file_path);
         finfo_close($finfo);
       }
       if ($mtype == '') {
         $mtype = "application/force-download";
       }
     // }
     // else {
     //   // get mime type defined by admin
     //   $mtype = $allowed_ext[$fext];
     // }
   
     // Browser will try to save file with this filename, regardless original filename.
     // You can override it if needed.
   
     if (!isset($_GET['fc']) || empty($_GET['fc'])) {
       $asfname = $fname;
     }
     else {
       // remove some bad chars
       $asfname = str_replace(array('"',"'",'\\','/'), '', $_GET['fc']);
       if ($asfname === '') $asfname = 'NoName';
     }
     if ($temp_name) {
       $asfname = $temp_name;
     }
     // set headers
     header("Pragma: public");
     header("Expires: 0");
     header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
     header("Cache-Control: public");
     header("Content-Description: File Transfer");
     header("Content-Type: $mtype");
     header("Content-Disposition: attachment; filename=\"$asfname\"");
     header("Content-Transfer-Encoding: binary");
     header("Content-Length: " . $fsize);
   
     // download
     print file_get_contents($file_path);
   
     // @readfile($file_path);
     // $file = @fopen($file_path,"rb");
     // if ($file) {
     //   while(!feof($file)) {
     //     print(fread($file, 1024*8));
     //     flush();
     //     if (connection_status()!=0) {
     //       @fclose($file);
     //       die();
     //     }
     //   }
     //   @fclose($file);
     // }
   
     // log downloads
     if (!LOG_DOWNLOADS) die();
   
     $f = @fopen(LOG_FILE, 'a+');
     if ($f) {
       @fputs($f, date("Y-m-d G:i e")." | ".$_SERVER['REMOTE_ADDR']." | ".$_SESSION['user']['username']." | ".$asfname."\n");
       @fclose($f);
     }
   } // misc_download_controller
   
   
   public static function misc_upload_controller() {
   
       switch ($_POST['upload-type']) {
           case 'site-diagram':
               //process_upload_diagram();
               if ( $_FILES['DiagramUpload']['error'] == UPLOAD_ERR_OK ) {
                   $diagram_location = VZP_FILES . '/node/' . lower($_POST['sitename']) . '/diagram.png';
           
                   if (!move_uploaded_file($_FILES['DiagramUpload']['tmp_name'], $diagram_location)) {
                       echo "Cannot move " . $_FILES['DiagramUpload']['tmp_name'] . " to " . $diagram_location ."\n";
                   } else { // Log the action
                       $log_file = fopen(VZP_LOG . '/'.$_POST['sitename'].'_diagram_audit.log', 'a+');
                       fputs($log_file, date("Y-m-d G:i e")." | ".$_SERVER['REMOTE_ADDR']." | ".$_SESSION['user']['username']." replaced Site " . $_POST['sitename'] . " Diagram \n");
                   }
               } else {
                   echo $_FILES['DiagramUpload']['error'];
               }
               break;
           case 'site-photo':
               //process_upload_photo();
               if ( $_FILES['PhotoUpload']['error'] == UPLOAD_ERR_OK ) {
                   $copy_dir = VZP_FILES . '/node/' . $_POST['sitename'] . '/photos/full/';
                   $thumb_dir = VZP_FILES . '/node/' . $_POST['sitename'] . '/photos/thumb/';
           
                   if (!move_uploaded_file($_FILES['PhotoUpload']['tmp_name'], $copy_dir . $_FILES['PhotoUpload']['name'])) {
                       echo "File: ". $_FILES['PhotoUpload']['name'] ." failed to be moved properly.";
                   } else {
                       // Create thumbnail of newly uploaded photo.
                       $image = new \Portal\ImageResize($copy_dir . $_FILES['PhotoUpload']['name']);
                       $image->resizeToWidth(260);
                       $image->save($thumb_dir . $_FILES['PhotoUpload']['name']);
           
                       // Log the action to the photo audit log.
                       $log_file = fopen(VZP_LOG . '/'.$_POST['sitename'].'_photo_audit.log', 'a+');
                       fputs($log_file, date("Y-m-d G:i e")." | ".$_SERVER['REMOTE_ADDR']." | ".$_SESSION['user']['username']." added new photo '".$_FILES['PhotoUpload']['name']. "'\n");
                   }
               } else {
                   echo $_FILES['PhotoUpload']['error'];
               }
               break;
           default:
               echo "Unknown Upload Type";
               exit;
       }
   
       echo "success";
   } // misc_upload_controller
   
   /*
    *   Upload Processing Functions
    */
   public static function process_upload_diagram() {
       if ( $_FILES['DiagramUpload']['error'] == UPLOAD_ERR_OK ) {
           $diagram_location = VZP_FILES . '/node/' . lower($_POST['sitename']) . '/diagram.png';
   
           if (!move_uploaded_file($_FILES['DiagramUpload']['tmp_name'], $diagram_location)) {
               echo "Cannot move " . $_FILES['DiagramUpload']['tmp_name'] . " to " . $diagram_location ."\n";
           } else { // Log the action
               $log_file = fopen(VZP_LOG . '/'.$_POST['sitename'].'_diagram_audit.log', 'a+');
               fputs($log_file, date("Y-m-d G:i e")." | ".$_SERVER['REMOTE_ADDR']." | ".$_SESSION['user']['username']." replaced Site " . $_POST['sitename'] . " Diagram \n");
           }
       } else {
           echo $_FILES['DiagramUpload']['error'];
       }
   }
   
   public static function process_upload_photo() {
       if ( $_FILES['PhotoUpload']['error'] == UPLOAD_ERR_OK ) {
           $copy_dir = VZP_FILES . '/node/' . $_POST['sitename'] . '/photos/full/';
           $thumb_dir = VZP_FILES . '/node/' . $_POST['sitename'] . '/photos/thumb/';
   
           if (!move_uploaded_file($_FILES['PhotoUpload']['tmp_name'], $copy_dir . $_FILES['PhotoUpload']['name'])) {
               echo "File: ". $_FILES['PhotoUpload']['name'] ." failed to be moved properly.";
           } else {
               // Create thumbnail of newly uploaded photo.
               $image = new \Portal\ImageResize($copy_dir . $_FILES['PhotoUpload']['name']);
               $image->resizeToWidth(260);
               $image->save($thumb_dir . $_FILES['PhotoUpload']['name']);
   
               // Log the action to the photo audit log.
               $log_file = fopen(VZP_LOG . '/'.$_POST['sitename'].'_photo_audit.log', 'a+');
               fputs($log_file, date("Y-m-d G:i e")." | ".$_SERVER['REMOTE_ADDR']." | ".$_SESSION['user']['username']." added new photo '".$_FILES['PhotoUpload']['name']. "'\n");
           }
       } else {
           echo $_FILES['PhotoUpload']['error'];
       }
   }

}
