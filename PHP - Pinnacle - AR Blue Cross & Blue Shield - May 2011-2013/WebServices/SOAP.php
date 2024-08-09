<?php

// DOCUMENTATION : http://www.php.net/manual/en/book.soap.php

require_once (dirname(__FILE__)."/../includes/auth.inc");
require_once (dirname(__FILE__)."/../includes/config.inc");
require_once (dirname(__FILE__)."/../includes/dbfunctions.inc");
require_once (dirname(__FILE__).'/../includes/xml_functions.php');
require_once (dirname(__FILE__)."/../includes/status.inc.php");

ini_set('display_errors', true);
error_reporting(E_ALL);

function PWR_authenticate_user($username, $password)
{
    global $config;
    // tie this in with the current PWR authentication, once it works
    // login and password are in config file
    if (isset($username) && $username != '' && isset($password) && $password != '') {
      return ($username==$config['csw_login'] && $password==$config['csw_password']) ? true : false;
    }
    return false;
}


class SOAP
{
  private $authenticated;
  private $username;

  public function __construct()
  {
    $this->authenticated = true;
  }

  public function authenticate_user($args)
  {
    // Throw SOAP fault for invalid username and password combo
    if (! PWR_authenticate_user($args->username, $args->password)) {
      throw new SOAPFault("Incorrect username and password combination.", 401);
    }
    $this->username = $args->username; // we need this info to create a ticket
    $this->authenticated = true;
  }

  //
  // st_Name : Queue name(upto 30 chars) into which we want to crreate a ticket; always required
  // returns : ticket number as a long int
  //
  public function PWR_CreateTicket($st_Name)
  {
    if ($this->authenticated) {

      if (isset($st_Name) && $st_Name != '' && strlen($st_Name)<31) { //st_Name is varchar(30)

        // Create ticket in given queue

        $new_co_ID = create_new_corr($this->username,'',52);
        $upd_comment = 'Set initial queue (SOAP Webservice)';
        $st_ID = get_st_ID($st_Name);
        if (isset($st_ID) && isset($new_co_ID)) {
          $upd_status = set_corr_status($new_co_ID, $st_ID, $upd_comment);
        } else {
          throw new SOAPFault("Unknown PBSI POWER Queue name.", 400);  // Bad request
        }

        return $new_co_ID; // WSDL doc could be modified to specify a long int, string will do for now
        //return '<br>' . $this->username . ' : ' . "Creating ticket in " . $st_Name . ' : ' . $new_co_ID;

      } else {
        throw new SOAPFault("Must provide a valid PBSI POWER Queue name.", 400);   // Bad Request
      }

    } else {
      throw new SOAPFault("Access to PBSI POWER requires authentication.", 401);  // access denied
    }
  }

 //
  // Operation : GET, SET, LIST; always required
  // Worflow name : string(50); required for the 'LIST' request
  // Ticket : The ticket number for wich we want to get/set a control variable; required fo 'GET'/'SET' operations
  // ctrl_field : Control field name we want to operate with; required for 'GET'/'SET' operations
  // ctrl_field_value : Control Field value in case of a 'SET' operation; only required in case of a 'SET' operation
  // returns : true or false for success or failure or a hash of control fields for 'LIST' operation
  //
  public function PWR_CTRLFields($operation, $workflow_name, $ticket, $ctrl_field, $ctrl_field_val)
  {
    if ($this->authenticated) {

      if (isset($operation) && $operation != '') {

        switch ($operation) {
          case 'LIST' :
            if (isset($workflow_name) && $workflow_name != '' && strlen($workflow_name)<51) { //ct_Name is varchar(50)
              return $this->_LIST_PWR_CTRLFields($workflow_name);
            } else {
              throw new SOAPFault("'workflow name' is required with the 'LIST' operation", 400);  // bad request
            }
            break;
          case 'GET' :
            if (isset($workflow_name) && $workflow_name != '' && strlen($workflow_name)<51 &&
                isset($ticket) && $ticket != '' && isset($ctrl_field) && $ctrl_field != '') {
              return $this->_GET_PWR_CTRLFields($workflow_name, $ticket, $ctrl_field);
            } else {
              throw new SOAPFault("Workflow Name, Ticket number AND Control Field Name are required with the 'GET' operation", 400);  /
            }
            break;
          case 'SET' :
            if (isset($workflow_name) && $workflow_name != '' && strlen($workflow_name)<51 &&
                isset($ticket) && $ticket != '' && isset($ctrl_field) && $ctrl_field != '' &&
                isset($ctrl_field_val) && $ctrl_field_val != '') {
              return $this->_SET_PWR_CTRLFields($workflow_name, $ticket, $ctrl_field, $ctrl_field_val);
            } else {
              throw new SOAPFault("Workflow Name, Ticket number, Control Field Name AND Control Field Value are required with the 'SET'
            }
            break;
          default :
            throw new SOAPFault("Unknown PWR_CTRLFields operation : ".$operation, 400); // bad request
            break;
        }

      } else {
        throw new SOAPFault("Please provide the required operation parameter:'LIST','GET' or 'SET'", 400);  // bad request
      }

    } else {
      throw new SOAPFault("Access to PBSI POWER requires authentication.", 401);  // access denied
    }
  }

  protected function _GET_PWR_CTRLFields($workflow, $ticket, $controlfield)
  {
    $ourList = array();
    $ourList = $this->_LIST_PWR_CTRLFields($workflow);
    if (count($ourList)) {
      $found = 0;
      foreach ($ourList as $record) {
        if ($record['fi_Name'] == $controlfield) {
          $found++;
          $sql = 'SELECT fv_Value FROM tbl_field_values WHERE fv_co_ID='.sql_escape_clean($ticket).' AND fv_fi_ID='.sql_escape_clean($r
          $vals = myload($sql);
          return count($vals) ? $vals : array();
        }
      }
      if (!$found) {
        return array(1, 'Error : Given Control Field ('.$controlfield.') is not associated with given Workflow/Queue('.$workflow.')');
      }
    } else {
      return array(1, 'Error : No Control Fields found for ticket '.$ticket.' in Workflow/Queue('.$workflow.')');
    }
  }

  protected function _SET_PWR_CTRLFields($workflow, $ticket, $controlfield, $controlfieldvalue)
  {
    $ourList = array();
    $ourList = $this->_LIST_PWR_CTRLFields($workflow);
    if (count($ourList)) {
      $found = 0;
      foreach ($ourList as $record) {
        if ($record['fi_Name'] == $controlfield) {
          $found++;
          $fvsql = 'CALL sp_add_fieldvalue('.sql_escape_clean($ticket).','.sql_escape_clean($record['fi_ID']).','.sql_escape_clean($con
          $fvrs = myload($fvsql);
          return ($fvrs[0][0]>0)  ? array(1, 'Error : fieldvalue update unsuccessful: '.$fvrs[0][1])
                                  : array(0, '');
        }
      }
      if (!$found) {
        return array(1, 'Error : Given Control Field ('.$controlfield.') is not associated with given Workflow/Queue('.$workflow.')');
      }
    } else {
      return array(1, 'Error : No Control Fields found for ticket '.$ticket.' in Workflow/Queue('.$workflow.')');
    }
  }

  protected function _LIST_PWR_CTRLFields($workflow)
  {
    $sql = "SELECT fi_Name, fi_ID
            FROM tbl_field_associations
            INNER JOIN tbl_fields on fa_fi_ID=fi_ID
            WHERE fa_ct_ID IN (SELECT ct_ID FROM tbl_corr_types where ct_Name=".sql_escape_clean($workflow).")
            AND fa_Active='y' AND fi_Active='y'
            ORDER by fa_Sort";

    $vars = myload($sql);
    return count($vars) ? $vars : array();
  }
}

?>
