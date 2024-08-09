<?php

Namespace Portal\Controller;

class AdminManageUser {

   public static function admin_manageusr_toggle_admin_controller($user_id) {
       if (isJMA()) {
           global $app;

           $user = \ORM::for_table('users')->find_one($user_id);
           if ($user->admin) {
               $user->set('admin', 0);
           } else {
               $user->set('admin', 1);
           }
           $user->save();
           $app->response->redirect('/admin/manage-users/', 303);
       }
   }
   
   public static function admin_manageusr_toggle_activated_controller($user_id) {

       if (isJMA()) {
           global $app;

           $user = \ORM::for_table('users')->find_one($user_id);
           if ($user->activated) {
               $user->set('activated', 0);
           } else {
               $user->set('activated', 1);
           }
           $user->save();
           $app->response->redirect('/admin/manage-users/', 303);
       }
   }
   
   public static function admin_manageusr_del_controller($user_id) {
       if (isJMA()) {
           global $app;

           // Can't remove a user with role relations still in place
           $user_role = \ORM::for_table('user_role')->where_equal('user_id', $user_id)->find_one();
           if (!empty($user_role)) {
               $user_role->delete();
           }

           $user = \ORM::for_table('users')->where_equal('id', $user_id)->find_one();
           if (!empty($user)) {
               $user->delete();
           }

           $app->response->redirect('/admin/manage-users/', 303);
       }
   }
   
  public static function admin_manageusr_new_controller() {

       if (isJMA()) {

           // Variable definition
           global $template, $app;

           $message = ""; // $message is used to present an error back to the user if they fail verification before POSTing
           $message2 = ""; // $message2 presents the user with notifications after the form has been POSTed
           $submitted_username = "";
           $submitted_email = "";
           $roles = array();

           if (!empty($_POST)) {

               // we might get here clicking 'save' on the new user screen and not having filled out any data.
               // -> do nothing and go back to user management
               if ($_POST['manage-users'] == 'empty') {

                   global $app;
                   $app->response->redirect('/admin/manage-users/', 303);

               } else {

                   // we need to retrieve the inserted values from the page.
                   $submitted_username = htmlentities($_POST['username'], ENT_QUOTES, 'UTF-8');
                   $submitted_email = htmlentities($_POST['email'], ENT_QUOTES, 'UTF-8');

                   // check if the user is supposed to be an admin or not:
                   //$submitted_admin = isset($_POST['isadmin']) ? true : false;

                   // Ensure that the user has entered a non-empty username
                   if (empty($_POST['username'])) {
                       $message = "Please enter a username.";
                   }

                   // Make sure the user entered a valid E-Mail address
                   // filter_var is a useful PHP function for validating form input, see:
                   // http://us.php.net/manual/en/function.filter-var.php
                   // http://us.php.net/manual/en/filter.filters.php
                   else if (!filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
                       $message = "Invalid E-Mail address.";
                   } // If all fields were entered, we start validation:
                   else {
                       $user_taken = \ORM::for_table('users')->where('username', $_POST['username'])->count();

                       // If a row was returned, then we know a matching username was found in
                       // the database already and we should not allow the user to continue.
                       if ($user_taken != 0) {
                           $message = "This username is already is use";
                       }
                       // Now we perform the same type of check for the email address, in order
                       // to ensure that it is unique.
                       else {
                           $email_taken = \ORM::for_table('users')->where('email', $_POST['email'])->count();
                           if ($email_taken != 0) {
                               $message = "This email address is already registered";
                           }
                           // If the email address is unique, we have passed all checks, and can start inserting the new user
                           // and send them the registration email.
                           else {
                               // A salt is randomly generated here to protect again brute force attacks
                               // and rainbow table attacks.  The following statement generates a hex
                               // representation of an 8 byte salt.  Representing this in hex provides
                               // no additional security, but makes it easier for humans to read.
                               // For more information:
                               // http://en.wikipedia.org/wiki/Salt_%28cryptography%29
                               // http://en.wikipedia.org/wiki/Brute-force_attack
                               // http://en.wikipedia.org/wiki/Rainbow_table
                               $salt = gen_salt();

                               // This hashes the password with the salt so that it can be stored securely
                               // in your database.  The output of this next statement is a 64 byte hex
                               // string representing the 32 byte sha256 hash of the password.  The original
                               // password cannot be recovered from the hash.  For more information:
                               // http://en.wikipedia.org/wiki/Cryptographic_hash_function
                               // The temp password is kept non-hashed so it can be sent to the user in the verifcation email.
                               $temp_password = rand(10000000, 99999999);
                               $password = hash('sha256', $temp_password . $salt);

                               // Next we hash the hash value 65536 more times.  The purpose of this is to
                               // protect against brute force attacks.  Now an attacker must compute the hash 65537
                               // times for each guess they make against a password, whereas if the password
                               // were hashed only once the attacker would have been able to make 65537 different
                               // guesses in the same amount of time instead of only one.
                               for ($round = 0; $round < 65536; $round++) {
                                   $password = hash('sha256', $password . $salt);
                               }

                               // This creates the 32 character unique hash value that is given to the user in the verification
                               // email that they'll use to activate their account.
                               $hash = md5(rand(0, 1000));

                               $new_user = \ORM::for_table('users')->create();
                               $new_user->activated = false;
                               $new_user->username = $_POST['username'];
                               $new_user->firstname = $_POST['firstname'];
                               $new_user->lastname = $_POST['lastname'];
                               $new_user->FullName = $_POST['firstname'] . ' ' . $_POST['lastname']; // not sure if we use this, so I leave it in
                               $new_user->workphone = $_POST['workphone'];
                               $new_user->cellphone = $_POST['cellphone'];
                               $new_user->usergroup = $_POST['usergroup'];
                               $new_user->Address1 = $_POST['Address1'];
                               $new_user->Address2 = $_POST['Address2'];
                               $new_user->City = $_POST['City'];
                               $new_user->Zip = $_POST['Zip'];
                               $new_user->State = $_POST['State'];
//                               $new_user->login_time = '0';
                               $new_user->password = $password;
                               $new_user->salt = $salt;
                               $new_user->email = $_POST['email'];
                               //$new_user->admin = $submitted_admin;
                               $new_user->hash = $hash;

                               $new_user->save();

                               // MQ : this should be done in the User class
                               $newid = $new_user->id();
                               if (isset($newid)) {
                                   $new_user_role = \ORM::for_table('user_role')->create();
                                   $new_user_role->set('user_id', $newid);
                                   $message = '';

                                   if (mysql_real_escape_string($_POST['role']) == 'JMA-Admin') {
                                       // get all JMA_Admin allowed email addresses, comma separated list
                                       $allowed_jma_admin = explode(',', $_SESSION['rules']['JMA_Admin']);
                                       foreach ($allowed_jma_admin as $allowed_email) {
                                           $regex_non_case = '/' . $allowed_email . '/i';
                                           if (!preg_match($regex_non_case, mysql_real_escape_string($_POST['email']))) {
                                               $message = "User created successfully, BUT this email address is not allowed the JMA-Admin role.";
                                               $message2 = 'Update user role in the below User Management screen.';
                                           }
                                       }
                                   }

                                   if (!strlen($message)) {

                                       $new_role_id = \ORM::for_table('roles')->raw_query('select role_id from roles where role_name=:role', array('role' => $_POST['role']))->find_one();
                                       if (!empty($new_role_id)) {
                                           $new_user_role->set('role_id', $new_role_id->role_id);
                                           $new_user_role->save();
                                       }

                                       // If the user was added into the user table successfully, we can now build the
                                       // activation email to send to them.
                                       $mail = new \PHPMailer;

                                       $mail->IsSMTP();
                                       $mail->Host = SMTP_HOST;
                                       $mail->SMTPAuth = SMTP_AUTH;
                                       $mail->Username = SMTP_USERNAME;
                                       $mail->Password = SMTP_PASSWORD;
                                       $mail->SMTPSecure = SMTP_ENCRYPTION;

                                       //$mail->SMTPDebug = 4;

                                       $mail->From = SMTP_FROM;
                                       $mail->FromName = APP_NAME;
                                       $mail->AddAddress($_POST['email']);
                                       $mail->IsHTML(true);
                                       $mail->Subject = APP_NAME . ' Account Verification';

                                       $activate_url = 'http://' . $_SERVER['SERVER_NAME'] . '/admin/verify/' . $_POST['email'] . '/' . $hash;

                                       // This is the body for HTML capable email clients
                                       $mail->Body = $template->render('email/new-user.html.twig', array('username' => $_POST['username'], 'temp_password' => $temp_password, 'activate_url' => $activate_url));

                                       // This is the alternate body for those without HTML capable email clients.
                                       $mail->AltBody = $template->render('email/new-user.text.twig', array('username' => $_POST['username'], 'temp_password' => $temp_password, 'activate_url' => $activate_url));

                                       // This tries to send the message and generates an error if it couldn't be sent.
                                       if (!$mail->Send()) {
                                           $message2 = 'Message could not be sent. Mailer Error: ' . $mail->ErrorInfo;
                                       } // if the mail does send, send them back to manage.php
                                       else {
                                           $message2 = 'A verification email and temporary password has been sent to: ' . $_POST['email'];
                                       }
                                   }
                               } else {
                                   $message = "Can't create new user record for " . $_POST['username'] . ' (id=' . $newid . ')';
                               }
                           }
                       }
                   }
               }

               // MQ : this is really a awefull construct to make this work
               if ($_POST['manage-users'] == 'data entered') {
                   global $app;

                   $_SESSION['user']['message'] = $message ? $message : '';
                   $_SESSION['user']['message2'] = $message2 ? $message2 : '';

                   $app->response->redirect('/admin/manage-users/', 303);
               }

               $roleList = new Role();
               $roles = $roleList->fetchAllRoles();

           }

           print $template->render('html/admin/manage-users/new.html.twig', array('message' => $message, 'message2' => $message2, 'roles' => $roles));
       }
  }

    public static function admin_manageusr_role_management_controller()
  {
      if (isJMA()) {
          global $template;

          $content['javascript'] = "/assets/js/tables/portal.tables.js";
          print $template->render('html/admin/manage-users/new-roles.html.twig', $content);
      }
  }

  public static function admin_manageusr_perm_management_controller()
  {
      if (isJMA()) {
          global $template;

          $content['javascript'] = "/assets/js/tables/portal.tables.js";
          print $template->render('html/admin/manage-users/new-permissions.html.twig', $content);
      }
  }

  public static function admin_manageusr_assign_management_controller()
  {
      if (isJMA()) {
          global $template;

          $someRole = new Role();
          $somePerm = new Role();

          if ($_POST) {

             extract($_POST);

             if (isset($_POST['removeperm']) && $_POST['removeperm'] == 'remove') {

                 $assignedRoleId = $someRole->FetchRoleId($_POST['role']);
                 $assignedPermId = $somePerm->FetchPermId($_POST['permission']);

                 $rolePerm = \ORM::for_table('role_permission')->raw_query('select * from role_permission where role_id=:role_id and permission_id=:perm_id', array('role_id'=>$assignedRoleId, 'perm_id'=>$assignedPermId))->find_one();
                 if (isset($rolePerm))
                     $rolePerm->Delete();

             } else {

                 $assignedRoleId = $someRole->FetchRoleId($_POST['role']);
                 $assignedPermId = $somePerm->FetchPermId($_POST['permission']);

                 // because the below does NOT work ..
                 // $role_perm = \ORM::for_table('role_permission')->raw_query('insert into role_permission (id, role_id, permission_id) values (null, :role_id, :perm_id)
                 //                                                             on duplicate key update role_id = :role_id_again',
                 //                                                            array('role_id'=>$assignedRoleId, 'perm_id'=>$assignedPermId, 'role_id_again'=>$assignedRoleId));
                 // we have to code it ourselves
                 $role_perm = \ORM::for_table('role_permission')->raw_query('select * from role_permission where role_id=:role_id and permission_id=:perm_id', array('role_id'=>$assignedRoleId, 'perm_id'=>$assignedPermId))->find_one();
                 if (empty($role_perm)) {
                     $new_role_perm = \ORM::for_table('role_permission')->create();

                     $new_role_perm->set('role_id', $assignedRoleId);
                     $new_role_perm->set('permission_id', $assignedPermId);

                     $new_role_perm->save();
                 }

             }

          }

          $roles = $someRole->fetchAllRoles();
          $perms = $somePerm->fetchAllPerms();

          print $template->render('html/admin/manage-users/assign-permissions.html.twig', array( 'roles'=>$roles, 'perms'=>$perms ));
      }
  }

    public static function admin_manageusr_change_role_controller($user_id, $role)
    {
         if (isJMA()) {
             global $app;

             $someRole = new Role();
             $roleId = $someRole->fetchRoleId($role);
             $userRole = \ORM::for_table('user_role')->raw_query('select * from user_role where user_id=:user_id',
                                                                  array('user_id'=>$user_id) )->find_one();

             if (empty($userRole)) {
                 $userRole = \ORM::for_table('user_role')->create();
             }
             $userRole->set( array('user_id' => $user_id, 'role_id' => $roleId) );
             $userRole->save();

             $app->response->redirect('/admin/manage-users/', 303);
         }
    }

}
