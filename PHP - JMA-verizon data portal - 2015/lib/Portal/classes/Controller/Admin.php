<?php

Namespace Portal\Controller;

class Admin {

   public static function admin_login_controller() {
       global $app,$template;
       // This variable will be used to re-display the user's username to them in the
       // login form if they fail to enter the correct password.  It is initialized here
       // to an empty value.
       $submitted_username = '';

       // Here we initialize the message variable to null, which is checked for to see if
       // there was an error in logging in.
       $message = array();
       //$message['messageUserFail'] = null;
       //$message['messagePWFail'] = null;
       //$message['messageLoginFail'] = null;
       //$message['messageRecoverFail'] = null;

       // This if statement checks to determine whether the login form has been submitted
       // If it has, then the login code is run, otherwise the form is displayed ready to receive input.
       if (!empty($_POST['login-submit'])) {
           // This variable tells us whether the user has successfully logged in or not.
           // We initialize it to false, assuming they have not.
           // If we determine that they have entered the right details, then we switch it to true.
           $login_ok = false;
   
           // creates a pwd_fail variable to log if the password was not correct
           $pwd_fail = false;
           // Retrieve the user data from the database.  If $row is false, then the username
           // they entered was not found in the database.
           $row = \ORM::for_table('users')->where('username', $_POST['username'])->find_one();
   
           if ($row) {
               $row_obj = $row;
               $row = $row->as_array();
               // makes sure their account has been activated.
               if ($row['activated'] == true){
                   // Using the password submitted by the user and the salt stored in the database,
                   // we now check to see whether the passwords match by hashing the submitted password
                   // and comparing it to the hashed version already stored in the database.
                   $check_password = hash('sha256', $_POST['password'] . $row['salt']);
                   for($round = 0; $round < 65536; $round++) {
                       $check_password = hash('sha256', $check_password . $row['salt']);
                   }
   
                   if($check_password === $row['password']) {
                       // If they their entered pw matches the database, then we flip this to true, signifying they can log in.
                       $login_ok = true;
                   }
                   else {
                       // sets the variable so that we know the user failed the password check.
                       $pwd_fail = true;
                   }
               } else {
                   $message['messageLoginFail'] = "Your account is not active.";
               }
           }
           // If the user logged in successfully, then we send them to the private members-only page
           // Otherwise, we display a login failed message and show the login form again
           if ($login_ok) {
               // Here I am preparing to store the $row array into the $_SESSION by
               // removing the salt and password values from it. There is no reason to
               // store sensitive values in it unless you have to.  Thus, it is best
               // practice to remove these sensitive values first.
               unset($row['salt']);
               unset($row['password']);

               // Show date & time in a human readable format
               $last_login_yr = date('Y', strtotime($row['login_time']));
               // If last_login = 0, the year value will be negative on conversion
               if ($last_login_yr < 0) {
                   // let's not display zero's or odd strings
                   $row['login_time'] = 'Unknown';
               } else {
                   $row['login_time'] = date('M j, Y g:i A T', strtotime($row['login_time']));
               }
               $row_obj->set_expr('login_time', 'now()');
               $row_obj->save();

               // This stores the user's data into the session at the index 'user'.
               // We will check this index on the private members-only page to determine whether
               // or not the user is logged in.  We can also use it to retrieve
               // the user's details.
               $_SESSION['user'] = $row;
               $_SESSION['newuser'] = new User($row['id']);
               $_SESSION['role'] = $_SESSION['newuser']->getRole();

               // Redirect the user to the original page they requested.
               if ($_GET['req']) {
                   $app->redirect($_GET['req']);
               } else {
                   // if they didn't request anything intially, just send them to the index page. 
                   $app->redirect('/node/browser');
               }
           } else {
               // Show them their username again so all they have to do is enter a new
               // password.  The use of htmlentities prevents XSS attacks.  You should
               // always use htmlentities on user submitted values before displaying them
               // to any users (including the user that submitted them).  For more information:
               // http://en.wikipedia.org/wiki/XSS_attack
               $submitted_username = htmlentities($_POST['username'], ENT_QUOTES, 'UTF-8');
   
               if (!$row) {
                   // there would only be no row if the username they entered was not found.
                   $message['messageUserFail'] = "Username not found";
               }
               else if ($pwd_fail) {
                   // checks to see if the password they entered did not work.
                   $message['messagePWFail'] = "Invalid password";
               }
               else if (!defined($_POST['message'])) {
                   // sets the login failed message for any other case, this should never show.
                   $message['messageLoginFail'] = "Login failed";
               }
           }
       }
   
       if (!empty($_POST['recover-submit'])) {
           $user = \ORM::for_table('users')->where('email', $_POST['email'])->find_one();
   
           if ($user) {
               if ($user->activated == true) {
                   // Generate the new salt.
                   $salt = gen_salt();
   
                   // The temp password is kept non-hashed so it can be sent to the user in the verifcation email.
                   $temp_password = rand(10000000,99999999);
   
                   // We then hash the password temp password.
                   $password = hash('sha256', $temp_password . $salt);
   
                   // And again another 65536 times.
                   for($round = 0; $round < 65536; $round++)
                   {
                       $password = hash('sha256', $password . $salt);
                   }

                   $user->password = $password;
                   $user->salt = $salt;
                   $user->save();
   
                   // Once the user entry is updated, we can send them the email with the temporary password.
                   $mail = new \PHPMailer;
   
                   $mail->IsSMTP();
                   $mail->Host = SMTP_HOST;
                   $mail->SMTPAuth = SMTP_AUTH;
                   $mail->Username = SMTP_USERNAME;
                   $mail->Password = SMTP_PASSWORD;
                   $mail->SMTPSecure = SMTP_ENCRYPTION;
                   $mail->From = SMTP_FROM;
   
                   $mail->FromName = APP_NAME;
                   $mail->AddAddress($_POST['email']);
                   $mail->IsHTML(true);                                  // Set email format to HTML
                   $mail->Subject = APP_NAME . ' Password Recovery';
   
                   // This is the body for HTML capable email clients
                   $mail->Body = $template->render('email/password-recovery.html.twig', array('username' => $user->username, 'temp_password' => $temp_password));
   
                   // This is the alternate body for those without HTML capable email clients.
                   $mail->AltBody = $template->render('email/password-recovery.text.twig', array('username' => $user->username, 'temp_password' => $temp_password));
   
                   // This tries to send the message and generates an error if it couldn't be sent.
                   if (!$mail->Send()) {
                       $message['messageRecoverFail'] = 'Message could not be sent. Mailer Error: ' . $mail->ErrorInfo;
                   }
                   $message['messageRecoverFail'] = "A temporary password has been sent to: " . $_POST['email'];
               } else {
                   $message['messageRecoverFail'] = "We cannot send you a temporary password because your account has not been activated.";
               }
           } else {
               $message['messageRecoverFail'] = "The email you entered for password recovery could not be found.";
           }
       }
   
       print $template->render('html/admin/login.html.twig', $message);
   
   }
   
   
   public static function admin_logout_controller() {
     global $app;
     unset($_SESSION['user']);
     $app->redirect('/admin/login');
   } // logout_controller


   public static function admin_verify_controller($email,$hash) {
      global $template;
      $message = array();

      $user = \ORM::for_table('users')->where(array('email' => $email,'hash' => $hash))->find_one();
      if (!empty($user)) {
          $user->activated = true;
          $user->save();
      } else {
          $message = 'User with email address '.$email.' not found. Nothing to verify or activate.';
      }
      unset($_SESSION['user']);
      print $template->render('html/admin/verify.html.twig', array('message' => $message));

   }


   public static function admin_profile_controller() {

       global $template;
       $error = false;
       $password = null;
       $salt = null;
       $message = '';
       $image_avail = 0;
       $userData = array();
       $userObjs = array();

       // This if statement checks to determine whether the edit form has been submitted
       // If it has, then the account updating code is run, otherwise the form is displayed
       if (!empty($_POST)) {

            // This will check to see if they changed their email, and if so, will make sure the new email they
            // entered is not already in the database.
            if (($_POST['email'] != $_SESSION['user']['email']) && (filter_var($_POST['email'], FILTER_VALIDATE_EMAIL))) {

                $email_taken = \ORM::for_table('users')->where('email', $_POST['email'])->count();

                if ($email_taken != 0) {
                    $message = 'This email is already in use.';
                    $error = true;
                } else {
                    $user = \ORM::for_table('users')->find_one($_SESSION['user']['id']);

                    $user->email = $_POST['email'];
                    $_SESSION['user']['email'] = $_POST['email'];
                    $user->save();
                }
            }

            // If the user entered a new password, we need to hash it and generate a fresh salt for good measure.
            if (!empty($_POST['npassword']) && !empty($_POST['cpassword']) && $error == false) {

                // the user entered a new password and a confirmation password
                // only change password if the correct current password is entered
                $row = \ORM::for_table('users')->find_one($_SESSION['user']['id']);

                $check_password = hash('sha256', $_POST['password'] . $row['salt']);
                for($round = 0; $round < 65536; $round++) {
                    $check_password = hash('sha256', $check_password . $row['salt']);
                }

                if ($check_password === $row['password']) {
                    // this creates a new salt and hashed password of their entry.
                    $salt = gen_salt();
                    $password = hash('sha256', $_POST['npassword'] . $salt);
                    for($round = 0; $round < 65536; $round++) {
                        $password = hash('sha256', $password . $salt);
                    }

                    $user = \ORM::for_table('users')->find_one($_SESSION['user']['id']);

                    $user->password = $password;
                    $user->salt = $salt;
                    $user->save();

                } else {
                    $message = 'Incorrect current password specified.';
                    $error = true;
                }

            }

            $row = \ORM::for_table('users')->find_one($_SESSION['user']['id']);

            $row->workphone = $_POST['workphone'];
            $row->cellphone = $_POST['cellphone'];
            $row->Address1 = $_POST['Address1'];
            $row->Address2 = $_POST['Address2'];
            $row->City = $_POST['City'];
            $row->State = $_POST['State'];
            $row->Zip = $_POST['Zip'];
            $row->FullName = $_POST['firstname'].' '.$_POST['lastname'];
            $row->firstname = $_POST['firstname'];
            $row->lastname = $_POST['lastname'];
            $row->username = $_POST['username'];
            $row->usergroup = $_POST['usergroup'];

            $row->save();

            // This checks if one of the previous statements returned an error message to print to the user.
            // If there was an error, they stay on the same page, and don't redirect
            // If there is no error, they return to the index page.
            if (!$error) {
                $message = 'Your profile has been updated successfully.';
            }
       } else {
            $message = isset($_SESSION['user']['message']) ? $_SESSION['user']['message'] : '';
            $_SESSION['user']['message'] = '';
       }

       $row = \ORM::for_table('users')->find_one($_SESSION['user']['id']);
       $image_avail = $row['image'] ? $row['image'] : 0;
       $userObj = new User($_SESSION['user']['id']);
       $userData = $row;
       $userData['role'] = $userObj->getRole();

       $groups = array();
       $groupList = \ORM::for_table('user_groups')->raw_query('select group_name from user_groups order by group_id asc')->find_many();
       foreach($groupList as $group) {
           $groups[] = $group->group_name;
       }

       $roleList = new Role();
       $roles = $roleList->fetchAllRoles();

       $businessRules = \ORM::for_table('business_rules')->raw_query('select rule_key, rule_value from business_rules order by id asc')->find_many();
       foreach($businessRules as $rule) {
           $_SESSION['rules'][$rule->rule_key] = $rule->rule_value;
       }

       print $template->render('html/admin/manage-users/profile.html.twig', array('user' => $userData, 'message' => $message, 'image' => $image_avail, 'groups' => $groups, 'roles' => $roles));
   }

   public static function admin_manage_users_controller () {
     global $template;

     if (isJMA()) {

         $messages = array();

         $messages[] = isset($_SESSION['user']['message']) ? $_SESSION['user']['message'] : '';
         $messages[] = isset($_SESSION['user']['message2']) ? $_SESSION['user']['message2'] : '';
         $_SESSION['user']['message2'] = '';
         $_SESSION['user']['message'] = '';

         $users = array();
         $userList = \ORM::for_table('users')->order_by_asc('username')->find_array();
         foreach ($userList as $user) {
             $userObjs = array();
             $userData = $user;
             $userObj = new User($user['id']);
             // Can't use this because our version of Twig can't handle hashes (associated arrays)
             // We need the current user role
             //array_push($userObjs, $userObj);
             $userData['role'] = $userObj->getRole();
             // Again, because Twig limitations, I need to flag non-jma-email address + JMA role
             // get all JMA_Admin allowed email addresses, comma separated list
             $allowed_jma_admin = explode(',', $_SESSION['rules']['JMA_Admin']);
             foreach ($allowed_jma_admin as $allowed_email) {
                $regex_non_case = '/'.$allowed_email.'/i';
                if (! preg_match($regex_non_case, $user['email']) ) {
                    $userData['flag'] = 1;
                }
             }
             array_push($users, $userData);
         }
         $roleList = new Role();
         $roles = $roleList->fetchAllRoles();

         $groups = array();
         $groupList = \ORM::for_table('user_groups')->raw_query('select group_name from user_groups order by group_id asc')->find_many();
         foreach($groupList as $group) {
             $groups[] = $group->group_name;
         }
         print $template->render('html/admin/manage-users.html.twig', array('users' => $users, 'roles' => $roles, 'groups' => $groups, 'messages' => $messages ));
     }
   }
}


// Some of the implemented methods/functions are not used as they're handled by datatables.js
// The following Role and User classes are based on these links
// http://www.sitepoint.com/role-based-access-control-in-php/
// https://www.sevvlor.com/post/2014/10/14/how-to-make-role-based-access-control-in-php/
class Role
{
    protected $permissionList;

    public function __construct()
    {
       $this->permissionList = array();
    }

    //Create populate Role Object
    public static function getRolePermissions($role_id)
    {
        $role = new Role(); //Create new role object

        //Prepate statement and execute it
        $results = \ORM::for_table('role_permission')->raw_query('SELECT permissions.permission_description FROM role_permission
                                                                  JOIN permissions ON role_permission.permission_id = permissions.permission_id
                                                                  WHERE role_permission.role_id = :role_id', array('role_id' => $role_id)
                                                                )->find_many();

        //Loop through the results
        foreach($results as $perm) {
            $role->permissionList[$perm->permission_description] = true;
        }
        return $role;
    }

    // Check if the specific role has a given permission
    // Not used
    public function hasPermission($permission)
    {
        return isset($this->permissionList[$permission]);
    }

    // insert a new role
    // Not used
    public static function insertRole($role_name) {
        $role = \ORM::for_table('roles')->create();
        $role->role_name = $role_name;
        $role->role_description = $role_name;
        $role->save();
        return array('role_name', $role_name);
    }

    // insert array of roles for specified user id
    // Not used
    public static function insertUserRoles($user_id, $roles) {
        $role = \ORM::for_table('user_role')->create();
        $role->user_id = $user_id;
        foreach ($roles as $role_id) {
            $role->role_id = $role_id;
            $role->save();
        }
        return true;
    }

    // delete array of roles, and all associations
    // Not used
    public static function deleteRoles($roles) {
        foreach($roles as $role_id) {
            $role = \ORM::for_table('roles')->rawjoin('JOIN user_role as t2 on roles.role_id = t2.role_id
                                                      JOIN role_perm as t3 on roles.role_id = t3.role_id
                                                      WHERE roles.role_id = :role_id', array('role_id', $role_id));
        }
        return true;
    }

    // delete ALL roles for specified user id
    // Not used
    public static function deleteUserRoles($user_id) {
        $role = \ORM::for_table('user_role')->where_equal('user_id', $user_id)->delete_many();
        return $role;
    }

    public function fetchAllRoles()
    {
        $roleList = \ORM::for_table('roles')->raw_query('select role_name from roles order by role_id asc')->find_many();
        foreach($roleList as $role) {
            $this->permissionList[] = $role->role_name;
        }
        return $this->permissionList;
    }

    public function fetchAllPerms()
    {
        $permList = \ORM::for_table('permissions')->raw_query('select permission_description from permissions order by permission_id asc')->find_many();
        foreach($permList as $perm) {
            $this->permissionList[] = $perm->permission_description;
        }
        return $this->permissionList;
    }

    public function fetchPermId($perm)
    {
        $fetchPerm = \ORM::for_table('permissions')->raw_query('SELECT permission_id from permissions
                                                                WHERE permission_description = :perm', array('perm' => $perm)
                                                              )->find_one();
        return isset($fetchPerm->permission_id) ? $fetchPerm->permission_id : 0;
    }

    public function fetchRoleId($role)
    {
        $fetchRole = \ORM::for_table('roles')->raw_query('SELECT role_id from roles
                                                          WHERE role_name = :role', array('role' => $role)
                                                        )->find_one();
        return isset($fetchRole->role_id) ? $fetchRole->role_id : 0;
    }

}

class User
{
    private $userRole = array();
    private $rolePermissions;   /* Role object */

    //Populate the user object when it's created
    public function __construct($user_id)
    {
        $getUser = \ORM::for_table('users')->where('id', $user_id)->find_one();
        if(!empty($getUser)) {
            $this->user_id = $user_id;
            $this->username = $getUser->username;
            $this->email = $getUser->email;
            $this->activated = $getUser->activated;
            $this->usergroup = $getUser->usergroup;
            $this->firstname = $getUser->firstname;
            $this->lastname = $getUser->lastname;
            $this->FullName = $getUser->FullName;
            $this->Address1 = $getUser->Address1;
            $this->Address2 = $getUser->Address2;
            $this->City = $getUser->City;
            $this->State = $getUser->State;
            $this->Zip = $getUser->Zip;
            $this->workphone = $getUser->workphone;
            $this->cellphone = $getUser->cellphone;
            $this->image = $getUser->image;
            $this->fetchRole(); //Initiate the userroles
            $this->fetchPermissions();
        }
    }

    public static function getByUsername($username) {
        $getUser = \ORM::for_table('users')->where('username', $username)->find_one();

        if (!empty($getUser)) {
            $User = new User();
            $User->user_id = $getUser->user_id;
            $User->username = $username;
            $User->password = $getUser->password;
            $User->email = $getUser->email;
            $User->usergroup = $getUser->usergroup;
            $User->firstname = $getUser->firstname;
            $User->lastname = $getUser->lastname;
            $User->FullName = $getUser->FullName;
            $User->Address1 = $getUser->Address1;
            $User->Address2 = $getUser->Address2;
            $User->City = $getUser->City;
            $User->State = $getUser->State;
            $User->Zip = $getUser->Zip;
            $User->workphone = $getUser->workphone;
            $User->cellphone = $getUser->cellphone;
            $User->image = $getUser->image;
            $User->fetchRole();
            $User->fetchPermissions();
            return $User;
        } else {
            return false;
        }
    }

    protected function fetchRole()
    {
        $fetchRole = \ORM::for_table('user_role')->raw_query('SELECT user_role.role_id, roles.role_name from user_role
                                                              JOIN roles ON user_role.role_id = roles.role_id
                                                              WHERE user_role.user_id = :user_id', array('user_id' => $this->user_id)
                                                             )->find_one();
        if (!empty($fetchRole->role_name)) {
            $this->userRole['role'] = $fetchRole->role_name;
            $this->userRole['role_id'] = $fetchRole->role_id;
        } else {
            // If role is not in the database, default to least-perm 'RO' and get the corresponding Id
            // Don't want to hardcode anything, so get its name and value
            $leastPermRole = \ORM::for_table('roles')->raw_query('select role_id, role_name from roles where role_id = (select min(role_id) from roles)')->find_one();

            if (isset($leastPermRole)) {
                $this->userRole['role'] = $leastPermRole->role_name;
                $this->userRole['role_id'] = $leastPermRole->role_id;

                // update user_role table
                $userRole = \ORM::for_table('user_role')->create();
                $userRole->set( array('user_id' => $this->user_id, 'role_id' => $leastPermRole->role_id) );
                $userRole->save();

            } // the else part should never be true
        }
    }

    protected function fetchPermissions()
    {
        $this->rolePermissions = Role::getRolePermissions($this->userRole['role_id']);
    }

    // Check if the user has a certain permission
    // Not used
    public function hasPermission($permission)
    {
        //If the user has more roles, check them too
        foreach ($this->rolePermissions as $role) {
            //Do the actual checking
            if ($role->hasPermission($permission)) {
                return true;
            }
        }
        return false;
    }

    protected function fetchRoleId($role)
    {
        $fetchRole = \ORM::for_table('roles')->raw_query('SELECT role_id from roles
                                                          WHERE role_name = :role', array('role' => $role)
                                                        )->find_one();
        if (!empty($fetchRole->role_name)) {
            $this->userRole['role'] = $fetchRole->role_name;
            $this->userRole['role_id'] = $fetchRole->role_id;
        } else {
            $this->userRole['role'] = 'RO';
            $this->userRole['role_id'] = $fetchRole->role_id;
        }
    }

    public function hasRole($role)
    {
        return $this->userRole['role'] == $role ? true : false;
    }

    public function getRole()
    {
        return $this->userRole['role'];
    }

    public function getRoleId()
    {
        return $this->userRole['role_id'];
    }

}