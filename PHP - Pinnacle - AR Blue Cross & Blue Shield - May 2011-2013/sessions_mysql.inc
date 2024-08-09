<?php
/**
 *
 * Session data stored/read from MySQL database
 *
 * Copyright 2009 (c) Pinnacle Business Solutions, Inc. All Rights Reserved.
 *
 * @version  $Id: sessions_mysql.inc 6953 2012-08-28 20:09:12Z pnshaver $
 *
 */

$ses_obj = new session();

/* Change the save_handler to use the class functions */
session_set_save_handler (array(&$ses_obj, '_open'),
                          array(&$ses_obj, '_close'),
                          array(&$ses_obj, '_read'),
                          array(&$ses_obj, '_write'),
                          array(&$ses_obj, '_destroy'),
                          array(&$ses_obj, '_gc'));

/**
 * Session class
 * 
 */
class session
{
	/* Define the mysql table you wish to use with this class, this table MUST exist. */
	private $ses_table 			= 'tbl_sessions';
	private $dbh 				= NULL; /* database object handle */  
	private $session_timeout 	= 900; // Default value will may be overwritten by insantiation and use of $config
	private $logging 			= false;
	private $logfile 			= "/tmp/i-am-a-coding-mistake.log";
	private $username			= null;
  
	/**
	 * Connect to database
	 * @global array $config
	 * @return bool
	 */
	private function db_connect()
	{
		global $config;

		//$this->session_timeout 	= $config['session_timeout'];
		//$this->session_timeout = $_SESSION['session_timeout']; // this may change from page to page
		$this->logging 			= (!empty($config['session_logging'])) ? $config['session_logging'] : NULL;
		$this->logfile 			= (!empty($config['session_logfile'])) ? $config['session_logfile'] : NULL;

		// do we use a different port # from config ?
		$optional_port_number = ((!empty($config['dbport'])) && (is_numeric($config['dbport']))) ? ($config['dbport']) : 3306;
		
		// do we have accurate parameters ?
		if ( (!isset($config['dbhost'])) || (!isset($config['dbuser'])) || (!isset($config['dbpass'])) )
		{
			error_log('Session database configuration incomplete.',0);
			$this->log("db_connect(): Session database configuration incomplete");
			return false;
		}

		// connect to database
		$this->dbh = new mysqli($config['dbhost'], $config['dbuser'], $config['dbpass'], $config['sessdatabase'] ,$optional_port_number);
		
		// were there any errors ?
		if (mysqli_connect_error())
		{
			error_log('Session database connection failed:'.mysqli_connect_errno(),0);
			$this->log("db_connect(): Session database connection failed - ".mysqli_connect_errno());
			return false;
		}
		
		return true;
	}

	/**
	 * Open session
	 *
	 * @param string $path
	 * @param string $name
	 * @return bool
	 */
	public function _open($path, $name)
	{
		if ($this->db_connect())
		{
			return true;
		}
		return false;
	}

	/**
	 * Close session
	 *
	 * @return bool
	 */
	public function _close()
	{
		if ( $this->dbh)
		{
			$this->dbh->close();
		}
		return true;
	}

	/**
	 * Read session
	 *
	 * @param string $ses_id
	 * @return string
	 */
  
	public function _read($ses_id)
	{
		if ($ses_id)
		{
			//echo "ID = $ses_id";
			//$ses_id = $this->dbh->real_escape_string($ses_id);
			//$this->session_timeout = $_SESSION['session_timeout'];
		
			$sql = "SELECT ses_value FROM " . $this->ses_table . " WHERE ses_id='$ses_id'";
			//die ($sql);
			
			if ($query = $this->dbh->query($sql))
			{
				if ($session_row = $query->fetch_assoc())
				{
					return $session_row['ses_value'];
				}
				return '1';
			}
			else
			{
				if ($this->dbh->error) {
					error_log('Session database read error: '.$this->dbh->error,0);
					if ($this->logging) $this->log("_read():    Error: Session database read error - ".$this->dbh->error);
				}
				elseif (!$this->dbh) {
					error_log('Session database read error: no database handle',0);
					if ($this->logging) $this->log("_read():    Error: Could not connect to database - ".$this->dbh->error);
				}
				return '0';
			}
		}
		else
		{
			error_log('Empty session id passed to _read(): '.$this->dbh->error,0);
			if ($this->logging) $this->log("_read():    Error: No session id passed - ".$this->dbh->error);
			return '';
		}
	}

  /**
   * Write session
   * 
   * @param string $ses_id
   * @param string $data
   * @return bool
   */
  public function _write($ses_id, $data)
  {
	global $config;

	//$function_ses_id = $ses_id;
	//$ses_id 				= $this->dbh->real_escape_string($ses_id);
	$dval 					= $this->dbh->real_escape_string($data);
	$tval 					= time();
	$this->session_timeout 	= isset($_SESSION['session_timeout']) ? $_SESSION['session_timeout'] : $config['session_timeout'];
	$current_time 			= date("H:i:s"); // human form
	$expire_time 			= date("H:i:s",strtotime("+ " . $this->session_timeout . " seconds",$tval)); // human form
	$this->username = trim($_SESSION['username']);
	//$current_user = (!empty($_SESSION['username'])) ? $_SESSION['username'] : 'Nobody Yet';
	
	$this->username = $username = isset($_SESSION['username']) ? $this->dbh->real_escape_string($_SESSION['username']) : null;
	if ($this->logging) $this->log("_write():   Session (".$ses_id.") belongs to ".$username);
	
	/*
	 if (isset($_SESSION['username']))
	{
	  $username = $this->dbh->real_escape_string($_SESSION['username']);
	  $this->username = $username;
	  $this->log("_write(): Session belongs to $username");
	}
	*/

	// if config set to allow/disallow multiple sessions for same user - default is to disallow
	if ( (!isset($config['multi_session'])) || ($config['multi_session'] != 'on') )
	{
		if ($this->logging)
		{
		  $this->log("_write():   Cheking for multiple sessions for user ".($this->username));
		}
		if ($this->username != '') // prevent multiple sessions for the same user - cleanly destroy old sessions
		{
		  $multiple_entries = $this->dbh->query("SELECT ses_id FROM {$this->ses_table} WHERE ses_user='$this->username'");
		  if (isset($multiple_entries))
		  {
			  if ($this->logging)
			  {
				  $this->log("_write():   Found ".count($multiple_entries)." entries for user ".$this->username);
			  } 
			  while ($row = $multiple_entries->fetch_assoc())
			  {
				  if ((!empty($row['ses_id'])) && ($row['ses_id'] != $ses_id) ) // different session id than current - kill old
				  {
					  $this->_destroy($row['ses_id'],true);
					  if ($this->logging)
					  {
						  $this->log("_write():   User ".($this->username)." ".$row['ses_id']." clobbered for having multiple sessions");
					  }
				  }
			  }
		  }
		}
	}
	
	if ($this->logging) $this->log("_write():   Attempting to update session record (".$ses_id.") for ".$this->username);
	
	/* Update a user first, if we can */
	$sql = "UPDATE {$this->ses_table} SET ses_time=$tval, ses_user='$this->username', ses_value='$dval' WHERE ses_id='$ses_id'"; // $dval
	$update = $this->dbh->query($sql,MYSQLI_STORE_RESULT);
	
	$info_rows = stristr ($this->dbh->info,"Rows matched: 1");
	
	// Affected rows isn't so great
	// lets check if "Rows matched: 1" was returned with an update success
	
	if (($update) && ($info_rows)) 
	{
			if ($this->logging) $this->log("_write():   User ".$this->username." (".$ses_id.") (last=".$current_time.") plus ".$this->session_timeout." seconds of time (expire=".$expire_time.")");
			return true;
	}
	// new session - insert instead of update
	else
	{
		if ($this->logging)
		{
			$this->log("_write():   Error: Could not update record (".$ses_id."), Doing insert instead");
			$this->log("_write():      Affected Rows = " . $this->dbh->affected_rows);
			$this->log("_write():      Error No  = " . $this->dbh->errno);
			$this->log("_write():      Info = " . $this->dbh->info);
			//$this->log("_write():      SQL = ".$sql);
		}
		
		$sql = "INSERT INTO ".$this->ses_table." (ses_id, ses_time, ses_start, ses_value, ses_user) VALUES ('".$ses_id."',".$tval.",".$tval.",'".$dval."','".$this->username."')";
		$insert = $this->dbh->query($sql);
		
		if ($insert)
		{
			if ($this->logging) 
				$this->log("_write():   ".$this->dbh->affected_rows." row inserted for user ".$this->username." (".$ses_id.")");
			if ($this->logging) $this->log("_write():   User ".$this->username." (".$ses_id.") (start=".$current_time.") created a new session (expire=".$expire_time.")");
		
			// if we know user details, insert them into tbl_login
			if (!empty($this->username))
			{         
				$sql_ins = 'INSERT INTO `'.$config['dbdatabase']."`.tbl_login VALUES (null,'".strftime('%Y-%m-%d %H:%M:%S',$tval)."','".strftime('%Y-%m-%d %H:%M:%S',$tval)."','$this->username' )";
				$this->dbh->query($sql_ins);
				if ($this->logging) $this->log("_write():   Logging User ".$this->username." (".$ses_id.") (start=".$current_time.") (last=".$current_time.") into tbl_login");
				return true;
			}
			else
			{
				if ($this->dbh->error)
				{
					if ($this->dbh->errno != 1062) /* 1062 is duplicate entry (nothing was done in update - insert noticed a dupe - just ignore */
					{
						error_log('Session database write error on insert: Error #'.$this->dbh->errno.': '.$this->dbh->error,0);
						if ($this->logging) $this->log("_write():   Error: Database write error - ".$this->dbh->error);

					}
					return false;
				}
				elseif (!$this->dbh)
				{
					error_log('Session database write error on insert: no database handle',0);
					if ($this->logging) $this->log("_write():   Error: Database write error - ".$this->dbh->error);
				}
				return false;
			}
		}
		else
		{
			if ($this->logging)
			{
				$this->log("_write():   Error: Inserting into ".$this->ses_table."(username=".$this->username.")");
				//$this->log("_write():      SQL = ".$sql);
			}
		}
	}
  }


  /**
   * Destroy session - manual logout
   *
   * @global array $config
   * @param string $ses_id
   * @param bool $removeold If only allow 1 session, old will be true when called from _write - uses ses_time if session expired, otherwise uses current time as logout time
   * @return bool
   */
  
  public function _destroy($ses_id, $removeold=false)
  {
	global $config;

	$function_ses_id = $ses_id;
	$ses_id = $this->dbh->real_escape_string($ses_id);
	
	$user_sessions = $this->dbh->query("SELECT ses_id,ses_user,ses_start,FROM_UNIXTIME(ses_start,'%H:%i:%s') as start_time, ses_time, FROM_UNIXTIME(ses_time,'%H:%i:%s') as last_accessed, ses_value FROM " . $this->ses_table . " WHERE ses_id='$ses_id'");
	
	$departing_user = $user_sessions->fetch_assoc();
	//$user_details = print_r($departing_user,true);

	if ( ($departing_user) && (isset($departing_user['ses_user'])) && (isset($departing_user['ses_start'])) && ($departing_user['ses_user'] != '') )
	{
		//echo "<!-- remove old part... -->";
		$ses_user = $departing_user['ses_user']; // user id of the user that logged in with this session
		$ses_start = $departing_user['ses_start']; // time when session started (when user logs in a new session is started and time ses_start set)
		$ses_time = $departing_user['ses_time']; // last access time
		
		$start_time = $departing_user['start_time']; // human form
		$last_accessed = $departing_user['last_accessed']; // human form
		
		$l_Out = '';
    $cTime = time();
    
		if ($removeold) // destroying a previous session due to a new session for the user starting - destroying old sesion - use ses_time
		{
		  $ses_life = strtotime('-'. $this->session_timeout .' seconds');
		  if (!empty($ses_time) && ($departing_user['ses_time'] < $ses_life) ) {
			  $cTime = $ses_time;
		  }
		}

    $l_Out = strftime( '%Y-%m-%d %H:%M:%S', $cTime );

    //  Populate session variables if empty.... Hey, we need to be able to read them
    if( empty( $_SESSION ) ) {
      session_decode( $departing_user['ses_value'] );
    }
        
    $l_Idle     = ( ! empty( $_SESSION['LoginIdleTime'] ) ) ? $_SESSION['LoginIdleTime'] : 0;
    $l_Timeout  = ( ! empty( $_SESSION['LoginTimeout'] ) ) ? $_SESSION['LoginTimeout'] : 0;
    $l_Meta     = ( ! empty( $_SESSION['LoginMetaData'] ) ) ? $_SESSION['LoginMetaData'] : '';

		$insert = "INSERT INTO `".$config['dbdatabase']."`.tbl_login VALUES(null,'" . strftime( '%Y-%m-%d %H:%M:%S', $ses_start ) . "','$l_Out','$ses_user', $cTime-$ses_start, $l_Idle, $l_Timeout, '$l_Meta' )";
		$log = $this->dbh->query($insert);
	}
	
	$delete = $this->dbh->query ("DELETE FROM " . $this->ses_table . " WHERE ses_id='$ses_id'");

	if ($delete) {
		if ($this->logging) {
			$this->log("_destroy(): User ".$ses_user." (".$ses_id.") (start=".$start_time.") has logged out");
			$this->log("_destroy(): User ".$ses_user." (".$ses_id.") purged from session table");
		}
	}
	return true; // always returning true - if destroy fails, garbage collection will delete old sessions
  }

  /**
   * Garbage collection
   *
   * @global array $config
   * @global array $_SESSION
   * @param int $life
   * @return bool
   */
  public function _gc($life)
  {
	global $config;

	// for some reason we are getting several php notices indicating that $_SESSION['session_timeout'] doesn't exist
	if (isset($_SESSION['session_timeout'])){
		$this->session_timeout = $_SESSION['session_timeout'];
	} else {
		// not sure what to do in GC if this happens. return false?
		return true;
	}
		
	if ( (!$this->dbh) && (!$this->db_connect()) )
	{
		return false;
	}

	// first delete all non-user sessions (login page accessed, session started, never logged in)
	$delete_no_shows = $this->dbh->query("DELETE FROM tbl_sessions WHERE ses_time < (UNIX_TIMESTAMP() - ".$config['session_timeout'].") AND ses_user=''");

	$inactive_users = $this->dbh->query("SELECT ses_id,ses_user,ses_start,FROM_UNIXTIME(ses_start,'%H:%i:%s') as start_time, FROM_UNIXTIME(ses_time,'%H:%i:%s') as last_accessed, ses_time FROM tbl_sessions WHERE DATE_SUB(curdate(),INTERVAL ses_time SECONDS) < " . $this->session_timeout);

	if ($inactive_users) {
		if ($this->logging) {
			$this->log("_destroy(): Found ".count($inactive_users)." inactive users");	
		}
		/* We have at least 1 expired user, maybe more */
		while ($single_user = $inactive_users->fetch_assoc() )
		{
			$ses_id 			= $single_user['ses_id'];
			$start_time 		= $single_user['start_time'];
			$last_accessed 		= $single_user['last_accessed'];
		
			// delete the record from sessions
			$delete = $this->dbh->query("DELETE FROM tbl_sessions WHERE ses_id = '$ses_id'");
		  
			if ($delete)
			{
				if ($this->logging) $this->log("_gc():      User ".$this->username." (".$ses_id.") (start=".$start_time.") (last=".$last_accessed.") purged for slacking");
			}
		}
	}
	return true;
  }
  
  private function log($something) {
	if (file_put_contents($this->logfile,"[" . date("m-d-Y H:i:s") . "] ".$something." \n",FILE_APPEND))
	{
		return true;
	}
	else
	{
		return false;
	}
  }
}
?>
