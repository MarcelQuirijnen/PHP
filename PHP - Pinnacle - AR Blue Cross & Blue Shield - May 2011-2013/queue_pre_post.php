<?php
//
/**
 *
 * Pre/Post Queue Functions.
 * Functions used in PRE, POST and INIT settings in queue setup.
 *
 * Copyright 2007-2010 (c) Pinnacle Business Solutions, Inc. All Rights Reserved.
 *
 * @version   $Id: queue_pre_post.php 7809 2013-07-18 16:26:08Z pnshaver $
 *
 */

/*
This software contains intellectual property of Pinnacle Business Solutions,
Inc. (PBSI) or is licensed to PBSI from third parties. Use of this software
and the intellectual property contained therein is expressly limited to the
terms and conditions of the License Agreement under which it is provided by or
on behalf of PBSI.
*/


require_once (dirname(__FILE__).'/../includes/filecache.inc');
require_once (dirname(__FILE__).'/../includes/document_repository.inc.php');
require_once (dirname(__FILE__).'/../includes/mimetypes.inc');
require_once (dirname(__FILE__).'/../includes/groups_functions.inc');

/* List of available pre-code functions:
 * 	forward_preexisting_ccn
 * 	remove_ccn
 *  unid_barcode_routing
 * 	required_fields
 *  PD_system_test
 *  PD_SAG_required
 *  increment_iteration
 *  email_info
 *
 * List of available post-code functions:
 * 	required_fields
 *  copy_field
 *  set_field
 *  ccn_set_batchnum
 *
 */

/**
 * Parses ExcelXML into POWER fields.
 *
 * This function uses a specially formatted Excel XML file to populate control
 * fields.  Added for ticket 744080
 *
 * @global array $config
 * @param array $post
 * @param string $user
 * @return array
 */
function parseExcelXML( $post=array(), $user='' ) {
	global $config;
  require_once (dirname(__FILE__).'/../includes/excel2fields.php');

  //  See if an Excel XML file is attached to this record....

	$coid = $post['fco_ID'];

	// get the "newest" text/xml document and ignore others (this allows "updates")
	$docids=myloadslave("
      SELECT tbl_filerefs.fi_docID as DOCID
      FROM tbl_filerefs
      WHERE fi_co_ID=$coid
        AND fi_active='y'
        AND fi_mimetype in ('application/excel','application/vnd.ms-excel')
      ORDER BY fi_datetimestamp desc
      LIMIT 1
  ");
  if( $docids == NULL )
      return( array( 0, "No Excel XML document found." ) );

  //  Get the file into memory....
	$imageArray=get_filecache( $docids[0]["DOCID"], False );
  if( ! excel2fields( $imageArray["data"] ) )
      return( array( 1, "Error on XML" ) );
  else
      return( 0 );
}

/**
 * Moves record with an existing CCN field to :primary: status queue.
 *
 * @param array $post
 * @param string $user
 * @return array
 */
function forward_preexisting_ccn($post=array(), $user='')
{
	// determine field ID for CCN
	$ccnfid=get_fid('CCN');
	if (!$ccnfid)
		echo 'Error: No CCN field found. Cannot assign CCNs.';

	if ( (!isset($post['fco_ID'])) || (!is_numeric($post['fco_ID'])) )
		return array(0,'Invalid record ID.');

  $fco_ID = $post['fco_ID'];

	if (($user == '') && (isset($_SESSION['username'])) && ($_SESSION['username'] != ''))
		$user = $_SESSION['username'];
	else if ($user == '')
		$user = 'POWER';

	$ccnsql ="SELECT fv_Value as CCN FROM tbl_field_values WHERE fv_co_ID=$fco_ID AND fv_fi_ID=$ccnfid ORDER BY fv_Datetimestamp DESC LIMIT 1";
	$ccnrs=myload($ccnsql);

	if (count($ccnrs)>0 && isset($ccnrs[0]['CCN']) && $ccnrs[0]['CCN']!='')
	{
		// CCN found, change queue if :primary: status queue next in workflow
		$nqsql = "SELECT sf_st_followed_by_id FROM tbl_corr";
		$nqsql.= " INNER JOIN tbl_status_followed_by ON sf_st_ID=co_queue";
		$nqsql.= " INNER JOIN tbl_statuses ON tbl_status_followed_by.sf_st_followed_by_id=st_ID";
		$nqsql.= " WHERE co_ID=$fco_ID AND st_Category LIKE '%:primary:%' AND st_Active='y'";
		$nqrs=myload($nqsql);
		if ( (count($nqrs)>0) && (isset($nqrs[0]['sf_st_followed_by_id'])) )
		{
      $st_ID = $nqrs[0]['sf_st_followed_by_id'];
      $fComments = 'Post-CCN Assignment Autoforward';
      $post = array();
      $update_status = set_corr_status ($fco_ID, $st_ID, $fcomment, $post, $user);

      if ($update_status[0] == false)
      {
        return array(1,"Failed to forward record $fco_ID to status queue ($st_ID): ".$update_status[1]);
      }
      else
      {
        return array(0,"Record $fco_ID forwarded to next queue due to CCN");
      }
		}
		return array(0,'This record has a CCN, but could not be forwarded because there was no primary status queue found.');
	}

	return array(0,'CCN not found. Not fowarding to primary status queue.');
}

/**
 * Set the CCN Batch Number.
 *
 * @param array $post
 * @param string $user
 * @return array
 */
function ccn_set_batchnum($post=array(), $user='')
{
	/* ticket 565133
	 * Use this function on the "Cash Activations Received" (MCS_CAAC_REC) status queue post-code
	 * If CCN populated and BATCHNUM is not
	 * If destination status is in either of these workflow types:
	 * 	MCS Medicare Secondary Payer Cash (MCS_MSPCA)
	 * 	MCS Non-MSP Cash Receipts (MCS_CASH)
	 * Set BATCHNUM to 8th-10th character of CCN
	 *
	 * This function will NOT block the status change if it cannot set the BATCHNUM
	 *
 	 */

	if (!isset($post['fco_ID']) || (!is_numeric($post['fco_ID'])))
 		return array(1,'No record # passed to ccn_set_batchnum.');

	if ( (!isset($post['fst_id'])) || (!isset($post['fco_ID'])) )
		return array(1,'Status or record # missing' );

	if (($user == '') && (isset($_SESSION['username'])) && ($_SESSION['username'] != ''))
		$user = $_SESSION['username'];
	else if ($user == '')
		$user = 'POWER';

	$co_ID = $post['fco_ID'];
	$st_ID = $post['fst_id'];

	$stat_check = myload("SELECT st_ID, st_Name, ct_Name FROM tbl_corr_types INNER JOIN tbl_statuses ON ct_ID=st_ct_ID WHERE ct_Name IN ('MCS_MSPCA','MCS_CASH') AND st_ID=$st_ID");
	if (count($stat_check)==0) // not moving to a status we need to apply batch number
		return array(0,'');

	$ccn_val = get_field($co_ID, 'CCN');
	$batchnum_val = get_field($co_ID, 'BATCHNUM');

	if ((!$ccn_val) || ($ccn_val == ''))
		return array(0,'No CCN value - not setting Batch Number.');
	if ($batchnum_val)
		return array(0,'Batch Number already set - not setting based upon CCN value.');

	// Set BATCHNUM to 8th-10th character of CCN
	if (strlen($ccn_val) < 10)
		return array(0,'Cannot determine Batch Number from CCN - CCN too short.');

	$batchnum = substr($ccn_val,7,3);

	if ($batchnum == '')
		return array(0,'Failed to set Batch Number - invalid value.');

	if (preg_match('/^\d\d\d$/',$batchnum))
	{
		if (set_control_field ($co_ID, 'BATCHNUM', $batchnum, $user))
			return array(0,"Batch Number set to $batchnum.");
		else
			return array(0,"Failed to set Batch Number to $batchnum.");
	}
	else
		return array(0,"Failed to set Batch Number to $batchnum - invalid value.");

}

/**
 * Validate Prenote fields #1
 *
 * @param array $post
 * @param string $user
 * @return array
 */
function prenote_edits_1($post=array(), $user=''){
	if (!isset($post['fco_ID']) || !is_numeric($post['fco_ID'])){
		return array(1,'prenote_edits_1: Invalid record ID.');
	}
	$co_ID=$post['fco_ID'];

	$msg='';

	// get header-level record
		$sql="SELECT * FROM tbl_corr WHERE co_ID=".$co_ID;
		$rs=myload($sql);
		if (count($rs)==0){
			return array(1,'prenote_edits_1: record ID not found.');
		}

		$co_receivedate=strtotime($rs[0]['co_receivedate']);

		// get any control fields that we might need for these edits
			$PC_CASE_MGR_NAME=get_field($co_ID, 'PC_CASE_MGR_NAME');
			$REFERDT=strtotime(get_field($co_ID, 'REFERDT'));
			$DENIALDT=strtotime(get_field($co_ID, 'DENIALDT'));
			$PN_PEN_OPEN=strtotime(get_field($co_ID, 'PN_PEN_OPEN'));
			$PN_BM_OPEN=strtotime(get_field($co_ID, 'PN_BM_OPEN'));
			$PN_PEN_CLOSED=strtotime(get_field($co_ID, 'PN_PEN_CLOSED'));
			$PN_BM_CLOSED=strtotime(get_field($co_ID, 'PN_BM_CLOSED'));
			$PN_VND_BH_REFDT=strtotime(get_field($co_ID, 'PN_VND_BH_REFDT'));
			$PN_VND_OV_REFDT=strtotime(get_field($co_ID, 'PN_VND_OV_REFDT'));

			$PN_PEN_TYPE=strtotime(get_field($co_ID, 'PN_PEN_TYPE'));
			$PN_PEN_PHASE=strtotime(get_field($co_ID, 'PN_PEN_PHASE'));
			$PN_PEN_DAYS_OPEN=strtotime(get_field($co_ID, 'PN_PEN_DAYS_OPEN'));
			$PN_PEN_GOALS_MET=strtotime(get_field($co_ID, 'PN_PEN_GOALS_MET'));
			$PN_BM_TYPE=strtotime(get_field($co_ID, 'PN_BM_TYPE'));
			$PN_BM_PHASE=strtotime(get_field($co_ID, 'PN_BM_PHASE'));
			$PN_BM_DAYS_OBM=strtotime(get_field($co_ID, 'PN_BM_DAYS_OBM'));
			$PN_BM_GOALS_MET=strtotime(get_field($co_ID, 'PN_BM_GOALS_MET'));

		// perform header-level edits
			if ($DENIALDT<>'' && $DENIALDT<$co_receivedate) $msg.=" - Denial date cannot be earlier than received date.\n";
			if ($REFERDT<>'' && $REFERDT<$co_receivedate) $msg.=" - Refer date cannot be earlier than received date.\n";

		// verify that PC_CASE_MGR_NAME and REFERDT are populated 
			if ($PC_CASE_MGR_NAME=='' || $REFERDT==''){
				$msg.=" - Case manager and refer date must be populated.\n";
			}

		// verify valid PN_PEN_OPEN > REFERDT
			if ($REFERDT<>'' && $PN_PEN_OPEN<>'' && $PN_PEN_OPEN<$REFERDT){
				$msg.=" - The Pending Date Case Opened cannot be greater than Referred Date.\n";
			}
		// verify valid PN_BM_OBM > REFERDT
			if ($REFERDT<>'' && $PN_BM_OPEN<>'' && $PN_BM_OPEN<$REFERDT){
				$msg.=" - The Pending Date Case Opened cannot be greater than Referred Date.\n";
			}

		// verify valid PN_PEN_CLOSED >= $PN_PEN_OPEN
			if ($PN_PEN_OPEN<>'' && $PN_PEN_CLOSED<>'' && $PN_PEN_CLOSED<$PN_PEN_OPEN){
				$msg.=" - The Pending Date Case Closed cannot be earlier than the Pending Date Case Opened.\n";
			}
		// verify valid PN_BM_CLOSED >= $PN_BM_OPEN
			if ($PN_BM_OPEN<>'' && $PN_BM_CLOSED<>'' && $PN_BM_CLOSED<$PN_BM_OPEN){
				$msg.=" - The Pending Date Case Closed cannot be earlier than the Pending Date Case Opened.\n";
			}

		// verify that BH refer date is later than or equal to receive date
			if ($PN_VND_BH_REFDT<>'' && $PN_VND_BH_REFDT<$co_receivedate){
				$msg.=" - BH Vendor Refer Date cannot be earlier than the record receive date.\n";
			}
		// verify that other vendor refer date is later than or equal to receive date
			if ($PN_VND_OV_REFDT<>'' && $PN_VND_OV_REFDT<$co_receivedate){
				$msg.=" - OV Vendor Refer Date cannot be earlier than the record receive date.\n";
			}


	// if we got this far then we apparently passed all the edits.
	if ($msg<>''){
		return array(1, "prenote_edits_1: record verification errors:\n\n".$msg);
	} else {
		return array(0, 'prenote_edits_1: all edits passed.');
	}
}

/**
 * Validate PD System Testing checks.
 *
 * @param array $post
 * @param string $user
 * @return array
 */
function PD_system_test($post=array(), $user='')
{
  // make sure these control fields are populated: AccTestDt, SysTstPer
  $control_fields = array('AccTestDt','SysTstPer');
  $missing_control_fields = array();

  foreach ($control_fields as $field)
  {
    if (!control_field_populated($post['fco_ID'], $field))
    	$missing_control_fields[] = $field;
  }

  if ( count($missing_control_fields) == 0)
    return array(0,'Required System Test fields populated.');

  $msg = '';
  if (count($missing_control_fields)>0)
    $msg .= 'Required control fields missing data: '. implode(', ', $missing_control_fields) . "\n";

  return array(1,$msg );
}

/**
 * Validate PD Supervisor checks.
 *
 * @global array $config
 * @param array $post
 * @param string $user
 * @return array
 */
function PD_supervisor_validation($post=array(), $user='')
{
  global $config;

	if ( (!isset($post['fst_id'])) || (!isset($post['fco_ID'])) || (!is_numeric($post['fst_id'])) || (!is_numeric($post['fco_ID'])) )
		return array(1,'Status or record # missing' );

	$stchange_res = myload("SELECT st_Name FROM tbl_statuses WHERE st_ID=".$post['fst_id']);
	if (!isset($stchange_res[0]['st_Name']))
		return array(1,'Unknown status id ' . $post['fst_id'] );

	// Skip check if moving to IT_PD_SUP_DEN
	if ($stchange_res[0]['st_Name'] == 'IT_PD_SUP_DEN')
		return array(0,'PD validation skipped' );

  $msg = '';

  // Make sure the AssignedRelease is populated and on the stages.arr file
  if( $AsgnRelNum = control_field_populated( $post['fco_ID'], 'AsgnRelNum', TRUE ) ) {

    $a = file_get_contents( "/" . $config['fcdir'] . '/tmp/stages.arr' );
    //  Get the list of releases PI is accepting...
    $stages = @unserialize( @file_get_contents( '/' . $config['fcdir'] . '/tmp/stages.arr' ) );

    // Get Assigned Release Number from PAR
    if( ! isset( $stages[ strtoupper($AsgnRelNum) ] ) )
        $msg .= "AsgnRelNum ($AsgnRelNum) not currently being accepted by PI.";

  } else
      $msg .= 'Required control fields missing data: AsgnRelNum';

  //  Return check
  if( $msg != '' )
      return array( 1, $msg );
  else
      return array( 0, 'Required Supervisor fields populated.');
}

/**
 * Validate PD SAG.
 *
 * @param array $post
 * @param string $user
 * @return array
 */
function PD_SAG_required($post=array(), $user='') {
	/*
	 * 798790: Force SAG approval on all pars
	 * Force SAG approval on all pars (except recurring pars, those that start with R).
	 * + should only be forced with the first iteration
	 *
	 * For example,
	 *  PARNum: CR1234 - SAG required
	 *  PARNum: CR1234R2 and Iternation: 1 - SAG required
	 *  PARNum: CR1234 and Iteration: 2 - SAG Approval not required
	 *  PARNum: RO0038 - SAG Approval not required
	 *  PARNum: RO0050 - SAG Approval not required
	 *  PARNum: IM0000 - SAG Approval not required (per 1123758)
	 *
	 * Flow is like this:
	 * Dev -> SAG -> SAG Approve [AF]-> Dev -> other
	 * Dev -> SAG -> SAG Deny [AF]-> Dev -> SAG -> SAG Approve [AF]-> Dev -> other
	 *
	 * If this record has already had a status of "IT PD SAG Approved"
	 * don't require it to go thru SAG again.
	 * + only needs to occur once
	 *
	 */

    if ($user == 'Infoman') /* allow status change when user is Infoman (which is used in automated processes) */
        return array(0,'PAR SAG Approval required (Infoman import) - passed.');

    if ( (!isset($post['fst_id'])) || (!isset($post['fco_ID']))  || (!is_numeric($post['fst_id'])) || (!is_numeric($post['fco_ID'])) )
        return array(1,'Status or record # missing' );

    $stchange_res = myload("SELECT st_Name FROM tbl_statuses WHERE st_ID=".$post['fst_id']);
    if (!isset($stchange_res[0]['st_Name']))
        return array(1,'Unknown status id ' . $post['fst_id'] );

    $st_Name = $stchange_res[0]['st_Name'];

    $PARNum_fid=get_fid('PARNum');
    $Iteration_fid = get_fid('Iteration');

    if ((!$PARNum_fid) || ($PARNum_fid == ''))
        return array(1,'PARNum control field missing.');

    if ((!$Iteration_fid) || ($Iteration_fid == ''))
        return array(1,'Iteration control field missing.');

    $pnumsql ="SELECT fv_Value FROM tbl_field_values WHERE fv_fi_ID=$PARNum_fid AND fv_co_id=".$post['fco_ID']." ORDER BY fv_Datetimestamp DESC LIMIT 1";
    $pnumrs=myload($pnumsql);
    $PARNum = '';
    if (isset($pnumrs[0][0]))
        $PARNum = $pnumrs[0][0];
    else
        return array(1,'No PARNum value found.');

    if (preg_match('/^(R|IM)/i',$PARNum)) /* PARNum starts with an "R" don't require SAG Approval */
        return array(0,'PAR SAG Approval required (R/IM) - passed.');

    // Let go through if moving back to IT_PD_Rec "IT PD Received" or moving into IT_PD_SAG
    if (($st_Name == 'IT_PD_SAG') || ($st_Name == 'IT_PD_Rec' ) )
        return array(0,'PAR SAG Approval required (allowed status change) - passed.');

    $pnumsql ="SELECT fv_Value FROM tbl_field_values WHERE fv_fi_ID=$Iteration_fid AND fv_co_id=".$post['fco_ID']." ORDER BY fv_Datetimestamp DESC LIMIT 1";
    $pnumrs=myload($pnumsql);
    $Iteration = '';
    if (isset($pnumrs[0][0]))
        $Iteration = intval($pnumrs[0][0]);

    if ((is_numeric($Iteration)) && ($Iteration > 1)) /* approval should only be forced with the first iteration */
        return array(0,'PAR SAG Approval required (iteration) - passed.');

    // If this record has not been through IT_PD_SAG_APP "IT PD SAG Approve" then halt
    $appsql = "SELECT sl_status_datetime FROM tbl_statusflow LEFT JOIN tbl_statuses ON sl_st_ID=st_ID WHERE sl_co_ID=".$post['fco_ID']." AND sl_st_ID IS NOT NULL AND sl_Activity='STATUS' AND st_Name='IT_PD_SAG_APP' LIMIT 1";
    $apprs=myload($appsql);
    if (count($apprs) == 0) // has not been through IT_PD_SAG_APP
        return array(1,'PAR SAG Approval required - Please move PAR to IT PD SAG for approval.');

    return array(0,'PAR SAG Approval required - passed.');
}
/**
 * Error handler.
 *
 * @param int $errno
 * @param string $errstr
 */
function echoError($errno, $errstr)
{
  echo 'Error: ' . preg_replace( '/^.*]:/', '', $errstr );
}

/**
 * Run JCL job on Mainframe.
 *
 * This function will be passed a DataSet name.  It will download the dataset,
 * update templated control fields and then submit the job for execution on the
 * mainframe.  Ticket 1006684.
 *
 * @global array $config
 * @param array $post
 * @param string $user
 * @return array
 */
function executeJCL($post=array(), $user='') {
  global $config;

  set_error_handler("echoError");

  //  If user logged in using network ID - Don't allow status change and "SUGGEST" loging in using TPX ID
  if( ( isset( $_SESSION['Network_User'] ) && (  $_SESSION['Network_User'] == 1 ) ) ||
    ( (! isset( $_SESSION['username'] ) ) || ( $_SESSION['username'] == "" ) ) ||
    ( (! isset( $_SESSION['password'] ) ) || ( $_SESSION['password'] == "" ) ) ) {
    restore_error_handler();
    return array(1,'TPX Login Required - Please Log out and log back in using your TPX ID!' );
  }

  //  Make sure DataSet / member are entered
  $ds = explode( ',', preg_replace( '/[( )]/', '', $post['codeparams'] ) );

  if( (! is_array ($ds ) ) || ( $ds[0] == '' ) ) {
    restore_error_handler();
    return array(1,'Configuration incorrect - no dataset name found.' );
  }

  // Build Template DataSet Name
  $dsName = $ds[0];
  if( isset( $ds[1] ) ) {
    $dsName .= '(' . $ds[1] . ')';
  }

  // set up basic connection
  echo( "Connecting to the mainframe...\n");
  if( ! ( $conn_id = @ftp_connect( $config["mainframe_host"] ) ) ) {
    restore_error_handler();
    return array( 1, "Unable to connect to the mainframe!");
  }

  // login with username and password
  echo( "Logging on...<BR>");

  if( ! ($login_result = @ftp_login( $conn_id, $_SESSION["username"], $_SESSION["password"] ))) {
    restore_error_handler();
    return array( 1, "Unable to log on to mainframe." );
  }

  // Create temp file
  $tempFile = tmpfile();

  // Get Template File
  echo "Getting JCL template from mainframe....<BR>";

  if( ! ftp_fget($conn_id, $tempFile, "'" . $dsName . "'", FTP_ASCII) ) {
    restore_error_handler();
    return array( 1, "Download failed...");
  }

  //  Get JCL Template info from DataSet
  fseek( $tempFile, SEEK_SET );
  $jclTemplate = fread( $tempFile, 204800 );

  //  Figure out what POWER control fields are being used
  preg_match_all( '/[$](\@*)([a-zA-Z0-9_]+)[$]/', $jclTemplate, $cField);
  $co_ID = $post["fco_ID"];

  //  Substitue field values in template
  foreach( $cField[2] as $k => $n ) {

    // Get substitute value
    switch ( strtoupper($n) ) {

      //  Corr ID
      case 'CO_ID':
        $v = $co_ID;
        break;

      //  Test/Production Indicator
      case 'TP_IND':
				$v = 'T'; if($config["system_short_name"]=='POWERTEST') $v = 'P';
        break;

      //  Control Field Value
      default:

        if( $cField[1][$k] != '@' ) {

          //  Field value lookup

          if( ($v = get_field( $co_ID, $n )) == "" )
              $v = '$' . $n . '$';  //  Return field name if no match

        } else {

          //  E-mail address lookup
          $lookup = get_control_field_email_address($co_ID, $n );

          //  Make sure e-mail address exists
          if( $lookup[0] == 0 )
              //$v = $lookup[1];
			  	    $v = explode(',', $lookup[1]);
          else
              $v = $cField[1][$k] . $cField[2][$k];

        }
        break;
    }

    $jclTemplate = str_replace( '$' . $cField[1][$k] . $cField[2][$k] . '$', $v, $jclTemplate );
  }

  // Write updated JCL back to temp file
  fseek( $tempFile, SEEK_SET );
  ftruncate( $tempFile, 0 );
  if( ! fwrite( $tempFile, $jclTemplate ) ) {
    restore_error_handler();
    return array( 1, "Error writing JCL to temporary file." );
  }
  fseek( $tempFile, SEEK_SET );

  echo( "JCL Created....<BR>" );

  // Dump JCL to log if in Debug Mode
  if( ( isset( $_SESSION["debug"] ) ) && ( $_SESSION["debug"] == 1 ) ) {
    echo "<PRE>\n";
    echo $jclTemplate;
    echo "</PRE>\n";
  }

  $r=ftp_raw( $conn_id, "site filetype=jes" );

  if( ! @ftp_fput( $conn_id, 'Endevor', $tempFile, FTP_ASCII ) ) {
    restore_error_handler();
    return array( 1, "Unable to submit job!" );
  }

  @ftp_close( $conn_id );

  echo( "Job Submitted!<BR>" );

  restore_error_handler();
  return array(0,'Job Submitted.');
}


/**
 * Evaluate expression and autofoward if true.
 *
 * Called as autoForwardEval(V1==V2,Q).  V1, V2 and Q can be either
 * single quoted strings ('abc') or control field names (PARNum).  If a field
 * name is used for the Q value, the value of the field will determine where the
 * record moves.  Ticket: 1013278
 *
 * Can also be called as autoForwardEval(@GFName).  Where GFName is the name of
 * a group field.  In this version, the members of the group will be evaluated
 * individually and moved based on first successful match.  Ticket: 1211115
 *
 * @global array $config
 * @param array $post
 * @param string $user
 * @return array
 */
function autoForwardEval($post=array(), $user='') {
  global $config;

  $queue = null;

  //  Lookup field values
  $co_ID = $post["fco_ID"];

  //  Check to see if this is a group field (@GROUP_NAME) or eval move
  if( preg_match( '/\(\s*@\s*([a-zA-Z0-9_]+)\s*\)/', $post['codeparams'], $ds ) )
  {

    //  Get Values from group field to evaluate against
    $sql = "SELECT fF.fi_Name as name, fg_Name as regx, fg_Group as queue, fg_Sort as sort
            FROM tbl_field_groups
            JOIN tbl_fields as gF on gF.fi_ID = fg_group_fi_ID and gF.fi_Active='y'
            JOIN tbl_fields as fF on fF.fi_ID = fg_fi_ID and fF.fi_Active='y'
            WHERE gF.fi_Name = " . sql_escape_clean( $ds[1] ) . " and fg_Active='y'
            ORDER BY fg_Sort";
    $rules = myload( $sql );

    //  Make sure the ruleset is not empty
    if( empty( $rules ) )
        return array( 1, 'autoForwardEval Ruleset (@'.$ds[1].') not found in field groups.');

    //  gC array based on group level
    foreach( $rules as $l )
        $gC[$l['sort']][] = $l;
    unset( $rules );

    //  Loop through all of the group levels and "and" them
    foreach( $gC as $groupLevel ) {

      $join=array();
      $where = '';

      foreach( $groupLevel as $compare ) {
        $join[ $compare['name'] ] = 0;
        $where .= " AND " . $compare['name'] . ".fv_Value REGEXP '" . $compare['regx'] . "'\n";
      }

      //  Build Query
      $sql = "SELECT '" . $compare['queue'] . "' as queue\nFrom tbl_corr\n";
      foreach( $join as $fName => $fNum )
          $sql .= build_join( $fName );
      $sql .= " WHERE\n co_ID='$co_ID'\n" . $where . "limit 1";

      //  Execute query and break out of loop if found
      $res =  myload( $sql );
      if( ! empty( $res ) ) {
        $queue = $res[0]['queue'];
        $evalExpr = 'return(true);';
				break;
      }
    }
  } else {
    //  Get parameters - Return OK if no match (ie, it's going to continue as expected)
    if( ! preg_match( "/^\((\'{0,1}[a-zA-Z0-9_]*\'{0,1})\s*(==|!=|<=|>=|<|>)\s*(\'{0,1}[a-zA-Z0-9_\s]*\'{0,1})\s*,\s*(\'{0,1}[a-zA-Z0-9_]+\'{0,1})\)/", $post['codeparams'], $ds ) ) {
      return array( 1, 'autoForwardEval configuration error.');
    }

    //  Check fields for POWER Control Field names
    for( $k=1; $k<=4; $k++ ) {

      //  Don't parse the '=='
      if($k==2)
          continue;

      //  Substitute values for fields
      if( ! preg_match( "/\'/", $ds[$k] ) ) {
        $ds[$k] = "'" . get_field( $co_ID,  $ds[$k] ). "'";
      }
    }

    //  Cleanup Queue name
    $queue = str_replace( "'", '', $ds[4] );

    // Build string to evaluate
    $evalExpr = 'return(';
    for( $k=1;$k<=3;$k++ )
        $evalExpr .= $ds[$k];
    $evalExpr .= ');';
  }

  if( $queue == '' )
      return array( 0, 'Queue name empty - no autoforward.');

  //  Lookup queue name
  if( ( ! isset( $queue ) ) || ($queue==null) || ( ($st_ID = get_st_ID( $queue ) ) == False ) )
      return array( 1, "autoForwardEval Queue Name ($queue) Not Found.");

  //  Check to see if expression evaluates to true and forward if it does
  $msg = 'AutoForward expression not met.  Normal processing.';
  if(  eval($evalExpr) ) {
    $mts_ret = move_to_status( $co_ID, $st_ID, 'Automatic Forward (autoForwardEval).' );
    if ($mts_ret[0] === false)
      return array(0,'autoForwardEval: Failed to Automatic Forward (autoForwardEval) record: '.$mts_ret[1]);

    $msg = 'Automatic Forward (autoForwardEval).';
  }

  return array( 0, $msg );
}


/**
 * Copy the value of one control field into another control field
 *
 * Called as copy_field(FIELD1,FIELD2)
 * Copy the value of one control field (FIELD1) to another control field (FIELD2).
 *
 * @param array $post
 * @param string $user
 * @return array
 */
function copy_field($post=array(), $user='')
{
	if (!isset($post['codeparams']) || (!preg_match('/^(.*)$/',$post['codeparams'])) || ($post['codeparams']=='()'))
 		return array(1,"No parameters sent to copy_field function. Setup error.");

	if (!isset($post['fco_ID']) || (!is_numeric($post['fco_ID'])))
 		return array(1,"No record # passed to copy_field.");

	if ( (!isset($post['fst_id'])) || (!isset($post['fco_ID'])) )
		return array(1,'Status or record # missing' );

	if (($user == '') && (isset($_SESSION['username'])) && ($_SESSION['username'] != ''))
		$user = $_SESSION['username'];
	else if ($user == '')
		$user = 'POWER';

	$codeparams = $post['codeparams']; // this can be (FIELD1,FIELD2)
	$co_ID = $post['fco_ID'];

	$check_fields = array();
	$check_st_Name = array(); // if st_Name=something is included in the parameters this checked will only be done for this status

	if (preg_match('/^\((.*)\)$/', $codeparams, $matches))
	{
		$cfields = array();
		if (isset($matches[1]))
	  	$cfields = explode(',', $matches[1]);
		// check if there is an st_Name (only apply this check if moving to a certain status
		foreach ($cfields as $field)
		{
			if (preg_match('/^stName\=\.*/', $field))
			{
				$tmp = $field;
				$tmp = str_replace('stName=','',$tmp);
				$check_st_Name[] = $tmp;
			}
			else
			{
				$check_fields[] = $field;
			}
		}
	}

	$apply_rule = true;

	if (count($check_st_Name) > 0)
	{
		$apply_rule = false;
		// only apply the required_fields if moving to a st_Name that is passed in.
		foreach ($check_st_Name as $c_st_Name)
		{
			if ($st_Name == $c_st_Name)
				$apply_rule = true;
		}
	}

	if ($apply_rule == false) 	// skipping because not in a status queue we are changing to
		return array(0,'Change status does not require field validation - skipped.');

	if (count($check_fields) != 2)
		return array(1,'Must have source and destination to copy a field value to another field.' );

	$src_field = $check_fields[0];
	$dst_field = $check_fields[1];

	// get the src fid. get the value of the src field - if the src field doesn't exist then assume "" (in case blanking out value in dst field)
	$src_fid = get_fid($src_field);
	if (!$src_fid)
		return array(1,"Copy field failed. Source field ID ($src_field) does not exist.");

  $src_val = '';
  $fvalres = myload("SELECT fv_Value FROM tbl_field_values WHERE fv_fi_ID=$src_fid AND fv_co_id=".$post['fco_ID']." ORDER BY fv_Datetimestamp DESC LIMIT 1");
  if (isset($fvalres[0][0]))
    $src_val = $fvalres[0][0];

	if (!set_control_field ($co_ID, $dst_field, $src_val, $user))
		return array(0,"Failed to set destination control field ($dst_field) with source control field ($src_field) value ($src_val)");

	return array(0,"Source control field ($src_field) value copied to destination control field ($dst_field).");
}

/**
 * Route Enrollment Application by type.
 *
 * @param array $post
 * @param string $user
 * @return array
 */
function MCS_ENR_APPTYPE_Route($post=array(), $user='')
{
  /*
   * ussage: MCS_ENR_APPTYPE_Route
   * - ticket: 907603
   * - moves record to status based upon APPTYPE value
      APPTYPE value                             Status Queue      Status Desc
      '855B'                                    MCS_ENR2_SRSPEC   MCS Prov Enr Sr. Specialist
      '855B - Submitted Base on NPI Issue'      MCS_ENR2_SRSPEC   MCS Prov Enr Sr. Specialist
      '855B Chg'                                MCS_ENR2_SRSPEC   MCS Prov Enr Sr. Specialist
      '855B Chg - Submitted Based on NPI Issue' MCS_ENR2_SRSPEC   MCS Prov Enr Sr. Specialist
      '855I'                                    MCS_ENR2_SRSPEC   MCS Prov Enr Sr. Specialist
      '855I - Submitted Based on NPI Issue'     MCS_ENR2_SRSPEC   MCS Prov Enr Sr. Specialist
      '855I Chg'                                MCS_ENR2_SRSPEC   MCS Prov Enr Sr. Specialist
      '855I Chg - Submitted Based on NPI Issue' MCS_ENR2_SRSPEC   MCS Prov Enr Sr. Specialist
      '855R - Chg/Term'                         MCS_ENR2_SRSPEC   MCS Prov Enr Sr. Specialist
      '855R (45 days)'                          MCS_ENR2_SRSPEC   MCS Prov Enr Sr. Specialist
      '855R (60 days)'                          MCS_ENR2_SRSPEC   MCS Prov Enr Sr. Specialist
      'Cap'                                     MCS_ENR2_SRSPEC   MCS Prov Enr Sr. Specialist
      'Letters'                                 MCS_ENR2_SRSPEC   MCS Prov Enr Sr. Specialist
      'PAR'                                     MCS_ENR2_SRSPEC   MCS Prov Enr Sr. Specialist
      'EFT 588'                                 MCS_ENR2_EFT      MCS Prov Enr EFT
      'Missing Information'                     MCS_ENR2_MISS     MCS Prov Enr Missing Information
      'Other'                                   MCS_ENR2_MISS     MCS Prov Enr Missing Information
      'Opt-Out'                                 MCS_ENR2_ROS      MCS Prov Enr ROS
      'Revocation'                              MCS_ENR2_ROS      MCS Prov Enr ROS
      'Sanction'                                MCS_ENR2_ROS      MCS Prov Enr ROS
   */

   $MCS_ENR2_SRSPEC_APPTYPE = array('855B', '855B - Submitted Base on NPI Issue',
    '855B Chg', '855B Chg - Submitted Based on NPI Issue',
    '855I', '855I - Submitted Based on NPI Issue', '855I Chg',
    '855I Chg - Submitted Based on NPI Issue',
    '855R - Chg/Term', '855R (45 days)', '855R (60 days)', 'Cap', 'Letters', 'PAR'
   );

   $MCS_ENR2_EFT_APPTYPE = array('EFT 588');
   $MCS_ENR2_MISS_APPTYPE = array('Missing Information', 'Other');
   $MCS_ENR2_ROS_APPTYPE = array('Opt-Out','Revocation','Sanction');

  if (!isset($post['fco_ID']) || (!is_numeric($post['fco_ID'])))
    return array(1,'No record # passed to MCS_ENR_APPTYPE_Route.');

	if (($user == '') && (isset($_SESSION['username'])) && ($_SESSION['username'] != ''))
		$user = $_SESSION['username'];
	else if ($user == '')
		$user = 'POWER';

  $co_ID = $post['fco_ID'];

  /* check if the TRACK field is already SET */
  $APPTYPE_fid = get_fid('APPTYPE');

  if ( (!$APPTYPE_fid) || (!is_numeric($APPTYPE_fid)) )
    return array(1,'Missing APPTYPE control field');

  // check for required status queue before continuing
  $MCS_ENR2_SRSPEC_stid =get_st_ID('MCS_ENR2_SRSPEC');
  if (!$MCS_ENR2_SRSPEC_stid)
    return array(1,'Missing MCS_ENR2_SRSPEC status queue');

  $MCS_ENR2_EFT_stid    =get_st_ID('MCS_ENR2_EFT');
  if (!$MCS_ENR2_EFT_stid)
    return array(1,'Missing MCS_ENR2_EFT status queue');

  $MCS_ENR2_MISS_stid   =get_st_ID('MCS_ENR2_MISS');
  if (!$MCS_ENR2_MISS_stid)
    return array(1,'Missing MCS_ENR2_MISS status queue');

  $MCS_ENR2_ROS_stid    =get_st_ID('MCS_ENR2_ROS');
  if (!$MCS_ENR2_ROS_stid)
    return array(1,'Missing MCS_ENR2_ROS status queue');

  $APPTYPE_val = '';

  $res = myload("SELECT fv_Value FROM tbl_field_values WHERE fv_fi_ID=$APPTYPE_fid AND fv_co_ID=$co_ID LIMIT 1");
  if (isset($res[0][0]))
    $APPTYPE_val = $res[0][0];

  unset($res);

  if ($APPTYPE_val == '')
    return array(0,'Application Type auto-route: APPTYPE not set.');

  $st_ID = '';

  if (in_array($APPTYPE_val,$MCS_ENR2_SRSPEC_APPTYPE))
    $st_ID = $MCS_ENR2_SRSPEC_stid;
  else if (in_array($APPTYPE_val,$MCS_ENR2_EFT_APPTYPE))
    $st_ID = $MCS_ENR2_EFT_stid;
  else if (in_array($APPTYPE_val,$MCS_ENR2_MISS_APPTYPE))
    $st_ID = $MCS_ENR2_MISS_stid;
  else if (in_array($APPTYPE_val,$MCS_ENR2_ROS_APPTYPE))
    $st_ID = $MCS_ENR2_ROS_stid;

  if ($st_ID != '')
  {
    $update_status = set_corr_status ($co_ID, $st_ID, $fcomment, $post, $user, true);

		if ($update_status[0] == false)
		{
      return array(1,'Application Type auto-route: Failed to route record: '.$update_status[1]);
		}
		else
		{
      return array(0,'Application Type auto-route: Record routed.');
    }
  }
}

/**
 * Build Enrollment Tracking Number.
 *
 * @param array $post
 * @param string $user
 * @return array
 */
function MCS_ENR_tracking_number($post=array(), $user='')
{
/**
 *
 * usage:  MCS_ENR_tracking_number
 * - ticket: 905300
 * - intended to be used as post-code function for MCS_ENR2_SORT
 * - unique value applicable for TRACK field within current Workflow
 *
 * State/Year/Julian Date/001/Sequential Number
 *
 *  State: 2 digit
 *  Year: 2 digit
 *  Julian Date: 3 digit
 *  001: fixed value
 *  Sequential Number: 3 digit, starts at 001
 *
 *  Tracking number must be unique
 *
 *  Field name (TRACK) Tracking Number
 */

  if (!isset($post['fco_ID']) || (!is_numeric($post['fco_ID'])))
    return array(1,'No record # passed to MCS_ENR_tracking_number.');

	if (($user == '') && (isset($_SESSION['username'])) && ($_SESSION['username'] != ''))
		$user = $_SESSION['username'];
	else if ($user == '')
		$user = 'POWER';

  $co_ID = $post['fco_ID'];

  /* check if the TRACK field is already SET */
  $TRACK_fid = get_fid('TRACK');

  if ( (!$TRACK_fid) || (!is_numeric($TRACK_fid)) )
    return array(1,'Missing TRACK control field');

  $res = myload("SELECT fv_Value FROM tbl_field_values WHERE fv_co_ID=$co_ID AND fv_fi_ID=$TRACK_fid");
  if ( (isset($res[0][0])) && (trim($res[0][0]) != '') )
    return array(0,'Tracking number already set - skipped setting value.');

  unset($res);

  /* Get the record co_region  for the State */
  $state = '';
  $res = myload("SELECT co_region,co_queue,co_receivedate FROM tbl_corr WHERE co_ID=$co_ID");
  if (!isset($res[0][0]))
    return array(1,'No State/Region code set.');

  $state = $res[0][0];
  $curr_st_ID = $res[0][1];
  $rec_date = $res[0][2];

  unset($res);

  /* Get the YY and day of year */
  $thisdate=strtotime($rec_date);
  $YY_yday=sprintf("%05d",sprintf("%02d",date("y",$thisdate)).sprintf("%03d",date("z",$thisdate))+1);

  $getlast = $state . $YY_yday . '001';
  $setval = $getlast . '001';

  $statuses = array();

  $res = myload("SELECT t2.st_ID FROM tbl_statuses AS t1 INNER JOIN tbl_statuses AS t2 ON t1.st_ct_id=t2.st_ct_ID AND t2.st_Active='y' WHERE t1.st_ID=$curr_st_ID AND t1.st_Active='y'");
  for($i=0,$ic=count($res);$i<$ic;$i++)
    $statuses[] = $res[$i][0];

  unset($res);

  // Get the last tracking number value, if any, in this workflow (we know current status)
  $res = myload("SELECT fv_Value FROM tbl_field_values INNER JOIN tbl_corr ON fv_co_ID=co_ID WHERE fv_fi_ID=$TRACK_fid and fv_Value IS NOT NULL AND fv_Value like '{$getlast}%' AND co_queue IN (".implode(',',$statuses).") ORDER BY fv_Value DESC LIMIT 1");

  if ( (isset($res[0][0])) && ($res[0][0] != '') )
  {
    $lastval = $res[0][0];
    // increment $lastval and make that the $setval
    $last_seq = substr($lastval,-3);
    $last_seq = (int)$last_seq + 1;
    $setval = $getlast . sprintf("%03d",$last_seq);
  }

  unset($res);

	if (!set_control_field ($co_ID, 'TRACK', $setval, $user))
		return array(0,"Failed to set control field ($dst_field) with value ($value)");

	return array(0,"Control field (TRACK) value set ($setval).");
}

/**
 * Set a control field with a value, date, etc. if a condition is met
 *
 * Called set_field_if('SomePOWERVar==SomeValue',FIELD,'VALUE',overwrite='false')
 * Optional: overwrite='false' Description: Set the value of a field.
 * Conditional operators: == < >
 *
 * @param array $post
 * @param string $user
 * @return array
 */
function set_field_if($post=array(), $user='')
{
	if (!isset($post['codeparams']) || (!preg_match('/^(.*)$/',$post['codeparams'])) || ($post['codeparams']=='()'))
 		return array(1,"No parameters sent to set_field function. Setup error.");

	if (!isset($post['fco_ID']) || (!is_numeric($post['fco_ID'])))
 		return array(1,'No record # passed to set_field.');

	if ( (!isset($post['fst_id'])) || (!isset($post['fco_ID'])) )
		return array(1,'Status or record # missing' );

	if (($user == '') && (isset($_SESSION['username'])) && ($_SESSION['username'] != ''))
		$user = $_SESSION['username'];
	else if ($user == '')
		$user = 'POWER';

  $codeparams = $post['codeparams'];

  if (preg_match('/^\((.*)\)$/', $codeparams, $matches)) {
		$cfields = array();
		if (isset($matches[1])) {
			$passon = $matches[1]; // used to pass on at the end
			$cfields = explode(',', $matches[1]);
			if (count($cfields) < 3) {
				return array(1,'Must have a condition field followed by a control field name and value.' );
			}

			// the condition field is in $cfields[0], format = 'HR_EECCOID==ANXZY'

			if (preg_match('/^\'([A-Za-z0-9_]*)(={2}|[<>]+)(.*)\'$/', $cfields[0], $matches)) {
				// at this point, we only allow SomeVar on the LHS
				//                              a constant or SomeVar on the RHS
				if (isset($matches[1])) {
					$lhs = get_field($post['fco_ID'], $matches[1]);
				}

				$ops = $matches[2];   // checked by regexp
				$rhs = is_string($matches[3]) ? $matches[3] : is_numeric($matches[3]) ? $matches[3] : null;
				if (!isset($rhs)) {
					return array(1,'Queue pre/init/post code is in wrong format');
				}
				if (preg_grep('/[\#\!\/]./', explode('/', $rhs))) {
					// let's not allow #!/bin/sh and alike
					return array(1,'Queue pre/init/post code is in wrong format (2)');
				}

				if (!isset($lhs)) $lhs='';

				if (isset($lhs) && isset($ops) && isset($rhs)) {
					//echo 'Valid condition : ', $lhs . ' ' . $ops . ' ' . $rhs . "\n";
					if ($rhs=='NULL'){
						$rhs='';
					}
					$eval_code = "return '".$lhs."'".$ops."'".$rhs."';";

					if (eval($eval_code)) {

						// The condition is met

						$pass_on_slice = array_slice(explode(',', $passon), 1);
						// codeparams needs to be specified within ()
						$pass_on['codeparams'] = '(' . implode(',', $pass_on_slice) . ')';
						$pass_on['fco_ID'] = $post['fco_ID'];
						$pass_on['fst_id'] = $post['fst_id'];

						return set_field($pass_on, $user);

					}
					// the condition is not met, continue
					return array(0,'');
				}
				return array(1,'Error in set_field_if condition parameter');
			}
		}
	}
  return array(1,'Wrong condition format in set_field_if');
}

/**
 * Set a control field with a value, date, etc.
 *
 * Called set_field(FIELD,'VALUE',overwrite='false')
 * Optional: overwrite='false' Description: Set the value of a field.
 * To populate the field with the current date use 'DATE' or for date and time 'DATETIME'.
 * To populate the field with the current user's username use 'USERNAME', current users first
 * and last name use 'USERFULLNAME', current users email address use 'USEREMAIL'.
 * For USERFULLNAME the first and last name must be defined for the user.
 * For the USEREMAIL the users email address must be defined.
 *
 * @param array $post
 * @param string $user
 * @return array
 */
function set_field($post=array(), $user='')
{
	if (!isset($post['codeparams']) || (!preg_match('/^(.*)$/',$post['codeparams'])) || ($post['codeparams']=='()'))
 		return array(1,"No parameters sent to set_field function. Setup error.");
	if (!isset($post['fco_ID']) || (!is_numeric($post['fco_ID'])))
 		return array(1,'No record # passed to set_field.');

	if ( (!isset($post['fst_id'])) || (!isset($post['fco_ID'])) )
		return array(1,'Status or record # missing' );

	if (($user == '') && (isset($_SESSION['username'])) && ($_SESSION['username'] != ''))
		$user = $_SESSION['username'];
	else if ($user == '')
		$user = 'POWER';

	$codeparams = $post['codeparams']; // this can be (FIELD1,FIELD2)
	$co_ID = $post['fco_ID'];

	$check_fields = array();
	$check_st_Name = array(); // if st_Name=something is included in the parameters this checked will only be done for this status

	if (preg_match('/^\((.*)\)$/', $codeparams, $matches))
	{
		$cfields = array();
		if (isset($matches[1]))
	  	$cfields = explode(',', $matches[1]);
		// check if there is an st_Name (only apply this check if moving to a certain status
		foreach ($cfields as $field)
		{
			if (preg_match('/^stName\=\.*/', $field))
			{
				$tmp = $field;
				$tmp = str_replace('stName=','',$tmp);
				$check_st_Name[] = $tmp;
			}
			else
			{
				$check_fields[] = $field;
			}
		}
	}

	$apply_rule = true;

	if (count($check_st_Name) > 0)
	{
		$apply_rule = false;
		// only apply the required_fields if moving to a st_Name that is passed in.
		foreach ($check_st_Name as $c_st_Name)
		{
			if ($st_Name == $c_st_Name)
				$apply_rule = true;
		}
	}

	if ($apply_rule == false) 	// skipping because not in a status queue we are changing to
		return array(0,'Change status does not require field validation - skipped.');

	if (count($check_fields) < 2)
		return array(1,'Must have control field name and value.' );

	$dst_field = $check_fields[0];
	$value = $check_fields[1];
	$pre_str = '';

  $dst_field = str_replace("'",'',$dst_field);

  if ( ($dst_field == 'REGION') || ($dst_field == 'co_region') ) // setting state/region code
  {
    $value = trim($value);
    $value = preg_replace("/^'/", '', $value);
    $value = preg_replace("/'$/", '', $value);
    $value = preg_replace("/^\"/",'', $value);
    $value = preg_replace("/\"$/", '', $value);

    if (isset($check_fields[2]) && ($check_fields[2] == "overwrite='false'"))
    {
      $exval2 = myload("SELECT co_region FROM tbl_corr WHERE co_ID=$co_ID");
      if ( (isset($exval2[0][0])) && ($exval2[0][0] != 0) )
        return array(0,"Overwrite disabled: State/region code already set (".$exval2[0][0].").");
    }

    if (is_numeric($value))
    {
      $sql="CALL sp_change_region($co_ID,$value,'$user',NOW())";
      $res = myexecute( $sql );

      if (!$res)
        return array(0,"Failed to set state/region code with value ($value)");

      return array(0,"State/region code value set ($value).");
    }
    else
    {
      return array(0,"Failed to set state/region code with value ($value)");
    }
  }

	if (isset($check_fields[2]) && ($check_fields[2] == "overwrite='false'"))
	{
		// if this control field already has a value then we won't set it
		$dfid = get_fid($dst_field);
		if (($dfid) && (is_numeric($dfid)))
		{
			$exval = myload("SELECT fv_Value FROM tbl_field_values WHERE fv_co_ID=$co_ID AND fv_fi_ID=$dfid");
			if (isset($exval[0][0]))
		    return array(0,"Overwrite disabled: Control field ($dst_field) already set (".$exval[0][0].").");
		}
	}

	if (isset($check_fields[2]) && ($check_fields[2] == "overwrite='blank'"))
	{
		// if this control field already has a value then we won't set it
		$dfid = get_fid($dst_field);
		if (($dfid) && (is_numeric($dfid)))
		{
			$exval = myload("SELECT fv_Value FROM tbl_field_values WHERE fv_co_ID=$co_ID AND fv_fi_ID=$dfid");
			if ( (isset($exval[0][0])) && (strlen($exval[0][0]) > 0) )
		    return array(0,"Overwrite disabled: Control field ($dst_field) not blank - already set (".$exval[0][0].").");
		}
	}
	if (isset($check_fields[2]) && ($check_fields[2] == "overwrite='append'"))
	{
		// if this control field already has a value then we won't set it
		$dfid = get_fid($dst_field);
		if (($dfid) && (is_numeric($dfid)))
		{
			$exval = myload("SELECT fv_Value FROM tbl_field_values WHERE fv_co_ID=$co_ID AND fv_fi_ID=$dfid");
			if ( (isset($exval[0][0])) && (strlen($exval[0][0]) > 0) )
				$pre_str = $exval[0]['fv_Value'].';'; // separate multi values with semi colon, important for email 
		}
	}

	// get the src fid. get the value of the src field - if the src field doesn't exist then assume "" (in case blanking out value in dst field)
	$value = preg_replace("/^'/", '', $value);
	$value = preg_replace("/'$/", '', $value);
	$value = preg_replace("/^\"/",'', $value);
	$value = preg_replace("/\"$/", '', $value);

	if ($value == 'DATE')
	{
		$value=date("Y-m-d");
	}
	else if ($value == 'DATETIME')
  {
		$value=date("Y-m-d G:i:s");
	}
	else if ($value == 'blank')
  {
    // make sure its not already blank
		$dfid = get_fid($dst_field);
		if (($dfid) && (is_numeric($dfid)))
		{
			$exval = myload("SELECT fv_Value FROM tbl_field_values WHERE fv_co_ID=$co_ID AND fv_fi_ID=$dfid");
			if ( (!isset($exval[0][0]) || ((isset($exval[0][0])) && ($exval[0][0] == '')) ) )
		    return array(0,"Control field ($dst_field) already blank.");
		}
		$value='';
	}
  else if ($value == 'USERNAME')
	{
    if (isset($_SESSION['username']))
      $value = $_SESSION['username'];
		else
		  $value = $user;
	}
  else if ( ($value == 'USERFULLNAME') && (isset($_SESSION['username'])) ) // Lookup user full name
  {
    $utmp = $_SESSION['username'];
		$ures = myload("SELECT uFirst,uLast FROM tbl_users where uUsername='$utmp' AND uActive='y'");
		if ( (isset($ures[0]['uFirst'])) && (isset($ures[0]['uLast'])) )
      $value = $ures[0]['uFirst'] . ' ' . $ures[0]['uLast'];
	}
  else if ( ($value == 'USEREMAIL')&& (isset($_SESSION['username'])) ) // Lookup users email address
	{
    $utmp = $_SESSION['username'];
    $ures = myload("SELECT uEmail FROM tbl_users where uUsername='$utmp' AND uActive='y'");
    if (isset($ures[0]['uEmail']))
      $value = $ures[0]['uEmail'];
	}
  else if (preg_match("/DATE\s(.*)$/",$value,$mdate))
  {
  	$value = '';
  	// Today's date + or - a certain number of days
		$value_tmp = date ("Y-m-d", strtotime($mdate[1]));
    if ($value_tmp != false)
      $value = $value_tmp;
  }
  else if (preg_match("/DATE\[(.*)\](.*)$/",$value,$mdate)) // DATE[controlfieldname] +3 days
  {
    $value = '';
		$cfield = $mdate[1];
		$extras = $mdate[2];
		$dfid = get_fid($cfield);
		if ($dfid != false)
		{
      // check to see if field is a date
      $ft_res = myload("SELECT fi_ft_Name FROM tbl_fields WHERE fi_ID = $dfid");
      if (isset($ft_res[0]['fi_ft_Name']) &&  $ft_res[0]['fi_ft_Name'] != 'date') {
        // if field isn't a date, use date field was set
        $fd_res = myload("SELECT DATE_FORMAT(fv_datetimestamp,'%Y-%m-%d') AS fdate FROM tbl_field_values WHERE fv_co_ID=$co_ID AND fv_fi_ID=$dfid");
      }
      else {
        $fd_res = myload("SELECT DATE_FORMAT(fv_Value,'%Y-%m-%d') AS fdate FROM tbl_field_values WHERE fv_co_ID=$co_ID AND fv_fi_ID=$dfid");
      }
      if ( (isset($fd_res[0]['fdate'])) && ($fd_res[0]['fdate'] != '') )
      {
        $fielddate = $fd_res[0]['fdate'];
		    if ($extras == '')
				{
					$value = $fielddate;
				}
				else
				{
          $value_tmp = date ("Y-m-d", strtotime("$fielddate " . $extras));
          if ($value_tmp != false)
            $value = $value_tmp;
				}
			}
    }
  }
  else if (preg_match("/DATETIME\s(.*)$/",$value,$mdate))
  {
  	$value = '';
    // Today's date + or - a certain number of days
    $value_tmp = date ("Y-m-d G:i:s", strtotime($mdate[1]));
    if ($value_tmp != false)
      $value = $value_tmp;
  }
  else if ($value == 'co_receivedate')
  {
    $co_res = myload("SELECT co_receivedate FROM tbl_corr WHERE co_ID=$co_ID");
		$value = '';
		if (isset($co_res[0]['co_receivedate']))
		  $value = $co_res[0]['co_receivedate'];
  }
  else if ($value == 'co_checkout_userID')
  {
  	$value = '';
  	if (isset($post['fcouser']))
		  $value = $post['fcouser'];
		if ($value == '') // then its not in the post variable -- lets try checking from DB -- if set_field used in an initcode it won't be set yet
		{
	    $co_res = myload("SELECT co_checkout_userID FROM tbl_corr WHERE co_ID=$co_ID");
	    $value = '';
			//var_dump($co_res);
	    if (isset($co_res[0]['co_checkout_userID']))
	      $value = $co_res[0]['co_checkout_userID'];
		}
  }
  else if (preg_match("/co_receivedate\s([\+\-].*)$/",$value,$mdate))
  {
    $co_res = myload("SELECT DATE_FORMAT(co_receivedate,'%Y-%m-%d') AS co_receivedate FROM tbl_corr WHERE co_ID=$co_ID");
    $value = '';
    if (isset($co_res[0]['co_receivedate']))
		{
      $recdate = $co_res[0]['co_receivedate'];
      $value_tmp = date ("Y-m-d", strtotime("$recdate " . $mdate[1]));
			if ($value_tmp != false)
        $value = $value_tmp;
		}
  }

	// allow in-string replacements with other fields via %FIELDNAME%
	$cont=true;
	$contcount=0;
	while($cont){
		if (preg_match("/%([A-Za-z0-9_]*)%/", $value, $m)){
			$value = str_replace('%'.$m[1].'%', trim(get_field($co_ID, $m[1])), $value);
		} else {
			$cont=false;
		}
		$contcount++;
		// I'm not sure I completely trust this logic. I'll limit this while loop to 100 times through just in case.
		if ($contcount>100) $cont=false;
	}

	$value = $pre_str.$value;

	if (!set_control_field ($co_ID, $dst_field, $value, $user))
		return array(0,"Failed to set control field ($dst_field) with value ($value)");

	return array(0,"Control field ($dst_field) value set ($value).");

}

/**
 * Check that certain required fields are populated
 *
 * Called required_fields(FIELD1,FIELD2,FIELD3)
 *
 * @param array $post
 * @param string $user
 * @return array
 */
function required_fields($post=array(), $user='')
{
	// must have the $post['codeparams'] set and it have /^(.*)$/
	if (!isset($post['codeparams']) || (!preg_match('/^(.*)$/',$post['codeparams'])) || ($post['codeparams']=='()'))
 		return array(1,"No parameters sent to required_fields function. Setup error.");

	if (!isset($post['fco_ID']) || (!is_numeric($post['fco_ID'])))
 		return array(1,'No record # passed to required_fields.');

	if ( (!isset($post['fst_id'])) || (!isset($post['fco_ID'])) )
		return array(1,'Status or record # missing' );

	$stchange_res = myload("SELECT st_Name FROM tbl_statuses WHERE st_ID=".$post['fst_id']);
	if (!isset($stchange_res[0]['st_Name']))
		return array(1,'Unknown status id ' . $post['fst_id'] );
	$st_Name = $stchange_res[0]['st_Name'];
	$st_ID = $post['fst_id'];

	$codeparams = $post['codeparams']; // this can be (FIELD1) or (FIELD1,FIELD2,and so on)
	$co_ID = $post['fco_ID'];

	$check_fields = array();
	$check_st_Name = array(); // if st_Name=something is included in the parameters this checked will only be done for this status

	if (preg_match('/^\((.*)\)$/', $codeparams, $matches))
	{
		$cfields = array();
		if (isset($matches[1]))
	  	$cfields = explode(',', $matches[1]);
		// check if there is an st_Name (only apply this check if moving to a certain status
		foreach ($cfields as $field)
		{
			if (preg_match('/^stName\=\.*/', $field))
			{
				$tmp = $field;
				$tmp = str_replace('stName=','',$tmp);
				$check_st_Name[] = $tmp;
			}
			else
			{
				$check_fields[] = $field;
			}
		}
	}

	$apply_rule = true;

	if (count($check_st_Name) > 0)
	{
		$apply_rule = false;
		// only apply the required_fields if moving to a st_Name that is passed in.
		foreach ($check_st_Name as $c_st_Name)
		{
			if ($st_Name == $c_st_Name)
				$apply_rule = true;
		}
	}

	if ($apply_rule == false) 	// skipping because not in a status queue we are changing to
		return array(0,'Change status does not require field validation - skipped.');

	// $check_fields has the field names we need to check and make sure these fields have content in them
	// special case is the STATE
  $missing_fields = array();
	foreach ($check_fields as $field)
	{
		if (preg_match('/\=/', $field)) // special case - a field must be a certain value
		{

		}
		else
		{
			if (($field == 'STATE') || ($field == 'co_region')) // check tbl_corr since not a control field
			{
				$fvalres=myload("SELECT co_region FROM tbl_corr WHERE co_ID=$co_ID");
				if ( !(isset($fvalres[0]['co_region'])) || (trim($fvalres[0]['co_region']) != '0') || (trim($fvalres[0]['co_region']) != ''))
		    	$missing_fields[] = $field;
			}
			else if (!control_field_populated($co_ID, $field))
			{
	    	$missing_fields[] = $field;
			}
		}
  }

  if ( count($missing_fields) == 0)
    return array(0,'Required fields populated.');

  $msg = '';
  if (count($missing_fields)>0)
    $msg .= 'Required fields missing data: '. implode(', ', $missing_fields) . "\n";

  return array(1,$msg );
}

/**
 * Check that certain required fields are populated
 *
 * Called xp_eval(XPATH)
 *
 * @param array $post
 * @param string $user
 * @return array
 */
function xp_eval($post=array(), $user='')
{
  require_once (dirname(__FILE__).'/../includes/xml_builder.php');
  require_once (dirname(__FILE__).'/../includes/xml_functions.php');

  if( ! isset( $post['codeparams'][0] ) )
      return array( 1, 'XPath not specified' );
  
  //  Convert the record to xml
  $xml = new xml_builder();
  $xml->start_xml( 'POWER' );
  $xml->get_Record( $post['fco_ID'] );
  
  // Evaluate XPath Query  
  $res = xpath_eval( $xml->end_xml(), $post['codeparams'][0] );

  //  Get custom return message
  $msg = 'Xpath expression';
  if( isset( $post['codeparams'][1] ) )
      $msg = $post['codeparams'][1];
    
  //  Return results
  if( empty( $res ) )
      return array( 1, $msg . ' FAILED!' );
  else
    return array( 0, $msg . ' successful' );
}
/**
 * Require that all associated records are in a closed queue, otherwise cancel queue change
 *
 * require_assoc_closed
 *
 * No input parameters
 *
 * @return array (rc,msg), rc=0 if required conditions met, rc=1 if not met
 */
function require_assoc_closed($post=array(), $user='')
{
	if (!isset($post['fco_ID']) || (!is_numeric($post['fco_ID']))) return array(1,'No record # passed to required_fields.');

	$co_ID = $post['fco_ID'];

	$assoc_rs=get_assoc_corr($co_ID, 'open');

  if (count($assoc_rs) > 0) {
		$msg='';
		foreach($assoc_rs as $r){
			if (isset($r['co_ID'])) $msg.=','.$r['co_ID'];
		}
		return array(1, 'Open associated records found: '.substr($msg,1)."\n\nThose records must be closed before record ".$co_ID." can be transferred out of its current queue.\n\n");
	} else {
  	return array(0, "All associated records are closed.\n");
	}
}

/**
 * PI document repository validation
 *
 * @param array $post
 * @param string $user
 * @return array
 */
function PI_doc_validation($post=array(), $user='')
{
	/* ticket 528431
	 * IT PI R1 Review for required documents prior to passing to the 'Approve' queue
	 * IT_PI_R1_REVIEW moving to IT_PI_R1_Approve
	 *   - use in the post-code of IT_PI_R1_REVIEW
	 * Documents that must be in the document respository in the PI_{Iteration} folder
	 *  SuperC or CHGSRPT, at least one of these is required.
	 *  COPYFTST
	 *  WHEREFS
	 *  MOVEMEMBER
	 *  RDCP
	 */

	if ( (!isset($post['fst_id'])) || (!isset($post['fco_ID'])) || (!is_numeric($post['fst_id'])) || (!is_numeric($post['fco_ID'])) )
		return array(1,'Status or record # missing' );

	$stchange_res = myload("SELECT st_Name FROM tbl_statuses WHERE st_ID=".$post['fst_id']);
	if (!isset($stchange_res[0]['st_Name']))
		return array(1,'Unknown status id ' . $post['fst_id'] );

	// must be moving to IT_PI_R1_Approve
	if ($stchange_res[0]['st_Name'] != 'IT_PI_R1_Approve')
		return array(0,'PI document validation skipped' );

	$iteration_fid = get_fid('Iteration');
	if ( (!$iteration_fid) || (!is_numeric($iteration_fid)) )
		return array(1,'No Iteration field found.');

	// see if this record has an Iteration
	$itersql ="SELECT fv_Value AS Iteration FROM tbl_field_values WHERE fv_fi_ID=$iteration_fid AND fv_co_id=".$post['fco_ID']." ORDER BY fv_Datetimestamp DESC LIMIT 1";
	$iterrs=myload($itersql);

	$iteration = 1;

	if ( (isset($iterrs[0]['Iteration'])) && ($iterrs[0]['Iteration'] != '') )
		$iteration = intval($iterrs[0]['Iteration']);

	$PI_folder = 'PI_'.$iteration;
	$files = array('Copyftst', 'WhereFS', 'RDCP', 'MoveMember', 'SuperC||CHGSRPT');

	$missing_files = array();
	$missing_infoman_control_fields = array();
	$missing_control_fields = array();

	// check if missing files in doc repo
	foreach ($files as $file)
	{
		if (preg_match('/\|\|/', $file))
		{
			$orfile = explode('||',$file);
			$found = 0;
			foreach ($orfile as $f)
			{
				$fi_filename = $PI_folder .'|'. $f . '%';
				$checkf = myload("SELECT fi_ID FROM tbl_filerefs WHERE fi_co_ID=".$post['fco_ID']." AND fi_active='y' AND fi_filename LIKE ".sql_escape_clean($fi_filename));
				if ( isset($checkf[0][0]) && (is_numeric($checkf[0][0])) )
					$found++;
			}
			if ($found == 0)
				$missing_files[] = implode(' or ', $orfile);
		}
		else
		{
			$fi_filename = $PI_folder .'|'. $file . '%';
			$checkf = myload("SELECT fi_ID FROM tbl_filerefs WHERE fi_co_ID=".$post['fco_ID']." AND fi_active='y' AND fi_filename LIKE ".sql_escape_clean($fi_filename));
			if (!((isset($checkf[0][0]) && (is_numeric($checkf[0][0])))))
				$missing_files[] = $file;
		}
	}

	if ( (count($missing_files)==0) )
		return array(0,"Required files in $PI_folder folder.");

	$msg = '';
	if (count($missing_files)>0)
		$msg .= wordwrap("Required files missing in $PI_folder folder: ". implode(', ', $missing_files) . "\n", 90);

	return array(1,$msg );

}

/**
 * PD document repository validation
 *
 * @param array $post
 * @param string $user
 * @return array
 */

function PD_doc_validation($post=array(), $user='')
{

	/* ticket 519166 - don't apply if going to IT PD Deveopment queue
	 * From IT PD Development they can move to:
	 *  IT_PD_Rev_APP - IT PD Review Approve (autoforwards to IT_PD_SUP)
	 * 	IT_PD_Rev_DEN - IT PD Review Deny (autoforwards to IT_PD_Dev)
	 * So, don't run this if moving to IT_PD_Rev_DEN
	 * ticket 649088 - don't apply if going to IT PD Supervisor Hold (IT_PD_Sup_Hold)
	 * IT_PD_Rev_DEN
	 * $post['fst_id'] is the st_ID we are moving to
	 */

	if ( (!isset($post['fst_id'])) || (!isset($post['fco_ID'])) || (!is_numeric($post['fst_id'])) || (!is_numeric($post['fco_ID'])) )
		return array(1,'Status or record # missing' );

	$stchange_res = myload("SELECT st_Name FROM tbl_statuses WHERE st_ID=".$post['fst_id']);
	if (!isset($stchange_res[0]['st_Name']))
		return array(1,'Unknown status id ' . $post['fst_id'] );

	if ($stchange_res[0]['st_Name'] == 'IT_PD_Rev_DEN')
		return array(0,'PD document and field validation skipped' );

	if ($stchange_res[0]['st_Name'] == 'IT_PD_Sup_Hold')
		return array(0,'PD document and field validation skipped' );

	// do we check for the programmer PDF or the BA PDF ?
	if ( isset($post['codeparams']) ) {
		$codeparams = $post['codeparams'];
		if( preg_match( "/type=(\w*)/i", $codeparams, $match ) )
			$programmer_or_ba = strtolower($match[1]); //    [ programmer|ba ]
	}

	// determine field ID for Iteration
  $iteration_fid = get_fid('Iteration');
  if ( (!$iteration_fid) || (!is_numeric($iteration_fid)) )
    return array(1,'No Iteration field found.');

	// see if this record has an Iteration
	$itersql ="SELECT fv_Value AS Iteration FROM tbl_field_values WHERE fv_fi_ID=$iteration_fid AND fv_co_id=".$post['fco_ID']." ORDER BY fv_Datetimestamp DESC LIMIT 1";
	$iterrs=myload($itersql);

	$iteration = 1;

	if ( (isset($iterrs[0]['Iteration'])) && ($iterrs[0]['Iteration'] != '') )
		$iteration = intval($iterrs[0]['Iteration']);

	// get PARNum
  $PARNum_fid = get_fid('PARNum');
  if ( (!$PARNum_fid) || (!is_numeric($PARNum_fid)) )
    return array(1,'No PARNum field found.');

	$pnumsql ="SELECT fv_Value AS PARNum FROM tbl_field_values WHERE fv_fi_ID=$PARNum_fid AND fv_co_id=".$post['fco_ID']." ORDER BY fv_Datetimestamp DESC LIMIT 1";
	$pnumrs=myload($pnumsql);

	$PARNum = '';
	if (isset($pnumrs[0][0]))
		$PARNum = $pnumrs[0][0];
	else
		return array(1,"No PARNum value found.");


/* Check for the following documents in the following folder
    PD_{Iteration}
    (CM0080_BA_Prgmr.pdf)  <--- Old Value
    Endvrin
    WhereFS
    RDCP
    SuperC

 * Make sure certain control fields are also populated
	 From Infoman
	 ------------
		Asgn1Svp
		Asgn2Pgr
		Asgn3BA
		Asgn4TA

	 Within POWER
	 ------------
		ReqAnalyPer
		CodPer
		UnitTstPer
*/

	$PD_folder = 'PD_'.$iteration;
	//$files = array('CM0080_BA_Prgmr.pdf', 'Endvrin', 'WhereFS', 'RDCP', 'SuperC' );   //  <---    Old version

	//var_export($post);  var_export ($programmer_or_ba); exit();

	if ($programmer_or_ba == "ba")
		$files = array('FISS_PD_REVIEW_BA.pdf', 'Endvrin', 'WhereFS', 'RDCP', 'SuperC' );
	else
		$files = array('FISS_PD_REVIEW_PROGRAMMER.pdf', 'Endvrin', 'WhereFS', 'RDCP', 'SuperC' );

	// only require Design on first iteration and $PARNum does NOT starts with 'R'
	if ( ($iteration == 1) && (!preg_match('/^R/',$PARNum)) )
		$files[] = 'Design';

	//$infoman_control_fields = array('Asgn1Svp','Asgn2Pgr','Asgn3BA','Asgn4TA');
	$infoman_control_fields = array('Asgn1Svp','Asgn2Pgr','Asgn3BA'); // remove Asgn4TA, #1631956, Jeff
	$control_fields = array('ReqAnalyPer','CodPer','UnitTstPer');

	$missing_files = array();
	$missing_infoman_control_fields = array();
	$missing_control_fields = array();

	// check if missing files in doc repo
	foreach ($files as $file)
	{
		$fi_filename = $PD_folder .'|'. $file; //   example... PD_1|FISS_PD_REVIEW_PROGRAMMER.pdf
		$checkf = myload("SELECT fi_ID FROM tbl_filerefs WHERE fi_co_ID=".$post['fco_ID']." AND fi_active='y' AND fi_filename=".sql_escape_clean($fi_filename));
		if (!((isset($checkf[0][0]) && (is_numeric($checkf[0][0])))))
		{
			$missing_files[] = $file;
		}
	}

	// check if Infoman required control fields are populates

	foreach ($infoman_control_fields as $field)
	{
		if (!control_field_populated($post['fco_ID'], $field))
			$missing_infoman_control_fields[] = $field;
	}
	// check if POWER required control fields are populates
	foreach ($control_fields as $field)
	{
		if (!control_field_populated($post['fco_ID'], $field))
			$missing_control_fields[] = $field;
	}

	if ( (count($missing_files)==0) && (count($missing_infoman_control_fields) == 0) && (count($missing_control_fields) == 0) )
		return array(0,"Required files in $PD_folder folder.");

	$msg = '';
	if (count($missing_files)>0)
		$msg .= wordwrap("Required files missing in $PD_folder folder: ". implode(', ', $missing_files) . "\n", 90);

	if (count($missing_infoman_control_fields)>0)
		$msg .= wordwrap("Required Infoman fields missing data: ". implode(', ', $missing_infoman_control_fields) . "\n", 90);

	if (count($missing_control_fields)>0)
		$msg .= wordwrap("Required control fields missing data: ". implode(', ', $missing_control_fields) . "\n", 90);

	return array(1,$msg );
}

/**
 * PD System Testing document repository validation
 *
 * @param array $post
 * @param string $user
 * @return array
 */

function PD_SysTest_doc_validation($post=array(), $user='')
{

  /*  ticket 657161 ----
        Verify "I would like to see it halt the status change to System
                Testing - Approve and System Testing - Deny New Par
                (NOT System Testing - Deny New Iteration!!), if the
                CM0080_test  is not in the document repository."
  */

	if ( (!isset($post['fst_id'])) || (!isset($post['fco_ID'])) || (!is_numeric($post['fst_id'])) || (!is_numeric($post['fco_ID'])) )
		return array(1,'Status or record # missing' );

	$stchange_res = myload("SELECT st_Name FROM tbl_statuses WHERE st_ID=".$post['fst_id']);
	if (!isset($stchange_res[0]['st_Name']))
		return array(1,'Unknown status id ' . $post['fst_id'] );

	if ($stchange_res[0]['st_Name'] == 'IT_PD_Sys_Tst_Den_ITER')
		return array(0,'System Testing document and field validation skipped' );

/* Check for the following documents in the following folder
    System Testing
    CM0080_Test.pdf
*/

	$folder = 'system Testing';
	//$files = array('CM0080_Test.pdf' ); //  <------ Old Value
	$files = array('CM0080_Test.pdf' );

	$missing_files = array();
	$missing_infoman_control_fields = array();
	$missing_control_fields = array();

	// check if missing files in doc repo
	foreach ($files as $file)
	{
		$fi_filename = $folder .'|'. $file;
	        $sql = "SELECT fi_ID FROM tbl_filerefs WHERE fi_co_ID=".$post['fco_ID']." AND fi_active='y' AND fi_filename=".sql_escape_clean($fi_filename);
		$checkf = myload($sql);
		if (!((isset($checkf[0][0]) && (is_numeric($checkf[0][0])))))
		{
			$missing_files[] = $file;
		}
	}

	if ( (count($missing_files)==0) && (count($missing_infoman_control_fields) == 0) && (count($missing_control_fields) == 0) )
		return array(0,"Required files in $folder folder.");

	$msg = '';
	if (count($missing_files)>0)
		$msg .= wordwrap("Required files missing in $folder folder: ". implode(', ', $missing_files) . "\n", 90);

	return array(1,$msg );
}

/**
 * deny_group_membership_change
 *
 * @param array $post
 * @param string $user
 * @return array
 */
function deny_group_membership_change($post=array(), $user='')
{
  if ( (!isset($post['fst_id'])) || (!isset($post['fco_ID'])) || (!is_numeric($post['fst_id'])) || (!is_numeric($post['fco_ID'])) )
    return array(1,'Status or record # missing' );

	$fco_ID=$post['fco_ID'];
	$action=get_field($fco_ID, 'QCACTION');
	$actiondesc='';

	switch ($action){
		case 'deleteuser':
			$m_parent_ID=get_group_id(get_field($fco_ID, 'QCGROUP'));
			$m_child_ID=username2uID(get_field($fco_ID, 'QCMEMBER'));
			$actiondesc='Remove user '.get_field($fco_ID, 'QCMEMBER').' from group '.get_field($fco_ID, 'QCGROUP');
			break;

		case 'insertgroup':
			$m_parent_ID=get_group_id(get_field($fco_ID, 'QCGROUP'));
			$m_child_ID =get_group_id(get_field($fco_ID, 'QCMEMBER'));
			$actiondesc='Add group '.get_field($fco_ID, 'QCMEMBER').' to group '.get_field($fco_ID, 'QCGROUP');
			break;

		case 'insertuser':
			$m_parent_ID=get_group_id(get_field($fco_ID, 'QCGROUP'));
			$m_child_ID=username2uID(get_field($fco_ID, 'QCMEMBER'));
			$actiondesc='Add user '.get_field($fco_ID, 'QCMEMBER').' to group '.get_field($fco_ID, 'QCGROUP');
			break;

		case 'deletegroup':
			$m_parent_ID=get_group_id(get_field($fco_ID, 'QCGROUP'));
			$m_child_ID =get_group_id(get_field($fco_ID, 'QCMEMBER'));
			$actiondesc='Remove group '.get_field($fco_ID, 'QCMEMBER').' from group '.get_field($fco_ID, 'QCGROUP');
			break;

		default:
			$actiondesc='Unidentified action: '.$action;
	}

	$recip='';
	// get original ticket
	$PROJECT=get_field($fco_ID, 'PROJECT');
	if ($PROJECT > 0){
		// get original ticket submitter email
		$QCSUBEMAIL=get_field($PROJECT, 'QCSUBEMAIL');
		if ($QCSUBEMAIL<>'') $recip=';'.$QCSUBEMAIL;
	}

	// get notify group owner email, but only if it's not also the original ticket submitter
	$QCGROUPOWNER=get_field($fco_ID, 'QCGROUPOWNER');
	if ($QCGROUPOWNER<>'' && $QCGROUPOWNER<>$QCSUBEMAIL) $recip.=';'.$QCGROUPOWNER;
	$recip=substr($recip, 1);

	// build email message indicating that the membership change has been performed
	$subject='POWER notification - '.$actiondesc.' (denied)';
	$message="The following POWER group membership change has been denied by the owner of the affected group:\n\n".$actiondesc;

	// send email
	$emailret=send_email($recip, $subject, $message);
	$log='Sent denied notification email to '.$recip.' - '.$subject;
	$noters=myload("CALL sp_add_activity_note (".sql_escape_clean($fco_ID).",'NOTE',".sql_escape_clean($log).",'POWER','".date("Y-m-d G:i:s")."')");
	return array(0,$emailret.' - '.$log);
}

/**
 * perform_group_membership_change
 *
 * @param array $post
 * @param string $user
 * @return array
 */
function perform_group_membership_change($post=array(), $user='')
{
  if ( (!isset($post['fst_id'])) || (!isset($post['fco_ID'])) || (!is_numeric($post['fst_id'])) || (!is_numeric($post['fco_ID'])) )
    return array(1,'Status or record # missing' );

	$retgroups=array();
	$fco_ID=$post['fco_ID'];
	$action=get_field($fco_ID, 'QCACTION');
	$rDesc='unidentified error';
	$actiondesc='';

	switch ($action){
		case 'deleteuser':
			$m_parent_ID=get_group_id(get_field($fco_ID, 'QCGROUP'));
			$m_child_ID=username2uID(get_field($fco_ID, 'QCMEMBER'));
			$actiondesc='Remove user '.get_field($fco_ID, 'QCMEMBER').' from group '.get_field($fco_ID, 'QCGROUP');
			$retgroups=fEditMembers('deleteuser',
															array('m_parent_ID' 	=>  $m_parent_ID,
																		'm_child_ID'		=>  $m_child_ID),
															'n');
			break;

		case 'insertgroup':
			$m_parent_ID=get_group_id(get_field($fco_ID, 'QCGROUP'));
			$m_child_ID =get_group_id(get_field($fco_ID, 'QCMEMBER'));
			$actiondesc='Add group '.get_field($fco_ID, 'QCMEMBER').' to group '.get_field($fco_ID, 'QCGROUP');
			$retgroups=fEditMembers('insertgroup',
															array('parent_g_ID'	=>	$m_parent_ID,
																		'child_g_ID'	=>	$m_child_ID),
															'n');
			break;

		case 'insertuser':
			$m_parent_ID=get_group_id(get_field($fco_ID, 'QCGROUP'));
			$m_child_ID=username2uID(get_field($fco_ID, 'QCMEMBER'));
			$actiondesc='Add user '.get_field($fco_ID, 'QCMEMBER').' to group '.get_field($fco_ID, 'QCGROUP');
			$retgroups=fEditMembers('insertuser',
															array('child_uID'			=>	$m_child_ID,
																		 'parent_g_ID'	=>	$m_parent_ID,
																		 'm_FromDate'		=>	'',
																		 'm_ThruDate'		=>	''),
															'n');
			break;

		case 'deletegroup':
			$m_parent_ID=get_group_id(get_field($fco_ID, 'QCGROUP'));
			$m_child_ID =get_group_id(get_field($fco_ID, 'QCMEMBER'));
			$actiondesc='Remove group '.get_field($fco_ID, 'QCMEMBER').' from group '.get_field($fco_ID, 'QCGROUP');
			$sql="SELECT m_ID FROM tbl_members WHERE m_Type='g' AND m_Status='A' AND m_parent_ID=".sql_escape_clean($m_parent_ID)." AND m_child_ID=".sql_escape_clean($m_child_ID);
			$rs=myloadslave($sql);
			if (count($rs)>0){
				$retgroups=fEditMembers('deletegroup',
																array('m_ID'			=>	$rs[0]['m_ID']),
																'n');
			} else {
				$rDesc='An invalid group membership was specified (remove group '.$m_child_ID.' from group '.$m_parent_ID.')';
			}
			break;

		default:
			$rSuccess='n';
			$rDesc="Invalid action: ".$action;
	}

	if (isset($retgroups['rSuccess']) && $retgroups['rSuccess']=='y'){
		$recip='';
		// get original ticket
		$PROJECT=get_field($fco_ID, 'PROJECT');
		if ($PROJECT > 0){
			// get original ticket submitter email
			$QCSUBEMAIL=get_field($PROJECT, 'QCSUBEMAIL');
			if ($QCSUBEMAIL<>'') $recip=';'.$QCSUBEMAIL;
		}

		// get notify group owner email, but only if it's not also the original ticket submitter
		$QCGROUPOWNER=get_field($fco_ID, 'QCGROUPOWNER');
		if ($QCGROUPOWNER<>'' && $QCGROUPOWNER<>$QCSUBEMAIL) $recip.=';'.$QCGROUPOWNER;
		$recip=substr($recip,1);

		// build email message indicating that the membership change has been performed
		$subject='POWER notification - '.$actiondesc.' (approved)';
		$message="The following POWER group membership change has been completed:\n\n".$actiondesc;

		// send email
		$emailret=send_email($recip, $subject, $message);
		$log='Sent approved notification email to '.$recip.' - '.$subject;
		$noters=myload("CALL sp_add_activity_note (".sql_escape_clean($fco_ID).",'NOTE',".sql_escape_clean($log).",'POWER','".date("Y-m-d G:i:s")."')");

		return array(0,$retgroups['rDesc'].' - '.$emailret.' - '.$log);
	} else {
		if (isset($retgroups['rDesc'])) $rDesc=$retgroups['rDesc'];
		$route_to_status='PBSI_PWRQC_REC';
		$route_to_status_st_id = get_st_ID($route_to_status);
		$fcomment="error received when performing group membership change:\n".$rDesc;
		$update_status = move_to_status ($fco_ID, $route_to_status_st_id, $fcomment, $user);
		if ($update_status[0] == false) {
			return array(1,"Failed to perform group membership change: ".$rDesc." Also failed to forward record ".$fco_ID." to status queue (".$route_to_status_st_id."): ".$update_status[1]);
		} else {
			return array(0,"Record $fco_ID forwarded to ".$route_to_status.' due to errors encountered while performing the membersihp change: '.$rDesc);
		}
	}
}

/**
 * SAG document repository validation
 *
 * @param array $post
 * @param string $user
 * @return array
 */
function SAG_doc_validation($post=array(), $user='')
{
	/* ticket 746093 ---  PRE to IT_PD_SAG
	 * Documents that must be in the document respository in the PD_1 folder
	 *   Design.doc
	 */

  if ( (!isset($post['fst_id'])) || (!isset($post['fco_ID'])) || (!is_numeric($post['fst_id'])) || (!is_numeric($post['fco_ID'])) )
    return array(1,'Status or record # missing' );

	// determine field ID for Iteration
  $iteration_fid = get_fid('Iteration');
  if ( (!$iteration_fid) || (!is_numeric($iteration_fid)) )
    return array(1,'No Iteration field found.');

	// see if this record has an Iteration
	$itersql ="SELECT fv_Value AS Iteration FROM tbl_field_values WHERE fv_fi_ID=$iteration_fid AND fv_co_id=".$post['fco_ID']." ORDER BY fv_Datetimestamp DESC LIMIT 1";
	$iterrs=myload($itersql);

	$iteration = 1;

	if ( (isset($iterrs[0]['Iteration'])) && ($iterrs[0]['Iteration'] != '') )
		$iteration = intval($iterrs[0]['Iteration']);

  //  Only required on first iteration
  if( $iteration != 1 )
		return array(0,'SAG document validation skipped' );

	$PD_folder = 'PD_1';
	$files = array('Design');

	$missing_files = array();
	$missing_infoman_control_fields = array();
	$missing_control_fields = array();

	// check if missing files in doc repo
	foreach ($files as $file)
	{
		if (preg_match('/\|\|/', $file))
		{
			$orfile = explode('||',$file);
			$found = 0;
			foreach ($orfile as $f)
			{
				$fi_filename = $PD_folder .'|'. $f . '%';
				$checkf = myload("SELECT fi_ID FROM tbl_filerefs WHERE fi_co_ID=".$post['fco_ID']." AND fi_active='y' AND fi_filename LIKE ".sql_escape_clean($fi_filename));
				if ( isset($checkf[0][0]) && (is_numeric($checkf[0][0])) )
					$found++;
			}
			if ($found == 0)
				$missing_files[] = implode(' or ', $orfile);
		}
		else
		{
			$fi_filename = $PD_folder .'|'. $file . '%';
			$checkf = myload("SELECT fi_ID FROM tbl_filerefs WHERE fi_co_ID=".$post['fco_ID']." AND fi_active='y' AND fi_filename LIKE ".sql_escape_clean($fi_filename));
			if (!((isset($checkf[0][0]) && (is_numeric($checkf[0][0])))))
				$missing_files[] = $file;
		}
	}

	if ( (count($missing_files)==0) )
		return array(0,"Required files in $PD_folder folder.");

	$msg = '';
	if (count($missing_files)>0)
		$msg .= wordwrap("Required files missing in $PD_folder folder: ". implode(', ', $missing_files) . "\n", 90);

	return array(1,$msg );

}

/**
 * Increment iteration control field. (used in PD/PI)
 *
 * @param array $post
 * @param string $user
 * @return array
 */
function increment_iteration($post=array(), $user='')
{
  if ( (!isset($post['fst_id'])) || (!isset($post['fco_ID'])) || (!is_numeric($post['fst_id'])) || (!is_numeric($post['fco_ID'])) )
    return array(1,'Status or record # missing' );

	// determine field ID for Iteration
  $iteration_fid = get_fid('Iteration');
  if ( (!$iteration_fid) || (!is_numeric($iteration_fid)) )
    return array(1,'No Iteration field found.');

	// see if this record has an Iteration
	$itersql ="SELECT fv_Value AS Iteration FROM tbl_field_values WHERE fv_fi_ID=$iteration_fid AND fv_co_id=".$post['fco_ID']." ORDER BY fv_Datetimestamp DESC LIMIT 1";
	$iterrs=myload($itersql);

	$new_iteration = 2; // If Iteration isn't defined then there already is 1, so start at 2, since this function increments the Iteration

	if ( (isset($iterrs[0]['Iteration'])) && ($iterrs[0]['Iteration'] != '') )
		$new_iteration = intval($iterrs[0]['Iteration']) + 1;

	if (($user == '') && (isset($_SESSION['username'])) && ($_SESSION['username'] != ''))
		$user = $_SESSION['username'];
	else if ($user == '')
		$user = 'POWER';

	$bssql="CALL sp_add_fieldvalue (".$post['fco_ID'].",".$iteration_fid.",$new_iteration,".sql_escape_clean($user).",NOW())";
	$bsrs=myload($bssql);

	return array(0,"Iteration value incremented to $new_iteration");
}

/**
 * Remove CCN value
 *
 * @param array $post
 * @param string $user
 * @return array
 */
function remove_ccn($post=array(), $user='')
{

  if ( (!isset($post['fst_id'])) || (!isset($post['fco_ID'])) || (!is_numeric($post['fst_id'])) || (!is_numeric($post['fco_ID'])) )
    return array(1,'Status or record # missing' );

  if (($user == '') && (isset($_SESSION['username'])) && ($_SESSION['username'] != ''))
    $user = $_SESSION['username'];
  else if ($user == '')
    $user = 'POWER';

	// determine field ID for CCN
	$ccnfid=get_fid('CCN');
	if ((!$ccnfid) || ($ccnfid == ''))
		echo "No CCN control field found! Cannot assign CCNs.";

	// determine field ID for batch size
	$bsfid=get_fid('BATCHSIZE');
  if ((!$ccnfid) || ($ccnfid == ''))
    echo "No BATCHSIZE control field found! Cannot assign CCNs.";

  if ((is_numeric($ccnfid)) && (is_numeric($bsfid)))
	{
		// unset CCN
		$ccnsql="CALL sp_add_fieldvalue (".$post['fco_ID'].",".$ccnfid.",'',".sql_escape_clean($user).",NOW())";
		$ccnrs=myload($ccnsql);

		// unset batch size
		$bssql="CALL sp_add_fieldvalue (".$post['fco_ID'].",".$bsfid.",'',".sql_escape_clean($user).",NOW())";
		$bsrs=myload($bssql);

		return array(0,'CCN and Batch Size Unset');
	}
	return array(0,'CCN and BATCHSIZE not unset');
}

/**
 * Email links, use in pre/init/post code
 *  - usage examples:
 *      email_links(to='ERECIPIENTS',info='QCACTION',links='label1;url1|label2;url2|label3;url3',opts=':AttDefaultDoc:');
 *      email_links(to='ERECIPIENTS',info='QCACTION',links='@HR_LINKS',opts=':AttDefaultDoc:');
 *  - example of control field @HRLINKS:
 *      Non Exempt;http://pbsipower/power/external/fillform.php?ID=[co_ID]|Administrative;http://pbsipower/power/external/fillform.php?ID=[co_ID]
 *  - in links, the following dynamic replacements can be used:
 *      [co_ID] = current POWER ticket number
 *  - do not use any single quotes in label or url
 *
 * @param array $post
 * @param string $user
 * @return array
 */
function email_links($post=array(), $user='')
{
	global $config;

	$co_ID = @$post['fco_ID'];

	if (!isset($post['codeparams']) || (!preg_match('/^(.*)$/',$post['codeparams'])) || ($post['codeparams']=='()'))
  {
	  $recips = "No parameters sent to email_info function. Setup error.";
  	$comment = 'Failed sending email links: ' .$recips . ' (' . __LINE__ .')';
    $noters=myload("CALL sp_add_activity_note (".sql_escape_clean($co_ID).",'NOTE',".sql_escape_clean($comment).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");
 		return array(1,$recips);
 	}

  if (!isset($post['fco_ID']) || (!is_numeric($post['fco_ID'])))
  {
	  $recips = 'No record ID passed to email_info.';
  	$comment = 'Failed sending email links: ' .$recips . ' (' . __LINE__ .')';
    $noters=myload("CALL sp_add_activity_note (".sql_escape_clean($co_ID).",'NOTE',".sql_escape_clean($comment).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");
 		return array(1,$recips);
 	}

	if ( (!isset($post['fst_id'])) || (!isset($post['fco_ID'])) || (!is_numeric($post['fst_id'])) || (!is_numeric($post['fco_ID'])) )
  {
	  $recips = 'Status or record # missing';
  	$comment = 'Failed sending email links: ' .$recips . ' (' . __LINE__ .')';
    $noters=myload("CALL sp_add_activity_note (".sql_escape_clean($co_ID).",'NOTE',".sql_escape_clean($comment).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");
 		return array(1,$recips);
 	}

	if (($user == '') && (isset($_SESSION['username'])) && ($_SESSION['username'] != ''))
		$user = $_SESSION['username'];
	else if ($user == '')
		$user = 'POWER';

	$codeparams = $post['codeparams']; // this can be (FIELD1,FIELD2)
	$co_ID = $post['fco_ID'];
	$msg = '';
	$warn = '';

	$stchange_res = myload("SELECT st_Name, st_Desc, st_ct_ID FROM tbl_statuses WHERE st_ID=".$post['fst_id']);
	if (!isset($stchange_res[0]['st_Name']))
  {
	  $recips = 'Unknown status id ' . $post['fst_id'];
  	$comment = 'Failed sending email links: ' .$recips . ' (' . __LINE__ .')';
    $noters=myload("CALL sp_add_activity_note (".sql_escape_clean($co_ID).",'NOTE',".sql_escape_clean($comment).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");
 		return array(1,$recips);
 	}

	// need our workflow type to lookup longer named of control fields to place in emails:
	$ct_ID = $stchange_res[0]['st_ct_ID'];

	$st_Name = $stchange_res[0]['st_Name'];
	$st_Desc = $stchange_res[0]['st_Desc'];

	// example in codeparams
	// (to='Asgn3BA',include='PARNum,PARDesc,Asgn1Svp,Asgn2Pgr,Asgn3BA,Asgn4TA,Asgn5SA')

	// initialize paremeters
	$to_param = $info_param = $info_subject = $link_param = $link_opts = '';


  if (preg_match("/^\(to='(.*)',info='(.*)',links='(.*)',opts='(.*)'\)$/", $codeparams, $m)) {
  	if (isset($m[1]))
    	$to_param = $m[1];
	  if (isset($m[2]))
  	  $info_param = $m[2];
	  if (isset($m[3]))
  	  $link_param = $m[3];
	  if (isset($m[4]))
  	  $link_opts= $m[4];
	} else {
	  $recips = "Invalid parameters ($codeparams) sent to email_links function. Setup error.";
  	$comment = 'Failed sending email notification: ' .$recips . ' (' . __LINE__ .')';
    $noters=myload("CALL sp_add_activity_note (".sql_escape_clean($co_ID).",'NOTE',".sql_escape_clean($comment).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");
 		return array(1,$recips);
	}

	// see if the default document for this ticket needs to be attached to the links email
	$AttDefaultDoc=false;
	if (stristr($link_opts, ':AttDefaultDoc:')) {
		$AttDefaultDoc=true;
	}

	$info_param = str_replace(' ','',$info_param);

	// $info_param could be blank, but $to_param cannot
	if ($to_param == '')
  {
	  $recips = "No To information provided for email_info function. Setup error.";
  	$comment = 'Failed sending email notification: ' .$recips . ' (' . __LINE__ .')';
    $noters=myload("CALL sp_add_activity_note (".sql_escape_clean($co_ID).",'NOTE',".sql_escape_clean($comment).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");
 		return array(1,$recips);
 	}

	$to_addr = array();
	// there could be multiple in the $to_param
	if (preg_match('/,/', $to_param))
	{
  	$tf = explode(",", $to_param);
  	foreach ($tf as $tfval)
  	{
    	if (preg_match('/@/', $tfval))
			{
				$to_addr[] = $tfval;
			}
			else
			{
				$em = get_control_field_email_address($co_ID, $tfval);
				if ((isset($em[0])) && ($em[0] == 1))
      	{
					$comment = 'Failed sending email notification: Invalid To information provided for email_info function. '.$em[1];
					$warn .= $comment;
          $noters=myload("CALL sp_add_activity_note ($co_ID,'NOTE',".sql_escape_clean($comment).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");
					/* since there can be multiples to send to - don't return here - keep going */
      	}
				else if ((isset($em[1])) && ($em[1] != ''))
				{
					//$to_addr[] = $em[1];
			  	$to_addr = explode(',', $em[1]);
				}
				else
				{
          $comment = "No email address found for $tfval";
          $warn .= $comment;
          $noters=myload("CALL sp_add_activity_note ($co_ID,'NOTE',".sql_escape_clean($comment).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");
				}
			}
		}
	}
	else
	{
  	// lookup address for this $to_param value
   	if (preg_match('/@/', $to_param))
		{
			$to_addr[] = $to_param;
		}
		else
		{
			$em = get_control_field_email_address($co_ID, $to_param);
			if ((isset($em[0])) && ($em[0] == 1))
    	{
    	  $recips = "Invalid To information provided for email_info function. ".$em[1];
      	$comment = 'Failed sending email notification: ' .$recips . ' (' . __LINE__ .')';
        $noters=myload("CALL sp_add_activity_note (".sql_escape_clean($co_ID).",'NOTE',".sql_escape_clean($comment).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");
     		return array(0,$recips);
    	}
			else if ((isset($em[1])) && ($em[1] != ''))
			{
				//$to_addr[] = $em[1];
			  $to_addr = explode(',', $em[1]);
			}
			else
    	{
    	  $recips = "No email address found for $to_param.\n";
      	$comment = 'Failed sending email notification: ' .$recips . ' (' . __LINE__ .')';
        $noters=myload("CALL sp_add_activity_note (".sql_escape_clean($co_ID).",'NOTE',".sql_escape_clean($comment).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");
     		return array(0,$recips);
    	}
		}
	}

	if (count($to_addr) == 0)
	{
	  $recips = "No email address(es) found to send email to.\n";
  	$comment = 'Failed sending email notification: ' .$recips . ' (' . __LINE__ .')';
    $noters=myload("CALL sp_add_activity_note (".sql_escape_clean($co_ID).",'NOTE',".sql_escape_clean($comment).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");
 		return array(0,$recips);
	}

	// see if the links definition is a control field
	if (substr($link_param,0,1)=='@'){
		$link_param=get_field($co_ID, substr($link_param,1));
	}

	$link_fields = array();
	$link_array = array();
	$link_fields = explode('|' , $link_param); // link options are pipe separated
	if(count($link_fields) > 0){
		foreach ($link_fields as $vf){
			// link label and link url are separated by a semicolon
  		if (preg_match("/^(.*);(.*)$/", $vf, $v)){
				$link_array[]=array('option'=>$v[1], 'desc'=>$v[2]);
			}
		}
	}

	$info_fields = explode(',' , $info_param);
	$fdetails = array();
	$f_long_names = array();
  $header_fields = array('co_region'=>'State/Region','co_receivedate'=>'Received Date','co_source'=>'Source','co_create_datetime'=>'Created Date');

	foreach ($info_fields as $fname)
	{
    if ( isset($header_fields[$fname] ) ) // if the field is a header field
    {
      $fval = get_header_field_value($co_ID, $fname);
      if ($fval)
        $fdetails[$fname] = $fval;
      else
        $fdetails[$fname] = '';
      $f_long_names[$fname] = $header_fields[$fname]; // use array for the long descriptions
    }
    else // assume it's a control field
    {
      $fval = get_field($co_ID, $fname);
      if ($fval)
        $fdetails[$fname] = $fval;
      else
        $fdetails[$fname] = '';
      // lookup the long name for this field
      $f_long_names[$fname] = get_field_long_name($ct_ID, $fname);
    }

	}

	// now we have an array the email To: ($to_addr) and an
	// array of control fields ($info_fields) along an array of the current field values ($fdetails)
	// compose and send email message

  $http_host = '';
  if ((isset($_SERVER['HTTP_X_FORWARDED_HOST'])) && ($_SERVER['HTTP_X_FORWARDED_HOST'] != '') ) // accessed via web + proxy
    $http_host = $_SERVER['HTTP_X_FORWARDED_HOST'];
	else if ((isset($_SERVER['HTTP_HOST'])) && ($_SERVER['HTTP_HOST'] != '') ) // accessed via web
    $http_host = $_SERVER['HTTP_HOST'];
  else if	((isset($config['HTTP_HOST'])) && ($config['HTTP_HOST'] != '') ) // local process (i.e. batch)
	  $http_host = $config['HTTP_HOST'];
  else
	{
	  $recips = "Failed sending email notification - HTTP_HOST not defined in config file.\n";
  	$comment = 'Failed sending email notification: ' .$recips . ' (' . __LINE__ .')';
    $noters=myload("CALL sp_add_activity_note (".sql_escape_clean($co_ID).",'NOTE',".sql_escape_clean($comment).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");
 		return array(1,$recips);
	}

  if ($config['HTTP_SSL'] == 'on')
		$http_host = 'https://' . $http_host;
  else
    $http_host = 'http://' . $http_host;

	$fsubject = get_field($co_ID, 'ESUBJECT');
	if ($fsubject)
		$subject=$fsubject;
	else
		$subject = "POWER NOTIFICATION";
	$subject.=' [c='.$co_ID.']';

	$fbody = get_field($co_ID, 'EBODY');
	if ($fbody) {
		$body=$fbody;
		$msg = $body.'<br><br>';
		$txtmsg = $body."\n\n";
	} else {
		$body = "POWER NOTIFICATION";
		$msg = $body.'<br><br>';
		$txtmsg = $body.'\n\n';
		$msg .= 'Please select one of the following links.<br><br>';
		$txtmsg .= "Please select one of the following links.\n\n";
	}

	foreach ($link_array as $v){
		$thisurl=str_replace('[co_ID]', $co_ID, $v['desc']);
		//$msg .= '<a href="mailto:'.$config['contact_email'].'?subject='.$v['option'].' [c='.$co_ID.',vote='.$v['option'].']&body='.$subject.': '.$v['desc'].'">'.$v['desc'].'</a><br>';
		$msg .= '<a href="'.$thisurl.'">'.$v['option'].'</a><br>';
		$txtmsg .= '<a href="'.$thisurl.'">'.$v['option'].'</a><br>';
	}
	$msg.='<br>';
	$msg .= '<table cellpadding=0 cellspacing=0 border=0 style="border:solid 1px #000;margin:20px 0 20px 0;padding:0;"><thead><tr style="background-color:#AFAFAF">';
	$msg .= '<th style="border:solid 1px #CFCFCF;padding:5px;" colspan=2>Details</th></tr></thead>';
	$msg .= "<tbody>";
	$txtmsg.= "\nDetails:\n--------\n";
	$bg = '#FFF';

	foreach ($info_fields as $fname)
	{
		$txtmsg.= $f_long_names[$fname] . ': '. $fdetails[$fname] . "\n";
		if ($fdetails[$fname] == '')
			$fdetails[$fname] = '&nbsp;';
		$fdetails[$fname] = str_replace("\n",'<br>',$fdetails[$fname]);
		$msg .= '<tr style="background-color:'.$bg.';"><td style="border:solid 1px #CFCFCF;padding:5px;vertical-align:top;">' . $f_long_names[$fname] . '</td><td style="border:solid 1px #CFCFCF;padding:5px;">'. $fdetails[$fname] . ' </td></tr>';
		if ($bg == '#EFEFEF')
			$bg = '#FFF';
		else
			$bg = '#EFEFEF';
	}
	$msg .= '</tbody></table>';

	$msg.= "<br>Contact the <a href='mailto:powersupport@pinnaclebsi.com'>POWER support</a> team if you encounter problems with these links.\n";
	$txtmsg.= "\n\nContact the POWER support team if you encounter problems with these links.\n";

  if ($warn != '') /* include warning in outgoing email per Ticket # 824321 */
	{
    $msg.= '<p style="color:red;font-weight:bold;">Warning: '.$warn.'</p>';
		$txtmsg.= 'Warning: ' . $warn . "\n";
	}
	$notify = implode(";",$to_addr);

  $recips = send_status_change_notification_with_info($notify, $subject, $msg, $txtmsg, $co_ID, $AttDefaultDoc);

	if (preg_match('/email notification sent to/', $recips))
	{
		$comment = $recips;
	  $noters=myload("CALL sp_add_activity_note (".sql_escape_clean($co_ID).",'NOTE',".sql_escape_clean($comment).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");
		if ($warn != '')
		  $recips .= ' Warning:' . $warn;
		return array(0, "$recips\n");
	}

	$comment = 'Failed sending links email notification: ' .$recips . ' (' . __LINE__ .')';
	$noters=myload("CALL sp_add_activity_note (".sql_escape_clean($co_ID).",'NOTE',".sql_escape_clean($comment).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");

	return array(1, "Failed sending links email notification: $recips\n");
}

/**
 * Email vote
 *  - usage example - in pre/init code:
 *    email_vote(to='ERECIPIENTS',info='QCACTION',vote='Approve=I approve this action|Deny=I DO NOT approve this action',opts=':AttDefaultDoc:');
 * @param array $post
 * @param string $user
 * @return array
 */
function email_vote($post=array(), $user='')
{
	global $config;

	$co_ID = @$post['fco_ID'];

	if (!isset($post['codeparams']) || (!preg_match('/^(.*)$/',$post['codeparams'])) || ($post['codeparams']=='()'))
  {
	  $recips = "No parameters sent to email_info function. Setup error.";
  	$comment = 'Failed sending email notification: ' .$recips . ' (' . __LINE__ .')';
    $noters=myload("CALL sp_add_activity_note (".sql_escape_clean($co_ID).",'NOTE',".sql_escape_clean($comment).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");
 		return array(1,$recips);
 	}

  if (!isset($post['fco_ID']) || (!is_numeric($post['fco_ID'])))
  {
	  $recips = 'No record ID passed to email_info.';
  	$comment = 'Failed sending email notification: ' .$recips . ' (' . __LINE__ .')';
    $noters=myload("CALL sp_add_activity_note (".sql_escape_clean($co_ID).",'NOTE',".sql_escape_clean($comment).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");
 		return array(1,$recips);
 	}

	if ( (!isset($post['fst_id'])) || (!isset($post['fco_ID'])) || (!is_numeric($post['fst_id'])) || (!is_numeric($post['fco_ID'])) )
  {
	  $recips = 'Status or record # missing';
  	$comment = 'Failed sending email notification: ' .$recips . ' (' . __LINE__ .')';
    $noters=myload("CALL sp_add_activity_note (".sql_escape_clean($co_ID).",'NOTE',".sql_escape_clean($comment).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");
 		return array(1,$recips);
 	}

	if (($user == '') && (isset($_SESSION['username'])) && ($_SESSION['username'] != ''))
		$user = $_SESSION['username'];
	else if ($user == '')
		$user = 'POWER';

	$codeparams = $post['codeparams']; // this can be (FIELD1,FIELD2)
	$co_ID = $post['fco_ID'];
	$msg = '';
	$warn = '';

	$stchange_res = myload("SELECT st_Name, st_Desc, st_ct_ID FROM tbl_statuses WHERE st_ID=".$post['fst_id']);
	if (!isset($stchange_res[0]['st_Name']))
  {
	  $recips = 'Unknown status id ' . $post['fst_id'];
  	$comment = 'Failed sending email notification: ' .$recips . ' (' . __LINE__ .')';
    $noters=myload("CALL sp_add_activity_note (".sql_escape_clean($co_ID).",'NOTE',".sql_escape_clean($comment).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");
 		return array(1,$recips);
 	}

	// need our workflow type to lookup longer named of control fields to place in emails:
	$ct_ID = $stchange_res[0]['st_ct_ID'];

	$st_Name = $stchange_res[0]['st_Name'];
	$st_Desc = $stchange_res[0]['st_Desc'];

	// example in codeparams
	// (to='Asgn3BA',include='PARNum,PARDesc,Asgn1Svp,Asgn2Pgr,Asgn3BA,Asgn4TA,Asgn5SA')

	// initialize paremeters
	$to_param = $info_param = $info_subject = $vote_param = $vote_opts = '';

  //if (preg_match("/^\(to='(.*)',info='(.*)',vote='(.*)'\)$/", $codeparams, $m))
  if (preg_match("/^\(to='(.*)','info=\"(.*)\"',vote='(.*)',opts='(.*)'\)$/", $codeparams, $m))
	{
  	if (isset($m[1]))
    	$to_param = $m[1];
	  if (isset($m[2]))
  	  $info_param = $m[2];
	  if (isset($m[3]))
  	  $vote_param = $m[3];
	  if (isset($m[4]))
  	  $vote_opts= $m[4];
	}
	else
	{
	  $recips = "Invalid parameters ($codeparams) sent to email_vote function. Setup error.";
  	$comment = 'Failed sending email notification: ' .$recips . ' (' . __LINE__ .')';
    $noters=myload("CALL sp_add_activity_note (".sql_escape_clean($co_ID).",'NOTE',".sql_escape_clean($comment).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");
 		return array(1,$recips);
	}

	// see if the default document for this ticket needs to be attached to the vote email
	$AttDefaultDoc=false;
	if (stristr($vote_opts, ':AttDefaultDoc:')) {
		$AttDefaultDoc=true;
	}

	$info_param = str_replace(' ','',$info_param);

	// $info_param could be blank, but $to_param cannot
	if ($to_param == '')
  {
	  $recips = "No To information provided for email_info function. Setup error.";
  	$comment = 'Failed sending email notification: ' .$recips . ' (' . __LINE__ .')';
    $noters=myload("CALL sp_add_activity_note (".sql_escape_clean($co_ID).",'NOTE',".sql_escape_clean($comment).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");
 		return array(1,$recips);
 	}

	$to_addr = array();
	// there could be multiple in the $to_param
	if (preg_match('/,/', $to_param))
	{
  	$tf = explode(",", $to_param);
  	foreach ($tf as $tfval)
  	{
    	if (preg_match('/@/', $tfval))
			{
				$to_addr[] = $tfval;
			}
			else
			{
				$em = get_control_field_email_address($co_ID, $tfval);
				if ((isset($em[0])) && ($em[0] == 1))
      	{
					$comment = 'Failed sending email notification: Invalid To information provided for email_info function. '.$em[1];
					$warn .= $comment;
          $noters=myload("CALL sp_add_activity_note ($co_ID,'NOTE',".sql_escape_clean($comment).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");
					/* since there can be multiples to send to - don't return here - keep going */
      	}
				else if ((isset($em[1])) && ($em[1] != ''))
				{
					//$to_addr[] = $em[1];
			  	$to_addr = explode(',', $em[1]);
				}
				else
				{
          $comment = "No email address found for $tfval";
          $warn .= $comment;
          $noters=myload("CALL sp_add_activity_note ($co_ID,'NOTE',".sql_escape_clean($comment).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");
				}
			}
		}
	}
	else
	{
  	// lookup address for this $to_param value
   	if (preg_match('/@/', $to_param))
		{
			$to_addr[] = $to_param;
		}
		else
		{
			$em = get_control_field_email_address($co_ID, $to_param);
			if ((isset($em[0])) && ($em[0] == 1))
    	{
    	  $recips = "Invalid To information provided for email_info function. ".$em[1];
      	$comment = 'Failed sending email notification: ' .$recips . ' (' . __LINE__ .')';
        $noters=myload("CALL sp_add_activity_note (".sql_escape_clean($co_ID).",'NOTE',".sql_escape_clean($comment).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");
     		return array(0,$recips);
    	}
			else if ((isset($em[1])) && ($em[1] != ''))
			{
				//$to_addr[] = $em[1];
			  $to_addr = explode(',', $em[1]);
			}
			else
    	{
    	  $recips = "No email address found for $to_param.\n";
      	$comment = 'Failed sending email notification: ' .$recips . ' (' . __LINE__ .')';
        $noters=myload("CALL sp_add_activity_note (".sql_escape_clean($co_ID).",'NOTE',".sql_escape_clean($comment).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");
     		return array(0,$recips);
    	}
		}
	}

	if (count($to_addr) == 0)
	{
	  $recips = "No email address(es) found to send email to.\n";
  	$comment = 'Failed sending email notification: ' .$recips . ' (' . __LINE__ .')';
    $noters=myload("CALL sp_add_activity_note (".sql_escape_clean($co_ID).",'NOTE',".sql_escape_clean($comment).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");
 		return array(0,$recips);
	}

	$vote_fields = array();
	$vote_array = array();
	$vote_fields = explode('|' , $vote_param); // vote options are pipe separated
	if(count($vote_fields) > 0){
		foreach ($vote_fields as $vf){
  		if (preg_match("/^(.*)=(.*)$/", $vf, $v)){
				$vote_array[]=array('option'=>$v[1], 'desc'=>$v[2]);
			}
		}
	}

	$info_fields = explode(',' , $info_param);
	$fdetails = array();
	$f_long_names = array();
  $header_fields = array('co_region'=>'State/Region','co_receivedate'=>'Received Date','co_source'=>'Source','co_create_datetime'=>'Created Date');

	foreach ($info_fields as $fname)
	{
    if ( isset($header_fields[$fname] ) ) // if the field is a header field
    {
      $fval = get_header_field_value($co_ID, $fname);
      if ($fval)
        $fdetails[$fname] = $fval;
      else
        $fdetails[$fname] = '';
      $f_long_names[$fname] = $header_fields[$fname]; // use array for the long descriptions
    }
    else // assume it's a control field
    {
      $fval = get_field($co_ID, $fname);
      if ($fval)
        $fdetails[$fname] = $fval;
      else
        $fdetails[$fname] = '';
      // lookup the long name for this field
      $f_long_names[$fname] = get_field_long_name($ct_ID, $fname);
    }

	}

	// now we have an array the email To: ($to_addr) and an
	// array of control fields ($info_fields) along an array of the current field values ($fdetails)
	// compose and send email message

  $http_host = '';
  if ((isset($_SERVER['HTTP_X_FORWARDED_HOST'])) && ($_SERVER['HTTP_X_FORWARDED_HOST'] != '') ) // accessed via web + proxy
    $http_host = $_SERVER['HTTP_X_FORWARDED_HOST'];
	else if ((isset($_SERVER['HTTP_HOST'])) && ($_SERVER['HTTP_HOST'] != '') ) // accessed via web
    $http_host = $_SERVER['HTTP_HOST'];
  else if	((isset($config['HTTP_HOST'])) && ($config['HTTP_HOST'] != '') ) // local process (i.e. batch)
	  $http_host = $config['HTTP_HOST'];
  else
	{
	  $recips = "Failed sending email notification - HTTP_HOST not defined in config file.\n";
  	$comment = 'Failed sending email notification: ' .$recips . ' (' . __LINE__ .')';
    $noters=myload("CALL sp_add_activity_note (".sql_escape_clean($co_ID).",'NOTE',".sql_escape_clean($comment).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");
 		return array(1,$recips);
	}

  if ($config['HTTP_SSL'] == 'on')
		$http_host = 'https://' . $http_host;
  else
    $http_host = 'http://' . $http_host;

	$fsubject = get_field($co_ID, 'ESUBJECT');
	if ($fsubject)
		$subject=$fsubject;
	else
		$subject = "POWER VOTE REQUEST";
	$subject.=' [c='.$co_ID.']';

	$fbody = get_field($co_ID, 'EBODY');
	if ($fbody)
		$body=$fbody;
	else
		$body = "POWER VOTE REQUEST";

	$msg = 'You are being requested to vote on the following issue:<br><br>'.$body.'<br><br>';
	$txtmsg = 'You are being requested to vote on the following issue:\n\n'.$body.'\n\n';
	$msg .= 'Please select one of the following voting options, and send the email that it creates.<br><br>';
	$txtmsg .= "Please select one of the following voting options, and send the email that it creates.\n\n";

	foreach ($vote_array as $v){
		$msg .= '<a href="mailto:'.$config['contact_email'].'?subject='.$v['option'].' [c='.$co_ID.',vote='.$v['option'].']&body='.$subject.': '.$v['desc'].'">'.$v['desc'].'</a><br>';
		$txtmsg .= '<a href="mailto:'.$config['contact_email'].'?subject='.$v['option'].' [c='.$co_ID.',vote='.$v['option'].']&body='.$v['desc'].'">'.$subject.': '.$v['desc'].'</a><br>';
	}
	$msg.='<br>';
	$msg .= '<table cellpadding=0 cellspacing=0 border=0 style="border:solid 1px #000;margin:20px 0 20px 0;padding:0;"><thead><tr style="background-color:#AFAFAF">';
	$msg .= '<th style="border:solid 1px #CFCFCF;padding:5px;" colspan=2>Details</th></tr></thead>';
	$msg .= "<tbody>";
	$txtmsg.= "\nDetails:\n--------\n";
	$bg = '#FFF';

	foreach ($info_fields as $fname)
	{
		$txtmsg.= $f_long_names[$fname] . ': '. $fdetails[$fname] . "\n";
		if ($fdetails[$fname] == '')
			$fdetails[$fname] = '&nbsp;';
		$fdetails[$fname] = str_replace("\n",'<br>',$fdetails[$fname]);
		$msg .= '<tr style="background-color:'.$bg.';"><td style="border:solid 1px #CFCFCF;padding:5px;vertical-align:top;">' . $f_long_names[$fname] . '</td><td style="border:solid 1px #CFCFCF;padding:5px;">'. $fdetails[$fname] . ' </td></tr>';
		if ($bg == '#EFEFEF')
			$bg = '#FFF';
		else
			$bg = '#EFEFEF';
	}
	$msg .= '</tbody></table>';

	$msg.= "<br>Contact the <a href='mailto:powersupport@pinnaclebsi.com'>POWER support</a> team if you encounter problems casting your vote.\n";
	$txtmsg.= "\n\nContact the POWER support team if you encounter problems casting your vote.\n";

  if ($warn != '') /* include warning in outgoing email per Ticket # 824321 */
	{
    $msg.= '<p style="color:red;font-weight:bold;">Warning: '.$warn.'</p>';
		$txtmsg.= 'Warning: ' . $warn . "\n";
	}
	$notify = implode(";",$to_addr);

  $recips = send_status_change_notification_with_info($notify, $subject, $msg, $txtmsg, $co_ID, $AttDefaultDoc);

	if (preg_match('/email notification sent to/', $recips))
	{
		$comment = $recips;
	  $noters=myload("CALL sp_add_activity_note (".sql_escape_clean($co_ID).",'NOTE',".sql_escape_clean($comment).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");
		if ($warn != '')
		  $recips .= ' Warning:' . $warn;
		return array(0, "$recips\n");
	}

	$comment = 'Failed sending email notification: ' .$recips . ' (' . __LINE__ .')';
	$noters=myload("CALL sp_add_activity_note (".sql_escape_clean($co_ID).",'NOTE',".sql_escape_clean($comment).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");

	return array(1, "Failed sending email notification: $recips\n");
}

/**
 * Forward To Other Queues
 *
 * @param array $queues
 * @param string $user
 * @return boolean [true|false]
 * 
 */
/* see append_filerefs_master for Nick's example */

function forward_to_other_queues($post=array())
{
 // $post = array ( 'fco_ID' => '1964266', 'fst_id' => '2080', 'fComments' => 'Set initial queue', 'codeparams' => '(‘FSS_AUDIT_REC’,’ FSS_OVRPMTS_REC’,’ FSS_IRR_NEED’,’ FSS_CTC_RDY’)', )	
	
	$co_ID = @$post['fco_ID'];
	$codeparams =  @$post['codeparams'];

	$replace_these = array("(",")","'","\"");
	$cleaned_codeparams = str_replace($replace_these,"",$codeparams);
	$arr_cleaned_codeparams = explode(",",$cleaned_codeparams);
	
	foreach ($arr_cleaned_codeparams as $key => $queue_name)
	{
		// create new POWER ticket
		$new_corr_ID = create_new_corr();
		$status_ID = get_st_ID($queue_name);
		
		// set status
		$sql='CALL sp_change_queue (' . $new_corr_ID . ',' . $status_ID . ',"Create new record","POWER","' . date("Y-m-d G:i:s") . '","na")';
		$result = myload($sql);
		
		$url = "<a href='".$http_host.$config['systemroot']."view_rec.php?co_ID=".$new_corr_ID."'>".$new_corr_ID."</a>";
		echo "<span style='font-weight:normal;font-size:13px;'>Created POWER #" . $url . " in queue " . $queue_name . "</span><br>";
		
		
		$comment = 'Created new ticket ' . $new_corr_ID . ' from ' . $co_ID . ' in the ' . $queue_name . ' queue';
		$result = myload('CALL sp_add_activity_note (' . $new_corr_ID . ',"NOTE",' . sql_escape_clean($comment) . ',"forward_to_other_queues()","' . date("Y-m-d G:i:s") . '")');
		
    /*
     * Copy control fields 
     */

    $sql = "select fv.fv_fi_ID,fv.fv_co_ID, fi.fi_Name, fv.fv_Value from tbl_field_values fv " .
      "join tbl_fields fi on fv.fv_fi_ID = fi.fi_ID " . 
      "where fv_co_ID = " . $co_ID;
		
		$rs = myload($sql);
    
    foreach ($rs as $row)
		{
        set_control_field ($new_corr_ID,$row['fi_Name'],$row['fv_Value'],'POWER');
    }

    /*
     * Copy attached files 
     */

		//$sql = "select * from tbl_filerefs where fi_co_ID=" . $co_ID; // list of attached files
		$sql = "select * FROM tbl_filerefs WHERE fi_co_ID=" . $co_ID . " AND (fi_mimetype <> 'folder' OR fi_mimetype IS NULL)";	
		$rs = myload($sql);
		
		foreach ($rs as $row)
		{
			$docID = $row['fi_docID'];

			$sql = "INSERT INTO tbl_filerefs (
					fi_ID,
					fi_co_ID,
					fi_docID,
					fi_docID_original,
					fi_encoder,
					fi_source,
					fi_active,
					fi_desc,
					fi_datetimestamp,
					fi_userID,
					fi_bt_id,
					fi_bookmarks,
					fi_filename,
					fi_mimetype,
					fi_version,
					fi_version_top,
					fi_filesize,
					fi_category
				) VALUES (
					NULL,
					".sql_escape_clean($new_corr_ID).",
					".sql_escape_clean($row['fi_docID']).",
					".sql_escape_clean($row['fi_docID_original']).",
					".sql_escape_clean($row['fi_encoder']).",
					".sql_escape_clean($row['fi_source']).",
					".sql_escape_clean($row['fi_active']).",
					".sql_escape_clean($row['fi_desc']).",
					".sql_escape_clean($row['fi_datetimestamp']).",
					".sql_escape_clean($row['fi_userID']).",
					".sql_escape_clean($row['fi_bt_id']).",
					".sql_escape_clean($row['fi_bookmarks']).",
					".sql_escape_clean($row['fi_filename']).",
					".sql_escape_clean($row['fi_mimetype']).",
					".sql_escape_clean($row['fi_version']).",
					".sql_escape_clean($row['fi_version_top']).",
					".sql_escape_clean($row['fi_filesize']).",
					".sql_escape_clean($row['fi_category'])."
			)";

			$result = myload($sql);	
		}
	}
}

/**
 * Email info
 *
 * @param array $post
 * @param string $user
 * @return array
 */
function email_info($post=array(), $user='', $from='')
{
	global $config;

	$co_ID = @$post['fco_ID'];

	if (!isset($post['codeparams']) || (!preg_match('/^(.*)$/',$post['codeparams'])) || ($post['codeparams']=='()'))
  {
	  $recips = "No parameters sent to email_info function. Setup error.";
  	$comment = 'Failed sending email notification: ' .$recips . ' (' . __LINE__ .')';
    $noters=myload("CALL sp_add_activity_note (".sql_escape_clean($co_ID).",'NOTE',".sql_escape_clean($comment).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");
 		return array(1,$recips);
 	}

  if (!isset($post['fco_ID']) || (!is_numeric($post['fco_ID'])))
  {
	  $recips = 'No record ID passed to email_info.';
  	$comment = 'Failed sending email notification: ' .$recips . ' (' . __LINE__ .')';
    $noters=myload("CALL sp_add_activity_note (".sql_escape_clean($co_ID).",'NOTE',".sql_escape_clean($comment).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");
 		return array(1,$recips);
 	}

	if ( (!isset($post['fst_id'])) || (!isset($post['fco_ID'])) || (!is_numeric($post['fst_id'])) || (!is_numeric($post['fco_ID'])) )
  {
	  $recips = 'Status or record # missing';
  	$comment = 'Failed sending email notification: ' .$recips . ' (' . __LINE__ .')';
    $noters=myload("CALL sp_add_activity_note (".sql_escape_clean($co_ID).",'NOTE',".sql_escape_clean($comment).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");
 		return array(1,$recips);
 	}

	if (($user == '') && (isset($_SESSION['username'])) && ($_SESSION['username'] != ''))
		$user = $_SESSION['username'];
	else if ($user == '')
		$user = 'POWER';

	$codeparams = $post['codeparams']; // this can be (FIELD1,FIELD2)
	$co_ID = $post['fco_ID'];
	$msg = '';
	$warn = '';

	$stchange_res = myload("SELECT st_Name, 
																 st_Desc, 
																 st_ct_ID,
																 ct_email_subjects
													FROM tbl_statuses 
													INNER JOIN tbl_corr_types ON st_ct_ID=ct_ID
													WHERE st_ID=".$post['fst_id']);
	if (!isset($stchange_res[0]['st_Name']))
  {
	  $recips = 'Unknown status id ' . $post['fst_id'];
  	$comment = 'Failed sending email notification: ' .$recips . ' (' . __LINE__ .')';
    $noters=myload("CALL sp_add_activity_note (".sql_escape_clean($co_ID).",'NOTE',".sql_escape_clean($comment).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");
 		return array(1,$recips);
 	}

	// need our workflow type to lookup longer named of control fields to place in emails:
	$ct_ID = $stchange_res[0]['st_ct_ID'];

	$st_Name = $stchange_res[0]['st_Name'];
	$st_Desc = $stchange_res[0]['st_Desc'];
	$ct_email_subjects='POWER';
	if ($stchange_res[0]['ct_email_subjects']<>''){
		$ct_email_subjects=$stchange_res[0]['ct_email_subjects'];
	}
		

	// example in codeparams
	// (to='Asgn3BA',include='PARNum,PARDesc,Asgn1Svp,Asgn2Pgr,Asgn3BA,Asgn4TA,Asgn5SA')
	$to_param = '';
	$info_param = '';
  $info_subject = '';

	// to could contain multiple, and they could be email addresses embedded
  if (preg_match("/^\(to='(.*)',info='(.*)',subject='(.*)'\)$/", $codeparams, $m)) /* if subject set in call */
	{
  	if (isset($m[1]))
    	$to_param = $m[1];
	  if (isset($m[2]))
  	  $info_param = $m[2];
	  if (isset($m[3]))
  	  $info_subject = $m[3];
	}
  else if (preg_match("/^\(to='(.*)',info='(.*)'\)$/", $codeparams, $m))
	{
  	if (isset($m[1]))
    	$to_param = $m[1];
	  if (isset($m[2]))
  	  $info_param = $m[2];
	}
	else
	{
		//var_dump($m);
	  $recips = "Invalid parameters ($codeparams) sent to email_info function. Setup error.";
  	$comment = 'Failed sending email notification: ' .$recips . ' (' . __LINE__ .')';
    $noters=myload("CALL sp_add_activity_note (".sql_escape_clean($co_ID).",'NOTE',".sql_escape_clean($comment).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");
 		return array(1,$recips);
	}

	$info_param = str_replace(' ','',$info_param);

	// $info_param could be blank, but $to_param cannot
	if ($to_param == '')
  {
	  $recips = "No To information provided for email_info function. Setup error.";
  	$comment = 'Failed sending email notification: ' .$recips . ' (' . __LINE__ .')';
    $noters=myload("CALL sp_add_activity_note (".sql_escape_clean($co_ID).",'NOTE',".sql_escape_clean($comment).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");
 		return array(1,$recips);
 	}

	$to_addr = array();
	// there could be multiple in the $to_param
	if (preg_match('/,/', $to_param))
	{
  	$tf = explode(",", $to_param);
  	foreach ($tf as $tfval)
  	{
    	if (preg_match('/@/', $tfval))
			{
				$to_addr[] = $tfval;
			}
			else
			{
				$em = get_control_field_email_address($co_ID, $tfval);
				if ((isset($em[0])) && ($em[0] == 1))
      	{
					$comment = 'Failed sending email notification: Invalid To information provided for email_info function. '.$em[1];
					$warn .= $comment;
          $noters=myload("CALL sp_add_activity_note ($co_ID,'NOTE',".sql_escape_clean($comment).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");
					/* since there can be multiples to send to - don't return here - keep going */
      	}
				else if ((isset($em[1])) && ($em[1] != ''))
				{
					//$to_addr[] = $em[1];
			  	$to_addr = explode(',', $em[1]);
				}
				else
				{
          $comment = "No email address found for $tfval";
          $warn .= $comment;
          $noters=myload("CALL sp_add_activity_note ($co_ID,'NOTE',".sql_escape_clean($comment).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");
				}
			}
		}
	}
	else
	{
  	// lookup address for this $to_param value
   	if (preg_match('/@/', $to_param))
		{
			$to_addr[] = $to_param;
		}
		else
		{
			$em = get_control_field_email_address($co_ID, $to_param);
			if ((isset($em[0])) && ($em[0] == 1))
    	{
    	  $recips = "Invalid To information provided for email_info function. ".$em[1];
      	$comment = 'Failed sending email notification: ' .$recips . ' (' . __LINE__ .')';
        $noters=myload("CALL sp_add_activity_note (".sql_escape_clean($co_ID).",'NOTE',".sql_escape_clean($comment).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");
     		return array(0,$recips);
    	}
			else if ((isset($em[1])) && ($em[1] != ''))
			{
				//$to_addr[] = $em[1];
			  $to_addr = explode(',', $em[1]);
			}
			else
    	{
    	  $recips = "No email address found for $to_param.\n";
      	$comment = 'Failed sending email notification: ' .$recips . ' (' . __LINE__ .')';
        $noters=myload("CALL sp_add_activity_note (".sql_escape_clean($co_ID).",'NOTE',".sql_escape_clean($comment).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");
     		return array(0,$recips);
    	}
		}
	}

	if (count($to_addr) == 0)
	{
	  $recips = "No email address(es) found to send email to.\n";
  	$comment = 'Failed sending email notification: ' .$recips . ' (' . __LINE__ .')';
    $noters=myload("CALL sp_add_activity_note (".sql_escape_clean($co_ID).",'NOTE',".sql_escape_clean($comment).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");
 		return array(0,$recips);
	}

	$info_fields = explode(',' , $info_param);
	$fdetails = array();
	$f_long_names = array();
  $header_fields = array('co_region'=>'State/Region','co_receivedate'=>'Received Date','co_source'=>'Source','co_create_datetime'=>'Created Date');

	foreach ($info_fields as $fname)
	{
    if ( isset($header_fields[$fname] ) ) // if the field is a header field
    {
      $fval = get_header_field_value($co_ID, $fname);
      if ($fval)
        $fdetails[$fname] = $fval;
      else
        $fdetails[$fname] = '';
      $f_long_names[$fname] = $header_fields[$fname]; // use array for the long descriptions
    }
    else // assume it's a control field
    {
      $fval = get_field($co_ID, $fname);
      if ($fval)
        $fdetails[$fname] = $fval;
      else
        $fdetails[$fname] = '';
      // lookup the long name for this field
      $f_long_names[$fname] = get_field_long_name($ct_ID, $fname);
    }

	}

	// now we have an array the email To: ($to_addr) and an
	// array of control fields ($info_fields) along an array of the current field values ($fdetails)
	// compose and send email message

  $http_host = '';
  if ((isset($_SERVER['HTTP_X_FORWARDED_HOST'])) && ($_SERVER['HTTP_X_FORWARDED_HOST'] != '') ) // accessed via web + proxy
    $http_host = $_SERVER['HTTP_X_FORWARDED_HOST'];
	else if ((isset($_SERVER['HTTP_HOST'])) && ($_SERVER['HTTP_HOST'] != '') ) // accessed via web
    $http_host = $_SERVER['HTTP_HOST'];
  else if	((isset($config['HTTP_HOST'])) && ($config['HTTP_HOST'] != '') ) // local process (i.e. batch)
	  $http_host = $config['HTTP_HOST'];
  else
	{
	  $recips = "Failed sending email notification - HTTP_HOST not defined in config file.\n";
  	$comment = 'Failed sending email notification: ' .$recips . ' (' . __LINE__ .')';
    $noters=myload("CALL sp_add_activity_note (".sql_escape_clean($co_ID).",'NOTE',".sql_escape_clean($comment).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");
 		return array(1,$recips);
	}

  if ($config['HTTP_SSL'] == 'on')
		$http_host = 'https://' . $http_host;
  else
    $http_host = 'http://' . $http_host;

	$msg = "POWER record ".$co_ID." has just entered the '".$st_Desc .' ('.$st_Name.")' status queue.";
	$msg .= "<br><br>You may click the following link to view this record:<br>";
	$msg.= "<a href='".$http_host.$config['systemroot']."view_rec.php?co_ID=".$co_ID."'>POWER Record ".$co_ID."</a><br><br>";
	$msg .= '<table cellpadding=0 cellspacing=0 border=0 style="border:solid 1px #000;margin:20px 0 20px 0;padding:0;"><thead><tr style="background-color:#AFAFAF">';
	$msg .= '<th style="border:solid 1px #CFCFCF;padding:5px;" colspan=2>Details</th></tr></thead>';
	$msg .= "<tbody>";
	$txtmsg = "POWER record ".$co_ID." has just entered the '".$st_Desc .' ('.$st_Name.")' status queue.";
	$txtmsg.= "\nDetails:\n--------\n";
	$bg = '#FFF';

	foreach ($info_fields as $fname)
	{
		$txtmsg.= $f_long_names[$fname] . ': '. $fdetails[$fname] . "\n";
		if ($fdetails[$fname] == '')
			$fdetails[$fname] = '&nbsp;';
		$fdetails[$fname] = str_replace("\n",'<br>',$fdetails[$fname]);
		$msg .= '<tr style="background-color:'.$bg.';"><td style="border:solid 1px #CFCFCF;padding:5px;vertical-align:top;">' . $f_long_names[$fname] . '</td><td style="border:solid 1px #CFCFCF;padding:5px;">'. $fdetails[$fname] . ' </td></tr>';
		if ($bg == '#EFEFEF')
			$bg = '#FFF';
		else
			$bg = '#EFEFEF';
	}
	$msg .= '</tbody></table>';
	$msg.= "<br>Contact the <a href='mailto:powersupport@pinnaclebsi.com'>POWER support</a> team if you no longer wish to receive notifications when records enter the ".$st_Name." status queue.\n";
	$txtmsg.= "\n\nContact the POWER support team if you no longer wish to receive notifications when records enter the ".$st_Name." queue.\n";

  if ($warn != '') /* include warning in outgoing email per Ticket # 824321 */
	{
    $msg.= '<p style="color:red;font-weight:bold;">Warning: '.$warn.'</p>';
		$txtmsg.= 'Warning: ' . $warn . "\n";
	}
	$notify = implode(";",$to_addr);

	$primaryfield = get_primary_fieldvalue($co_ID);
	if ($primaryfield)
	{
		$subject = "POWER: ".$primaryfield['fa_Name']." ".$primaryfield['fv_Value']." moved to '".$st_Name."' status queue [c=".$co_ID."]";
  }
  else if ($info_subject != '')
  {
    $subject = $info_subject;
	}
  else
  {
		$subject = "New ".$ct_email_subjects." document in the '".$st_Name."' status queue [c=$co_ID]";
	}

  $recips = send_status_change_notification_with_info($notify, $subject, $msg, $txtmsg, $co_ID, false, $from);

	if (preg_match('/email notification sent to/', $recips))
	{
		$comment = $recips;
	  $noters=myload("CALL sp_add_activity_note (".sql_escape_clean($co_ID).",'NOTE',".sql_escape_clean($comment).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");
		if ($warn != '')
		  $recips .= ' Warning:' . $warn;
		return array(0, "$recips\n");
	}

	$comment = 'Failed sending email notification: ' .$recips . ' (' . __LINE__ .')';
	$noters=myload("CALL sp_add_activity_note (".sql_escape_clean($co_ID).",'NOTE',".sql_escape_clean($comment).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");

	return array(1, "Failed sending email notification: $recips\n");
}

/**
 * Get control field descriptive long name
 *
 * @param int $ctid
 * @param string $fname
 * @return string
 */
function get_field_long_name ($ctid='', $fname='')
{
	if (($fname == '') || ($ctid == '') || (!is_numeric($ctid)) )
		return ('');
	$flongres = myload("SELECT fa_Name from tbl_field_associations INNER JOIN tbl_fields ON fi_ID=fa_fi_ID WHERE tbl_fields.fi_Name=".sql_escape_clean($fname)." AND fi_Active='y' AND tbl_field_associations.fa_Active='y' AND fa_ct_ID=$ctid LIMIT 1");

	if (isset($flongres[0]['fa_Name']))
		return $flongres[0]['fa_Name'];
	return ('');
}

/**
 * Email status change notification and include information in email body
 *
 * @param string $recipients
 * @param string $subject
 * @param string $msg
 * @param string $txtmsg
 * @param int $coID
 * @param bool $AttDefaultDoc = attach default coid document
 * @return string
 */
function send_status_change_notification_with_info(	$recipients,
																										$subject="POWER",
																										$msg="POWER Notification",
																										$txtmsg="POWER Notification",
																										$coID="0",
																										$AttDefaultDoc=false,
																										$from='')
{

	global $config;

  // send email notification of new documents
  // if it contains @QCSUBEMAIL email the submitter
  if (preg_match('/@QCSUBEMAIL/', $recipients))
	{
    // lookup our submitter
    $ssql="SELECT fv_Value, fv_Username FROM tbl_field_values WHERE fv_fi_id=(SELECT fi_id FROM tbl_fields WHERE fi_name ='QCSUBEMAIL' LIMIT 1) AND fv_co_ID=$coID LIMIT 1";
    $ssres=myload($ssql);
    $subemail = '';
    if (count($ssres)>0)
		{
      if ( (isset($ssres[0]['fv_Value'])) && ($ssres[0]['fv_Value']!='') )
			{
        // must have an @
        if (preg_match('/@/', $ssres[0]['fv_Value']))
				{
          $subemail = $ssres[0]['fv_Value'];
        }
      }
    }
    $recipients = preg_replace('/@QCSUBEMAIL/', $subemail, $recipients);
  }

  // fv_Username may be SA which and we may need to send a different message
  $recipients=preg_replace("/,/",";",$recipients);
  $recarr=preg_split('/;/',$recipients);

	if ($from==''){
		$production=false;if($config["system_short_name"]=='POWERTEST') $production=true;
		if($production)
			$from=$config["contact_email"];
		else
			$from="powertest@pinnaclebsi.com";
		$fromname=$config["contact_name"];
	} else {
  	$fromname=$from;
	}


  require_once (dirname(__FILE__)."/phpmailer/class.phpmailer.php");
  $mail = new PHPMailer();
  $mail->IsSMTP();
  $mail->Host="mail.abcbs.net";
  $mail->From=$from;
  $mail->FromName=$fromname;
  for ($x=0;$x<count($recarr);$x++)
	{
    // allow notification to email address found in a particular fieldvalue if address starts with @ and a coID was passed in
    if (substr($recarr[$x],0,1)=="@" && intval($coID)>0)
		{
      $fiidrs=myload("SELECT fi_ID FROM tbl_fields WHERE fi_Name=".sql_escape_clean(substr($recarr[$x][1],1))." LIMIT 1");
      if (count($fiidrs)>0 && $fiidrs[0][0]>0)
			{
        $fvrs=myload("SELECT fv_value FROM tbl_field_values WHERE fv_fi_ID=".$fiidrs[0][0]." AND fv_co_ID=$coID LIMIT 1");
        if (count($fvrs)>0 && $fvrs[0][0]!="")
				{
          // set this recipient to the desired fieldvalue for this record
          $recarr[$x]=$fvrs[0][0];

          // add @pinnaclebsi.com if no @ is found
          if (!stristr($recarr[$x],"@"))
						$recarr[$x].="@pinnaclebsi.com";
        }
      }
    }
    $mail->AddAddress($recarr[$x]);
  }
  $mail->WordWrap=50;
  $mail->Subject=$subject;
  $mail->Body=$msg;
  $mail->AltBody=$txtmsg;

	// Attach default document to email if requested
	if ($AttDefaultDoc){
		// does default document exist?
		$docrs=doc_repo_default_details($coID);
		if (isset($docrs['docID']) && $docrs['docID']>0){
			// get default doc
			$image_array=get_filecache($docrs['docID'],false);

			if (isset($image_array['data'])){
				// get temp filename
				$tempName = tempnam($config['fctmppath'], "voteattach");

				// save document to temp file
				$tmpDocFile=file_put_contents($tempName, $image_array['data']);

				// if image_array[0] exists, make sure it doesn't have 'Document not found'. That's a sure sign that there's no document to attach.
				if ($tmpDocFile && (!isset($image_array[0]) || (isset($image_array[0]) && !stristr($image_array[0], 'Document not found')))){
					if (isset($image_array['Content-Disposition']) && preg_match('/filename="(.*)"/', $image_array['Content-Disposition'], $imatches)){
						$i_filename=$imatches[1];
					} else {
						$i_filename=$docrs['filename'];
					}
					// attach document
					$mail->AddAttachment(	$tempName,
																$i_filename,
																'base64',
																$image_array['Content-Type']);
				}
			}
		}
	}

	// If we are ANYWHERE other than PROD,  (POWERTEST),
	// make sure emails only go to internal POWER support employees

	$production=false;if($config["system_short_name"]=='POWERTEST') $production=true;

	if (!$production) {
		$mail->ClearAddresses();
		$mail->ClearBCCs();
		$mail->ClearCCs();
		if (stristr($recipients, 'jdminton')) $mail->AddAddress("jdminton@pinnaclebsi.com");
		if (stristr($recipients, 'tlroper')) $mail->AddAddress("tlroper@arkbluecross.com");
		if (stristr($recipients, 'mjquirijnen')) $mail->AddAddress("mjquirijnen@pinnaclebsi.com");
		if (stristr($recipients, 'tcwalsh')) $mail->AddAddress("tcwalsh@pinnaclebsi.com");
		if (stristr($recipients, 'pnshaver')) $mail->AddAddress("pnshaver@pinnaclebsi.com");
		if (stristr($recipients, 'jmmaxwell')) $mail->AddAddress("jmmaxwell@pinnaclebsi.com");
		$mail->AddAddress("powertest@pinnaclebsi.com");
		//$mail->AddAddress("tcwalsh@pinnaclebsi.com");
		//$mail->AddAddress("pnshaver@pinnaclebsi.com");
		//$mail->AddAddress("jmmaxwell@pinnaclebsi.com");
		//$mail->AddAddress("mjquirijnen@pinnaclebsi.com");	
		$recipients = "The POWER team (1)";
	}
	
  if (!$mail->Send()) {
		// remove document from /tmp
		if ($AttDefaultDoc) @unlink ($tempName);
    return "email notification failed. ".$mail->ErrorInfo."";
	} else {
		// remove document from /tmp
		if ($AttDefaultDoc) @unlink ($tempName);
    return "email notification sent to: $recipients";
	}
}

/**
 * Get a columns value from tbl_corr for a given record
 *
 * @param int $coid
 * @param string $fieldname
 * @return string|bool
 */
function get_header_field_value($coid,$fieldname)
{
	if (($fieldname == '') || ($coid == ''))
		return false;

  $fieldname = strtolower($fieldname);

  // make sure $fieldname is a string
  $tbl_corr_cols = array('co_receivedate', 'co_create_datetime', 'co_source', 'co_checkout_userID', 'co_checkout_datetime', 'co_queue', 'co_queue_datetime', 'co_region', 'co_region_datetime', 'co_update_datetime', 'co_bt_ID');

  if (!in_array($fieldname,$tbl_corr_cols))
    return false;

  $fvalres = myload("SELECT $fieldname as value FROM tbl_corr WHERE co_ID=$coid");
	if (($fvalres) && (count($fvalres) == 0))
		return ('');

	if ( (!isset($fvalres[0]['value'])) || ($fvalres[0]['value'] == '') )
		return ('');

	return ($fvalres[0]['value']);
}

/**
 * Get control field email address.
 *
 * This function will lookup up the e-mail address associated with the
 * POWER field.  The value of the field needs to be a valid user ID.
 *
 * @param string $coid        This is the co_ID for the record
 * @param string $fieldname   This is the FIELD NAME to use for the lookup
 * @return array              Returns 0 + EMAIL on success 1 + error of fail
 */
function get_control_field_email_address($coid='', $fieldname = '')
{
	if (($fieldname == '') || ($coid == ''))
		return array(1,'Missing coid or fieldname to lookup email address.');

	$fid = get_fid($fieldname);
	if (!$fid)
	  return array(1,"No field ID for field: $fieldname");

	$fvalres = myload("SELECT fv_Value FROM tbl_field_values WHERE fv_co_ID=$coid AND fv_fi_ID=$fid");
	if (($fvalres) && (count($fvalres) == 0))
		return array(0,'');

	if ( (!isset($fvalres[0]['fv_Value'])) || ($fvalres[0]['fv_Value'] == '') )
		return array(0,'');

	$emailaddresses = array();
    
  foreach ($fvalres as $key => $value) {
		//echo 'key = '.$key.'  value='.$fvalres[$key]['fv_Value']."<br>\n"; 
		if (isset($fvalres[$key]['fv_Value'])) {
			// split out multi email addresses from 1 field, separated by , or ;
			$comma = explode(',', $fvalres[$key]['fv_Value']);      
			$semicolon = explode(';', $fvalres[$key]['fv_Value']);
			$multiaddr = array_merge($comma, $semicolon);
      
			foreach ($multiaddr as $i => $value) {
				if (preg_match('/@/', $value)) {
					array_push($emailaddresses, $value);
				}
        else {
        	// lookup against user table - assume field contains the uUsername or uNetID value
        	// don't assume that the email address is the same as what is in the control field with a domain on it.
        	$ures = myload('SELECT uEmail FROM tbl_users 
                          WHERE (uUsername='.sql_escape_clean($value).'
                                 OR uNetID='.sql_escape_clean($value).") AND uActive='y'");
        	if (isset($ures[0]['uEmail']) && ($ures[0]['uEmail'] != '')) {
        		array_push($emailaddresses, $ures[0]['uEmail']);        
          }
        }
			}
		}
	}

	if (count($emailaddresses)) {
    // remove dupes
    $emailaddresses = array_unique($emailaddresses);

		// send back a string of comma separated email addresses. If send back array, extra coding is required all over this file
		$comma_separated = implode(',', $emailaddresses);
		return array(0, $comma_separated);
	}

	// lookup against user table - assume field contains the uUsername value
	$ures = myload('SELECT uEmail FROM tbl_users WHERE uUsername='.sql_escape_clean($val)." AND uActive='y'");
	if (isset($ures[0]['uEmail']) && ($ures[0]['uEmail'] != ''))
		return array(0,$ures[0]['uEmail']);

	// try looking up their email address from their netID
	$ures = myload('SELECT uEmail FROM tbl_users WHERE uNetID='.sql_escape_clean($val)." AND uActive='y'");
	if (isset($ures[0]['uEmail']) && ($ures[0]['uEmail'] != ''))
		return array(0,$ures[0]['uEmail']);

	// don't assume that the email address is the same as what is in the control field with a domain on it.

	return array(1,"No email address found for $fieldname field value: $val.");
}

/**
 * BARCODE ROUTING - unid_barcode_routing
 *
 * @global array $config
 * @param array $post
 * @return array
 */
function unid_barcode_routing($post=array())
{
	global $config;
	$debug = false;

	$barcode_field = 'ROUTE_BCODE';
	$barcode_user = 'BARCODE'; // user placed in user fields when performing changes to the DB within this code
  $route_to_status = 'MCS_UNID_COID';
	$route_to_status_force = '';

	$check_pages = array(1);
	$img_angles = array(90, 89.5, 90.5);
  $max_mean = 230; // used on cropped image to determine if $img_angles should be attempted based upon black/white mean

	$ocr_util = 'zebraimg'; // use zebraimg as the default

	if (isset($config['zebraimg']))
	{
		$img_angles = array(90);
		$max_mean = 'none'; // saves time - zebraimg does a good enuff job time wise without the extra max_mean step
	}

	$page_rotate = array(0); // 0 = won't rotate 180, when set in code parameters as rotate='yes' it will
	// the area to crop and angles to try when analyzing barcode part of page

	$img_crop_coords_arr = array();
	$img_crop_coords_arr[] = '200x2600+115+430';  // keep as first value for best results
	$img_crop_coords_arr[] = '220x3000+20+300'; // increases accuracy - but slows things down a little
//  $img_crop_coords_arr[] = '140x2400+55+500';
	// NOTE: if the $img_crop_coords_arr is modified the $max_mean may need to adjusted to allow for more white space

	// override the default parameters here
	if ((isset($post['codeparams'])) && ($post['codeparams'] != '') && ($post['codeparams'] !='()') )
	{
		$codeparams = $post['codeparams'];
		$parms = array();
		if (preg_match('/^\((.*)\)$/', $codeparams, $cparms))
		{
			$pair = explode("',", $cparms[1]);
			foreach ($pair as $parmval)
			{
				if (preg_match("/^(.*)=\'(.*?)('){0,1}$/",$parmval,$p))
					$parms[$p[1]] = $p[2];
			}
		}

		if ( (isset($parms['debug'])) && ($parms['debug'] == 'yes'))
			$debug = true;

		if ( (isset($parms['default'])) && ($parms['default'] != ''))
			$route_to_status = $parms['default'];

		if ( (isset($parms['force'])) && ($parms['force'] != ''))
			$route_to_status_force = $parms['force'];

		if ( (isset($parms['max_mean'])) && ($parms['max_mean'] != ''))
			$max_mean = $parms['max_mean'];

		if ( (isset($parms['pages'])) && ($parms['pages'] != ''))
			$check_pages = explode(',',$parms['pages']);

		if ( (isset($parms['crop'])) && ($parms['crop'] != ''))
			$img_crop_coords_arr = explode(',',$parms['crop']);

		if ( (isset($parms['angles'])) && ($parms['angles'] != ''))
			$img_angles = explode(',',$parms['angles']);

		if ( (isset($parms['page_rotate'])) && ($parms['page_rotate'] != ''))
			$page_rotate = explode(',',$parms['page_rotate']);

    if ( (isset($parms['ocr_util'])) && ($parms['ocr_util'] != ''))
      $ocr_util = $parms['ocr_util'];

	}

	if ($debug)
	{
		echo 'default status: '.$route_to_status.'<br>';
		echo 'force status: '.$route_to_status_force.'<br>';
		echo 'max mean: '. $max_mean.'<br>';
		echo 'check pages: '.implode(', ',$check_pages).'<br>';
		echo 'crop: '.implode(', ',$img_crop_coords_arr).'<br>';
		echo 'angles: '.implode(', ',$img_angles).'<br>';
		echo 'page rotate: '.implode(', ',$page_rotate).'<br>';
	}

	// make sure we have a fco_ID passed in
  if ((!isset($post['fco_ID'])) || ($post['fco_ID']=='') || (!is_numeric($post['fco_ID'])) )
		return array(1,"Missing fco_ID");

	$coid = $post['fco_ID'];
	$bcode_fid = get_fid($barcode_field);
	$route_to_status_st_id = get_st_ID($route_to_status);

	if (!$route_to_status_st_id)
		return array(1,"Default route ($route_to_status) has no status id"); // failure

	// checkout to BARCODE user before doing anything.
	$checkdate=date("Y-m-d G:i:s");
	$chsql="CALL sp_checkout ($coid,'checkout to ".$barcode_user." by ".$barcode_user."',".sql_escape_clean($barcode_user).",'".$checkdate."',	".sql_escape_clean($barcode_user).")";
	$chrs=myload($chsql);

	// search if this record has a $barcode_field field that isn't empty and route based upon the value
	if ( ($bcode_fid != '') && (is_numeric($bcode_fid)) )
	{
		$barcheck=myloadslave("SELECT tbl_field_values.fv_Value AS $barcode_field FROM tbl_field_values WHERE tbl_field_values.fv_co_ID=$coid AND tbl_field_values.fv_fi_ID=$bcode_fid LIMIT 1");
		if ( (count($barcheck)>0) && (isset($barcheck[0]["$barcode_field"])) && (trim($barcheck[0]["$barcode_field"]) != '') )
		{
			// barcode is a valid barcode - attempt to route this record
			$route_status = barcode_route_check($coid, trim($barcheck[0]["$barcode_field"]), $barcode_user, $route_to_status);
			// check if $route_status[0] is good or bad and return status
			if ($route_status[0] == 1)
			{ // failure
				$mts_ret = move_to_status ($coid, $route_to_status_st_id, $route_status[1] . " Moving to " . $route_status[2] . ".", $barcode_user, 'a');
				return array(0,$route_status[1] . ' ' . $mts_ret[1]);
			}
		 	// success
			if (isset($route_status[2]))
			{
				$route_to_status_st_id = get_st_ID($route_status[2]);
				if (!$route_to_status_st_id)
					return array(0,'Route ('.$route_to_status.') has no status id'); // failure
				//	return array(0,'Route ('.$route_status[2].') has no status id'); // failure
			}
			$mts_ret = move_to_status ($coid, $route_to_status_st_id, $route_status[1] . " Moving to " . $route_status[2] . ".", $barcode_user, 'a');
			return array(0,$route_status[1]. ' ' . $mts_ret[1]);
		} // end $barcode_field value exists
	} // end have a $barcode_field field in system

	/* --- no existing $barcode_field for this record - process document(s) for the record --- */

	/*
	 * make sure we have the necessary application in our config and the executables exist
	 * $config['ghostscript']='/opt/pbsi/bin/gs';
	 * $config['imagick_convert']='/opt/pbsi/bin/convert';
	 * $config['gocr']='/opt/pbsi/bin/gocr';
	 *
	 */

	if (!barcode_config_check())
	{
		$mts_ret = move_to_status ($coid, $route_to_status_st_id, "Barcode routing pre-code missing required configuration", $barcode_user, 'a');
		return array(1,"error: missing required configuration (ghostscript, imagick_convert) for barcode processing". ' ' . $mts_ret[1]); // failure
	}

  $barcode = false;

	// get list of active documents for this record
	$docids=myloadslave("SELECT tbl_filerefs.fi_docID as DOCID FROM tbl_filerefs WHERE fi_co_ID=$coid AND fi_active='y'");
	if (count($docids) == 0)
	{ // no docs attached to this record
		$mts_ret = move_to_status ($coid, $route_to_status_st_id, "No attached documents for barcode routing. Moving to $route_to_status", $barcode_user, 'a');
		return array(0,"No attached documents for barcode routing. Moved to $route_to_status". ' ' . $mts_ret[1]); // not an error - just a record we can barcode check
	}

	// going to need a temp directory for copies of our PDF files from the filecache
	$bc_dirbase = $config['fctmppath'] . '/barcode_routing_tmp';
	if (!file_exists($bc_dirbase))
		mkdir($bc_dirbase, 0700, TRUE);
	if (!is_dir($bc_dirbase))
	{
		$mts_ret = move_to_status ($coid, $route_to_status_st_id, "Barcode routing pre-code error. Base temp directory $bc_dirbase is not a directory. Moving to $route_to_status", $barcode_user, 'a');
		return array(0,"error: base temp directory $bc_dirbase is not a directory.". ' ' . $mts_ret[1]); // error
	}
	$bc_coid_dirbase = $bc_dirbase . '/' . $coid;
	if ((!$debug) && (file_exists($bc_coid_dirbase)))	// wipe is out!
		barcode_cleanup($bc_coid_dirbase); // cleanup temp files

	if (!@mkdir($bc_coid_dirbase, 0700, TRUE))
	{
		$mts_ret = move_to_status ($coid, $route_to_status_st_id, "Barcode routing pre-code error. Failed to create temp directory $bc_coid_dirbase. Moving to $route_to_status", $barcode_user, 'a');
		return array(0,"error: failed to create temp directory $bc_coid_dirbase.". ' ' . $mts_ret[1]); // error
	}

	$pdf_docs = array();
	for($i=0,$ic=count($docids);$i<$ic;$i++)
	{
		$docid = $docids[$i]['DOCID'];
		if ($debug)
			echo "docid: $docid<br>";
		$dirarr = make_filename($docid);
		$thisfile=$config['fcprepend'].$dirarr['dir'].$dirarr['filename'];
		// check if this file is in the filecache
		if (file_exists($thisfile))
		{
      $fgc = @file_get_contents($thisfile);

      if (!$fgc)
      {
        error_log("queue_pre_post:unid_barcode_routing Error: failed to get contents of file in filecache ($thisfile) (docID:$docid)",0);
        continue;
      }

      if (!is_serialized($fgc)) // make sure it is serialized
      {
        error_log("queue_pre_post:unid_barcode_routing Error: file in filecache ($thisfile) is not serialized (docID:$docid)",0);
        continue;
      }

			$imageArray=unserialize( $fgc );
      unset($fgc);

			// we only want PDF files that have data in the file
			if ( (isset($imageArray['Content-Type'])) && (preg_match('/pdf/', $imageArray['Content-Type'])) &&
			     (isset($imageArray['data'])) && (strlen($imageArray['data'])>0) )
			{
			  // write our data out to a temp file for barcode processing - $imageArray['data']
				// ------------- need to make sure this tmp file is cleaned up !!!! -------------
				$thistmpfile = $bc_coid_dirbase . '/' . $docid . '_input.pdf';
				file_put_contents($thistmpfile, $imageArray['data']);
				$pdf_docs[] = $thistmpfile;
			} // content of file from filecache
		} // file_exists in filecache
	} // foreach docid

	if ($debug)
	{
		var_dump($pdf_docs);
		echo '<br>';
	}

	if (count($pdf_docs)==0)
	{
		if (!$debug)
			barcode_cleanup($bc_coid_dirbase); // cleanup temp files
		/*
		 * -- no PDF attachments - move record to be manualy routed --
		 */
		$mts_ret = move_to_status ($coid, $route_to_status_st_id, "No attached PDF documents.", $barcode_user, 'a');
		return array(0,"No attached PDF documents. Moved to $route_to_status.\n". ' ' . $mts_ret[1]);
	}

	$detected_barcode = array(); // if there are multiple files, if multiple barcodes detected it needs to be manually routed

	$err = false;

  $start_time = '';
  if ($debug)
    $start_time = microtime(true);    // for letting know how long barcode detection loop took

	// for each of our PDF documents run it through the steps to get a barcode from the document.
	for($i=0;$i<count($pdf_docs);$i++)
	{
		$file = $pdf_docs[$i];

    //  Check for barcodes on page(s)
		foreach ($check_pages as $page)
    {
    	$onepage_image = false;
  		// Extract first page image from PDF.  If fails, move record to $route_to_status (MCS_UNID_COID) (for manual routing)
			if ($debug)
			{
        $time0=microtime(true);
        $onepage_image = barcode_pdf_export_page1($file, $page);
        $time1=microtime(true);
        $elapsed=$time1-$time0;
        echo 'exporting page '.$page.' took: '.$elapsed.' seconds.<br>';
      }
			else
        $onepage_image = barcode_pdf_export_page1($file, $page);

  	  if ($onepage_image == false) // no image file returned (there might not be a page 2 or 3)
          break;

      //  Flip the page for upside down faxes
			foreach ($page_rotate as $orent)
      {
				if (!is_numeric($orent)) // skip if page rotate value is not numeric
					continue;

        if( $orent != 0 )
				{
          if ($debug)
          {
	          $time0=microtime(true);
	          $onepage_image = barcode_rotate_image( $onepage_image, $orent );
	          $time1=microtime(true);
	          $elapsed=$time1-$time0;
	          echo 'rotate image '. $orent.' took: '.$elapsed.' seconds.<br>';
          }
          else
            $onepage_image = barcode_rotate_image( $onepage_image, $orent );
				}

    		// Crop image creating a new image file.  If fails, move record to $route_to_status (MCS_UNID_COID) (for manual routing)
				foreach ($img_crop_coords_arr as $img_crop_coords)
				{
					$cropped_image = false;

					if ($img_crop_coords == 'none')
					 $cropped_image = $onepage_image;
					else
					{
            if ($debug)
            {
	            $time0=microtime(true);
	            $cropped_image = barcode_image_crop($onepage_image, $img_crop_coords); // left side
	            $time1=microtime(true);
	            $elapsed=$time1-$time0;
	            echo 'crop image '. $img_crop_coords.' took: '.$elapsed.' seconds.<br>';
	          }
	          else
              $cropped_image = barcode_image_crop($onepage_image, $img_crop_coords); // left side
	    		}

	    	  if (!$cropped_image)
	      		  continue;

				  // if crop is not set to none and max_mean not set to none - then perform mean
				  if ($max_mean != 'none')
					{
						// check Median of image using ImageMagick "identify" utility $max_mean
						$img_mean = barcode_image_cropped_mean($cropped_image);
						if ($debug)
							echo "Image mean: $img_mean --- max: $max_mean<br>";

						if (($img_mean > $max_mean) || ($img_mean < 10) ) // -1 = could not get the Mean, < 10 = mostly black (skip)
							continue;
          }

	    		$err = false;
	    		foreach ($img_angles as $angle)
	    		{
						if ($debug)
							echo "rotate: $angle<br>";

						if (!is_numeric($angle)) // skip if page angle value is not numeric
							continue;

	    			if ($err)
	    			{
	    				break;
	    			}
	    			else
	    			{
	    				// Rotate image creating a new image file.  If fails, move record to $route_to_status (MCS_UNID_COID) (for manual routing)
							if ($angle == 0) // not rotating
							 $rotated_image = $cropped_image;
							else
	    				 $rotated_image = barcode_rotate_image($cropped_image, $angle);

	    			  if (!$rotated_image)
	    				{
	    					$err = true;
	    				}
	    				else
	    				{
	    					// Barcode/OCR the image to get barcode.
							  if ($debug)
							  {
							  	$time0=microtime(true);
							  	$barcode = barcode_ocr_image($ocr_util,$rotated_image);
							    $time1=microtime(true);
							    $elapsed=$time1-$time0;
							    echo 'barcode ocr utility took: '.$elapsed.' seconds.<br>';
							  }
								else
                  $barcode = barcode_ocr_image($ocr_util,$rotated_image);

	    					if ((file_exists($rotated_image)) && (!$debug))
	    						unlink ($rotated_image); // done with rotated image - remove
	    					if ($barcode)
	    					{ // break out of loop since we've got a barcode string
	    						$detected_barcode[] = $barcode;
									if ($debug)
										echo "got barcode: $barcode - going to break out of loops<br>";
	    		      	break(4);
	    		    	}
	    		    }
	    		  } // else no error - rotate and ocr

	    		} // end foreach ($img_angles as $angle)

					if ((file_exists($cropped_image)) && (!$debug))
						unlink ($cropped_image); // done with cropped image - remove

	  		} // end foreach ($img_crop_coords_arr as $img_crop_coords)

			} // for( $orent=0; $orent<=180; $orent+=180 )

  		if ((file_exists($onepage_image)) && (!$debug))
  			unlink ($onepage_image); // done with one page image - remove

		} // end for( $page=1; $page<=3; $page++)

	} // end foreach attached PDF document

	if ($debug)
	{
    $end_time=microtime(true);
    $elapsed=$end_time-$start_time;
		echo 'barcode detection loop took: '.$elapsed.' seconds.<br>';
	}

	if ($err)
	{
		if (!$debug)
			barcode_cleanup($bc_coid_dirbase); // cleanup temp files
		$mts_ret = move_to_status ($coid, $route_to_status_st_id, "PDF page image rotation failed. Requires manual routing. Moving to $route_to_status", $barcode_user, 'a');
		return array(0,"PDF page image rotation failed. Requires manual routing.". ' ' . $mts_ret[1]);
	}

	// dont with all the temp files at this point
	if (!$debug)
		barcode_cleanup($bc_coid_dirbase); // cleanup temp files

	if (count($detected_barcode) == 0)
	{
		$mts_ret = move_to_status ($coid, $route_to_status_st_id, "No barcode detected in attached document(s). Moving to $route_to_status", $barcode_user, 'a');
		return array(0,"No barcode detected in attached document(s). Requires manual routing.". ' ' . $mts_ret[1]);
	}
	else if (count($detected_barcode) > 1)
	{
		// make sure all the barcodes match!
		for($j=1;$j<count($detected_barcode);$j++)
		{
			if ($detected_barcode[0] != $detected_barcode[$j])
			{
				// move to $route_to_status (MCS_UNID_COID)
				$mts_ret = move_to_status ($coid, $route_to_status_st_id, "Multiple barcodes detected in attached documents mismatch. Moving to $route_to_status", $barcode_user, 'a');
				return array(0,"Multiple barcodes detected in attached documents mismatch. Requires manual routing.". ' ' . $mts_ret[1]);
			}
		}
	}

	$barcode = $detected_barcode[0];

	if (!set_control_field($coid, $barcode_field, $barcode, $barcode_user))
	{
		$mts_ret = move_to_status ($coid, $route_to_status_st_id, "Failed to set barcode control field. Moving to $route_to_status", $barcode_user, 'a');
		return array(0,"Failed to set barcode control field. Requires manual routing.". ' ' . $mts_ret[1]);
	}

	$route_status = barcode_route_check($coid, trim($barcode), $barcode_user, $route_to_status, $debug);

	// check if $route_status is good or bad and return status
	if ($route_status[0] == 1)
	{ // failure
		$mts_ret = move_to_status ($coid, $route_to_status_st_id, "Barcode detected. " . $route_status[1] . " Moving to " . $route_status[2] . ".", $barcode_user, 'a');
		return array(0,"Barcode detected. " . $route_status[1]. ' ' . $mts_ret[1]);
	}
	else
	{  // success
		if (isset($route_status[2]))
		{
			$route_to_status_st_id = get_st_ID($route_status[2]);
			if (!$route_to_status_st_id)
				return array(0,'Route ('.$route_status[2].') has no status id'); // failure
		}
		$mts_ret = move_to_status ($coid, $route_to_status_st_id, "Barcode detected. " . $route_status[1] . " Moving to " . $route_status[2] . ".", $barcode_user, 'a');
		return array(0, "Barcode detected. " . $route_status[1]. ' ' . $mts_ret[1]);
	}

	return array(0,"Barcode routing failed.");
}

/**
 * Check that the configuration contains all the settings needed for the barcode reading functions
 *
 * @global array $config
 * @return bool
 */
function barcode_config_check()
{
	global $config;
	// these are external programs needed for barcode processing.  They should be defined in the includes/config.inc
	$programs = array('ghostscript', 'imagick_convert');

	for ($i=0; $i<count($programs); $i++)
	{
    if (!isset($programs[$i]))
      return false;

		if (!isset($config[$programs[$i]]))
			return false;

		if (!file_exists($config[$programs[$i]]))
			return false;

		if (!is_executable($config[$programs[$i]]))
			return false;
	}
	return true;
}

/**
 * Check the status route for the barcode
 *
 * @param int $coid
 * @param string $barcode
 * @param string $barcode_user
 * @param string $route_to_status
 * @param bool $debug
 * @return array
 */
function barcode_route_check($coid='', $barcode='',$barcode_user='',$route_to_status='MCS_UNID_COID',$debug=false)
{
  //  Read INI to get list of barcode types supported
	$bcinifile = dirname(__FILE__).'/barcode_types.ini';

	if (!file_exists($bcinifile))
		return array(1, 'Missing barcode types ini.', $route_to_status);

  $BC = parse_ini_file( $bcinifile, TRUE );

 	if (count($BC)==0)
		return array(1, 'Invalid barcode types information.', $route_to_status);

	if (($coid == '') || ($barcode == ''))
		return array(1, 'Missing routing information', $route_to_status);

  // Build regular expression to identify barcode type
  $bartypes = '';
  foreach( $BC as $key => $data )
		$bartypes .= "|" . $key;

  $bartypes = "~^.(" . substr( $bartypes, 1 ) . ")~i";
  if( preg_match( $bartypes, $barcode, $match ) )
      $pwrtype = $match[1];
  else
   		return array(0,"Barcode $barcode read. Barcode type failed. Requires manual routing.", $route_to_status);

  //  Shortcut to selected barcode config
  $cfg = $BC[$pwrtype];

  // Check to see if code is valid
	if (! preg_match( "~" . $cfg['FORMAT'] . "~i", $barcode, $field ) )
		return array(0, "$barcode is not a valid barcode.", $route_to_status);

	// got a valid barcode - checksum valid?
// 2008-09-20: Disabling checksum until they start coming through valid
//	$csum_in = $field[ $cfg["BAR_CRC"] ];
//	$checksum = calc_39checksum( $field[ $cfg["BAR_DATA"] ] );
//  if ($csum_in != $checksum) 	 // reject barcode
// 		return array(0,"Barcode $barcode read. Barcode checksum failed ($csum_in/$checksum). Requires manual routing.", $route_to_status);

	//  Build Comment
	$status = "Barcode $barcode read. Checksum disabled.\n";

  //  Walk cfg record and process fields
  foreach( $cfg as $key => $data )
	{
    // Get field name
    if( ! preg_match( "~^FLD_(.+)~", $key, $match ) )
        continue;
    $fname = $match[1];

    if (!set_control_field($coid, $fname, trim($field[$data]), $barcode_user ))  	// failed to set field
		  return array(0,"Barcode $barcode read. Failed to set field value. Requires manual routing.",$route_to_status);

  	$status .= "$fname set to " . trim($field[$data]) . "\n";
  }

  //  Do extended lookup if
  if( isset( $cfg['ELOOKUP'] ) && $cfg['ELOOKUP'] != "" )
	{
    if ( ($cfg['ELOOKUP'] == 'BAR_DATA') && isset($cfg['BAR_DATA']) && is_numeric($cfg['BAR_DATA']) && isset($field[$cfg['BAR_DATA']] )) // key is the barcode data as-is
    {
      $key = $field[$cfg['BAR_DATA']];
    }
    else
    {
      //  Get the list of additional fields supported
      if( preg_match( "/~(STATE)~/", $cfg['ELOOKUP'], $flist ) )
      {
        //  Lookup the field
        switch( $flist[1] )
        {
          case 'STATE':
            $sql = "SELECT co_region FROM tbl_corr where co_ID=$coid";
            $lu = myload($sql);

            $cfg['FLD_STATE'] = 101;
            $field[ 101 ] = $lu[0][0];
            break;
        }
      }

      //  Parse the ELOOKUP field to get Key format
      if( preg_match_all( "/~(.+)~/U", $cfg['ELOOKUP'], $match, PREG_SET_ORDER ) )
      {
        // Build Key
        $key = $cfg['ELOOKUP'];
        foreach( $match as $k1 => $d1 )
        {
          $fname = 'FLD_' . $d1[1];

          //  Check to see if the field is in the barcode
          if( isset( $cfg[$fname] ) )
            $key = @preg_replace( "/~".$d1[1]."~/", $field[$cfg[$fname]] , $key );
        }
      }
    }
    $eFields = array();

    //  Lookup the key  (Exact Key)
    $res = false;
    if ($key != '')
    {
      $sql = "SELECT * FROM tbl_barcode_lookup WHERE bl_Key=".sql_escape_clean($key)." AND bl_Active='y';";
      $res = myload($sql);
    }

    if( $res != array() )
    {
      //  Exact Key Match
  		if (isset($res[0]['bl_Data']))
        $eFields = unserialize( $res[0]['bl_Data'] );

    }
    else
    {
      // Fall back to Alternate Key
      if( isset( $cfg['ALOOKUP'] ) && $cfg['ALOOKUP'] != "" )
      {
        //  Parse the ALOOKUP field to get Key format
        if( preg_match_all( "/~(.+)~/U", $cfg['ALOOKUP'], $match, PREG_SET_ORDER ) )
    		{
          // Build Key
          $key = $cfg['ALOOKUP'];
          foreach( $match as $k1 => $d1 )
    			{
            $fname = 'FLD_' . $d1[1];

            //  Check to see if the field is in the barcode
            if( isset( $cfg[$fname] ) )
              $key = @preg_replace( "/~".$d1[1]."~/", $field[$cfg[$fname]] , $key );
          }
        }

        //  Lookup the key  (Alternate Key)
        $sql = "SELECT * FROM tbl_barcode_lookup WHERE bl_Key LIKE '$key' AND bl_Active='y';";
        $res = myload( $sql );

        if( count( $res ) == 1 ) {
          //  Alternate Key Match

      		if (isset($res[0]['bl_Data']))
            $eFields = unserialize( $res[0]['bl_Data'] );

          //  Retreve the "stored" key so the record can be cleaned up
          $key = $res[0]['bl_Key'];
        }
      }
    }

    if( $eFields != array() )
		{
      foreach( $eFields as $k1 => $d1 )
			{

			  //  Change region if saved in stored data
			  if( $k1 == "co_region" ) {
  				$sql="CALL sp_change_region($coid,".sql_escape_clean($d1).",'BARCODE',NOW())";
          $res = myexecute( $sql );
          continue;
        }

        // Get field name
        if (!set_control_field($coid, $k1, $d1, $barcode_user ))
    		  return array(0,'Error adding extended lookup fields.',$route_to_status);
      	$status .= "LU-$k1 set to " . $d1 . "\n";
      }

      //  Delete the key
      $sql = "DELETE FROM tbl_barcode_lookup WHERE bl_Key='$key'";
      $res = myexecute( $sql );
    }

  }

  if( isset( $cfg['QUEUE'] ) && ($cfg['QUEUE'][0] == '@') )
	{
    $qFunction = substr( $cfg['QUEUE'], 1 );
		if (function_exists($qFunction))
      $route_to_status = $qFunction( $coid, $field, $cfg, $debug );
	  if (is_array($route_to_status))
	  {
	  	$status .= $route_to_status[0];
	    $route_to_status = $route_to_status[1];
	  }
  } else if ( isset( $cfg['BAR_QUEUE'] ) ) {
    $route_to_status = str_replace( '.', '_', $field[ $cfg['BAR_QUEUE'] ] );
  } else
		$route_to_status = $cfg['QUEUE'];

	return array(0,$status,$route_to_status);
}

/**
 * Barcode Provider Audit Workpaper
 *
 * @global string $route_to_status
 * @global string $route_to_status_force
 * @param int $coid
 * @param string $field
 * @param array $fNames
 * @param bool $debug
 * @return string
 */
function barcode_provider_audit_workpaper($coid, $field, $fNames, $debug=false)
{
  global $route_to_status, $route_to_status_force;

  $WP_Indexes = array(
		'A'=>'REPORT FILE',
		'B'=>'TB, AJE, JE',
	  'C'=>'GENERAL',
		'D'=>'EXPENSES',
		'E'=>'BAD DEBTS',
		'F'=>'MEDICAID',
		'G'=>'RELATED ORG',
		'H'=>'CAPITAL COSTS',
		'I'=>'GME IME, PASS THRU',
		'J'=>'HOSP SD, DSH, CR BAL',
		'K'=>'REVENUE',
		'M'=>'HOME HEALTH AGENCIES',
		'N'=>'NRCC',
		'O'=>'PATIENT STATISTICS',
		'P'=>'ALLOCATION STATISTICS',
		'R'=>'ESRD',
		'S'=>'PBP',
		'T'=>'HOME OFFICE',
		'U'=>'SNF',
		'V'=>'PSYCH UNIT',
	  'W'=>'REHAB UNIT',
		'X'=>'CORF',
		'Y'=>'HOSPICE',
		'Z'=>'REOPENINGS');

	$WP_desc = array(
		'A' => 'Cost Report Information Worksheet',
		'A-1' => 'Converted Electronic Cost Report',
		'A-1-1' => 'Provider Hard Copy Cost Report',
		'A-2' => 'Cost Report Questionnaire (CMS 339)',
		'A-3' => 'Acceptability & Tentative Review',
		'A-4' => 'Professional Desk Review',
		'A-4-1' => 'Information Requests/Other Provider Correspondence',
		'A-4-2' => 'Correspondence File Review',
		'A-4-3' => 'Audit Policy Statements',
		'A-5' => 'Audit Adjustment Report',
		'A-6' => 'Final Settlement Cost Report (FSCR)',
		'A-7' => 'Professional Review of FSCR',
		'A-8' => 'N/A at this time',
		'A-9' => 'NPR Letter',
		'B' => 'Trial Balance',
		'B-1' => 'Trial Balance Reconciliation',
		'B-2' => 'Correction of ECR Edits',
		'C-1' => 'Current Year Proposed Adjustments',
		'C-2' => 'Unused',
		'C-3' => 'Prior Year Adjustments',
		'C-4' => 'Tour of Facility',
		'C-5' => 'Pending Items',
		'C-6' => 'Notes for Future Audits-Current Year',
		'C-7' => 'Notes for Future Audits-Prior Year',
		'C-8' => 'Entrance/Pre-Exit/Exit conferences and Exit Waiver',
		'C-9' => 'Management Letter Items',
		'C-10' => 'In-Charge Review',
		'C-11' => 'Supervisor Review Program',
		'C-12' => 'Supervisor Review Notes',
		'D-1' => 'Expense Comparison by Cost Center',
		'D-2' => 'Detail Analysis of Expense Variances',
		'D-3' => 'Detail Analysis of A&G',
		'D-4' => 'Comparison of Reclassifications',
		'D-5' => 'Comparison of Adjustments',
		'D-6' => 'Wage Survey',
		'E-1' => 'Inpatient Bad Debts',
		'E-2' => 'Outpatient Bad Debts',
		'F' => 'Medicaid Detail PS&R',
		'F-1' => 'Over Age 1 Settlement Data',
		'F-2' => 'Under Age 1 Settlement Data',
		'F-3' => 'Tefra Rate',
		'H-1' => 'Directly Assigned Capital cost',
		'H-2' => 'Insurance',
		'H-3' => 'Interest',
		'H-4' => 'Depreciation',
		'H-5' => 'Asset Acquisitions',
		'H-6' => 'Rent/Lease Expense',
		'I-1' => 'I&R Accreditation',
		'I-2' => 'IRIS Report',
		'I-3' => 'Direct/Indirect I&R FTEs',
		'I-4' => 'I&R Per Resident Amount',
		'J-1' => 'Medicare I/P Settlement Data',
		'J-1-1' => 'Retroactive Adjustments',
		'J-1-2' => 'Pass Thru Payments',
		'J-2' => 'Medicare O/P  & I/P-B Settlement Data',
		'J-2-1' => 'TOPS Payments',
		'J-2-2' => 'Ambulance Limits/Trips',
		'J-3' => 'DSH',
		'J-4' => 'Credit Balance Review',
		'K-1' => 'Other Income',
		'K-2' => 'Comparison of Medicare Utilization Ratios',
		'K-3' => 'Comparison of Cost to Charge Ratios',
		'K-4' => 'Comparison of Total Revenue',
		'M-1' => 'HHA Settlement Data',
		'O-1' => 'Revenue Test of Days',
		'O-1-1' => 'Charges Program Room Rates',
		'P' => 'Cost Allocation Statistics Comparison',
		'S-1' => 'Provider Based Physicians',
		'U-1' => 'SNF Settlement Data',
		'V-1' => 'Psych Settlement Data',
		'V-2' => 'Tefra Rate',
		'V-3' => 'Incentive Rates',
		'W-1' => 'Rehab Settlement Data'
	);

  if ($debug)
    echo ' - function barcode_provider_audit_workpaper executing -<br>';

  $WP_Index = $attachto_coid = '';

	if (isset($field[$fNames['WP_INDEX']]))
	 $WP_Index = trim($field[$fNames['WP_INDEX']]);
	if (isset($field[$fNames['CO_ID']]))
	 $attachto_coid = trim($field[$fNames['CO_ID']]);

  if ( ($WP_Index == '') || ($attachto_coid == '') )
    return( $route_to_status_force );

  if ($debug)
  {
   echo 'Workpaper index: '.$WP_Index.'<br>';
   echo 'Attach to record #: '.$attachto_coid.'<br>';
	}

  //  Allow forced routing based on input
  if( isset( $route_to_status_force ) && ( $route_to_status_force != '') )
    return( $route_to_status_force );

	// Verify co_id exists since we will attach docs to it - not exists: move record to MCS_UNID_COID
  if (is_numeric($attachto_coid))
	{
		$cexist = myload("SELECT co_queue FROM tbl_corr WHERE co_ID=$attachto_coid");
		if (!isset($cexist[0][0]))
		  return( $route_to_status );
	}

  // Parse $WP_Index  (A|A-9|A-9-9)
  $indx = substr($WP_Index,0,1);
  $folder = 'scanned';
	$desc = $WP_Index;
  if ( (isset($WP_Indexes[$indx])) && ($WP_Indexes[$indx] != '') )
	 $folder = $WP_Indexes[$indx];

	// spreadsheet we get has A-1-1 - so does barcode as of 20081119
	//$tindx = preg_replace('/(\d)/',"-$1",$WP_Index);
	$tindx = $WP_Index;
  if ( (isset($WP_desc[$tindx])) && ($WP_desc[$tindx] != '') )
   $desc = $WP_desc[$tindx];

  if ($debug)
	{
   echo 'Index Lookup: '.$indx.'<br>';
   echo 'Folder: '.$folder.'<br>';
	 echo 'Description: ' . htmlspecialchars($desc) . '<br>';
	 echo 'Document(s) attached to '.$coid.' will be attached to Record # '.$attachto_coid.'<br>';
	}

  $userid = 'BARCODE';
  // Create folder in doc respository if needed for this folder based upon the $indx
  if (!doc_repo_folder_exists($attachto_coid, $folder))
    doc_repo_create_folder($attachto_coid, $folder, '', 'BARCODE', $userid, '');

  // Attach this $coid document(s) to $attachto_coid
  $curfiles = myload("SELECT * FROM tbl_filerefs WHERE fi_co_ID=$coid AND fi_active='y' and fi_mimetype<>'folder'");
	$cnt_f = count($curfiles); // just in case there is more than 1
	$docs_copied = 0;

	for($i=0;$i<$cnt_f;$i++)
	{
		$fname = $curfiles[$i]['fi_filename']; // will more than likely be "batch imported from filenet"

    if (!preg_match('/\|/',$fname))
		  $fname = $folder . '|' . $tindx . ' ' . $fname;

    if ( ($curfiles[$i]['fi_mimetype'] == 'application/pdf') && (!preg_match('/\.pdf$/i', $fname)) )
      $fname = $fname . '.pdf';

    $fname = doc_repo_new_filename($attachto_coid, $fname);
		if (!$fname)
		  return( $route_to_status_force );

    // create entrty in tbl_filerefs for this new file
	  $sql="INSERT INTO tbl_filerefs (fi_co_ID,fi_docID,fi_docID_original,fi_encoder,fi_source,fi_active,fi_datetimestamp,fi_userID,fi_bt_ID,fi_bookmarks,fi_mimetype,fi_version,fi_desc,fi_filename,fi_filesize) ";
		$sql .=" VALUES ($attachto_coid,".sql_escape_clean($curfiles[$i]['fi_docID']).','.sql_escape_clean($curfiles[$i]['fi_docID_original']).','.sql_escape_clean($curfiles[$i]['fi_encoder']).','.sql_escape_clean($curfiles[$i]['fi_source']).',';
	  $sql .="'y',".sql_escape_clean($curfiles[$i]['fi_datetimestamp']).','.sql_escape_clean($userid).','.sql_escape_clean($curfiles[$i]['fi_bt_id']).','.sql_escape_clean($curfiles[$i]['fi_bookmarks']).',';
	  $sql .=sql_escape_clean($curfiles[$i]['fi_mimetype']).',1,'.sql_escape_clean($desc).','.sql_escape_clean($fname).','.$curfiles[$i]['fi_filesize'].')';

	  $ret=myexecute($sql);
	  $new_fid = $ret[0];
	  if (!is_numeric($new_fid))
	    return( $route_to_status );

    // destination comment (ATTACH)
    $fComments = "Document ID ".$curfiles[$i]['fi_docID']." $fname attached";
    $notesql="CALL sp_add_activity_note ($attachto_coid,'ATTACH',".sql_escape_clean($fComments).",".sql_escape_clean($userid).",'".date("Y-m-d G:i:s")."')";
    $noters=myload($notesql);

    if ($debug)
      echo 'Record #: '.$attachto_coid . ' - ' . $fComments.'<br>';

    // source comment (NOTE)
    $fComments = "Copied Document ID ".$curfiles[$i]['fi_docID']." to record # $attachto_coid";
    $notesql="CALL sp_add_activity_note ($coid,'NOTE',".sql_escape_clean($fComments).",".sql_escape_clean($userid).",'".date("Y-m-d G:i:s")."')";
    $noters=myload($notesql);
		$docs_copied++;
	}

  $fComments = "Document Repository copied to record # $attachto_coid. This record can be closed.";
  $notesql="CALL sp_add_activity_note ($coid,'NOTE',".sql_escape_clean($fComments).",".sql_escape_clean($userid).",'".date("Y-m-d G:i:s")."')";
  $noters=myload($notesql);

  // Move this record to a closed status (since not needed): PWR_SYS_MERGE status queue
  $mergeqres=myload("SELECT st_id FROM tbl_statuses WHERE st_Name='PWR_SYS_MERGE' AND st_Active='y' LIMIT 1");
  if ( (isset($mergeqres[0]['st_id'])) && ($mergeqres[0]['st_id'] != '') )
	 return array( "Document Repository copied to record # $attachto_coid in $folder folder.\n", 'PWR_SYS_MERGE' );

  if ($route_to_status == '')
    $route_to_status = 'MCS_UNID_COID';

	return( $route_to_status );
}

/**
 * Barcode: Get PBCDL status queue
 *
 * @global string $route_to_status
 * @global string $route_to_status_force
 * @param int $coid
 * @param string $field
 * @param array $fNames
 * @param bool $debug
 * @return string                         Short name of status to route to
 */
function get_PBCDL_queue( $coid, $field, $fNames, $debug )
{
  global $route_to_status, $route_to_status_force;

  $clerkid = $field[ $fNames[ 'FLD_CLERK' ] ];

  /* determine status queue to move record to
		  Based upon Clerk ID move record to:
		   Clerk ID starts with U or Q move to MCS_MR_REC
		   Clerk ID starts with R move to MCS_CCR_REC
		   All other move to MCS_CDL_OPEN
	*/

  //  Allow forced routing based on input
  if( isset( $route_to_status_force ) && ( $route_to_status_force != '') )
      return( $route_to_status_force );

  // If clerkid is not set.... Use Default Queue
  if( ( isset($clerkid) ) && ( $clerkid != '' ) )
	{
  	if (($clerkid[0] == 'U') || ($clerkid[0] == 'Q'))
  	  $route_to_status = 'MCS_MR_REC';
  	else if ( $clerkid[0] == 'R')
  	  $route_to_status = 'MCS_CCR_REC';
  	else
  	  $route_to_status = 'MCS_CDL_OPEN';
  }

  return( $route_to_status );
}

/**
 * Barcode: PDF export page as image file
 *
 * @global array $config
 * @param string $infile
 * @param int $page
 * @return string|bool         Output file on success, false on failure.
 */
function barcode_pdf_export_page1($infile, $page=1)
{
	global $config;
	// output will be in same location as input file
	$file = basename($infile, '.pdf');
	$indir = dirname($infile);
	$outpath = $indir . '/' . $file;
	$outfile = $outpath . '-p'.$page.'.png';

	// this takes around 0.65-0.71 seconds per page to split off the page into a png file
  $cmd = $config['ghostscript'] . " -sDEVICE=pnggray -dTextAlphaBits=4 -dGraphicsAlphaBits=4 -q -dNOPAUSE -dBATCH -r300 -sOutputFile=$outfile -dFirstPage=$page -dLastPage=$page $infile >/dev/null ";

  $last_line = system($cmd, $retval);

  if ((!file_exists($outfile)) || (filesize($outfile)==0))
		return false;

  return ($outfile);
}

/**
 * Barcode: crop image
 *
 * @global array $config
 * @param string $infile
 * @param string $dimensions
 * @return string|bool        Output file on success, false on failure.
 */
function barcode_image_crop($infile, $dimensions)
{
  global $config;
	$file = basename($infile,'.jpg');
	$indir = dirname($infile);
	$outfile = $indir .'/'. $file . '.crop'.$dimensions.'.jpg';

	// crop takes around 0.568 seconds
  $cmd = $config['imagick_convert'] . " -density 300 -crop $dimensions $infile $outfile";
  system ($cmd, $retval);

	// if file doesn't exist or is 0 bytes return false
  if ((!file_exists($outfile)) || (filesize($outfile)==0))
    return false;

  return ($outfile);
}

/**
 * Barcode: get mean (black-to-white) of cropped image
 *
 * @global array $config
 * @param string $infile
 * @return float
 */
function barcode_image_cropped_mean($infile)
{
	global $config;
	$file = basename($infile,'.jpg');
	$meanval = 0;
	$idetify_cmd = '/opt/pbsi/bin/identify';

  $cmd = $idetify_cmd . " -verbose $infile 2>/dev/null";
	$cmd_output = shell_exec($cmd);

	// look for the line that has something like this: "Mean: 248.236 (0.973476)"
	if (preg_match('/Mean: (\d*)/i',$cmd_output,$m))
		$meanval = $m[1];
	else
		return (-1);

	return($meanval);
}

/**
 * Barcode: rotate image
 *
 * @global array $config
 * @param string $infile
 * @param float $degree
 * @return string|bool        Output file on success, false on failure.
 */
function barcode_rotate_image($infile,$degree=90)
{
  global $config;
	$file = basename($infile,'.jpg');
	$indir = dirname($infile);
	$outfile = $indir .'/'. $file . '.rot'.$degree.'.jpg';

  $cmd = $config['imagick_convert'] . " -density 300 -rotate $degree -blur 2 $infile $outfile";
  system ($cmd, $retval);
	// if file doesn't exist or is 0 bytes return false
  if ((!file_exists($outfile)) || (filesize($outfile)==0))
    return false;

  return ($outfile);
}

/**
 * Barcode: OCR the image to read the barcode
 *
 * @global array $config
 * @param strnig $ocr_util
 * @param string $infile
 * @return string|bool
 */
function barcode_ocr_image($ocr_util, $infile)
{
  global $config;
	$cmd = '';
	$ocrtype = '';

	if ( (isset($config[$ocr_util])) && ($config[$ocr_util] != '') )
	{
		$cmd = $config[$ocr_util] . " $infile 2>&1";
		$ocrtype = $ocr_util;
	}
	else // fall back to gocr if not configured to for the passed in $ocr_util (its not configured)
	{
    $cmd = $config['gocr'] . " $infile 2>&1";
    $ocrtype = 'gocr';
	}

  if ($cmd == '')
    return false;

  $fp = popen($cmd, 'r');
  $read = '';
  while (!feof($fp))
	{
    $read .= fread($fp, 2096);
  }
  pclose($fp);
  $bc_match = barcode_from_ocr_output($read,$ocrtype);
  if ($bc_match)
		return ($bc_match);

  return false;
}

/**
 * Barcode: parse barcode string from OCR program output
 *
 * @param string $val
 * @param string $ocrtype
 * @return string|bool
 */
function barcode_from_ocr_output($val='',$ocrtype='gocr')
{
	if ($ocrtype == 'gocr')
	{
	  // extract the barcode from the XML
	  if (preg_match('/<barcode type="39" chars="(\d+)" code="(\*.*?\*)" crc="(.+?)" error="(.+?)" \/>/', $val, $matches))
	    return ($matches[2]);
	}
	else if ($ocrtype == 'zebraimg')
	{
		// CODE-39:PBCDIU26111081924930503G
		// scanned 1 barcode symbols from 1 images in 0.6 seconds
		if (preg_match('/^CODE-39:(P(B|A).*)/', $val, $matches))
			return ('*'.$matches[1].'*');
	}
  return false;
}

/**
 * Barcode: calculate code3of9 barcode checksum
 *
 * @param string $barcode
 * @return string|bool
 */
function calc_39checksum ($barcode='')
{
  if ($barcode == '')
    return false;

	$barcode39_valid_chars = array('0','1','2','3','4','5','6','7','8','9','A','B','C','D','E','F','G','H','I','J','K','L','M','N','O','P','Q','R','S','T','U','V','W','X','Y','Z','-','.',' ','$','/','+','%');
  $checksum = 0;
  $add_these = array();

  for($i=0;$i<strlen($barcode);$i++)
	{
    $add_these[] = code39index($barcode[$i], $barcode39_valid_chars);
  }
  $checksum = array_sum($add_these);

  $checksum = $checksum % 43; /* mod43 */

  if (isset($barcode39_valid_chars[$checksum]))
  	return($barcode39_valid_chars[$checksum]);

  return false;
}

/**
 * Barcode: code3of9 index used when calculating checksum
 *
 * @param string $char
 * @param array $barcode39_valid_chars
 * @return int
 */
function code39index($char,&$barcode39_valid_chars)
{
  if ($char == '')
    return(0);
  return ((int)array_search($char,$barcode39_valid_chars));
}

/**
 * Move to status queue
 *
 * @param int $coid
 * @param int $st_id
 * @param string $msg
 * @param string $user
 * @param string $source
 * @return array
 */
function move_to_status ($coid='', $st_id='', $msg='', $user='', $source='a')
{
	if (($coid == '') || ($st_id == '') || (!is_numeric($coid)) || (!is_numeric($st_id)))
		return array(false,"Queue change unsuccessful to ". get_st_Name($st_id));

	if (($user == '') && (isset($_SESSION['username'])) && ($_SESSION['username'] != ''))
		$user = $_SESSION['username'];
	else if ($user == '')
		$user = 'POWER';

  $post = array();

  $update_status = set_corr_status ($coid, $st_id, $msg, $post, $user, false, false, false, false, false, $source);

  if ($update_status[0] != true)
		return array(false,"\nQueue change unsuccessful to ". get_st_Name($st_id). ": ".$update_status[1].".");

	return array(true,"\nQueue change successful to ". get_st_Name($st_id));
}

/**
 * Get description associated with st_id.
 *
 * @param int $st_id
 * @param string $active
 * @return string
 */
function get_st_Name($st_id='', $active='y')
{
	$ret = '';
	if ($st_id == '')
		return ($ret);
	$nms=myloadslave("SELECT st_Name FROM tbl_statuses WHERE st_ID=$st_id AND st_Active='$active'");
	if ((isset($nms[0]['st_Name'])) && ($nms[0]['st_Name'] != ''))
	  return ($nms[0]['st_Name']);
	return ($ret);
}

/**
 * Assign value to control field.
 *
 * @param int $coid
 * @param string $fieldname
 * @param string $fieldvalue
 * @param string $user
 * @return boolean
 */
function set_control_field ($coid='', $fieldname='', $fieldvalue='', $user='')
{
	if (($coid == '') || ($fieldname == '') || (!is_numeric($coid)))
		return false;

	if (($user == '') && (isset($_SESSION['username'])) && ($_SESSION['username'] != ''))
		$user = $_SESSION['username'];
	else if ($user == '')
		$user = 'POWER';

	$fid = get_fid($fieldname);
	if ($fid != '')
	{
		$usql="CALL sp_add_fieldvalue ($coid,$fid,".sql_escape_clean($fieldvalue).",".sql_escape_clean($user).",NOW())";
		$ures=myload($usql);
		if (isset($ures[0][0]) && ($ures[0][0] == "0"))
			return (true);
	}
	return false;
}

/**
 * Cleanup barcode directory.
 *
 * @param string $dir
 */
function barcode_cleanup($dir='')
{
	if ($dir == '') return;
	deltree($dir);
}

/**
 * Delete a directory.
 *
 * @param string $f
 */
function deltree($f)
{
	if(is_dir($f))
	{
		foreach( scandir( $f ) as $item )
		{
			if( !strcmp( $item, '.' ) || !strcmp( $item, '..' ) )
				continue;
			deltree( $f . "/" . $item );
		}
		rmdir($f);
	}
	else
	{
		if (file_exists($f))
			unlink($f);
	}
}

/**
 * Good test.
 *
 * @param array $post
 * @return array
 */
function dummy_pre_good($post=array())
{
	return array(0,"Pre step good - return value (0).");
}
function dummy_pre_bad($post=array())
{
	return array(1,"Pre step bad - return value (1).");
}
function dummy_post_good($post=array())
{
	return array(0,"Post step good - return value (0).");
}
function dummy_post_bad($post=array())
{
	return array(1,"Post step bad - return value (1).");
}
function test_post_code($post=array())
{
	$pres = print_r ($post, true);
	return array(0,"post code hit! \n$pres \ntest post-code complete");
}
function test_pre_code($post=array())
{
	$pres = print_r ($post, true);
	return array(0,"pre code hit! \n$pres \ntest pre-code complete");
}

/**
 * Check if a control field is populated
 *
 * @param int $coid
 * @param string $field
 * @param bool $ReturnValue
 * @return bool
 */
function control_field_populated($coid, $field='', $ReturnValue=false )
{
	if ($field == '')
		return (false);

	// determine field ID for $field
	$fidrs=myload("SELECT fi_ID FROM tbl_fields WHERE fi_Name='$field' AND fi_Active='y' LIMIT 1");
	if (count($fidrs)<1)
	{
		echo "No $field field found.";
		return false;
	}
	$fid=$fidrs[0]['fi_ID'];

	// get the current value
	$fsql ="SELECT fv_Value AS fval FROM tbl_field_values WHERE fv_fi_ID=$fid AND fv_co_id=$coid ORDER BY fv_Datetimestamp DESC LIMIT 1";
	$fvalres=myload($fsql);

	if ( (isset($fvalres[0]['fval'])) && (trim($fvalres[0]['fval']) != '') )
	  if( ! $ReturnValue )
		    return true;
		else
		    return( $fvalres[0]['fval']);

	return false;
}

/**
 * Block status change by user
 *
 * @param array $post
 * @param string $user
 * @return array
 */
function block_change_byuser($post=array(), $user='')
{
  if (!isset($post['fco_ID']) || (!is_numeric($post['fco_ID'])))
    return array(1,'No record # passed to required_fields.');

  if ( (!isset($post['fst_id'])) || (!isset($post['fco_ID'])) )
    return array(1,'Status or record # missing' );

  $codeparams = $post['codeparams']; // this can be (FIELD1) or (FIELD1,FIELD2,and so on)
  $co_ID = $post['fco_ID'];

  $supervisor_allow = false;

  if (($user == '') && (isset($_SESSION['username'])) && ($_SESSION['username'] != ''))
    $user = $_SESSION['username'];
  else if ($user == '')
    $user = 'POWER';

	if ($codeparams == "(supervisor='allow')")
		$supervisor_allow = true;

  // get the current status queue for this record and the user that put it in this status
	$userinrs = myload("SELECT sl_userID FROM tbl_statusflow WHERE sl_co_ID=$co_ID and sl_Active='y' AND sl_Activity='STATUS' ORDER BY sl_status_datetime DESC LIMIT 1");
  if (!isset($userinrs[0]['sl_userID']))
	 return array( 0, "Separation of duties rule: passed." );

  if ( ($user != 'POWER') && ($user != $userinrs[0]['sl_userID']) )
	 return array( 0, 'Separation of duties rule: passed.' );

  // the same user moved it in! -- if $supervisor_allow then check this users security level
	if ($supervisor_allow)
	{
		$usecrs = myload("SELECT uLevel FROM tbl_users WHERE uUsername='$user' AND uActive='y'");
		if ( (isset($usecrs[0]['uLevel'])) && ($usecrs[0]['uLevel'] > 99) )
		{
		  return array( 0, 'Separation of duties rule: passed.' );
		}
	}

	// the same user moved it! -- need to let them know they can't perform the change status because of this
	return array( 1, 'Separation of duties rule: You are not allowed to perform the status change.' );
}

/**
 * Overpayment email
 *
 * @param array $post
 * @param string $user
 * @return array
 */
function overpayment_email($post=array(), $user='')
{
/*
 * This function should ONLY be used from an InitCode
 *
 * email distribution lists:
 *  PSC     $ CH Overpayment Appeals = CHOverpaymentAppeals@arkbluecross.com
 *  MR A    $ MRA Overpayment Appeals = MRAOverpaymentAppeals@arkbluecross.com
 *  MR B    $ MRB Overpayment Appeals = MRBOverpaymentAppeals@arkbluecross.com
 *          $ Medicare Overpayment Appeals = MedicareOverpaymentAppeals@arkbluecross.com
 *
 *  Control fields:
 *    APLTYPE = Appeal Type
 *    AccRec = A/R number (Accounts Receivable)
 *
 *  Rule #1:
 *    If AccRec is not blank ALWAYS send an email to $ Medicare Overpayment Appeals
 *
 *  Rule #2:
 *    If the APLTYPE is PSC, MR A, or MR B then ALSO send to distrib email above (based upon value)
 *
 *  Rule #3:
 *    The emails will be sent:
 *      1. if/when AccRec is set
 *      2. if/when APLTYPE is set
 *      3. on the record moving into a close status (use email_info in this case on all the closed queue)

 *    A second hidden control field is needed to keep track of what's been sent so multiple won't get sent
 *      BECAUSE this function will be setup in almost all statuses in the MCS_RED workflow type
 *
 * 
 */

  if (!isset($post['fco_ID']) || (!is_numeric($post['fco_ID'])))
    return array(1,'No record # passed to set_overpayment_emaildist.');

  if ( (!isset($post['fst_id'])) )
    return array(1,'Status ID missing' );

  if (($user == '') && (isset($_SESSION['username'])) && ($_SESSION['username'] != ''))
    $user = $_SESSION['username'];
  else if ($user == '')
    $user = 'POWER';

  $co_ID = $post['fco_ID'];
	$st_ID = $post['fst_id'];

	$fcn = get_field($co_ID, 'FCN');
	$accrec = get_field($co_ID, 'AccRec'); 
	$hic = get_field($co_ID, 'HIC');
	$ccn = get_field($co_ID, 'CCN');
	$icn = get_field($co_ID, 'ICN');
	$prov = get_field($co_ID, 'PROV');
	$dos = get_field($co_ID, 'DOS');
	$other = get_field($co_ID, 'OTHER');

	
  $closing_status = false;
  // check if the status this record is moving to is a closed status
	$clres = myloadslave("SELECT st_Group FROM tbl_statuses WHERE st_ID=$st_ID AND st_Group like '%:cl:%' AND st_Active='y'");
  if ( (isset($clres[0][0])) && ($clres[0][0] != '') )
	 $closing_status = true;

  $codeparams = $post['codeparams']; // this can be (info='APLTYPE,AccRec')
	
  if ( (!preg_match("/^\(info\='.*'\)$/",$codeparams)) )
    return array(1,"overpayment_email: Must have info='control field' as parameter" );

	//echo "<h2>new corr $new_corr_ID created</h2>";

	//$powerSubject = "MCS RED OVERPAYMENT [v_HIC=$hic,v_FCN=$fcn,v_CCN=$ccn,v_ICN=$icn,v_PROV=$prov,v_DECPWR=$decPwr,v_DOS=$dos]";
	$powerSubject = "MCS RED OVERPAYMENT - New Record";
	
	// append email subject to $codeparams, POWER #1797481
  $email_info_parm = $codeparams . ',subject=' . $powerSubject;

  $EMAILDIST_val = get_field($co_ID, 'EMAILDIST');

  $APLTYPE_val = get_field($co_ID, 'APLTYPE');
  $AccRec_val = get_field($co_ID, 'AccRec');

	// Rule #3 flags
	$AccRec_sent = false;
	$APLTYPE_sent = false;

  $AccRec_email = 'MedicareOverpaymentAppeals@arkbluecross.com';

  $send_to = array();
  // Rule #1
  if ( ($AccRec_val) && ($AccRec_val != '') )
	{
    // if we haven't sent to them before - then add them to the $send_to array
    if (($EMAILDIST_val) && ($EMAILDIST_val != '') && (preg_match('/'.$AccRec_email.'/',$EMAILDIST_val)) )
      $AccRec_sent = true;
		else
		  $send_to[] = $AccRec_email;
	}

	// Rule #2
	$APLTYPE_email = '';
	if ( ($APLTYPE_val) && ($APLTYPE_val != '') )
	{
    $APLTYPE_val = strtolower($APLTYPE_val);
	  switch ($APLTYPE_val)
	  {
	  case 'psc':
	    $APLTYPE_email = 'CHOverpaymentAppeals@arkbluecross.com';
	    break;
	  case 'mr a':
	    $APLTYPE_email = 'MRAOverpaymentAppeals@arkbluecross.com';
	    break;
	  case 'mr b':
	    $APLTYPE_email = 'MRBOverpaymentAppeals@arkbluecross.com';
	    break;
	  }
  }

  if ($APLTYPE_email != '')
	{
    if (($EMAILDIST_val) && ($EMAILDIST_val != '') && (preg_match('/'.$APLTYPE_email.'/',$EMAILDIST_val)) )
      $APLTYPE_sent = true;
    else
      $send_to[] = $APLTYPE_email;
	}

	if ( (count($send_to)==0) && (!$APLTYPE_sent && !$AccRec_sent) )
    return array(0,"overpayment_email: Appeal Type and Account Receivable not set. No email notification sent." );

  $recipients = implode(';',$send_to);

  // not a close status -- but already sent email to those we needed to
  if ( (!$closing_status) && (count($send_to)==0) && ($APLTYPE_sent && $AccRec_sent) && ($EMAILDIST_val == $recipients) )
    return array(0,"overpayment_email: Appeal Type and Account Receivable email already sent." );

	$EMAILDIST_val_new = '';

	// scenario: in 1 status $AccRec is sent, and $APLTYPE sent in another -- append
	if ( (($EMAILDIST_val) && ($EMAILDIST_val != '') && $AccRec_sent && (count($send_to)==1)) // $AccRec sent before
	   || (($EMAILDIST_val) && ($EMAILDIST_val != '') && $APLTYPE_sent && (count($send_to)==1)) ) // $APLTYPE sent before
		$EMAILDIST_val_new = $AccRec_email . ';' . $APLTYPE_email;
  else
	 $EMAILDIST_val_new = $recipients;

  if ( ($EMAILDIST_val != $EMAILDIST_val_new) && ($EMAILDIST_val_new != '') && ($recipients != '') )
    set_control_field ($co_ID, 'EMAILDIST', $EMAILDIST_val_new, $user); // control field set

  if ($closing_status)
	{
		$post['codeparams'] = preg_replace ('/^\((.*)\)$/', "(to='EMAILDIST',$1)", $post['codeparams']);
	}
	else
	{
		$post['codeparams'] = preg_replace ('/^\((.*)\)$/', "(to='$recipients',$1)", $post['codeparams']);
	}
	
	/*********************************************************
	 * Some of this existing code is tricky.                 *
	 * But, basically create a new ticket if $recipients is  *
	 * NOT Null, OR $closing_status is true                  *
	 * 10/12/2011, Jeff                                      *                           
	 *********************************************************/
	
  if (($closing_status) || ($recipients != ''))
	{
		// create new POWER Overpayment corr ID ONLY IF $AccRec is set
		$new_corr_ID = create_new_corr();
		$status_ID = get_st_ID('MCS_RESP_OVRPMT_APPLS');
		
		$spsql='CALL sp_change_queue (' . $new_corr_ID . ',' . $status_ID . ',"Create new Overpayment Appeals record","POWER","' . date("Y-m-d G:i:s") . '","na")';
		$sprs=myload($spsql);
		
		//set_control_field ($new_corr_ID,"AccRec","$accrec","POWER");
		set_control_field ($new_corr_ID,"FCN","$accrec","POWER"); // MCS Accounts Receivable
		set_control_field ($new_corr_ID,"HIC","$hic","POWER");
		set_control_field ($new_corr_ID,"CCN","$ccn","POWER");
		set_control_field ($new_corr_ID,"ICN","$icn","POWER");
		set_control_field ($new_corr_ID,"PROV","$prov","POWER");
		set_control_field ($new_corr_ID,"DOS","$dos","POWER");
		set_control_field ($new_corr_ID,"OTHER","$other","POWER");
	
		$comment = 'Created new ticket (' . $new_corr_ID . ') in the MCS_RESP_OVRPMT_APPLS queue';
		$rs=myload('CALL sp_add_activity_note (' . $co_ID . ',"NOTE",' . sql_escape_clean($comment) . ',"overpayment_email()","' . date("Y-m-d G:i:s") . '")');
		
		$comment = "Field values transferred from ($co_ID) HIC=$hic,FCN=$fcn,AccRec=$accrec,CCN=$ccn,ICN=$icn,PROV=$prov,DOS=$dos,OTHER=$other";
		$rs=myload('CALL sp_add_activity_note (' . $new_corr_ID . ',"NOTE",' . sql_escape_clean($comment) . ',"overpayment_email()","' . date("Y-m-d G:i:s") . '")');
			

		// ns - set email subject so the email will be directed into the ticket that we just created above
		$post['codeparams']=preg_replace('/\)$/', ",subject='New POWER document in the MCS_RED_ACCT status queue [c=".$new_corr_ID."]')", $post['codeparams']);

    return ( email_info($post, $user) );
  	//return array(0,"Ticket ".$new_corr_ID." created in the MCS_RED_ACCT queue." );
	}

	
	if (!$APLTYPE_sent && !$AccRec_sent)
    return array(0,"overpayment_email: Appeal Type or/and Account Receivable email notification failed." );

  return array(0,"overpayment_email: Appeal Type or/and Account Receivable email notification previously sent." );
}

/**
 * User temination check. If user is not a POWER user, record is moved to PBSI_PWRQC_CL_USER
 *
 * @param array $post
 * @param string $user
 * @return array
 */
function user_termination_check($post=array(), $user='')
{

  if (!isset($post['fco_ID']) || (!is_numeric($post['fco_ID'])))
    return array(1,'No record # passed.');

	if (($user == '') && (isset($_SESSION['username'])) && ($_SESSION['username'] != ''))
		$user = $_SESSION['username'];
	else if ($user == '')
		$user = 'POWER';

  $co_ID = $post['fco_ID'];

  $ESUBJECT_fid = get_fid('ESUBJECT');
  $res = myload("SELECT fv_Value FROM tbl_field_values WHERE fv_co_ID=$co_ID AND fv_fi_ID=$ESUBJECT_fid AND fv_Value LIKE 'Termination of %'");

  if ( (count($res) == 0) || (!isset($res[0]['fv_Value'])) )
    return array(0,"user_termination_check: Not a termination notice." );

  $esubject = $res[0]['fv_Value'];
  $esubject = preg_replace('/^Termination of\s+/','',$esubject);
  $esubject = trim(strtoupper($esubject));

  $firstname = '';
  $goesbyname = '';
  $middlename = '';
  $lastname = '';

  if (preg_match("/^(.+)\s(\S+)\.{0,1}$/", $esubject, $m))
  {
    $lastname = $m[2];
    $firstmiddlegoesby = trim($m[1]);

    if (strpos($firstmiddlegoesby,' ') === false)
    {
      $firstname = $firstmiddlegoesby;
      $firstmiddlegoesby = '';
    }
    // Goes by usually inside '' or ()
    else if (preg_match("/'(\S+)'/", $firstmiddlegoesby, $m))
    {
      $goesbyname = $m[1];
      $firstmiddlegoesby = str_replace("'$goesbyname'", '', $firstmiddlegoesby);
    }
    else if (preg_match("/\((\S+)\)/", $firstmiddlegoesby, $m))
    {
      $goesbyname = $m[1];
      $firstmiddlegoesby = str_replace("($goesbyname)", '', $firstmiddlegoesby);
    }

    if (preg_match("/\s(\w)\.{0,1}$/", $firstmiddlegoesby, $m))
    {
      $middlename = $m[1];
      $firstmiddlegoesby = preg_replace("/\s\w\.{0,1}$/",'',$firstmiddlegoesby);
    }

    if (($firstmiddlegoesby != '') && (preg_match("/^(\S+)\s(\S+)$/", $firstmiddlegoesby, $m)) )
    {
      $firstname = $m[1];
      $middlename = $m[2];
      $firstmiddlegoesby = '';
    }

    if (($firstmiddlegoesby != '') && (preg_match("/^(\S+)\s+$/", $firstmiddlegoesby, $m)) )
    {
      $firstname = $m[1];
      $middlename = preg_replace("/^$firstname\s+/",'',$firstmiddlegoesby);
    }
    else if ($firstmiddlegoesby != '')
    {
      $firstname = $firstmiddlegoesby;
    }
  }

  $EBODY_fid = get_fid('EBODY');
  $ebody = '';

  $res = myload("SELECT fv_Value FROM tbl_field_values WHERE fv_co_ID=$co_ID AND fv_fi_ID=$EBODY_fid");
  if (isset($res[0]['fv_Value']))
    $ebody = $res[0]['fv_Value'];

  $firstname = trim($firstname);
  $goesbyname = trim($goesbyname);
  $middlename = trim($middlename);
  $lastname = trim($lastname);

  if ($lastname == '')
    return array(0,"user_termination_check: Not a termination notice (Failed to parse name)." );

  if ( (strpos($ebody, 'effective') === false) || (strpos($ebody, 'termination') === false) )
    return array(0,"user_termination_check: Not a termination notice (invalid EBODY)." );

  $eff_date = '';

  if (preg_match('/effective (\d.*?)\./', $ebody, $m))
  {
    $eff_date = date('Y-m-d',strtotime($m[1]));
    if (strpos($eff_date,'1969') !== false) // bad date
      return array(0,"user_termination_check: Not a termination notice (invalid effective date)." );
  }

  if (!set_control_field ($co_ID, 'EFFDATE', $eff_date, $user))
    return array(0,"user_termination_check: Failed to set Effective Date." );

  // set PRIORITY to 3
  if (!set_control_field ($co_ID, 'PRIORITY', '3', $user))
    return array(0,"user_termination_check: Failed to set Priority." );

  // Matching that requires manually termination of user on effective date:
  // 1. Check tbl_users uLast to match $lastname and uFirst to match $firstname or uFirst to match $firstname $middlename
  // 2. Check tbl_users uLast to match $lastname and uFirst to match $goesbyname, or uFirst to match $firstname ($goesbyname)
  // 3. Check tbl_users to match uFirst to $firstname and uLast to $lastname OR '$middlename $lastname' or '$middlename-$lastname'
  // 4. have $fistname, $middlename, and $lastname - check uNetID to be $firstname[0].$middlename[0].$lastname

  $sql1 = "SELECT uUsername FROM tbl_users WHERE uActive='y' AND uLast=".sql_escape_clean($lastname)." AND (uFirst=".sql_escape_clean($firstname)." OR uFirst=".sql_escape_clean("$firstname $middlename").")";
  $res = myloadslave($sql1);
  if (count($res) > 0)
  {
    $noters=myload("CALL sp_add_activity_note ($co_ID,'NOTE',".sql_escape_clean("Detected as POWER user: ".$res[0]['uUsername'] ).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");
    return array(0,"user_termination_check: Detected as POWER user (M1): " . $res[0]['uUsername'] );
  }

  if ($goesbyname != '')
  {
    $sql2 = "SELECT uUsername FROM tbl_users WHERE uActive='y' AND uLast=".sql_escape_clean($lastname)." AND (uFirst=".sql_escape_clean($goesbyname)." OR uFirst=".sql_escape_clean($firstname." ($goesbyname)").")";
    $res = myloadslave($sql2);
    if (count($res) > 0)
    {
      $noters=myload("CALL sp_add_activity_note ($co_ID,'NOTE',".sql_escape_clean("Detected as POWER user: ".$res[0]['uUsername'] ).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");
      return array(0,"user_termination_check: Detected as POWER user (M2): " . $res[0]['uUsername'] );
    }
  }

  if ($middlename != '')
  {
    $sql3 = "SELECT uUsername FROM tbl_users WHERE uActive='y' AND (uLast=".sql_escape_clean("$middlename $lastname")." OR uLast=".sql_escape_clean("$middlename-$lastname").") AND uFirst=".sql_escape_clean($firstname);
    $res = myloadslave($sql3);
    if (count($res) > 0)
    {
      $noters=myload("CALL sp_add_activity_note ($co_ID,'NOTE',".sql_escape_clean("Detected as POWER user: ".$res[0]['uUsername'] ).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");
      return array(0,"user_termination_check: Detected as POWER user (M3): " . $res[0]['uUsername']  );
    }
  }

  if (($firstname != '') && ($lastname != '') && ($middlename != ''))
  {
    $netid = $firstname[0] . $middlename[0] . $lastname;
    $sql4 = "SELECT uUsername FROM tbl_users WHERE uActive='y' AND uNetID=".sql_escape_clean($netid);
    $res = myloadslave($sql4);
    if (count($res) > 0)
    {
      $noters=myload("CALL sp_add_activity_note ($co_ID,'NOTE',".sql_escape_clean("Detected as POWER user: ".$res[0]['uUsername'] ).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");
      return array(0,"user_termination_check: User name match (NetID match): " . $res[0]['uUsername'] );
    }
  }

  // Could not match this user termination to an existing, active, POWER user - move to:
  //    Closed - User Request (PBSI_PWRQC_CL_USER)
  $fcomment = 'user_termination_check: Not detected as a POWER user.';

  if (!set_control_field ($co_ID, 'QCSOLUTION', $fcomment, $user))
    return array(0,"user_termination_check: Failed to set Solution." );

  $st_ID  = get_st_ID('PBSI_PWRQC_CL_USER');

  if (!$st_ID)
    return array(0,'user_termination_check: missing PBSI_PWRQC_CL_USER status queue');

  $mts_ret = move_to_status ($co_ID, $st_ID, $fcomment . " Moving to PBSI_PWRQC_CL_USER", $user, 'a');
  if ($mts_ret[0] === false)
    return array(0,$fcomment . ' Failed to auto-close record: '.$mts_ret[1]);
  else
    return array(0,$fcomment . ' Record closed.');
}


/**
 * FISS Collection 935 email - used in FISS Redetermination workflow.
 * Best to setup this function in each Init-code of the statuses in FSS_RED
 *
 * @param array $post
 * @param string $user
 * @return array
 */
function collections935_email($post=array(), $user='')
{
/*
 * 12/8/2010 Jeff
 *
 * Description of problem:
 * Tammy Roberts gets information in FISS Redeterminations
 *     Sometimes, this demands the need to create a ticket in
 *     935 Redeterminations (FSS_935_APPL_REQ).. (Nina's group)
 *
 * Solution: automatically email (935Approved...@pinnacle.com) Nina's team
 *     with this information and let them key in the ticket
 * A Better Solution: email CC corratch@ and have these tickets then be created automatically
 *
 * Rule 1:
 *  The email should be sent once when the "935 Collections" (935COLL) checkbox control field IS CHECKED
 *
 * Rule 2:
 *   The email should contain a subject that will result in a new ticket that has the DCN, PROV, and DECPWR populated.
 *
 * Rule 3:
 *   If the Redeterminations ticket is moving into a closed status, the email subject will reflect closed
 *
 */

	global $config;


	if (!isset($post['fco_ID']) || (!is_numeric($post['fco_ID'])))
	  return array(1,'No record # passed to collections935_email.');

	if ( (!isset($post['fst_id'])) )
	  return array(1,'Status ID missing' );

	// determine from address for outgoing email. it cannot be powersupport, otherwise it will get skipped upon import
	$from = 'powersupport@pinnaclebsi.com';

	if (($user == '') && (isset($_SESSION['username'])) && ($_SESSION['username'] != ''))
	{
		$user = $_SESSION['username'];
		$ures = myload("SELECT uEmail FROM tbl_users where uUsername='".$user."' AND uActive='y'");
		if (count($ures) > 0 && isset($ures[0]['uEmail'])) $from = $ures[0]['uEmail'];
	}
	else if ($user == '')
	{
		$user = 'POWER';
	}

	$co_ID = $post['fco_ID'];
	$st_ID = $post['fst_id'];

	$closing_status = false;
	// check if the status this record is moving to is a closed status
	$clres = myload("SELECT st_Group FROM tbl_statuses WHERE st_ID=$st_ID AND st_Group like '%:cl:%' AND st_Active='y'");
	if (count($clres)>0) $closing_status = true;

	$codeparams = $post['codeparams']; // this can be (info='DCN,HIC,DOS,PROV,QICAPPNO,ALJAPPNO,co_region,co_receivedate')

	if ( (preg_match("/\(q='(.*)',info='(.*)'\)/",$codeparams, $m)) ) // jeff
	{
		if (isset($m[1]))
			$queue= $m[1];
		if (isset($m[2]))
			$info_param = $m[2];
	}
	else
	{
		return array(1,$codeparams." collections935_email: Must have q='queue',info='control fields' as parameter" );
	}

	$email_info_parm = $codeparams;

	$COLL935_val = get_field($co_ID, '935COLL');

	// Rule #1 - 935 checkbox checked
	if ( (!$COLL935_val) || ($COLL935_val != 'y') )
	{
		return array(0,"collections935_email: 935 Collection checkbox not set. No email notification sent." );
	}

	$dcn = get_field($co_ID, 'DCN');
	$prov = get_field($co_ID, 'PROV');
	$dos = get_field($co_ID, 'DOS');
	$hic = get_field($co_ID, 'HIC');

	$decPwr = $co_ID;

	//var_export($codeparams); var_export($dcn); $var_export($prov); var_export($decPwr); exit();
	//if( stristr( $config["system_short_name"], 'DEVEL' ) )

	$production=false;if($config["system_short_name"]=='POWERTEST') $production=true;
	if ($production){
		$to='corrattach@pinnaclebsi.com';
	} else {
		$to="powertest@pinnaclebsi.com,pnshaver@pinnaclebsi.com,jmmaxwell@pinnaclebsi.com,jsweiss@pinnaclebsi.com";
	}

	$powerSubject = "[q=$queue,v_DCN=$dcn,v_PROV=$prov,v_DECPWR=$decPwr,v_DOS=$dos,v_HIC=$hic]";

	// we are on our way out of the RED queue. FSS_935_APPL_DEC is what we use if on the way out.
	// remember, this function is being called on FSS_RED_935_RED init() and post(), Jeff
	// init (q='FSS_935_APPL_REQ')
	// post (q='FSS_935_APPL_DEC')

	if ($queue=='FSS_935_APPL_REQ')
	{
			$sql = "SELECT
								fv_co_ID as PowerID,
								fv_Value as DCN,
								fv_Username as User,
								date_format(fv_datetimestamp,'%m/%d/%Y') dateTime,co_receivedate as dateReceived,
								s.st_ID,s.st_name as Queue,
								date_format(co_queue_datetime,'%m/%d/%Y') as dateQueue,
								LOCATE('_CL_',s.st_name) as pos
							FROM tbl_field_values fv
							JOIN tbl_corr c ON fv.fv_co_ID = c.co_ID
							JOIN tbl_statuses s ON c.co_queue = s.st_ID
							WHERE fv.fv_fi_ID=55
								AND fv.fv_value=".sql_escape_clean($dcn)."
								AND fv_Username='Email'
								AND s.st_name='FSS_935_APPL_REQ'";
			$result = myload($sql);

			// If an email has already been sent for this DCN (fv_fi_ID = 55)
			// don't send another email.  POWER #1626520, Jeff
			if (count($result) > 0)
			{
				return array(0,"collections935_email: An email has already been sent for DCN " . $dcn . ". No need to send another. (Rule #1626520)");
			}
		$post['codeparams'] = "(to='".$to."',info='".$info_param."',subject='FISS 935 Collections Record $co_ID Open $powerSubject')";
		return ( email_info($post, $user, $from) );
	}

	if ($queue=='FSS_935_APPL_DEC')
	{
		// if on the way out, see if the record is closed.
		if ($closing_status)
		{
			$sql = "SELECT
								fv_co_ID as PowerID,
								fv_Value as DCN,
								fv_Username as User,
								date_format(fv_datetimestamp,'%m/%d/%Y') dateTime,
								co_receivedate as dateReceived,
								s.st_ID,
								s.st_name as Queue,
								date_format(co_queue_datetime,'%m/%d/%Y') as dateQueue,
								LOCATE('_CL_',s.st_name) as pos
							FROM tbl_field_values fv
							JOIN tbl_corr c ON fv.fv_co_ID = c.co_ID
							JOIN tbl_statuses s ON c.co_queue = s.st_ID
							WHERE fv.fv_fi_ID=55
								AND fv.fv_value=".sql_escape_clean($dcn)."
								AND fv_Username='Email'
								AND s.st_name='FSS_935_APPL_DEC'";
			$result = myload($sql);

			// If an email has already been sent for this DCN (fv_fi_ID = 55)
			// don't send another email.  POWER #1626520, Jeff
			if (count($result) > 0)
			{
				return array(0,"collections935_email: An email has already been sent for DCN " . $dcn . ". No need to send another. (Rule #1626520)");
			}

			$post['codeparams'] = "(to='".$to."',info='".$info_param."',subject='FISS 935 Collections Record $co_ID Closed $powerSubject')";
			return ( email_info($post, $user, $from) );
		}
		else
		{
			return array(0,"collections935_email: Destination queue is not an closed queue, 935 email not sent.." );
		}
	}

	return array(0,"collections935_email: 935 email not sent, destination queue (".$queue.") not recognized." );
}

/**
 *  get_group_id - get the ID for a group by name
 *
 *  @param g_Name (str) group name
 *
 * 	@return	(int) group ID
 */
function get_group_id($g_Name){
	$sql="SELECT g_ID FROM tbl_groups WHERE g_Name=".sql_escape_clean($g_Name);
	$rs=myloadslave($sql);
	if (count($rs)==0) return '';
	return $rs[0]['g_ID'];
}

/**
 *  generic send email function
 *
 *  @param recipients (str) list of recipients, either comma or semicolon-separated
 *  @param subject		(str) message subject
 *  @param body				(str) message to put into body of message
 *
 * 	@return 					(str) empty if successful, error message if unsuccessful
 */
function send_email($recipients,$subject='(No message subject)',$body='(No message body)')
{
	global $config;
	$ret='';

	$recipients=preg_replace("/,/",";",$recipients);
	$recarr=preg_split('/;/',$recipients);

	$from=$config["contact_email"];
	$fromname=$config["contact_name"];
	$msg=$body;

	if (count($recarr) > 0){
		require_once (dirname(__FILE__).'/../includes/phpmailer/class.phpmailer.php');
		$mail = new PHPMailer();
		$mail->IsSMTP();
		$mail->Host="mail.abcbs.net";
		$mail->From=$from;
		$mail->FromName=$fromname;
		for ($x=0;$x<count($recarr);$x++){
			$mail->AddAddress($recarr[$x]);
		}
		$mail->WordWrap=50;
		$mail->Subject=$subject;
		$mail->Body=$msg;

	// If we are ANYWHERE other than PROD,  (POWERTEST),
	// make sure emails only go to internal POWER support employees

	$production=false;if($config["system_short_name"]=='POWERTEST') $production=true;

	if (!$production) {
		$mail->ClearAddresses();
		$mail->ClearBCCs();
		$mail->ClearCCs();
		$mail->AddAddress("powertest@pinnaclebsi.com");
		$mail->AddAddress("jsweiss@pinnaclebsi.com");
		$mail->AddAddress("pnshaver@pinnaclebsi.com");
		//$mail->AddAddress("jmmaxwell@pinnaclebsi.com");
		//$mail->AddAddress("mjquirijnen@pinnaclebsi.com");	
		$recipients = "The POWER team (2)";
	}
	
		if (!$mail->Send()){
			$ret=$mail->ErrorInfo;
		}
	} else {
		$ret='No recipients found. Message not sent.';
	}
	return $ret;
}

/**
 * Create another POWER record with fields based upon this record that is currently changing queues
 *
 * This function will be passed a queuename and field names. It will create XML that will create a power record, set its queue, and set some control field values
 *
 * @param array $post
 * @param string $user
 * @return array
 */
function prepost_create_power_record($post=array(), $user='') {
  global $config;

  $co_ID = $post["fco_ID"];

  set_error_handler("echoError");

  // explode passed-in variables on ,
  $varr = explode( ',', preg_replace( '/[( )]/', '', $post['codeparams'] ) );

  if(!is_array($varr ) || count($varr[0])==0){
    restore_error_handler();
    return array(1,'Configuration incorrect - no function parameters found.' );
  }

	$st_ID=0;
	$st_Name='';
	$copy_all_fields=false;
	$farr=array();
	foreach ($varr as $var){
		$this_varr=explode('=', $var);
		if (count($this_varr)==2){
			// get queue from function parameters
			if ($this_varr[0]=='q' && $this_varr[1]<>''){
				$st_ID=get_st_ID($this_varr[1]);
				$st_Name=$this_varr[1];
			}

			// get fields from function parameters
			if ($this_varr[0]=='f' && $this_varr[1]<>''){
				$fi_ID=get_fid($this_varr[1]);
				if ($fi_ID>0){
					$fv_Value=get_field($co_ID, $fi_ID);
					if ($fv_Value<>''){
						$farr[]=array('fi_Name'		=>	$this_varr[1],
													'fv_Value'	=>	$fv_Value);
					}
				}
			}

			// allow specification of all fields in codeparams
			if ($this_varr[0]=='allfields' && $this_varr[1]=='y'){
				$copy_all_fields=true;
			}
		}
	}

	if ($st_ID==0){
    restore_error_handler();
    return array(1,'Configuration incorrect - invalid queue specified.'.print_r($post,true).print_r($varr,true).'varr[0]='.print_r(explode('=',$varr[0]),true).get_st_ID('PBSI_PWRQC_REC')."\n");
	}

	if ($copy_all_fields){
		// rebuild farr with all fields for this workflow
		$farr=array();
		$sql="SELECT fi_ID, fi_Name
					FROM tbl_field_associations
					INNER JOIN tbl_fields ON fa_fi_ID=fi_ID
					INNER JOIN tbl_statuses ON st_ct_ID=fa_ct_ID
					WHERE st_ID=".$st_ID."
						AND fa_Active='y'
						AND fi_Active='y'";
		$fars=myload($sql);
		if (count($fars)>0){
			foreach ($fars as $fa){
				$fv_Value=get_field($co_ID, $fa['fi_Name']);
				if ($fv_Value<>''){
					$farr[]=array('fi_Name'		=>	$fa['fi_Name'],
												'fi_ID'			=>	$fa['fi_Name'],
												'fv_Value'	=>	$fv_Value);
				}
			}
		}
	}
	// establish XML object
	require_once (dirname(__FILE__).'/../includes/xml_functions.php');
	$xml = new SimpleXMLElement('<Import />');

	$xml->addAttribute( 'DefaultQueue', 'n');

	$rec=$xml->addChild( 'Record' );

	// associate this new POWER record with the user that has transferred this existing POWER record
	$rec->addAttribute( 'User', $user);
	$rec->addAttribute( 'Region', get_field($co_ID, 'REGION'));

	// create a comment that describes how this record was created
	$rec->addAttribute( 'Comment', 'Created by pre/init/post of record '.$co_ID.' on '.date('m/d/Y'));

	// set queue
	$rec->addChild( 'Queue', $st_Name );

	// establish field values subelement
	$fv = $rec->addChild('FieldValues');

	$fv->addChild( 'Region', get_field($co_ID, 'REGION'));

	if (count($farr)>0){
		// add each field value
		foreach ($farr as $f){
			$fv->addChild( $f['fi_ID'], $f['fv_Value']);
		}
	}

  $ret=xml_import_records($xml);
	/*
	// dump xml
	header('Content-Type: text/xml');
  echo $xml->asXML();
	exit;
	*/

	// call loader
  if(isset($ret->Records[0]->Create) && (string)$ret->Records[0]->Create > 0){
		$errors=''; if (isset($ret->Errors->Error[0])) $errors=(string)$ret->Errors->Error[0]->asXML();
		$new_co_ID=(string)$ret->Records[0]->Create;
		restore_error_handler();
		return array(0,'POWER record - '.$new_co_ID.' created. '.$errors);
	} else {
		restore_error_handler();
		if (isset($ret->Errors->Error[0]))
			return array(0,'Record creation unsuccessful - '.(string)$ret->Errors->Error[0]->asXML());
		else
			return array(0,'Record creation unsuccessful');
	}
}

/*
 * create a copy of the current record for bana_instate oneoff needs
 *
 * @param array $post - expect (q=DEST_QUEUE,f=DUPEFIELD,f=DUPEFIELD)
 * @param string $user
 * @return array
 */
function bana_instate_clone_record($post=array(), $user=''){
	global $config;

	// get the POWER record number
	if (isset($post['fco_ID']) && $post['fco_ID']>0){
		$co_ID=$post['fco_ID'];
	} else {
		return array(1,'No record # passed to function');
	}

  // explode passed-in variables on ,
  $varr = explode( ',', preg_replace( '/[( )]/', '', $post['codeparams'] ) );

  if(!is_array($varr ) || count($varr[0])==0){
    return array(1,'Configuration incorrect - no function parameters found.' );
  }

	// skip clone if DONOTCLONE=y
	$DONOTCLONE=get_field($co_ID, 'DONOTCLONE');
	if ($DONOTCLONE=='y'){
		// something has set DONOTCLONE=y, and doesn't want this clone to apply, so clear out DONOTCLONE and we're done here.
		put_field($co_ID, 'DONOTCLONE', '');
		return array(0,'Skipped record clone, DONOTCLONE set to y');
	}
	// clear out DONOTCLONE, regardless of what happens from here forward
	put_field($co_ID, 'DONOTCLONE', '');

	$farr=array();
	foreach ($varr as $var){
		$this_varr=explode('=', $var);
		if (count($this_varr)==2){

			// get queue from function parameters
			if ($this_varr[0]=='q' && $this_varr[1]<>''){
				$dupe_st_Name=$this_varr[1];
				$dupe_st_ID=get_st_ID($dupe_st_Name);
			}

			// get key fields from function parameters - these will be used for dupe check
			if ($this_varr[0]=='f' && $this_varr[1]<>''){
				$fi_ID=get_fid($this_varr[1]);
				if ($fi_ID>0){
					$farr[]=array('fi_ID'		=> $fi_ID,
												'fi_Name'	=> $this_varr[1]);
				}
			}
		}
	}

	if (!isset($dupe_st_ID)){
		// a destination queue was not passed in, no point in going further than here
		return array(1,'Could not identify destination queue for clone');
	}

	if (count($farr)>0){
		// found dupecheck field, build dupecheck SQL
		$joins=$wheres=$dupe_field='';
		foreach ($farr as $fa){
			$dupe_field.='/'.$fa['fi_Name'];
			$this_fv_Value=get_field($co_ID, $fa['fi_Name']);
			$joins.="INNER JOIN tbl_field_values ".$fa['fi_Name']." ON ".$fa['fi_Name'].".fv_co_ID=co_ID AND ".$fa['fi_Name'].".fv_fi_ID=".$fa['fi_ID']." ";
			$wheres.=" AND ".$fa['fi_Name'].".fv_Value=".sql_escape_clean($this_fv_Value);
		}
		$dupe_field=substr($dupe_field, 1);

		// look for a duplicate record in the destination queue
		$dupe_st_ID=get_st_ID($dupe_st_Name);
		$sql="SELECT COUNT(co_ID) AS co_count
					FROM tbl_corr
					INNER JOIN tbl_statuses ON co_queue=st_ID
					".$joins."
					WHERE st_Name=".sql_escape_clean($dupe_st_Name)."
					".$wheres;
		$rs=myload($sql);

		if ($rs[0]['co_count']>0){
			// a duplicate record was found, do not continue
			return array(0,'Skipped record clone, duplicate '.$dupe_field.' found in '.$dupe_st_Name);
		}
	}

	// get received date for source record
	$sql="SELECT * FROM tbl_corr WHERE co_ID=".$co_ID;
	$cors=myload($sql);
	if (count($cors)>0){
		$this_receivedate=$cors[0]['co_receivedate'];
	} else {
		return array(1,'Could not identify source record for clone');
	}

	// duplicate not found, create a new record in the destination queue
	$new_co_ID=create_new_corr($user, $this_receivedate);
	if ($new_co_ID>0){
		// copy this record's field values to the new record
		$sql="SELECT fi_Name,
								 fi_ID,
								 fi_ft_Name
					FROM tbl_field_associations
					INNER JOIN tbl_fields ON fa_fi_ID=fi_ID
					INNER JOIN tbl_statuses ON st_ct_ID=fa_ct_ID
					WHERE st_Name=".cleansql($dupe_st_Name)."
						AND fa_active='y'
						AND fi_active='y'";
		$fars=myload($sql);
		if (count($fars)>0){
			foreach ($fars as $fa){
				if ($fa['fi_ft_Name']=='group'){
					// get the group field value (json encoded)
					$this_fv_Value=get_multi_field($co_ID, $fa['fi_Name']);
				
					// put the group field value
					put_group_field($new_co_ID, $fa['fi_Name'], $this_fv_Value, 'POWER');
				} elseif ($fa['fi_ft_Name']=='select-multi' ||
									$fa['fi_ft_Name']=='text-multi'){
					// get multi field values
					$sql="SELECT * 
								FROM tbl_field_values 
								WHERE fv_co_ID=".$co_ID."
									AND fv_fi_ID=".$fa['fi_ID'];
					$mfrs=myload($sql);

					// put multi field values
					if (count($mfrs)>0){
						foreach ($mfrs as $mf){
							$sql="INSERT INTO tbl_field_values
										SET fv_fi_ID=".$mf['fv_fi_ID'].",
												fv_co_ID=".$new_co_ID.",
												fv_Value=".sql_escape_clean($mf['fv_Value']).",
												fv_Username=".sql_escape_clean($mf['fv_Username']).",
												fv_datetimestamp=".sql_escape_clean($mf['fv_datetimestamp']);
							$irs=myexecute($sql);
						}
					}
				} else {
					// get field
					$this_fv_Value=get_field($co_ID, $fa['fi_Name']);

					// put field
					put_field($new_co_ID, $fa['fi_Name'], $this_fv_Value);
				}
			}
		}

		// put the new record into its destination queue
		$comment='Split record from '.$co_ID;
		$upd=set_corr_status($new_co_ID, $dupe_st_ID, $comment);
		if ($upd[0]==false){
			return array(1,'Could not set initial queue for '.$new_co_ID.': '.$upd[1]);
		} else {
			return array(0,'Created clone record '.$new_co_ID.' in '.$dupe_st_Name.' queue: '.$upd[1]);
		}
	} else {
		return array(1,'Could not create clone record '.$co_ID);
	}
}

/**
 * exports a single document to filenet for HR
 *
 * @param array $post
 * @param string $user
 * @return array
 */
function export_filenet_HR($post=array(), $user=''){
	global $config, $mimetypes;

	// export tmp path -- need more space than /tmp has available for large exports
	$mytemppath = $config['fcprepend'] . $config['fcdir'] . '/tmp/';
	if (!file_exists($mytemppath))
		mkdir($mytemppath, 0770);

	$ret='';
	if (!is_writable($mytemppath))
	{
		$ret.='Could not export to filenet - temp path is not writable: ' . $mytemppath . "\n";
		return array(1,$ret);
	}

	set_time_limit(1800);

	// get co_ID for document
	if (isset($post['fco_ID']) && $post['fco_ID']>0){
		$co_ID = $post['fco_ID'];
	} else {
		return array(1,'No ticket found for export processing.');
	}

	// get most recent Evaluation* document unless another one has been flagged as default
	$sql="SELECT
	fi_id,
	fi_docID,
	fi_filename,
	ct_Filenet_Doctype,
	co_region,
	fi_bt_ID
FROM tbl_filerefs
INNER JOIN tbl_corr ON fi_co_ID=co_ID
INNER JOIN tbl_statuses ON co_queue=st_ID
INNER JOIN tbl_corr_types ON st_ct_ID=ct_ID
WHERE co_ID=".$co_ID."
	AND fi_active='y'
	AND fi_filename LIKE 'Evaluation%'
GROUP BY fi_docID
ORDER BY IF(fi_category='default',0,1),
	fi_datetimestamp DESC,
	fi_docID DESC
LIMIT 1 /* filenet_export_documents.php */";
	$newrs=myload($sql);

	// use page_udate, AIX doesn't seem to support microseconds
	$btdt=page_udate("YmdHisuu");

	$ret.='Filenet HR document texport - ' . count($newrs) . " documents found\n";

	if (count($newrs)>0)
	{
		// establish filenames
		$eobfn='hr_'.$btdt.'.eob';
		$datfn='transact.dat';

		// establish dat file contents
		$dat='';
		$unlink_files=array();
		$last_fi_docID='';
		for ($i=0;$i<count($newrs);$i++)
		{
			$fi_id=$newrs[$i]['fi_id'];
			$fi_docID=$newrs[$i]['fi_docID'];

			$fi_filename = '';
			if (isset($newrs[$i]['fi_filename']))
				$fi_filename=$newrs[$i]['fi_filename'];

			$ct_Filenet_Doctype=$newrs[$i]['ct_Filenet_Doctype'];

			// FileNet Doc Type must not exceed 5 chars and must be defined
			if ( ($ct_Filenet_Doctype == '') || (strlen($ct_Filenet_Doctype) > 5) )
			{
				$ret.="Filenet HR export rejected: fi_id:$fi_id  fi_docID:$fi_docID due to doc type: $ct_Filenet_Doctype\n";
				$last_fi_docID=$fi_docID;
				continue;
			}

			$co_region=$newrs[$i]['co_region'];
			$imageArray=get_filecache($fi_docID,false);

			// attempt to make certain content type is just a mime type, nothing more
			if (isset($imageArray['Content-Type']))
				$imageArray['Content-Type']=preg_replace("/;.*/","",$imageArray['Content-Type']);

			if ( (!isset($imageArray['Content-Type'])) || (trim($imageArray['Content-Type']) == '') || (trim($imageArray['Content-Type']) == 'none') ) // default to octet-stream if don't know mimetype
				$imageArray['Content-Type']='application/octet-stream';

			if ( ($last_fi_docID != $fi_docID) && (isset($imageArray['data'])) && (strlen($imageArray['data'])>0) )
			{
				if ($dat!='')
				 $dat.="\n";

				$ext='';

				if ( ($fi_filename != '') && (preg_match('/\..*/',$fi_filename)) )  // get extension from filename
				{
					$fparts = explode('.', $fi_filename);
					if (count($fparts) > 1)
						$extension = $fparts[count($fparts)-1];

					if ( (strlen($extension) > 1) && (isset($mimetypes[$extension])) ) // if a known mimetype for this extension
						$ext = '.'.$extension;
				}

				if ( ($ext == '') && (isset($mimetypes)) )
				{
					$mimeexts=array_flip($mimetypes);
					/* if application/pdf then default to a .pdf extension */
					if ( (isset($imageArray['Content-Type'])) && ($imageArray['Content-Type'] == 'application/pdf') )
						$ext = '.pdf';
					else if (isset($mimeexts[$imageArray['Content-Type']]))
						$ext='.'.$mimeexts[$imageArray['Content-Type']];
				}

				if ($ext == '')
				 $ext = '.dat';

				$dat_emp_name=trim(get_field($co_ID, 'HR_EEPNAMELAST')).' '.trim(get_field($co_ID, 'HR_EEPNAMEFIRST'));
				$dat_emp_no=sprintf("%06d", trim(get_field($co_ID, 'HR_EECEMPNO')));
				$dat_emp_ssn=trim(get_field($co_ID, 'HR_EMP_SSN'));
				$dat_active='A';
				$dat_mime=$imageArray['Content-Type'].';name="'.$fi_docID.$ext.'"';

				$dat.= "19:EVAL,".
					$dat_emp_name.",".
					$dat_emp_no.",".
					$dat_emp_ssn.",".
					$dat_active.",".
					$dat_mime.
					":".$fi_docID.
					":".$fi_docID.$ext;

				// write file
				file_put_contents($mytemppath.$fi_docID.$ext,$imageArray['data']);
				$unlink_files[$fi_id]=$fi_docID.$ext;
			}
			else
			{
				if ( ((isset($imageArray['data'])) && (strlen($imageArray['data'])==0)) || (!isset($imageArray['data'])) )
					$ret.="Filenet HR export export rejected: fi_id:$fi_id  fi_docID:$fi_docID due to 0 byte file\n";
				else if ($last_fi_docID == $fi_docID)
					$ret.="Filenet HR export export skipped: fi_id:$fi_id  fi_docID:$fi_docID - file already included in this export\n";
				else
					$ret.="Filenet HR export export rejected: fi_id:$fi_id  fi_docID:$fi_docID\n";
			}
			$last_fi_docID=$fi_docID;
		}

		if (count($unlink_files)>0)
		{
			// establish eob file contents
			$eob="\\\\lrd1fil3\\MedicarePower\\test\\input\\updocs\\".$btdt." ".count($unlink_files)." ".count($unlink_files);

			$results='';
			$results.= "eobfile: ".$eobfn."\n";
			$results.= "eob file contents:\n".$eob."\n\n";
			$results.= "datefile: ".$datfn."\n";
			$results.= "dat file contents:\n".$dat."\n\n";

			$ok=true;
			// create temp eob and dat files
			if ($ok && !file_put_contents($mytemppath.$datfn,$dat))
				$ok=false;

			if (!$ok)
				$results.=date("Y-m-d H:i:s") . " write dat failed\n";
			else
				$results.=date("Y-m-d H:i:s") . " write dat success\n";

			if ($ok && !file_put_contents($mytemppath.$eobfn,$eob))
				$ok=false;

			if (!$ok)
				$results.=date("Y-m-d H:i:s") . " write eob failed\n";
			else
				$results.=date("Y-m-d H:i:s") . " write eob success\n";

			// ftp upload the eob and dat files
			$ftphost=$config['filenet_batch_host'];
			$ftpuser=$config['filenet_batch_user'];
			$ftppass=$config['filenet_batch_pass'];

			$conn=@ftp_connect($ftphost,21,90);
			if ($ok && $conn && !@$login=ftp_login($conn,$ftpuser,$ftppass))
				$ok=false;

			if (!$ok)
				$results.=date("Y-m-d H:i:s") . " FTP login to $ftphost failed\n";
			else
				$results.=date("Y-m-d H:i:s") . " FTP login to $ftphost success\n";

			// put dat file
			if ($ok)
				@ftp_mkdir($conn,"test/");
			if ($ok)
				@ftp_mkdir($conn,"test/input/");
			if ($ok)
				@ftp_mkdir($conn,"test/input/updocs/");
			if ($ok)
				@ftp_mkdir($conn,"test/input/updocs/".$btdt);
			if ($ok && !@ftp_chdir($conn,"test/input/updocs/".$btdt))
				$ok=false;

			if (!$ok)
				$results.=date("Y-m-d H:i:s") . " chdir failed\n";
			if ($ok && !@ftp_put($conn,$datfn,$mytemppath.$datfn,FTP_ASCII))
				$ok=false;
			if (!$ok)
				$ret.=date("Y-m-d H:i:s") . " put dat failed\n";
			else
				$results.=date("Y-m-d H:i:s") . " put dat success\n";

			// upload documents
			if ($ok)
			{
				foreach($unlink_files AS $key=>$file)
				{
					$ret.="Filenet HR export DAT file written - ".$file."\n";
					$results.="Filenet HR export DAT file written - ".$file."\n";
					@ftp_put($conn,$file,$mytemppath.$file,FTP_BINARY);
				}
			}

			// put eob file
			if ($ok && !@ftp_chdir($conn,"/test/input"))
				$ok=false;
			if ($ok && !@ftp_put($conn,$eobfn,$mytemppath.$eobfn,FTP_ASCII))
				$ok=false;
			if (!$ok) {
				$ret.="Filenet HR export EOB file failed - ".$eobfn."\n";
				$results.="Filenet HR export EOB file failed - ".$eobfn."\n";
			} else {
				$ret.="Filenet HR export EOB file written - ".$eobfn."\n";
				$results.="Filenet HR export EOB file failed - ".$eobfn."\n";
			}

			@ftp_close($conn);

			// delete all temporary local files that were created
			unlink ($mytemppath.$datfn);
			unlink ($mytemppath.$eobfn);
			foreach($unlink_files AS $key=>$file)
			{
				unlink ($mytemppath.$file);
			}

			// send email notification
			$ret.=page_sendnotification("pnshaver@pinnaclebsi.com",$results);
		}
	}
	return array(0,$ret);
}

/**
 * create date with accurate milliseconds ("u" option)
 *
 * This function mimics the date function, but allows for an accurate milliseconds on AIX
 *
 * @param array $format - date format
 * @param string $utimestamp
 * @return string - formatted date string
 */
function page_udate($format, $utimestamp = null) {
	if (is_null($utimestamp)) $utimestamp = microtime(true);

	$timestamp = floor($utimestamp);
	$digits=substr_count($format, 'u');
	$milliseconds = round(($utimestamp - $timestamp) * (10 ^ $digits));

	return date(preg_replace('`(?<!\\\\)u`', $milliseconds, $format), $timestamp);
}

/**
 * Send email notification
 * @param string $recipients
 * @param string $body
 */
function page_sendnotification($recipients,$body)
{
	global $config;
	// send email notification of new documents
	$recipients=preg_replace("/,/",";",$recipients);
	$recarr=preg_split('/;/',$recipients);

	$from=$config["contact_email"];
	$fromname=$config["contact_name"];
	$msg="POWER HR EVAL To Filenet Document Batch Export\n\n".$body;
	$subject="HR EVAL Document Export";

	require (dirname(__FILE__)."/../includes/phpmailer/class.phpmailer.php");
	$mail = new PHPMailer();
	$mail->IsSMTP();
	$mail->Host="mail.abcbs.net";
	$mail->From=$from;
	$mail->FromName=$fromname;
	for ($x=0;$x<count($recarr);$x++)
	{
		$mail->AddAddress($recarr[$x]);
	}
	$mail->WordWrap=50;
	$mail->Subject=$subject;
	$mail->Body=$msg;

	// If we are ANYWHERE other than PROD,  (POWERTEST),
	// make sure emails only go to internal POWER support employees
	
	$production=false;if($config["system_short_name"]=='POWERTEST') $production=true;
	if( !$production ){
		$mail->ClearAddresses();
		$mail->ClearBCCs();
		$mail->ClearCCs();
		$mail->AddAddress("powertest@pinnaclebsi.com");
		$mail->AddAddress("jsweiss@pinnaclebsi.com");
		$mail->AddAddress("pnshaver@pinnaclebsi.com");
		//$mail->AddAddress("jmmaxwell@pinnaclebsi.com");
		//$mail->AddAddress("mjquirijnen@pinnaclebsi.com");	
		$recipients = "The POWER team (3)";
	}
	
	if (!$mail->Send())
		return date("Y-m-d H:i:s") . "Email notification failed: " . $mail->ErrorInfo . "\n";
	else
		return date("Y-m-d H:i:s") . "Email notification sent to: $recipients\n";
}

/**
 * find and process HR acknowledgement pending tickets, match with the current response ticket
 *
 * @param array $post
 * @param string $user
 * @return array
 */
function match_HR_ack_responses($post=array(), $user='')
{
	// get RESPONSE ticket's co_ID
	if (isset($post['fco_ID']) && $post['fco_ID']>0){
		$co_ID = $post['fco_ID'];
	} else {
		// go ahead and return a 1 return code here, something has gone horribly wrong.
		return array(1,'No ticket found incoming record.');
	}

	// get RESPONSE control field values
	$QCSUBEMAIL=trim(get_field($co_ID, 'QCSUBEMAIL'));
	$ESUBJECT=trim(get_field($co_ID, 'ESUBJECT'));

	// get field IDs for PENDING ticket lookup
	$fi_HR_ACK_RECIPIENT=get_fid('HR_ACK_RECIPIENT');
	$fi_HR_ACK_SUBJECT_MATCH=get_fid('HR_ACK_SUBJECT_MATCH');
	$fi_QCSUBEMAIL=get_fid('QCSUBEMAIL');
	$fi_ESUBJECT=get_fid('ESUBJECT');

	// get queue IDs
	$PENDING_st_ID=get_st_ID('HR_ACK_PENDING');
	$PENDING_CL_st_ID=get_st_ID('HR_ACK_CL_RESPONSES');
	$RESPONSE_st_ID=get_st_ID('HR_ACK_EMAIL_RESPONSES');
	$RESPONSE_CL_st_ID=get_st_ID('HR_ACK_CL_MATCHED_EMAIL');
	$DUPLICATE_CL_st_ID=get_st_ID('HR_ACK_CL_DUPLICATE_RECEIVED');

	// attempt to find a matching PENDING ticket
	$sql = "SELECT co_ID FROM tbl_corr
					JOIN tbl_field_values AS HR_ACK_RECIPIENT
						ON HR_ACK_RECIPIENT.fv_co_ID=co_ID
						AND HR_ACK_RECIPIENT.fv_fi_ID=".$fi_HR_ACK_RECIPIENT."
						AND HR_ACK_RECIPIENT.fv_Value=".sql_escape_clean($QCSUBEMAIL)."
					JOIN tbl_field_values AS HR_ACK_SUBJECT
						ON HR_ACK_SUBJECT.fv_co_ID=co_ID
						AND HR_ACK_SUBJECT.fv_fi_ID=".$fi_HR_ACK_SUBJECT_MATCH."
						AND ".sql_escape_clean($ESUBJECT)." LIKE CONCAT('%',HR_ACK_SUBJECT.fv_Value,'%')
					WHERE co_queue=".$PENDING_st_ID;
	$rs=myloadslave($sql);

	$matches=0;
	$retmsg='';

	if (count($rs) > 0){
		// found matching records. maybe there's more than one, somehow, so move them all
		foreach ($rs as $r){
			// put the PENDING ticket number into the RESPONSE ticket's HR_ACK_RESP_TICKET control field
			if (!put_field($co_ID, 'HR_ACK_RESP_TICKET', $r['co_ID'], $user='POWER')) $retmsg.="Could not set PENDING fieldvalue for HR_ACK_RESP_TICKET.\n";

			// put the RESPONSE ticket number into the PENDING ticket's HR_ACK_RESP_TICKET control field
			if (!put_field($r['co_ID'], 'HR_ACK_RESP_TICKET', $co_ID, $user='POWER')) $retmsg.="Could not set RESPONSE fieldvalue for HR_ACK_RESP_TICKET.\n";

			// put today's date into the PENDING ticket's HR_ACK_RECEIVED field
			if (!put_field($r['co_ID'], 'HR_ACK_RECEIVED', date('Y-m-d'), $user='POWER')) $retmsg.="Could not set PENDING fieldvalue for HR_ACK_RECEIVED.\n";

			// move the PENDING ticket into its destination closed queue
			$comment='closing acknowledgement - response received';
			$upd=set_corr_status($r['co_ID'], $PENDING_CL_st_ID, $comment);
			if ($upd[0] == false){
				$retmsg.='Failed to forward PENDING ticket '.$r['co_ID'].' to closed queue: '.$upd[1].".\n";
			} else {
				$retmsg.='Forwarded PENDING ticket '.$r['co_ID'].' to closed queue'.".\n";
			}

			// move this RESPONSE ticket into its destination closed queue
			$comment='closing response email - pending acknowledgement found';
			$upd2=set_corr_status($co_ID, $RESPONSE_CL_st_ID, $comment);
			if ($upd2[0] == false){
				$retmsg.='Failed to forward PENDING ticket '.$r['co_ID'].' to closed queue: '.$upd2[1].".\n";
			} else {
				$retmsg.='Forwarded PENDING ticket '.$r['co_ID'].' to closed queue'.".\n";
			}

			$matches++;
		}
	}

	// see if we now have a closed and matched response for this sender+subject
	$sql = "SELECT co_ID FROM tbl_corr
					JOIN tbl_field_values AS QCSUBEMAIL
						ON QCSUBEMAIL.fv_co_ID=co_ID
						AND QCSUBEMAIL.fv_fi_ID=".$fi_QCSUBEMAIL."
						AND QCSUBEMAIL.fv_Value=".sql_escape_clean($QCSUBEMAIL)."
					JOIN tbl_field_values AS ESUBJECT
						ON ESUBJECT.fv_co_ID=co_ID
						AND ESUBJECT.fv_fi_ID=".$fi_ESUBJECT."
						AND ESUBJECT.fv_Value=".sql_escape_clean($ESUBJECT)."
					WHERE co_queue=".$RESPONSE_CL_st_ID;
	$duprs=myloadslave($sql);
	if (count($duprs) > 0){
		// there is definitely a closed response for this sender+subject.
		// check to see if we have duplicates of this receipt that can now be moved into the closed dupe queue
		$sql = "SELECT co_ID FROM tbl_corr
						JOIN tbl_field_values AS QCSUBEMAIL
							ON QCSUBEMAIL.fv_co_ID=co_ID
							AND QCSUBEMAIL.fv_fi_ID=".$fi_QCSUBEMAIL."
							AND QCSUBEMAIL.fv_Value=".sql_escape_clean($QCSUBEMAIL)."
						JOIN tbl_field_values AS ESUBJECT
							ON ESUBJECT.fv_co_ID=co_ID
							AND ESUBJECT.fv_fi_ID=".$fi_ESUBJECT."
							AND ESUBJECT.fv_Value=".sql_escape_clean($ESUBJECT)."
						WHERE co_queue=".$RESPONSE_st_ID;
		$duprs2=myloadslave($sql);
		//echo 'here:<pre>'.print_r ($duprs2, true).'</pre>';
		if (count($duprs2) > 0){
			// we have other responses just like this one, there is no point in leaving them in this response queue
			// since we have already identified their matching request. let's move them to the dupe queue.
			foreach ($duprs2 as $dup){
				// move the duplicate response ticket into the dupe queue
				$comment='closing duplicate response email - pending acknowledgement found';
				$upd3=set_corr_status($dup['co_ID'], $DUPLICATE_CL_st_ID, $comment);
				if ($upd3[0] == false){
					$retmsg.='Failed to forward duplicate response ticket '.$dup['co_ID'].' to closed queue: '.$upd3[1].".\n";
				} else {
					$retmsg.='Forwarded duplicate response ticket '.$dup['co_ID'].' to closed queue'.".\n";
				}
			}
		}
	}

	// always return a 0 return code. we don't want to prevent incoming emails from getting into a queue.
	return array( 0, $retmsg.$matches.' matching pending records processed');
}

/**
 * Update Employee review data in Ultipro
 *
 * @param array $post
 * @param string $user
 * @return array containing success or failure
 */
function update_employee_review($post=array(), $user='')
{

	include_once(dirname(__FILE__).'/../includes/soap/soapservice.inc');

	if (!isset($post['fco_ID']) || (!is_numeric($post['fco_ID'])))
 		return array(1,'No record # passed to set_field.');

	if ( (!isset($post['fst_id'])) || (!isset($post['fco_ID'])) )
		return array(1,'Status or record # missing' );

	if (($user == '') && (isset($_SESSION['username'])) && ($_SESSION['username'] != ''))
		$user = $_SESSION['username'];
	else if ($user == '')
		$user = 'POWER';

	// get the supervisor, this is put into ultipro as the reviewer
	$hr_super_last = trim(get_field($post['fco_ID'], 'HR_SUPER_LAST'));
  $hr_super_first = trim(get_field($post['fco_ID'], 'HR_SUPER_FIRST'));

	// get the comployee company. this is needed for the ultipro lookup
  $hr_eeccoid = get_field($post['fco_ID'], 'HR_EECCOID');

	// get the date for the next review, add one year to it, and put that into ultipro as the "next review date"
	$hr_nextperf_dt_ms = date("mdY", strtotime("+1 year", strtotime(get_field($post['fco_ID'], 'HR_NEXTSAL_DT'))));

	// get the date of employee signoff, this gets put into ultipro as the "last review date"
	$hr_this_review_dt_ms = date("mdY", strtotime(get_field($post['fco_ID'], 'HR_EMP_DATE')));

	// get the employee number, this is needed for the ultipro lookup
  $hr_eecempno = trim(get_field($post['fco_ID'], 'HR_EECEMPNO'));

	// get the rating
	$hr_score_1_tot = get_field($post['fco_ID'], 'HR_SCORE_1_TOT');

	// get the template, this is used to format the rating to ultipro's requirements
  $form_template = get_field($post['fco_ID'], 'FORM_TEMPLATE');

	// put an E in front of exempt employee ratings, N in front on non-exempt
	if ($form_template=='Exempt' || $form_template=='PBSI_Exempt'){
		$rating='E';
	} else {
		$rating='N';
	}
	$rating=$rating.intval($hr_score_1_tot*10);

	$sql="SELECT r_ReviewPerfRating FROM tbl_review_rating WHERE r_ReviewPerfRating=".sql_escape_clean($rating);
  $rs = myloadslave($sql);
  if (count($rs) > 0) {
		$reviewPerfRating_ultipro = $rs[0][0];
	} else {
		return array(1,'Could not retrieve UltiPro Review Rating Code '.$rating);
	}

  $empreviewupdatexml = '<employee empno="'.$hr_eecempno.'" coid="'.$hr_eeccoid.'">' .
		                    '<DateofLastPerfReview>'.$hr_this_review_dt_ms.'</DateofLastPerfReview>' .
		                    '<ReviewPerfRating>'.$reviewPerfRating_ultipro.'</ReviewPerfRating>' .
		                    '<ReviewTypePerf>PERF</ReviewTypePerf>' .
		                    '<ReviewerPerf>'.$hr_super_last.', '.$hr_super_first.'</ReviewerPerf>' .
		                    '<DateofNextPerfReview>'.$hr_nextperf_dt_ms.'</DateofNextPerfReview>' .
		                    '</employee>';

	// for debugging uncomment the next line
	// echo '<pre>'.$empreviewupdatexml.'</pre>';
	$soapresp=page_update_ultipro_employee_review($empreviewupdatexml);
	put_field($post['fco_ID'], 'HR_ULTIPRO_UPDATE_RE', 'request: '.$empreviewupdatexml.' response: '.$soapresp[1], $user='POWER');
	if (isset($soapresp[2])) put_field($post['fco_ID'], 'HR_ULTIPRO_RC', $soapresp[2], $user='POWER');
	return $soapresp;
}

/**
 * get_corr_value returns  a value from the corr table
 *
 * @param string $co_ID
 * @param string $field_name
 * @return string
 */
function get_corr_value( $co_ID='', $field_name='' ) {

  if( ($co_ID == '') || ($field_name=='') )
      return( '' );

  $sql = "SELECT $field_name FROM tbl_corr WHERE co_ID = $co_ID LIMIT 1";
  $res = myload($sql);

  if (count($res)<1)
        return( '' );

  return( $res[0]["$field_name"] );
}


/*
 * As a precode to the Presort queue, there will be a program that assigns a PCN number to each record.
 *    NOTE !   This ticket was put on ice for the near future.
 *    When this comes back up, questions remain about the sequencing numbers, Jeff    June,2011
 */
function pwk_pcn($post=array(),$part = "B")
{
/*
 * POWER #1629931
 * June 22 2011
 * Jeff Weiss
 * 
 * As a precode to the Presort queue, there will be a program that assigns a PCN number to each record.
 * PCN format:  PYJJJBBBSS
 *    P- State (6 = LA Part B; 7 = AR Part B;  8 = AR Part A;  9 = LA/MS Part A )
 *    Y-Last Digit of the Year
 *    JJJ-Julian Date
 *    BBB-Batch Number (001)
 *    SS-Sequence Number (00 is first document, 01 is second document, 02 is third document, etc.)
 *
 *
 *    So, perhaps updates can parse PYJJJBBBSS, convert BBB and SS to integers, then add +1, convert to string, re-write - Jeff
 *
 *    NOTE !   This ticket was put on ice for the near future.
 *    When this comes back up, questions remain about the sequencing numbers, Jeff    June,2011
 */

	global $config;

	
	if (!isset($post['fco_ID']) || (!is_numeric($post['fco_ID'])))
		return array(1,'No record # passed to pwk_pcn.');
  
	if ( (!isset($post['fst_id'])) )
		return array(1,'Status ID missing' );

	$co_ID = $post['fco_ID'];		// ok
	$st_ID = $post['fst_id'];		// ok

	//var_export ($post); exit();
	

	
	//die ("region = " . $region);
	//$region =
	
	/*
	 *  Contractors will need to develop a process to receive and
	 * control attachments using a standard format for the
	 * Paperwork Control Number (PCN). The format for the PCN is
	 * PYJJJBBBSS where P = paperwork number 6-9, Y = last digit of
	 * the year, JJJ = Julian date, BBB = batch number and SS =
	 * sequence.
	*/

	$state = get_corr_value($co_ID, "co_region");			// This will probably be a new control field by Joey, Jeff
	$state = substr($state,0,2);

	$part = "B";
	
	if ($part == "A") // from function parameter
	{
		$P = "_";
		// do nothing
	}
	elseif ($part == "B")
	{
		// do something with state code
		if ($state == "53")		// LA
			$P = "6";
		elseif ($state == "52")		// AR
			$P = "7";
		else	$P = "-";
	}
	else $P = "7";
	
	
	$JJJ = date ("z") + 1;
	$Y = substr(date("Y"),3,1);
	$BBB = "001";
	$SS = "00";
		

	$PCN = $P . $Y . $JJJ . $BBB . $SS;
	//die ("P = $P,STATE=$state,PCN = $PCN");
	set_control_field ($co_ID,"PCN","$PCN","POWER");
	
	return array(0,"PCN Assignment Complete");
	//return array(0,"collections935_email: 935 email not sent, destination queue (".$queue.") not recognized." );
}

/**
 * Update license information in the master ticket for EMM workflow
 *
 * @param array $post
 * @param string $user
 * @return array with success or failure info
 */
function update_review_master($post=array(), $user='')
{
	  if (!isset($post['fco_ID']) || !is_numeric($post['fco_ID']))
			return array(1,'No record # passed to set_field.');

		if ( !isset($post['fst_id']) || !isset($post['fco_ID']))
			return array(1,'Status or record # missing' );

	  if ($user == '' && isset($_SESSION['username']) && $_SESSION['username'] != '')
	    $user = $_SESSION['username'];
	  else if ($user == '')
	    $user = 'POWER';

		// has already been incremented for next year
		$master_rec_revdt = trim(get_field($post['fco_ID'], 'REVDT'));
		//subtract 1 from revdt year, as its already set for next year
    $this_yr_eval = date("Y-m-d", strtotime("-1 year", strtotime($master_rec_revdt)));
		// store attached review in this years folder
	  $rc = append_filerefs_master($post, $user, $this_yr_eval);

		if ($rc[0]>0 && isset($rc[1])) {
			$errors=$rc[1];
		}

		if ($errors==''){
			return array(0, 'Updated master record successfully with annual review renewal information.');
		} else {
			return array(1, 'Error updating master record with annual review renewal information.');
		}
}


/**
 * Update license information in the master ticket for EMM workflow
 *
 * @param array $post
 * @param string $user
 * @return array with success or failure info
 */
function update_license_master($post=array(), $user='')
{
	if (!isset($post['fco_ID']) || !is_numeric($post['fco_ID']))
		return array(1,'No record # passed to set_field.');

	if ( !isset($post['fst_id']) || !isset($post['fco_ID']))
		return array(1,'Status or record # missing' );

	if ($user == '' && isset($_SESSION['username']) && $_SESSION['username'] != '')
		$user = $_SESSION['username'];
	else if ($user == '')
		$user = 'POWER';

	// get the master ticket number
	$master_rec = trim(get_field($post['fco_ID'], 'LICMASTERCOID'));

	// get license renew info
	$lic_renew_sql = sprintf("SELECT a.fv_Value AS lic_json 
			                      FROM tbl_field_values a, tbl_fields b 
														WHERE b.fi_ID = a.fv_fi_ID AND 
														      b.fi_Name = 'LICENSERENEW' AND 
																	a.fv_co_ID = %d",
			                     (int)$post['fco_ID']);

	$lic_renewal = myload($lic_renew_sql);

	$licarr = get_multi_field($master_rec, 'LICENSERENEW');

	$errors='';

	if (count($lic_renewal)) {
		foreach ($lic_renewal as $r) {
	  	$jsobj = json_decode($r['lic_json']);
			if ($jsobj && isset($jsobj->values) && isset($jsobj->records) && is_numeric($jsobj->records)) {
				//echo '<pre>'.print_r($jsobj, true).'</pre>';
				$jsrecs = $jsobj->records;
				for ($j=0; $j<$jsrecs; $j++) {
			  	$LICSTATE     = (isset($jsobj->values[$j]->LICSTATE))     ? $jsobj->values[$j]->LICSTATE     : '';
					$LICEXP       = (isset($jsobj->values[$j]->LICEXP))       ? $jsobj->values[$j]->LICEXP       : '';
					$LICPSV       = (isset($jsobj->values[$j]->LICPSV))       ? $jsobj->values[$j]->LICPSV       : '';
					$LICRECDT     = (isset($jsobj->values[$j]->LICRECDT))     ? $jsobj->values[$j]->LICRECDT     : '';
					$NEWLICRENEW  = (isset($jsobj->values[$j]->NEWLICRENEW))  ? $jsobj->values[$j]->NEWLICRENEW  : '';
					$LICRENEWCOID = (isset($jsobj->values[$j]->LICRENEWCOID)) ? $jsobj->values[$j]->LICRENEWCOID : '';

					// update existing record
					if (count($licarr)) {
						for ($i=0; $i<count($licarr); $i++) {
							if ($licarr[$i]['LICSTATE'] == $LICSTATE && $licarr[$i]['LICEXP'] == $LICEXP) {
								$licarr[$i]['NEWLICRENEW'] = date("Y-m-d", strtotime("now"));
							}
						}
					}

					if ($LICSTATE=='ANNUAL ATTESTATION'){
						// annual attestations should all be set to expire on 1/30 next year.
						$NEWLICRENEW=date("Y-01-30", strtotime("+1 year", strtotime("now")));
					}

					// add new record
					$licarr[] = array('LICSTATE'     => $LICSTATE, 
									        	'LICEXP'       => $NEWLICRENEW, 
														'LICPSV'			 =>	$LICPSV,
														'LICRECDT'     => $LICRECDT,
									        	'NEWLICRENEW'  => '', 
														'LICRENEWCOID' => $LICRENEWCOID);
               
					put_group_field($master_rec, 'LICENSERENEW', $licarr, $user);

	  			$rc = append_filerefs_master($post, $user, $LICEXP, $LICSTATE);

					if ($rc[0]>0 && isset($rc[1])) {
						$errors=$rc[1];
					}
				}
			}
		}
	} else {
		return array(0, 'Master record not updated, no license renewal information found.');
	}

	if ($errors==''){
		return array(0, 'Updated master record successfully with license renewal information.');
	} else {
		return array(1, 'Error updating master record with license renewal information.');
	}
}

/**
 * Update license information in the master ticket for MARS workflow
 *
 * @param array $post
 * @param string $user
 * @return array with success or failure info
 */
function update_mars_license_master($post=array(), $user='')
{
	if (!isset($post['fco_ID']) || !is_numeric($post['fco_ID']))
		return array(1,'No record # passed to set_field.');

	if ( !isset($post['fst_id']) || !isset($post['fco_ID']))
		return array(1,'Status or record # missing' );

	if ($user == '' && isset($_SESSION['username']) && $_SESSION['username'] != '')
		$user = $_SESSION['username'];
	else if ($user == '')
		$user = 'POWER';

	// get the master ticket number
	$master_rec = trim(get_field($post['fco_ID'], 'LICMASTERCOID'));

	// get license renew info
	$lic_renew_sql = sprintf("SELECT a.fv_Value AS lic_json 
			                      FROM tbl_field_values a, tbl_fields b 
														WHERE b.fi_ID = a.fv_fi_ID AND 
														      b.fi_Name = 'RENEWDATA' AND 
																	a.fv_co_ID = %d",
			                     (int)$post['fco_ID']);

	$lic_renewal = myload($lic_renew_sql);

	$licarr = get_multi_field($master_rec, 'RENEWDATA');

	$errors='';

	if (count($lic_renewal)) {
		foreach ($lic_renewal as $r) {
	  	$jsobj = json_decode($r['lic_json']);
			if ($jsobj && isset($jsobj->values) && isset($jsobj->records) && is_numeric($jsobj->records)) {
				//echo '<pre>'.print_r($jsobj, true).'</pre>';
				$jsrecs = $jsobj->records;
				for ($j=0; $j<$jsrecs; $j++) {
			  	$RENEWDETAIL     = (isset($jsobj->values[$j]->RENEWDETAIL))     ? $jsobj->values[$j]->RENEWDETAIL     : '';
					$LICNUMB			= (isset($jsobj->values[$j]->LICNUMB))      ? $jsobj->values[$j]->LICNUMB      : '';
					$LICEXP       = (isset($jsobj->values[$j]->LICEXP))       ? $jsobj->values[$j]->LICEXP       : '';
					$LICPSV       = (isset($jsobj->values[$j]->LICPSV))       ? $jsobj->values[$j]->LICPSV       : '';
					$LICRECDT     = (isset($jsobj->values[$j]->LICRECDT))     ? $jsobj->values[$j]->LICRECDT     : '';
					$NEWLICRENEW  = (isset($jsobj->values[$j]->NEWLICRENEW))  ? $jsobj->values[$j]->NEWLICRENEW  : '';
					$LICRENEWCOID = (isset($jsobj->values[$j]->LICRENEWCOID)) ? $jsobj->values[$j]->LICRENEWCOID : '';

					// update existing record
					if (count($licarr)) {
						for ($i=0; $i<count($licarr); $i++) {
							if ($licarr[$i]['RENEWDETAIL'] == $RENEWDETAIL && $licarr[$i]['LICEXP'] == $LICEXP) {
								$licarr[$i]['NEWLICRENEW'] = date("Y-m-d", strtotime("now"));
							}
						}
					}

					// add new record
					$licarr[] = array('RENEWDETAIL'     => $RENEWDETAIL, 
									        	'LICEXP'       => $NEWLICRENEW, 
														'LICPSV'			 =>	$LICPSV,
														'LICNUMB'			 =>	$LICNUMB,
														'LICRECDT'     => $LICRECDT,
									        	'NEWLICRENEW'  => '', 
														'LICRENEWCOID' => $LICRENEWCOID);
               
					put_group_field($master_rec, 'RENEWDATA', $licarr, $user);

	  			$rc = append_filerefs_master($post, $user, $LICEXP, $RENEWDETAIL);

					if ($rc[0]>0 && isset($rc[1])) {
						$errors=$rc[1];
					}
				}
			}
		}
	} else {
		return array(0, 'Master record not updated, no license renewal information found.');
	}

	if ($errors==''){
		return array(0, 'Updated master record successfully with license renewal information.');
	} else {
		return array(1, 'Error updating master record with license renewal information.');
	}
}


/**
 * Update license information in the master ticket for EMM workflow
 * Update with uploaded file data
 *
 * @param array $post
 * @param string $user
 * @param string $licexp : license expiration data
 * @param string $licstate : state in which license is valid
 * @return array with success or failure info
 */
function append_filerefs_master($post=array(), $user='', $licexp='', $licstate='')
{
	if (!isset($post['fco_ID']) || !is_numeric($post['fco_ID']))
		return array(1,'No record # passed to set_field.');

	if (!isset($post['fst_id']) || !isset($post['fco_ID']))
		return array(1,'Status or record # missing' );

	if ($user == '' && isset($_SESSION['username']) && $_SESSION['username'] != '')
	  $user = $_SESSION['username'];
	else if ($user == '')
		$user = 'POWER';

	// get the master ticket number
	$master_rec = trim(get_field($post['fco_ID'], 'LICMASTERCOID'));

	$licexp_arr = preg_split('/-/', $licexp, -1, PREG_SPLIT_NO_EMPTY);
  //$new_folder = $licstate . '_' . $licexp_arr[0] . '|';    // create folder ST_YYYY in master record
  $new_folder = $licexp_arr[0] . '|';

	if (! doc_repo_folder_exists($master_rec, $new_folder)) {
		if (! doc_repo_create_folder($master_rec, $new_folder, '', 'Document Repository', $user, '')) {
			return array(1, 'Error creating folder ' . htmlspecialchars($new_folder));
		}
    //echo 'new doc repo folder : ' . $new_folder . '<br>';
	}

	// get all doc refs that need to be transfered to the master record
  $sql = sprintf("select * FROM tbl_filerefs WHERE fi_co_ID=%d AND (fi_mimetype <> 'folder' OR fi_mimetype IS NULL)", $post['fco_ID']);
	$file_refs = myload($sql);

	if (count($file_refs) > 0) {
		foreach ($file_refs as $fref) {

			// make sure the we only make 1 reference to a document
    	$sql = sprintf("select fi_docID FROM tbl_filerefs WHERE fi_co_ID=%d AND fi_docID='%s' AND (fi_mimetype <> 'folder' OR fi_mimetype IS NULL)", $master_rec, $fref['fi_docID']);
			$docid_exists = myload($sql);

    	if (!count($docid_exists)) {
      	$sql = "INSERT INTO tbl_filerefs (
									fi_ID,
									fi_co_ID,
									fi_docID,
									fi_docID_original,
									fi_encoder,
									fi_source,
									fi_active,
									fi_desc,
									fi_datetimestamp,
									fi_userID,
									fi_bt_id,
									fi_bookmarks,
									fi_filename,
									fi_mimetype,
									fi_version,
									fi_version_top,
									fi_filesize,
									fi_category
								) VALUES (
									NULL,
									".sql_escape_clean($master_rec).", 
									".sql_escape_clean($fref['fi_docID']).", 
									".sql_escape_clean($fref['fi_docID_original']).", 
									".sql_escape_clean($fref['fi_encoder']).", 
									".sql_escape_clean($fref['fi_source']).", 
									".sql_escape_clean($fref['fi_active']).", 
									".sql_escape_clean($fref['fi_desc']).", 
									".sql_escape_clean($fref['fi_datetimestamp']).", 
									".sql_escape_clean($fref['fi_userID']).", 
									".sql_escape_clean($fref['fi_bt_id']).", 
									".sql_escape_clean($fref['fi_bookmarks']).", 
									".sql_escape_clean($new_folder.$fref['fi_filename']).", 
									".sql_escape_clean($fref['fi_mimetype']).", 
									".sql_escape_clean($fref['fi_version']).", 
									".sql_escape_clean($fref['fi_version_top']).", 
									".sql_escape_clean($fref['fi_filesize']).",
									".sql_escape_clean($fref['fi_category'])."
								)";
				/*
      	$sql = sprintf("INSERT INTO tbl_filerefs(fi_ID,fi_co_ID,fi_docID,fi_docID_original,fi_encoder,fi_source,fi_active,fi_desc,
		                                           	fi_datetimestamp,fi_userID,fi_bt_id,fi_bookmarks,fi_filename,fi_mimetype,fi_version,
																	             	fi_version_top,fi_filesize,fi_category)
				              	VALUES (null,%d,'%s','%s','%s','%s','%s','%s','%s','%s',%d,'%s','%s','%s',%d,%d,%d,'%s')",
				$master_rec, $fref['fi_docID'], $fref['fi_docID_original'], $fref['fi_encoder'], $fref['fi_source'], 
				$fref['fi_active'], $fref['fi_desc'], $fref['fi_datetimestamp'], $fref['fi_userID'], $fref['fi_bt_id'], 
				$fref['fi_bookmarks'], $new_folder.$fref['fi_filename'], $fref['fi_mimetype'], $fref['fi_version'], 
				$fref['fi_version_top'], $fref['fi_filesize'],$fref['fi_category']);
				*/

	    	//echo $sql . "<br>";
	    	$file_refs = myexecute($sql);
				if ($file_refs === NULL) {
	      	return array(1, 'Error updating master record ('.$master_rec.') with file references ('.$new_folder.$fref['fi_filename'].') of license renewals.');
				}
			}
		}
		return array(0, 'File references successfully updated in master record.');
	} else {
		return array(0, 'No file references found. No files copied to master record.');
	}
}

/**
 * automate EMM license renewal
 *
 * @param array $post
 * @param string $user
 * @return array with success or failure info
 */
function emm_update_annual_education($post=array(), $user='') {
	if (!isset($post['fco_ID']) || !is_numeric($post['fco_ID']))
		return array(1,'No record # passed to set_field.');

	if (!isset($post['fst_id']) || !isset($post['fco_ID']))
		return array(1,'Status or record # missing' );

	if ($user == '' && isset($_SESSION['username']) && $_SESSION['username'] != '')
	  $user = $_SESSION['username'];
	else if ($user == '')
		$user = 'POWER';

	// identify the ABCBS_EMM_LIC queue that correlates with this record
	$CEMAIL=trim(get_field($post['fco_ID'], 'CEMAIL'));
	$LICSTATE=trim(get_field($post['fco_ID'], 'LICSTATE'));
 	$co_queue=get_st_ID('ABCBS_EMM_LIC');
	$sql = "SELECT 
						co_ID,
						CEMAIL.fv_Value AS CEMAIL,
						LICSTATE.fv_Value as LICSTATE
					FROM tbl_corr
					".build_join('CEMAIL')."
					".build_join('LICSTATE')."
					WHERE CEMAIL.fv_Value=".sql_escape_clean($CEMAIL)."
						AND LICSTATE.fv_Value=".sql_escape_clean($LICSTATE)."
						AND co_queue=".$co_queue;
	$licrs=myload($sql);
	if (count($licrs)>0){
		// we have a matching record(s), based upon email and state/license type
		$lic_co_ID=$licrs[0]['co_ID'];

		// get license renew info
		$lic_renew_sql = "SELECT 
												a.fv_Value AS lic_json 
											FROM tbl_field_values a, tbl_fields b 
											WHERE b.fi_ID = a.fv_fi_ID 
												AND b.fi_Name = 'LICENSERENEW' 
												AND a.fv_co_ID=".$lic_co_ID;
		$lic_renewal = myload($lic_renew_sql);

		// see if a LICENSERENEW group field is found for our license ticket
		if (count($lic_renewal)) {
			// get the ABCBS_EMM_LIC ticket's LICENSERENEW group field's values
			$licarr = get_multi_field($lic_co_ID, 'LICENSERENEW');

			$qmst="";
			foreach ($lic_renewal as $r) {
				$jsobj = json_decode($r['lic_json']);
				if ($jsobj && isset($jsobj->values) && isset($jsobj->records) && is_numeric($jsobj->records)) {
					$jsrecs = $jsobj->records;
					for ($j=0; $j<$jsrecs; $j++) {
						$LICSTATE     = (isset($jsobj->values[$j]->LICSTATE))     ? $jsobj->values[$j]->LICSTATE     : '';
						$LICEXP       = (isset($jsobj->values[$j]->LICEXP))       ? $jsobj->values[$j]->LICEXP       : '';
						$LICPSV       = (isset($jsobj->values[$j]->LICPSV))       ? $jsobj->values[$j]->LICPSV       : '';
						$LICRECDT     = (isset($jsobj->values[$j]->LICRECDT))     ? $jsobj->values[$j]->LICRECDT     : '';
						$NEWLICRENEW  = (isset($jsobj->values[$j]->NEWLICRENEW))  ? $jsobj->values[$j]->NEWLICRENEW  : '';
						$LICRENEWCOID = (isset($jsobj->values[$j]->LICRENEWCOID)) ? $jsobj->values[$j]->LICRENEWCOID : '';

						// update existing record's data
						if (count($licarr)) {
							for ($i=0; $i<count($licarr); $i++) {
								if ($licarr[$i]['LICSTATE'] == $LICSTATE && $licarr[$i]['LICEXP'] == $LICEXP) {
									$licarr[$i]['LICRECDT'] = date("Y-m-d", strtotime("now"));
									if ($LICSTATE=='ANNUAL ATTESTATION'){
										// annual attestations should all be set to expire on 1/30 next year.
										$licarr[$i]['NEWLICRENEW'] = date("Y-01-30", strtotime("+1 year", strtotime("now")));
									} else {
										$licarr[$i]['NEWLICRENEW'] = date("Y-m-d", strtotime("+1 year", strtotime("now")));
									}
								}
							}
						}

						// write back the group field
						put_group_field($lic_co_ID, 'LICENSERENEW', $licarr, $user);

						// transfer license expiration record into its closed queue
						// get the queue that we want to transfer into
  					$cl_st_ID=get_st_ID('ABCBS_EMM_CL_LIC');

						if ($cl_st_ID>0){
							// we need to use the function in status.inc.php because we need all of its pre/post/init logic to execute.
							require_once (dirname(__FILE__)."/../includes/status.inc.php"); 

							// set the fields needed for set_corr_status
							$qcomment="Automated ticket closure";
							$qpost = array();
							$update_status = set_corr_status ($lic_co_ID, $cl_st_ID, $qcomment, $qpost, $user);
							if ($update_status[0] == false) {
								$qmsg="Failed to forward record $lic_co_ID to status queue ($st_ID): ".$update_status[1].".";
							} else {
								$qmsg="Record $lic_co_ID forwarded to next queue.";
							}
						} else {
							$qmsg="Could not identify license expiration closing queue, license expiration record not closed.";
						}
					}
				}
			}
			return array(0, 'License expiration record successfully updated ticket '.$lic_co_ID.' with renewal information. '.$qmsg);
		} else {
			return array(0, 'License expiration record not updated, no matching group information found. '.$qmsg);
		}
	}
	return array(0, 'License expiration record not updated, no matching records found. '.$qmsg);
}

/**
 * upload document into filenet HR repository
 *
 * @param array $post
 * @param string $user
 * @return array with success or failure info
 */
function filenet_upload_default_HR_appraisal($post=array(), $user='') {
	global $config;
	if (!isset($post['fco_ID']) || !is_numeric($post['fco_ID'])){
		return array(1,'No record # passed to set_field.');
	} else {
		$coID=$post['fco_ID'];
	}

	// does default document exist?
	$docrs=doc_repo_default_details($coID);
	if (isset($docrs['docID'])){
		// get default doc
		$image_array=get_filecache($docrs['docID'],false);

		if (isset($image_array['data'])){
			// get temp filename
			$tempName = tempnam($config['fctmppath'], "voteattach");

			// save document to temp file
			$tmpDocFile=file_put_contents($tempName, $image_array['data']);

			// if image_array[0] exists, make sure it doesn't have 'Document not found'. That's a sure sign that there's no document to attach.
			if ($tmpDocFile && (!isset($image_array[0]) || (isset($image_array[0]) && !stristr($image_array[0], 'Document not found')))){
				if (isset($image_array['Content-Disposition']) && preg_match('/filename="(.*)"/', $image_array['Content-Disposition'], $imatches)){
					$i_filename=$imatches[1];
				} else {
					$i_filename=$docrs['filename'];
				}

				// base64 document
				$doc=base64_encode($image_array['data']);

				// bring in webservice functions
				require_once(dirname(__FILE__).'/../includes/soap/soapservice.inc');

				// get property values
				$Date_Processed=date('Y-m-d');
				$Status_ID=trim(get_field($coID, 'HR_EECEMPLSTATUS'));
				$SSN=trim(get_field($coID, 'HR_EMP_SSN'));
				if ($SSN==''){
					return array(1, 'POWER Document number '.$docrs['docID'].' NOT uploaded into Filenet. SSN must be populated first.');
				}
				$Name=trim(get_field($coID, 'HR_EEPNAMELAST')).' '.trim(get_field($coID, 'HR_EEPNAMEFIRST'));
				$Number=trim(get_field($coID, 'HR_EECEMPNO'));

				$documentxml=htmlentities('<?xml version="1.0" encoding="UTF-8"?>
				<Document>
					<Library>'.$config['filenet_web_library'].'</Library>
					<UserName>'.$config['filenet_web_username'].'</UserName>
					<Password>'.$config['filenet_web_password'].'</Password>
					<DocID/>
					<DocClass>Human_Resources</DocClass>
					<Properties>
						<Property>
							<Name>Doc_Type</Name>
							<Value>EVAL</Value>
						</Property>
						<Property>
							<Name>Date_Processed</Name>
							<Value>'.$Date_Processed.'</Value>
						</Property>
						<Property>
							<Name>SSN</Name>
							<Value>'.$SSN.'</Value>
						</Property>
						<Property>
							<Name>Name</Name>
							<Value>'.$Name.'</Value>
						</Property>
						<Property>
							<Name>Number</Name>
							<Value>'.$Number.'</Value>
						</Property>
					</Properties>
					<Image>
						<Data>'.$doc.'</Data>
						<Type>pdf</Type>
					</Image>
				</Document>');

				$filenetAddImagexml = '<AddDocument xmlns="http://MRRWorkflow/AddImage"><DocumentInfo>'.$documentxml.'</DocumentInfo></AddDocument>';

				$service     = 'AddImage';
				$host        = $config['filenet_web_host'];
				$uri         = '/addimage/AddImage.asmx';
				$port        = $config['filenet_web_port'];
				$soapversion = '1.2';

				$header = '<?xml version="1.0" encoding="utf-8"?><soap12:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap12="http://www.w3.org/2003/05/soap-envelope"><soap12:Body>'.$filenetAddImagexml.'</soap12:Body></soap12:Envelope>';
				$soapresp=page_call_ultipro_service($service, $host, $port, $uri, $header, $soapversion);

				// break response into lines
				$sl=explode("\n", $soapresp);
				$sx='';
				if (count($sl)>0){
					// we have more than one line
					if (isset($sl[0]) && substr($sl[0], 0, 15)=="HTTP/1.1 200 OK"){
						// we have a good http response
						foreach ($sl as $sr){
							if (substr($sr, 0, 5)=='<?xml'){
								// we have an xml response
								$xml=simplexml_load_string($sr);
								$xml->registerXPathNamespace('fn', 'http://MRRWorkflow/AddImage');
								$x=$xml->xpath('//fn:AddDocumentResult');
								if (isset($x[0]->Error)){
									$errmsg='Error found: ';
									if (isset($x[0]->Error->Type)){
										$errmsg.='Type: '.$x[0]->Error->Type.'. ';
									}
									if (isset($x[0]->Error->Message)){
										$errmsg.='Message: '.$x[0]->Error->Message.'. ';
									}

									return array(1, 'POWER Document number '.$docrs['docID'].' NOT uploaded into Filenet<pre>'.$errmsg.'</pre>');
								} elseif (isset($x[0]->Document->DocID)){
									$logmsg='POWER Document number '.$docrs['docID'].' uploaded into Filenet as Filenet DocID '.$x[0]->Document->DocID;
									$noters=myload("CALL sp_add_activity_note (".$coID.",'NOTE',".sql_escape_clean($logmsg).",'POWER','".date("Y-m-d G:i:s")."')");
									return array(0, $logmsg);
								}
							} 
						} 
					} else {
						return array(1, 'POWER Document number '.$docrs['docID'].' NOT uploaded into Filenet<pre>'.$sl[0].'</pre>');
					}
				} 
				return array(1, 'POWER Document number '.$docrs['docID'].' NOT uploaded into Filenet<pre>'.$soapresp.'</pre>');

				/*
				*/
				//echo '<h1>Response:</h1><pre>'.str_replace('&lt;', "\n&lt;", htmlentities(print_r($soapresp, true))).'</pre>';
			} else {
				return array(1, 'POWER default document not uploaded to Filenet. No valid document found.');
			}
		} else {
			return array(1, 'POWER default document not uploaded to Filenet. No valid image found.');
		}
	} else {
		return array(1, 'POWER default document not uploaded to Filenet. No default document found.');
	}
}

/**
 * send ticket email from ESUBJECT, EBODY to CEMAIL
 *
 * @param array $post
 * @param string $user
 * @return array with success or failure info
 */
function send_ticket_email($post=array(), $user='') {
	global $config;
	if (!isset($post['fco_ID']) || !is_numeric($post['fco_ID'])){
		return array(1,'No record # passed to set_field.');
	} else {
		$coID=$post['fco_ID'];
	}

	$CEMAIL=get_field($coID, 'CEMAIL');
	$ESUBJECT=get_field($coID, 'ESUBJECT');
	$EBODY=get_field($coID, 'EBODY');

	$msg='';
	if ($CEMAIL=='')$msg.=', email address';
	if ($CEMAIL=='')$msg.=', subject';
	if ($CEMAIL=='')$msg.=', body';

	if ($msg<>''){
		return array(0, 'POWER send_ticket_email did not send any message. The following fields were not populated: '.substr($msg, 2));
	} else {
		$from=$config["contact_email"];
		$fromname=$config["contact_name"];
		require_once (dirname(__FILE__)."/../includes/phpmailer/class.phpmailer.php");
		$mail = new PHPMailer();
		$mail->IsSMTP();
		$mail->Host="mail.abcbs.net";

		//$mail->AddAddress($CEMAIL);
    // POWER #27640778, Jeff
    $names = preg_split("/[;,]+/",$CEMAIL);
    foreach ($names as $name)
    {   
    	$mail->AddAddress($name);
    }   

  
		$mail->From=$from;
		$mail->FromName=$fromname;
		$mail->Subject=$ESUBJECT;
		$mail->Body=$EBODY;
		$mail->isHTML(true);

		// If we are ANYWHERE other than PROD,  (POWERTEST),
		// make sure emails only go to internal POWER support employees
		
		$production=false;if($config["system_short_name"]=='POWERTEST') $production=true;
		if( !$production ){
			$mail->ClearAddresses();
			$mail->ClearBCCs();
			$mail->ClearCCs();
			$mail->AddAddress("powertest@pinnaclebsi.com");
			$mail->AddAddress("pnshaver@pinnaclebsi.com");
			$recipients = "The POWER team (3)";
		}
		
		if (!$mail->Send())
			return array(1, 'POWER send_ticket_email unsuccessful. To: '.$CEMAIL.' Subject: '.$ESUBJECT);
		else
			return array(0, 'POWER send_ticket_email successful. To: '.$CEMAIL.' Subject: '.$ESUBJECT);
	}
}

/**
 * send ticket email from ESUBJECT, EBODY to ERECIPIENTS
 *
 * @param array $post
 * @param string $user
 * @return array with success or failure info
 */
function send_ticket_email_with_attach($post=array(), $user='') {
	global $config;
	if (!isset($post['fco_ID']) || !is_numeric($post['fco_ID'])){
		return array(1,'No record # passed to set_field.');
	} else {
		$coID=$post['fco_ID'];
	}

	$ERECIPIENTS=get_field($coID, 'ERECIPIENTS');
	$ESUBJECT=get_field($coID, 'ESUBJECT');
	$EBODY=get_field($coID, 'EBODY');

	$msg='';
	if ($ERECIPIENTS=='')$msg.=', email address';
	if ($ERECIPIENTS=='')$msg.=', subject';
	if ($ERECIPIENTS=='')$msg.=', body';

	if ($msg<>''){
		return array(0, 'POWER send_ticket_email did not send any message. The following fields were not populated: '.substr($msg, 2));
	} else {
		$from=$config["contact_email"];
		$fromname=$config["contact_name"];
		require_once (dirname(__FILE__)."/../includes/phpmailer/class.phpmailer.php");
		$mail = new PHPMailer();
		$mail->IsSMTP();
		$mail->Host="mail.abcbs.net";
		$mail->AddAddress($ERECIPIENTS);
		$mail->From=$from;
		$mail->FromName=$fromname;
		$mail->Subject=$ESUBJECT;
		$mail->Body=$EBODY;
		$mail->isHTML(true);

		// If we are ANYWHERE other than PROD,  (POWERTEST),
		// make sure emails only go to internal POWER support employees
		
		$production=false;if($config["system_short_name"]=='POWERTEST') $production=true;
		if( !$production ){
			$mail->ClearAddresses();
			$mail->ClearBCCs();
			$mail->ClearCCs();
			//$mail->AddAddress("powertest@pinnaclebsi.com");
			$mail->AddAddress("pnshaver@pinnaclebsi.com");
			$mail->AddAddress("jmmaxwell@pinnaclebsi.com");
			$recipients = "The POWER team (3)";
		}
		
		// Attach default document to email if requested
		$AttDefaultDoc=true;
		if ($AttDefaultDoc){
			// does default document exist?
			$docrs=doc_repo_default_details($coID);
			if (isset($docrs['docID'])){
				// get default doc
				$image_array=get_filecache($docrs['docID'],false);

				if (isset($image_array['data'])){
					// get temp filename
					$tempName = tempnam($config['fctmppath'], "voteattach");

					// save document to temp file
					$tmpDocFile=file_put_contents($tempName, $image_array['data']);

					// if image_array[0] exists, make sure it doesn't have 'Document not found'. That's a sure sign that there's no document to attach.
					if ($tmpDocFile && (!isset($image_array[0]) || (isset($image_array[0]) && !stristr($image_array[0], 'Document not found')))){
						if (isset($image_array['Content-Disposition']) && preg_match('/filename="(.*)"/', $image_array['Content-Disposition'], $imatches)){
							$i_filename=$imatches[1];
						} else {
							$i_filename=$docrs['filename'];
						}
						// attach document
						$mail->AddAttachment(	$tempName,
																	$i_filename,
																	'base64',
																	$image_array['Content-Type']);
					}
				}
			}
		}

		if (!$mail->Send())
			return array(1, 'POWER send_ticket_email unsuccessful. To: '.$ERECIPIENTS.' Subject: '.$ESUBJECT);
		else
			return array(0, 'POWER send_ticket_email successful. To: '.$ERECIPIENTS.' Subject: '.$ESUBJECT);
	}
}

/**
 * Ticket 2853274 : create given folders upon creation of new ticket
 *
 * @param array $post
 * @param string $user
 * @return array with success or failure info
 */
function doc_repo($post=array(), $user='', $from='')
{
	global $config;

	if (!isset($post['codeparams']) || (!preg_match('/^(.*)$/',$post['codeparams'])) || ($post['codeparams']=='()'))
	{
		$recips = "No parameters sent to doc_repo function. Setup error.";
		$comment = 'Failed to update document repository: ' .$recips . ' (' . __LINE__ .')';
    $noters=myload("CALL sp_add_activity_note (".sql_escape_clean($co_ID).",'NOTE',".sql_escape_clean($comment).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");
		return array(1,$recips);
	}

	if (!isset($post['fco_ID']) || (!is_numeric($post['fco_ID'])))
	{
		$recips = 'No record ID passed to email_info.';
		$comment = 'Failed to update document repository: ' .$recips . ' (' . __LINE__ .')';
    $noters=myload("CALL sp_add_activity_note (".sql_escape_clean($co_ID).",'NOTE',".sql_escape_clean($comment).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");
		return array(1,$recips);
	}

	if ( (!isset($post['fst_id'])) || (!isset($post['fco_ID'])) || (!is_numeric($post['fst_id'])) || (!is_numeric($post['fco_ID'])) )
	{
		$recips = 'Status or record # missing';
		$comment = 'Failed to update document repository: ' .$recips . ' (' . __LINE__ .')';
    $noters=myload("CALL sp_add_activity_note (".sql_escape_clean($co_ID).",'NOTE',".sql_escape_clean($comment).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");
		return array(1,$recips);
	}

	if (($user == '') && (isset($_SESSION['username'])) && ($_SESSION['username'] != ''))
		$user = $_SESSION['username'];
	else if ($user == '')
		$user = 'POWER';

	$codeparams = $post['codeparams']; // this can be (do='createfolder',data='foldername1,foldername2')
	$co_ID = $post['fco_ID'];

	// to could contain multiple, and they could be email addresses embedded
	if (preg_match("/^\(do='(.*)',data='(.*)'\)$/", $codeparams, $m))
	{
		if (isset($m[1]))
			$do = $m[1];
		if (isset($m[2]))
			$data = $m[2];
	} else {
		//var_dump($m);
		$recips = "Invalid parameters ($codeparams) sent to doc_repo function. Setup error.";
		$comment = 'Failed to update document repository: ' .$recips . ' (' . __LINE__ .')';
    $noters=myload("CALL sp_add_activity_note (".sql_escape_clean($co_ID).",'NOTE',".sql_escape_clean($comment).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");
		return array(1,$recips);
	}

	$do = str_replace(' ','',$do);

	if ($do == '' || $data == '')
  {
	  $recips = "No information provided for doc_repo function. Setup error.";
		$comment = 'Failed to update document repository: ' .$recips . ' (' . __LINE__ .')';
    $noters=myload("CALL sp_add_activity_note (".sql_escape_clean($co_ID).",'NOTE',".sql_escape_clean($comment).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");
 		return array(1,$recips);
 	}

	switch ( strtoupper($do) ) {
		case 'CREATEFOLDER': // get list of folders to be created, foldernames separated by ,
						 $folderlist = explode(',', $data);
						 foreach ($folderlist as $folder) {
								//echo 'Creating folder '.$folder." <br>\n";
								$fi_filename = trim($folder) . '|';
								$vfnres = doc_repo_valid_folder($folder);
								if ($vfnres[0] == false)
								{
	  							$recips = 'Folder '.$folder.' not created. '.$vfnres[1];
									$comment = 'Failed to update document repository: ' .$recips . ' (' . __LINE__ .')';
    							$noters=myload("CALL sp_add_activity_note (".sql_escape_clean($co_ID).",'NOTE',".sql_escape_clean($comment).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");
 									return array(1,$recips);
								}
								if (! doc_repo_folder_exists($co_ID, $fi_filename))
								{
									if (! doc_repo_create_folder($co_ID, $fi_filename, '', 'Document Repository', $user, ''))
									{
	  								$recips = 'Error creating folder '.$fi_filename;
										$comment = 'Failed to update document repository: ' .$recips . ' (' . __LINE__ .')';
    								$noters=myload("CALL sp_add_activity_note (".sql_escape_clean($co_ID).",'NOTE',".sql_escape_clean($comment).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");
 										return array(1,$recips);
									} // folder created successful
								} // folder already exist .. I think that is OK
						 }
						 return array(0,'Folders created successfully.');

						 break;
		default: break;
	}
	return array(0, ''); // if we get here thru other than 'create directory', we do nothing, say nothing.
}

/**
 * process bulk ticket with zip file that contains multiple ansi records, each to get its own import ticket
 *
 * @param array $post
 * @param string $user
 * @return array with success or failure info
 */
function zip_to_ansi($post=array(), $user=''){
	global $config;
	$ret='';

	if (($user == '') && (isset($_SESSION['username'])) && ($_SESSION['username'] != ''))
		$user = $_SESSION['username'];
	else if ($user == '')
		$user = 'POWER';

	// verify input
	if (isset($post['fco_ID']) && $post['fco_ID']>0){
		$co_ID=$post['fco_ID'];
	} else {
 		return array(1,'No record # passed to function.');
	}

	// get documents from document repository
	$sql="SELECT fi_docID AS docID,
							 fi_filename,
							 fi_filesize,
							 fi_co_ID,
							 st_ct_ID
				FROM tbl_filerefs 
				INNER JOIN tbl_corr ON fi_co_ID=co_ID
				INNER JOIN tbl_statuses ON co_queue=st_ID
				WHERE fi_co_ID=".$co_ID." 
					AND fi_mimetype LIKE '%zip%'
					AND fi_active='y'";
	$rs=myload($sql);
	if (count($rs)==0){
		// no documents found in this ticket, close it to the skipped queue
		$st_Name='IMPORT_BULK_SKIPPED_CL';
		$st_ID=get_st_ID($st_Name);
		$fcomment='Skipping ansi import, no documents found.';
		$updatepost=array();
    $update_status = set_corr_status ($co_ID, $st_ID, $fcomment, $updatepost, $user, true);
		if ($update_status[0]==false){
      return array(1,'No documents found, routing to '.$st_Name.' ('.$st_ID.') failed: '.$update_status[1]);
		} else {
      return array(0,'No documents found, routing to '.$st_Name.' ('.$st_ID.') successful.');
    }
	}

	$zipret=array();

	$processed_file_count=0;
	// foreach document found
	foreach($rs as $r){
		$filearr=array();
		// get document contents, save to temp file, add location to array for processing
		$image_array=get_filecache($r['docID'],false);
		if (!isset($image_array['data'])){
			$ret.='No document data found for docID '.$r['docID'].', file skipped.'."\n";
			continue;
		}

		// make sure we don't process more than one file, skip everything after the first ANSI file found
		if ($processed_file_count>1){
			$ret.='Skipping zip file (docID='.$r['docID'].'), only one zip file per import record is allowed.'."\n";
			unlink ($zipfile);
			continue;
		}

		// get user email address, populate ERECIPIENTS so that the external process will know who to send an email to
		$sql="SELECT uEmail FROM tbl_users where uUsername=".sql_escape_clean($user)." AND uActive='y'";
		$urs=myload($sql);
		if (count($urs)>0){
			put_field($co_ID, 'ERECIPIENTS', $urs[0]['uEmail']);
			$ret.="\n".' - An email will be sent to '.$urs[0]['uEmail'].' upon completion of import.'."\n";
		}

		if (isset($config['phpcli'])){
			$phpcli=$config['phpcli'].' -f';
		} else {
			$phpcli='/usr/bin/php -f';
			//$phpcli='/opt/pbsi/bin/php -f';
		}

		if (isset($config['base_path'])){
			$url=' '.$config['base_path'].'/batch/zip_to_ansi_records.php';
		} else {
			$url=' /var/www/html'.$config['systemroot'].'/batch/zip_to_ansi_records.php';
		}

		$urlopts=' -- --zipdocID='.$r['docID'];
		$urlret=' >> /tmp/zip_to_ansi_import_results.txt &';
		exec ($phpcli.$url.$urlopts.$urlret);

		// if we got here, it means we found a zip file
		$processed_file_count++;
	}

	// forward this ticket to the successful closed
	$st_Name='IMPORT_BULK_CL';
	$st_ID=get_st_ID($st_Name);
	$fcomment='Zip import processing started, closing import ticket.';
	$updatepost=array();
	$update_status = set_corr_status ($co_ID, $st_ID, $fcomment, $updatepost, $user, true);
	if ($update_status[0]==false){
		return array(1,'Zip processing started, routing to '.$st_Name.' ('.$st_ID.') failed: '.$update_status[1]);
	} else {
		return array(0,'Zip processing started, routing to '.$st_Name.' ('.$st_ID.') successful: '.$ret);
	}
}

/**
 * process ansi file into xml and then into power tickets
 *
 * @param array $post
 * @param string $user
 * @return array with success or failure info
 */
function prepost_ansi_to_tickets($post=array(), $user='') {
	global $config;
	$ret='';

	if (($user == '') && (isset($_SESSION['username'])) && ($_SESSION['username'] != ''))
		$user = $_SESSION['username'];
	else if ($user == '')
		$user = 'POWER';

	// verify input
	if (isset($post['fco_ID']) && $post['fco_ID']>0){
		$co_ID=$post['fco_ID'];
	} else {
 		return array(1,'No record # passed to function.');
	}

	$use_era_edits=true;
	if (isset($post['codeparams']) && stristr($post['codeparams'], 'noedits')){
		$use_era_edits=false;
	}

	require_once (dirname(__FILE__)."/../includes/ansi.php");
	require_once (dirname(__FILE__).'/../includes/xml_functions.php');

	// get documents from document repository
	$sql="SELECT fi_docID AS docID,
							 fi_filename,
							 fi_filesize,
							 fi_co_ID,
							 st_ct_ID
				FROM tbl_filerefs 
				INNER JOIN tbl_corr ON fi_co_ID=co_ID
				INNER JOIN tbl_statuses ON co_queue=st_ID
				WHERE fi_co_ID=".$co_ID." 
					AND fi_active='y'";
	$rs=myload($sql);
	if (count($rs)==0){
		// no documents found in this ticket, close it to the skipped queue
		$st_Name='IMPORT_SKIPPED_CL';
		$st_ID=get_st_ID($st_Name);
		$fcomment='Skipping ansi import, no documents found.';
		$updatepost=array();
    $update_status = set_corr_status ($co_ID, $st_ID, $fcomment, $updatepost, $user, true);
		if ($update_status[0]==false){
      return array(1,'No documents found, routing to '.$st_Name.' ('.$st_ID.') failed: '.$update_status[1]);
		} else {
      return array(0,'No documents found, routing to '.$st_Name.' ('.$st_ID.') successful.');
    }
	}

	// identify destination queue for imported records
	if ($use_era_edits==false){
		$sql="SELECT st_ID, st_Name FROM tbl_statuses WHERE st_Category LIKE '%:SR_UNPROCESSED_REC:%' AND st_Active='y'";
		$qrs=myload($sql);
		if (count($qrs)>0){
			$import_st_Name=$qrs[0]['st_Name'];
		} else {
			return array(1,'Could not identify SR_UNPROCESSED_REC queue for imported records. Process failed.');
		}
	} else {
		$sql="SELECT st_ID, st_Name FROM tbl_statuses WHERE st_Category LIKE '%:SR_REC:%' AND st_Active='y'";
		$qrs=myload($sql);
		if (count($qrs)>0){
			$import_st_Name=$qrs[0]['st_Name'];
		} else {
			return array(1,'Could not identify SR_REC queue for imported records. Process failed.');
		}
	}

	$processed_file_count=0;
	// foreach document found
	foreach($rs as $r){
		$filearr=array();
		// get document contents, save to temp file, add location to array for processing
		$image_array=get_filecache($r['docID'],false);
		if (!isset($image_array['data'])){
			$ret.='No document data found for docID '.$r['docID'].', file skipped.'."\n";
			continue;
		}
		// get temp filename
		$ansifile=tempnam($config['fctmppath'], "ansiimport_");

		// save document to temp file
		file_put_contents($ansifile, $image_array['data']);
		$filearr[$ansifile]=array('tempfile'=>$ansifile);

		$xslfile='a835.xml';

		// convert from ansi to xml
		$ansiret=ansi_parse_to_xml($ansifile, $import_st_Name, $xslfile);

		if ($ansiret['success']=='n'){
			$ret.='Non-ansi file (docID='.$r['docID'].'), skipping processing for that file.'."\n";
			unlink ($ansifile);
			continue;
		}

		// if we got here, it means we found an ansi file

		// populate control fields from ansi file
		if($ax = new ansi2xml('ANSI835')){
			if ($ax->open($image_array['data'], 'string')===FALSE){
				// issues with ansi file
			} else {
				$ax->parse();

				// put into dom for xpath queries
				$dom=simplexml_load_string($ax->xml->outputMemory(TRUE));
				$PAYER=$dom->xpath('//L1000A/N1/N102');
				if (!empty($PAYER) && isset($PAYER[0][0])){
					put_field($co_ID, 'PAYER', $PAYER[0][0]);
				}
				$PAYER_NUMBER=$dom->xpath('//TRN/TRN03');
				if (!empty($PAYER_NUMBER) && isset($PAYER_NUMBER[0][0])){
					put_field($co_ID, 'PAYER_NUMBER', $PAYER_NUMBER[0][0]);
				}
				$PAYMENT_DATE=$dom->xpath('//BPR/BPR16');
				if (!empty($PAYMENT_DATE) && isset($PAYMENT_DATE[0][0])){
					put_field($co_ID, 'PAYMENT_DATE', $PAYMENT_DATE[0][0]);
				}
			}
		}

		// dupe check, make sure there isn't another active record with this same document filename
		$dupesql="SELECT fi_co_ID
							FROM tbl_filerefs
							INNER JOIN tbl_corr ON fi_co_ID=co_ID
							INNER JOIN tbl_statuses ON co_queue=st_ID
							WHERE fi_filename=".sql_escape_clean($r['fi_filename'])."
								AND fi_active='y'
								AND st_ct_ID=".$r['st_ct_ID']."
								AND fi_co_ID<>".$r['fi_co_ID'];
		$dupers=myload($dupesql);
		if (count($dupers)>0){
			$dupetickets='';
			foreach ($dupers as $dupe){
				$dupetickets.=', '.$dupe['fi_co_ID'];
			}
			$dupetickets=substr($dupetickets, 2);
			unlink ($ansifile);
      return array(1,'Duplicate ANSI file found in ticket(s): '.$dupetickets.'. You need to either reprocess those tickets, delete the ANSI file found in them, delete the ANSI file in this ticket and upload a unique ANSI file. Processing halted.');
		}

		$processed_file_count++;

		// make sure we don't process more than one file, skip everything after the first ANSI file found
		if ($processed_file_count>1){
			$ret.='Skipping ANSI file (docID='.$r['docID'].'), only one ANSI file per import record is allowed.'."\n";
			unlink ($ansifile);
			continue;
		}

		// put xml back into ticket
		$bytelen=strlen($ansiret['xml']);
		$fi_filename = 'xml_import_'.date('Ymd_His').'.xml';
		$fi_filename = doc_repo_new_filename($co_ID, $fi_filename);
		$fdesc = '';
		$mime='application/xml';
		$ftime = date ("Y-m-d G:i:s");
		$new_doc = @doc_repo_add_file($co_ID, 'ANSI Import', '', $fi_filename, $bytelen, $fdesc, $mime);
		if (!$new_doc['docID']) {
			$ret.='Adding ANSI file to document repository failed.'."\n";
			unlink ($ansifile);
			return array(1,'Documents found, errors encountered: '.$ret);
		}
		$xmldocid = $new_doc['docID'];
		$dirarr=make_filename($xmldocid);
		$imageArray=array();
		$imageArray["Content-Type"]=$mime;
		$imageArray["Content-Length"]=$bytelen;
		$imageArray["Content-Disposition"]="filename=\"".$fi_filename."\"";
		$imageArray["data"]=$ansiret['xml'];
		$ctser=serialize($imageArray);
		upload_filecache( $dirarr["dir"], $dirarr["filename"], $ctser, $xmldocid );

		// Add a note about this activity (include the filename and fi_ID here)
		$fComments = "Added xml doc ID ".$xmldocid." ".$fi_filename." for import job";
		$notesql="CALL sp_add_activity_note (".sql_escape_clean($co_ID).",'ATTACH',".sql_escape_clean($fComments).",'POWER','".date("Y-m-d G:i:s")."')";
		$noters=myload($notesql);				
		unset($imageArray);
		unset($ctser);

		// gather initial statistics for file
		$xml=simplexml_load_string($ansiret['xml']);
		//if ($xmlcnt=count($xml->xpath('//SVC[sum(SVC03)=0]')))
		if ($xmlcnt=count($xml->xpath('//Record'))){
			$ret.='DocID '.$r['docID'].' converted to XML, found '.$xmlcnt.' records.'."\n";

			// allow 5 seconds per ticket for this import
			$new_time_limit=intval(30+(5*$xmlcnt));
			$ret.="Allowing ".$new_time_limit." seconds for the import of ".$xmlcnt." records.\n";
			set_time_limit($new_time_limit);
		}

		// get user email address, populate ERECIPIENTS so that the external process will know who to send an email to
		$sql="SELECT uEmail FROM tbl_users where uUsername=".sql_escape_clean($user)." AND uActive='y'";
		$urs=myload($sql);
		if (count($urs)>0){
			put_field($co_ID, 'ERECIPIENTS', $urs[0]['uEmail']);
			$ret.="\n".' - An email will be sent to '.$urs[0]['uEmail'].' upon completion of import.'."\n";
		}

		$spawn_external=false;
		if ($spawn_external){
			// kick off external process to import from xml to power tickets, external process will unlink temp files
			$ret.="\n".'Spawning process to import records from ansi file '.$r['docID'].', xml file '.$new_doc['docID'].'.';

			if (isset($config['phpcli'])){
				$phpcli=$config['phpcli'].' -f';
			} else {
				$phpcli='/usr/bin/php -f';
				//$phpcli='/opt/pbsi/bin/php -f';
			}

			if (isset($config['base_path'])){
				$url=' '.$config['base_path'].'/batch/ansicreate.php';
			} else {
				$url=' /var/www/html'.$config['systemroot'].'/batch/ansicreate.php';
				//$url=' /pwrdev/power'.$config['systemroot'].'trunk/www/batch/ansicreate.php';
			}

			$urlopts=' -- --docID='.$new_doc['docID'].' --ansidocID='.$r['docID'];
			if ($use_era_edits==false){
				$urlopts.=' --use_era_routing_edits=n';
			}

			$urlret=' >> /tmp/ansi_import_results.txt &';
			exec ($phpcli.$url.$urlopts.$urlret);
			//$ret.='<br>'."exec ($phpcli.$url.$urlopts.$urlret);<br>";
		} else {
  		require_once (dirname(__FILE__)."/phpmailer/class.phpmailer.php");
			// process right now
			$import_results=page_log(ansi_import_from_xml($ansiret['xml'], $co_ID));

			// send results to ticket recipient
			$ERECIPIENTS=get_field($co_ID, 'ERECIPIENTS');
			if ($ERECIPIENTS<>''){
				// send notification email to user
				$mail = new PHPMailer();
				$mail->IsSMTP();
				$mail->Host="mail.abcbs.net";
				$mail->From=$config['contact_email'];
				$mail->FromName=$config['contact_name'];
				$mail->AddAddress($ERECIPIENTS);
				$mail->Subject="POWER IMPORT RESULTS";
				$mail->Body="The ANSI import for POWER ticket ".$co_ID.", doc ID ".$new_doc['docID']." is complete.<br><br>The import job results are as follows:<br><br>".$import_results;
				$mail->isHTML(true);
				$mail->Send();
			}

			// add text document with import results to ticket
			$bytelen=strlen($import_results);
			$fi_filename = 'results_xml_import_'.date('Ymd_His').'.txt';
			$fi_filename = doc_repo_new_filename($co_ID, $fi_filename);
			$fdesc = '';
			$mime='text/plain';
			$ftime = date ("Y-m-d G:i:s");
			$results_doc = @doc_repo_add_file($co_ID, 'ANSI Import Results', '', $fi_filename, $bytelen, $fdesc, $mime);
			$xmldocid = $results_doc['docID'];
			$dirarr=make_filename($xmldocid);
			$imageArray=array();
			$imageArray["Content-Type"]=$mime;
			$imageArray["Content-Length"]=$bytelen;
			$imageArray["Content-Disposition"]="filename=\"".$fi_filename."\"";
			$imageArray["data"]=$import_results;
			$ctser=serialize($imageArray);
			upload_filecache( $dirarr["dir"], $dirarr["filename"], $ctser, $xmldocid );

			$ret.=str_replace('<br>', '', $import_results);
		}
	}

	// stamp record as processed
	put_field($co_ID, 'PROCESS_DATE', date('Y-m-d H:i:s'));

	// forward this ticket to the successful closed
	if ($use_era_edits==false){
		$st_Name='IMPORT_HISTORY_CL';
	} else {
		$st_Name='IMPORT_CL';
	}
	$st_ID=get_st_ID($st_Name);
	$fcomment='Ansi import processing started, closing import ticket.';
	$updatepost=array();
	$update_status = set_corr_status ($co_ID, $st_ID, $fcomment, $updatepost, $user, true);
	if ($update_status[0]==false){
		return array(1,'Ansi processing started, routing to '.$st_Name.' ('.$st_ID.') failed: '.$update_status[1]);
	} else {
		return array(0,'Ansi processing started, routing to '.$st_Name.' ('.$st_ID.') successful: '.$ret);
	}
}

/**
 * reroutes ERA records based upon control field values
 *
 * @param array $post
 * @param string $user
 * @return array
 */
function ERA_reroute_edits($post=array(), $user=''){
  require_once (dirname(__FILE__).'/../includes/xml_functions.php');
	global $config; 

	if (($user == '') && (isset($_SESSION['username'])) && ($_SESSION['username'] != ''))
		$user = $_SESSION['username'];
	else if ($user == '')
		$user = 'POWER';

	// verify input
	if (isset($post['fco_ID']) && $post['fco_ID']>0){
		$co_ID=$post['fco_ID'];
	} else {
 		return array(1,'No record number found.');
	}

	// verify queue mappings
	$queue_array=array(
		'SR_CL_PD'=>'',
    'SR_CL_XX8'=>'',
    'SR_SUP'=>'',
		'SR_ELIG'=>'',
		'SR_NONCOD'=>'',
		'SR_COD'=>'',
    'SR_ADJ'=>'');

	$sql="SELECT st_ID, st_Name, st_Category
				FROM tbl_statuses
				WHERE st_Category like ':SR_%'
					AND st_Active='y'";
	$rs=myload($sql);
	if (count($rs)==0){
		return array(1,'SimpleRemit queue mappings not found. Function halted.');
	}
	$missing_queue_mappings='';
	foreach($queue_array as $qak=>$qav){
		foreach($rs as $r){
			if (stristr($r['st_Category'], $qak)){
				$queue_array[$qak]=$r['st_Name'];
				break;
			}
		}
		if ($queue_array[$qak]==''){
			// if we get here, we didn't find a queue mapping for this queue
			$missing_queue_mappings.=','.$qak;
		}
	}
	if ($missing_queue_mappings<>''){
		return array(1,'SimpleRemit queue mappings missing: '.substr($missing_queue_mappings, 1).'. Function halted.');
	}

	// get applicable control field values
	$CARC_fi_ID=get_fid('CARC');
	$LNDENY_fi_ID=get_fid('LNDENY');
	$PAYAMT_fi_ID=get_fid('PAYAMT');

	$sql="SELECT co_ID,
							LNDENY.fv_Value AS LNDENY,
							PAYAMT.fv_Value AS PAYAMT
				FROM tbl_corr
				LEFT OUTER JOIN tbl_field_values LNDENY ON LNDENY.fv_co_ID=".$co_ID." AND LNDENY.fv_fi_ID=".$LNDENY_fi_ID."
				LEFT OUTER JOIN tbl_field_values PAYAMT ON PAYAMT.fv_co_ID=".$co_ID." AND PAYAMT.fv_fi_ID=".$PAYAMT_fi_ID."
				WHERE co_ID=".$co_ID;
	$rs=myload($sql);
	
	if (count($rs)==0){
		return array(0,'No matches found for ERA routing edits.');
	}

	$LNDENY_arr=$CARC_arr=$GROUP_arr=$AMT_arr=array();
	$PAYAMT='';

	$TOB = get_field($co_ID, 'TOB');
	$TOBFREQ = get_field($co_ID, 'TOBFREQ');
  $STAT = get_field($co_ID, 'STAT');
  
  // get the RARCs from the record's XML
  $xml = get_field($co_ID, 'XML');
  $RARC_arr = array_flip(xpath_eval($xml, '//MIA/MIA05 | //MOA/MOA03 | //MOA/MOA04 | //MOA/MOA05 | //MOA/MOA06 | //MOA/MOA07 | //LQ/LQ02'));
      
	// get service line fields
	$SVC=get_field($co_ID, 'SVC');
	$INDCD=array();
	if ($SVC<>''){
		$jsobj = json_decode($SVC);
		if ($jsobj && isset($jsobj->values) && isset($jsobj->records) && is_numeric($jsobj->records)) {
			$jsrecs = $jsobj->records;
			for ($j=0; $j<$jsrecs; $j++) {
				$this_INDCD='';
				if (isset($jsobj->values[$j]->INDCD)){
					$this_INDCD=$jsobj->values[$j]->INDCD;
				}
				// store with line number, starting at 1
				$INDCD[$j+1]=$this_INDCD;
			}
		}
	}

	foreach ($rs as $r){
		if ($r['LNDENY']<>'') {
			$LNDENY_explode=explode('|',$r['LNDENY']);
			if (count($LNDENY_explode)==4){
				$GROUP_arr[$LNDENY_explode[0]]=$LNDENY_explode[0];
				$CARC_arr[$LNDENY_explode[1]]=$LNDENY_explode[1];
				$AMT_arr[$LNDENY_explode[0]]=$LNDENY_explode[0];
			}
			$LNDENY_arr[$r['LNDENY']]=$r['LNDENY'];
		}
		$PAYAMT=$r['PAYAMT'];
	}

	// start off with no edits hit. edits follow in order of highest to lowest priority. first edit hit will
	// set $st_Name, and subsequent edits will not process if $st_Name has already been set.
	$st_Name=$editmsg='';

	// process dynamic routing edits
	if ($st_Name==''){
		$sql="SELECT * 
					FROM tbl_external_lookup_control 
					LEFT OUTER JOIN tbl_external_lookup ON lc_id=ml_lc_id 
					WHERE lc_active='y' 
						AND lc_ini_file='ERA_ROUTE_OVERRIDES'";
		$routers=myload($sql);
		if (count($routers)>0){
			// we have some dynamic routes, proceed through them, trying to build up a hierarchy of boolean logic rules
			$route_arr=array();
			foreach ($routers as $route){
				$js=json_decode($route['ml_value_1'], true);
				$route_arr[$js['SR_EDITSORT']]=$js;
			}
		}
		if (count($route_arr)>0){
			ksort($route_arr);
			$this_then=false;
			$this_all_met=true;
			$valid_commands=array_flip(array('IF','AND','THEN'));
			$valid_operands=array_flip(array('=','<','>','<=','>=','<>'));
			foreach ($route_arr as $route){
				if (isset($valid_commands[$route['SR_COMMAND']])){
					$this_met=false;
					if ($route['SR_COMMAND']=='IF' || $route['SR_COMMAND']=='AND'){
						if (isset($valid_operands[$route['SR_OPER']])){
							$this_fv=get_field($co_ID, $route['SR_FIELD']);
							$this_op=$route['SR_OPER'];
							$this_value=$route['SR_VALUE'];
							if ($this_op=='=') $this_op='==';
							//echo 'if('.sql_escape_clean($this_fv).$this_op.sql_escape_clean($this_value).') return true; else return false;<br>';
							if (eval('if('.sql_escape_clean($this_fv).$this_op.sql_escape_clean($this_value).') return true; else return false;')){
								$this_met=true;
							} else {
								$this_all_met=false;
							}
						}
				 	}
					if ($route['SR_COMMAND']=='AND' && $this_met==false){
						$this_all_met=false;
					}
				}
				if ($route['SR_COMMAND']=='THEN'){
					// we have finished all the IF and AND, see if we didn't meet any conditions
					if ($this_all_met){
						if ($route['SR_OPER']=='=' && $route['SR_VALUE']<>''){
							// we appear to have a queue in our "THEN"
							$st_Name=$queue_array[$route['SR_VALUE']];
							$editmsg='Dynamic routing: '.$route['SR_COMMENT'];
						}
					} else {
						$this_all_met=true;
						continue;
					}
				}
			}
		}
	}

	// 1. reroute to ERA_ELIG for certain CARC/RARC codes
	if ($st_Name == '') {
    // get ELIG codes from lookup tables
    $ERA_CARC_EDITS = ERA_code_lookup('ERA_CARC_CODES', 'SR_ELIG');
    $ERA_RARC_EDITS = ERA_code_lookup('ERA_REMARK_CODES', 'SR_ELIG');
    
    // if a RARC is present, use RARC queue, else use CARC queue
		if (count($RARC_arr) > 0) {
			foreach ($RARC_arr as $ra => $c) {
				if (isset ($ERA_RARC_EDITS[$ra])) {
					$st_Name = $queue_array['SR_ELIG'];
					$editmsg = 'RARC in ELIG list';
					break;
				}
			}
		}
		else if (count($CARC_arr) > 0) {
			foreach ($CARC_arr as $ca) {
				if (isset ($ERA_CARC_EDITS[$ca])) {
					$st_Name = $queue_array['SR_ELIG'];
					$editmsg = 'CARC in ELIG list';
					break;
				}
			}
		}
	}

	// 2. reroute to ERA_NONCOD for certain CARC/RARC codes
	if ($st_Name==''){
    // get NONCOD codes from lookup tables
		$ERA_CARC_EDITS = ERA_code_lookup('ERA_CARC_CODES', 'SR_NONCOD');
    $ERA_RARC_EDITS = ERA_code_lookup('ERA_REMARK_CODES', 'SR_NONCOD');

    // if a RARC is present, use RARC queue, else use CARC queue
		if (count($RARC_arr) > 0) {
			foreach ($RARC_arr as $ra => $c) {
				if (isset ($ERA_RARC_EDITS[$ra])) {
					$st_Name = $queue_array['SR_NONCOD'];
					$editmsg = 'RARC in NONCOD list';
					break;
				}
			}
		}    
		else if (count($CARC_arr)>0){
			foreach ($CARC_arr as $ca){
				if (isset($ERA_CARC_EDITS[$ca])){
					$st_Name=$queue_array['SR_NONCOD'];
					$editmsg='CARC in NONCOD list';
					break;
				}
			}
		}
	}

	// 3. reroute to ERA_COD for certain CARC/RARC codes
	if ($st_Name==''){
    // get COD codes from lookup tables
		$ERA_CARC_EDITS = ERA_code_lookup('ERA_CARC_CODES', 'SR_COD');
    $ERA_RARC_EDITS = ERA_code_lookup('ERA_REMARK_CODES', 'SR_COD');
    
    // if a RARC is present, use RARC queue, else use CARC queue
		if (count($RARC_arr) > 0) {
			foreach ($RARC_arr as $ra => $c) {
				if (isset ($ERA_RARC_EDITS[$ra])) {
					$st_Name = $queue_array['SR_COD'];
					$editmsg = 'RARC in COD list';
					break;
				}
			}
		}        
		else if (count($CARC_arr)>0){
			foreach ($CARC_arr as $ca){
				if (isset($ERA_CARC_EDITS[$ca])){
					$st_Name=$queue_array['SR_COD'];
					$editmsg='CARC in COD list';
					break;
				}
			}
		}
	}

	// 4. route to ERA_CL_PD for certain CARC/RARC codes
	if ($st_Name==''){
    // get CL_PD codes from lookup tables
		$ERA_CARC_EDITS = ERA_code_lookup('ERA_CARC_CODES', 'SR_CL_PD');
    $ERA_RARC_EDITS = ERA_code_lookup('ERA_REMARK_CODES', 'SR_CL_PD');
    
		// if no CARC, close as paied
		if (count($CARC_arr)==0){
			$st_Name=$queue_array['SR_CL_PD'];
			$editmsg='No CARC found';
		}

    // if a RARC is present, use RARC queue, else use CARC queue
		if (count($RARC_arr) > 0) {
			foreach ($RARC_arr as $ra => $c) {
				if (isset ($ERA_RARC_EDITS[$ra])) {
					$st_Name = $queue_array['SR_CL_PD'];
					$editmsg = 'RARC in Closed Paid list';
					break;
				}
			}
		} elseif (count($CARC_arr)>0){
			foreach ($CARC_arr as $ca){
				if (isset($ERA_CARC_EDITS[$ca])){
					$st_Name=$queue_array['SR_CL_PD'];
					$editmsg='CARC in Closed Paid list';
					break;
				}
			}
		}
	}

	// 5. route to ERA_CL_PD for certain CARC/GRP combinations
	if ($st_Name==''){
		// each array of the following array can contain an array of edits. 
		// all edits within each edit must get a hit, or the edit doesn't pass.
		$ERA_CARC_GROUP_EDITS=array(
			array(
				array('CARC'=>'1', 'GROUP'=>'PR'),
				array('CARC'=>'45','GROUP'=>'CO')
			),
			array(
				array('CARC'=>'3', 'GROUP'=>'PR'),
				array('CARC'=>'45','GROUP'=>'CO')
			),
			array(
				array('CARC'=>'2', 'GROUP'=>'CO'),
				array('CARC'=>'45','GROUP'=>'CO')
			),
			array(
				array('CARC'=>'3', 'GROUP'=>'CO'),
				array('CARC'=>'45','GROUP'=>'CO')
			),
			array(
				array('CARC'=>'23', 'GROUP'=>'OA'),
			),
			array(
				array('CARC'=>'45','GROUP'=>'CO','AMT'=>'>0')
			),
			array(
				array('CARC'=>'45','GROUP'=>'CO','INDCD'=>'MA67')
			)
		);

		// step through each edit
		foreach ($ERA_CARC_GROUP_EDITS as $ege){

			// start off assuming that we haven't found any matches for any edits
			$any_group_not_found=false;

			// step through each edit row
			foreach ($ege as $ege_row){

				// start off assuming that we haven't found any matches for this part of an edit
				$any_line_found=false;

				// step through each LNDENY
				foreach ($LNDENY_arr as $l){
					// explode this LNDENY
					$l_arr=explode('|', $l);

					if (count($l_arr)>=2){
						$this_group=$l_arr[0];
						$this_CARC=$l_arr[1];
						$this_AMT=$l_arr[2];
						$this_LINE=$l_arr[3];

						if (isset($ege_row['CARC']) && $ege_row['CARC']<>$this_CARC){
							// this edit calls for a CARC, and it's not a match. no need to go any further with this edit. move on to next foreach.
							continue;
						}

						if (isset($ege_row['GROUP']) && $ege_row['GROUP']<>$this_group){
							// this edit calls for a group, and it's not a match. no need to go any further with this edit. move on to next foreach.
							continue;
						}

						if (isset($ege_row['INDCD'])){
							// this edit calls for an INDCD
							if (isset($INDCD[$this_LINE])){
								// an INDCD exists for this service line
								if ($ege_row['INDCD']<>$INDCD[$this_LINE]){
									// it's not a match ON THIS SERVICE LINE. no need to go any further with this edit. move on to next foreach.
									continue;
								}
							} else {
								// and INDCD does not exist for this service line, only a match if this edit expects and empty INDCD
								if ($ege_row['INDCD']<>''){
									continue;
								}
							}
						}

						if (isset($ege_row['AMT'])){
							$amt_found=false;
							// edits that require a particular AMT
							// supports >0, >=0, <0, <=0, 0, exact matches, null, not null
							if ($ege_row['AMT']=='>0' && $this_AMT>0){
								$amt_found=true;
							}
							if ($ege_row['AMT']=='>=0' && $this_AMT>=0){
								$amt_found=true;
							}
							if ($ege_row['AMT']=='<0' && $this_AMT<0){
								$amt_found=true;
							}
							if ($ege_row['AMT']=='<=0' && $this_AMT<=0){
								$amt_found=true;
							}
							if ($ege_row['AMT']=='0' && $this_AMT==0){
								$amt_found=true;
							}
							if ($ege_row['AMT']==$this_AMT){
								$amt_found=true;
							}
							if (($ege_row['AMT']=='null' || $ege_row['AMT']=='') && $this_AMT==''){
								$amt_found=true;
							}
							if ($ege_row['AMT']=='not null' && $this_AMT<>''){
								$amt_found=true;
							}
							if ($amt_found==false){
								// after all that, the AMT didn't match. continue (unmatched) with the next edit.
								continue;
							}
						}

						// if we got this far, this edit row matched this LNDENY, that's all we care about, LNDENY wise for this edit row
						$any_line_found=true;
						break;
					}
				}

				// if we didn't get a hit for EVERY SINGLE edit row, then consider this edit NOT hit
				if ($any_line_found==false) {
					// since all we are implementing now is "AND" functionality, we might as well get out now since
					// we know that one of these edit rows was not hit.
					$any_group_not_found=true;
					break;
				}
			}

			if ($any_group_not_found==false){
				// all criteria for this edit were found within the LNDENY records
				$st_Name=$queue_array['SR_CL_PD'];
				$editmsg='Match found in group+CARC close list';
				break;
			}
		}
	}

	// all edits have had a chance to process. if an edit was hit then $st_Name will not be empty.
	if ($st_Name<>''){
		$st_ID=get_st_ID($st_Name);
		if ($st_ID>0){
      $fComments = 'ERA edit autoforward';
      $post = array();
      $update_status = set_corr_status ($co_ID, $st_ID, $fcomment, $post, $user);

      if ($update_status[0] == false) {
        return array(1,"Failed to forward record ".$co_ID." to status queue (".$st_ID."): ".$update_status[1]);
      } else {
        return array(0,"Record ".$co_ID." routed to ".$st_Name." (".$st_ID.") based upon ERA edits (".$editmsg.")");
      }
		}
	}

	// the following message needs to stay in sync with message check in links/ERA_REROUTE.php
	return array(0,'No ERA autoforward edits found.');
}

/**
 * Get CARC or RARC codes from lookup tables.
 * Creates an array of codes associated with the code type and queue specified;
 * for example, all CARC codes that route to the ELIG queue  
 *
 * @author    Jamie Minton <jdminton@pinnaclebsi.com>
 * @param     string $ini the .ini file for the lookup table
 * @param     string $queue the queue code (e.g. SR_ELIG)
 * @return    array $ERA_EDITS array of codes found 
*/
function ERA_code_lookup($ini, $queue){
  $sql = "SELECT ml_value_1
  			 FROM tbl_external_lookup
  			 INNER JOIN tbl_external_lookup_control ON lc_id = ml_lc_id
  			 WHERE lc_ini_file = '$ini'
  			   AND ml_active = 'y'
           AND ml_value_1 LIKE '%$queue%'
  			 ORDER BY ml_key_1";
  $rs = myload($sql);

  $ERA_EDITS = array();  
  if (count($rs) > 0) {
    foreach ($rs as $r => $rec) {
      $rec_explode = explode('"', $rec['ml_value_1']); 
			if (count($rec_explode) == 21){
				$ERA_EDITS[$rec_explode[3]]=$rec_explode[3];
      }
    }    
  }
  return $ERA_EDITS; 
}

function CheckEDCConnectErrorCat($post=array(), $user=''){
	global $config;

	$co_ID = @$post['fco_ID'];

  if (!isset($post['fco_ID']) || (!is_numeric($post['fco_ID'])))
  {
	  $recips = 'No record ID passed.';
  	$comment = 'Failed to check for error category: ' .$recips . ' (' . __LINE__ .')';
    $noters=myload("CALL sp_add_activity_note (".sql_escape_clean($co_ID).",'NOTE',".sql_escape_clean($comment).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");
 		return array(1,$recips);
 	}

	if ( (!isset($post['fst_id'])) || (!isset($post['fco_ID'])) || (!is_numeric($post['fst_id'])) || (!is_numeric($post['fco_ID'])) )
  {
	  $recips = 'Status or record # missing';
  	$comment = 'Failed to check for error category: ' .$recips . ' (' . __LINE__ .')';
    $noters=myload("CALL sp_add_activity_note (".sql_escape_clean($co_ID).",'NOTE',".sql_escape_clean($comment).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");
 		return array(1,$recips);
	}

	$stchange_res = myload("SELECT st_Name, st_Desc, st_ct_ID
													FROM tbl_statuses 
													INNER JOIN tbl_corr_types ON st_ct_ID=ct_ID
													WHERE st_ID=".$post['fst_id']);
	if (!isset($stchange_res[0]['st_Name']))
  {
	  $recips = 'Unknown status id ' . $post['fst_id'];
  	$comment = 'Failed to check for error category: ' .$recips . ' (' . __LINE__ .')';
    $noters=myload("CALL sp_add_activity_note (".sql_escape_clean($co_ID).",'NOTE',".sql_escape_clean($comment).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");
 		return array(1,$recips);
 	}

	if (isset($post['codeparams']) && preg_match('/^\((.*)\)$/',$post['codeparams'], $matches)) {
		$ESUBJECT = get_field($co_ID, $matches[1]);
		// we're only interested in Short Descriptions containing 'HP EDC Connect:Direct Errors'
		if (preg_match('/HP EDC Connect:Direct Errors/', $ESUBJECT)) {
    	$default_doc = doc_repo_default_details($co_ID);
    	if ($default_doc['docID'] != '') {
      	$vfname = $default_doc['filename'];
      	if ($vfname == '')
        	$vfname = $default_doc['desc'];
				if (!preg_match('/\.csv/', $vfname)) {
					// No .csv attachement - > move ticket to EDC Close Queue
					$EDC_Closed_q = get_st_ID('EDC_CL');
					$ret = move_to_status ($co_ID, $EDC_Closed_q, 'Automatic move to Closed queue', $user, 'a');
    			if ($ret[0] === false)
      			return array(0,'CheckEDCConnectErrorCat: Failed to Automatic Move to Closed queue, record: '.$ret[1]);
				} else {	
					// Do nothing if there's an csv file
				}
    	} else {
				// No attachement -> move ticket to EDC Close Queue
				$EDC_Closed_q = get_st_ID('EDC_CL');
				$ret = move_to_status ($co_ID, $EDC_Closed_q, 'Automatic move to Closed queue', $user, 'a');
    		if ($ret[0] === false)
      		return array(0,'CheckEDCConnectErrorCat: Failed to Automatic Move to Closed queue, record: '.$ret[1]);
			}
		}
	}
}

/**
 * reroutes HA records based upon control field values
 *
 * @param array $post
 * @param string $user
 * @return array
 */
function HA_reroute($post=array(), $user=''){
	global $config;

	if (($user == '') && (isset($_SESSION['username'])) && ($_SESSION['username'] != ''))
		$user = $_SESSION['username'];
	else if ($user == '')
		$user = 'POWER';

	// verify input
	if (isset($post['fco_ID']) && $post['fco_ID']>0){
		$co_ID=$post['fco_ID'];
	} else {
 		return array(1,'No record number found.');
	}

	$d=array();
	foreach (array('CLAIM',
								'MEMBER_NUMBER',
								'SCCF',
								'DOS',
								'PROVREND',
								'PAYTONAME',
								'GROUP',
								'DIVISION',
								'CLAIMTYPE',
								'AGE',
								'CARRIER',
								'SCCF1',
								'ITS',
								'AGEDT',
								'FILENET',
								'SOURCE',
								'ICN7',
								'COMPANY',
								'SERVADJ',
								'STAT',
								'30',
								'NM01',
								'BUSUNIT',
								'PROGRAMS',
								'RISK_POP',
								'PROC'
						) as $field){
		$d[$field]=get_field($co_ID, $field);
	}

	$sql="SELECT fv_Value
				FROM tbl_field_values 
				INNER JOIN tbl_fields ON fv_fi_ID=fi_ID
				WHERE fv_co_ID=".$co_ID."
					AND fi_Name='PENDCD'";
	$frs=myload($sql);

	$d['PENDCD']=array();
	if (count($frs)>0){
		foreach ($frs as $f){
			$d['PENDCD'][]=$f['fv_Value'];
		}
	}
	
	$st_Name=ha_queue_routing_edits($d);

	// all edits have had a chance to process. if an edit was hit then $st_Name will not be empty.
	if ($st_Name<>''){
		$st_ID=get_st_ID($st_Name);
		if ($st_ID>0){
      $fComments = 'HA edit autoforward';
      $post = array();
      $update_status = set_corr_status ($co_ID, $st_ID, $fcomment, $post, $user);

      if ($update_status[0] == false) {
        return array(1,"Failed to forward record ".$co_ID." to status queue (".$st_ID."): ".$update_status[1]);
      } else {
        return array(0,"Record ".$co_ID." routed to ".$st_Name." (".$st_ID.") based upon HA edits (".$editmsg.")");
      }
		}
	}

	return array(0,'No HA autoforward edits found.');
}

function ha_queue_routing_edits($d){
	$ret='';

	// setup variables used to analyze pending code, most edits rely on pending code.
		if (isset($d['PENDCD']) && is_array($d['PENDCD']) && count($d['PENDCD'])>0){
			$PENDCD=$d['PENDCD'];
			$PENDCD_flip=array_flip($PENDCD);
		} else {
			// no pending codes found, set empty array
			$PENDCD=$PENDCD_flip=array();
		}

	// 1. If the 7th digit of the claim number (ICN7) is = "T" will go to the "Blue Card" queue (HAIS_MISC_CL_BLCD).
		if ($ret==''){
			if (isset($d['ICN7']) && $d['ICN7']=='T'){
				$ret='HAIS_MISC_CL_BLCD';
			}
		}

	// 1.5. 1.5 If the source code (SOURCE) is "pnADJ", "pnMULT", or "pn3KLT", the record goes to the "Daily pn" queue (HAIS_MISC_CL_PN).
		if ($ret==''){
			$this_SOURCE=array('pnADJ','pnMULT','pn3KLT');
			$this_SOURCE_flip=array_flip($this_SOURCE);
			if (isset($d['SOURCE']) && isset($this_SOURCE_FLIP[$d['SOURCE']])){
				$ret='HAIS_MISC_CL_PN';
			}
		}

	// 2. If the source code (SOURCE) is "BTCHER", the record goes to the  'HA In State Reports Batch Commercial' queue  (HAIS_Reports_BatchComm).
		if ($ret==''){
			if (isset($d['SOURCE']) && $d['SOURCE']=='BTCHER'){
				$ret='HAIS_RPTS_BTCHCM';
			}
		}

	//3.	If the source code (SOURCE)  is "HAD5", the record goes as follows:
	//	1)	When  the 7th digit of the claim number (ICN-7) is "Y" or "Z" the records 
	//			will go to the 'HA In State Reports HAD5 Medicaid' queue (HAIS_RPTS_HAD5MD)
	//	2)	When the 7th digit of the claim number (ICN-7) is anything other than a "Y" or "Z", the 
	//			record goes to 'HA In State Reports HAD5 Comm (Non Medicaid)' queue (HAIS_RPTS_HAD5CM)
		if ($ret==''){
			if (isset($d['SOURCE']) && $d['SOURCE']=='HAD5'){
				if (isset($d['ICN7']) && ($d['ICN7']=='Y' || $d['ICN7']=='Z')){
					$ret='HAIS_RPTS_HAD5MD';
				} else {
					$ret='HAIS_RPTS_HAD5CM';
				}
			}
		}

	// 4. If the record only has one pend code (PENDCD), including 
	// "89","Za","KK","T9","TA","BT","JH","KR","KP","9C","lc","xx","P0","P1" the record will go to the 'Amisys Error' queue 
	// (HAIS_MISC_AMISERR).

		if ($ret==''){
			$this_errors=array('89','Za','KK','T9','TA','BT','JH','KR','KP','9C','lc','xx','P0','P1','PT', 'YA','BA');
			$this_errors_flip=array_flip($this_errors);
			if (count($PENDCD)==1 && isset($this_errors_flip[$PENDCD[0]])){
				$ret='HAIS_MISC_AMISERR';
			}
		}

	// 4.5. If any of the pend codes (PENDCD) are "br", the record will to to the 'Bronze Member Reject' queue (HAIS_MISC_BZRJ).
		if ($ret==''){
			if (isset($PENDCD_flip['br'])){
				$ret='HAIS_MISC_BZRJ';
			}
		}

	// 5. If any of the pend codes (PENDCD) are "PY" or "PP" the record will go to the 'Closed - PY/PP Pend Claims' queue 
	// (HAIS_MISC_CL_PYPP).
		if ($ret==''){
			if (isset($PENDCD_flip['PY']) || 
					isset($PENDCD_flip['PP'])){
				$ret='HAIS_MISC_CL_PYPP';
			}
		}

	// 6. If any of the pend codes (PENDCD) are "cz", "ls", "sm" or "BX", the record will go to the 'Processor Error' queue 
	// (HAIS_MISC_PROCERR).
		if ($ret==''){
			if (isset($PENDCD_flip['cz']) || 
					isset($PENDCD_flip['ls']) ||
					isset($PENDCD_flip['sm']) ||
					isset($PENDCD_flip['BX'])){
				$ret='HAIS_MISC_PROCERR';
			}
		}

	// 7. If any of the pend codes (PENDCD) are equal to the "UR" codes, including 
	// "UR","U2","U3","U5","U6","EX","a5","FR","FS","mt","SM","BR","2M","2L","4B","U7","U9","LO","lo","RV" or ,"ur" the 
	// record will go to the "Closed - UR" queue (HAIS_MISC_CL_UR).
		if ($ret==''){
			$this_errors=array('UR','U2','U3','U5','U6','EX','a5','FR','FS','mt',
												 'SM','BR','2M','2L','4B','U7','U9','LO','lo','RV','ur');
			$this_errors_flip=array_flip($this_errors);
			if (count($PENDCD)>0){
				foreach ($PENDCD as $PC){
					if (isset($this_errors_flip[$PC])){
						$ret='HAIS_MISC_CL_UR';
						break;
					}
				}
			}
		}

	// 8. If any record only has the following pend codes it should be placed in the Automated Pend Code que "PN" "E7" "we"
		if ($ret==''){
			if (isset($PENDCD_flip['PN']) || 
					isset($PENDCD_flip['E7']) ||
					isset($PENDCD_flip['we'])){
				$ret='HAIS_MISC_CL_AUTO';
			}
		}

	// 9. If the 'Status-x'  (STAT) control field is = "39", the records will go to 
	// the "Processor Error" queue (HAIS_MISC_PROCERR). 
		if ($ret==''){
			if (isset($d['STAT']) && $d['STAT']=='39'){
				$ret='HAIS_MISC_PROCERR';
			}
		}

	// 10. If any of the pend codes are equal to the "Dirty Pend" codes (PENDCD), including 
	// "31","Am","Ar","GM","3e","3g","3P","U6","uu","U1","AY","S8"  will go to the "Dirty Pend" queue (HAIS_MISC_DTYPEND).
	// added Br,S7
		if ($ret==''){
			$this_errors=array('31','Am','Ar','GM','3e','3g','3P','3o','U6','uu','U1','AY','S5','S8','tk','th','tl','UX','Br','S7');
			$this_errors_flip=array_flip($this_errors);
			if (count($PENDCD)>0){
				foreach ($PENDCD as $PC){
					if (isset($this_errors_flip[$PC])){
						$ret='HAIS_MISC_DTYPEND';
						break;
						}
				}
			}
		}

	// 11. If the pend code (PENDCD) is ONLY "LC", the "High Dollar" pend code, the records will go to the 'High Dollar 
	// Review - Team Lead' queue (HAIS_MISC_HD_LEAD).
		if ($ret==''){
			if (count($PENDCD)==1 && isset($PENDCD_flip['LC'])){
				$ret='HAIS_MISC_HD_LEAD';
			}
		}

	// 12. Business Package definitions: control fields: BUSUNIT/PROGRAMS/CARRIER/RISK_POP
		// GROUP 1: Commercial Non-COB and Commercial COB - 1-7
		$BP=array();
		$BP[1]='ST/HM/MA/';
		$BP[2]='ST/HM/AS/';
		$BP[3]='ST/HM/UA/';
		$BP[4]='ST/OE/AS/';
		$BP[5]='ST/OE/HA/';
		$BP[6]='FE/HM/BH/';
		$BP[7]='ST/HM/HA/';

		// GROUP 2: Employee Non-COB and Employee COB - 8
		$BP[8]='FE/HM/AB/';

		// GROUP 3: AR Benefits Non-COB and AR COB Benefits - 9-12
		$BP[9]='FE/TP/AP/';
		$BP[10]='FE/TP/PS/';
		$BP[11]='FE/TP/AR/';
		$BP[12]='FE/TP/ME/';

		// GROUP 4: AR Retirees Non-COB and AR COB Retirees - 13-18 COB Related ARBenefits (all ques for these packages will be worked by the COB area):
		$BP[13]='FE/TP/AP/MP';
		$BP[14]='FE/TP/AP/ME';
		$BP[15]='FE/TP/AP/MB';
		$BP[16]='FE/TP/PS/MP';
		$BP[17]='FE/TP/PS/ME';
		$BP[18]='FE/TP/PS/MB';
		$BP[19]='FE/TP/ME/MP';
		$BP[20]='FE/TP/ME/ME';
		$BP[21]='FE/TP/ME/MB';

	// 13. perform business process edits
		if ($ret==''){
			$this_BP_SHORT=$this_BP_LONG='';
			if (isset($d['BUSUNIT'])) $this_BP_LONG.=$d['BUSUNIT'];
			$this_BP_LONG.='/';
			if (isset($d['PROGRAMS'])) $this_BP_LONG.=$d['PROGRAMS'];
			$this_BP_LONG.='/';
			if (isset($d['CARRIER'])) $this_BP_LONG.=$d['CARRIER'];
			$this_BP_LONG.='/';
			$this_BP_SHORT=$this_BP_LONG;
			if (isset($d['RISK_POP'])) $this_BP_LONG.=$d['RISK_POP'];

			// determine BP_GROUP, which group of business packages does this record belong in.
				$this_BP_GROUP=$this_BP=0;
				foreach ($BP as $BPk=>$BPv){
					if ($this_BP_SHORT==$BPv){
						// matches one of the 3-element business processes
						if ($BPk>=1 && $BPk<=7) {
							$this_BP_GROUP=1;
							$this_BP=$BPk;
							break;
						}
						if ($BPk==8) {
							$this_BP_GROUP=2;
							$this_BP=$BPk;
							break;
						}
						if ($BPk>=9 && $BPk<=12) {
							if (isset($d['RISK_POP'])){
								if ($d['RISK_POP']=='MP' ||
										$d['RISK_POP']=='MB' ||
										$d['RISK_POP']=='ME'){
									// the RISK_POP has excluded this record from being in group 3 AND from being in package 9-12
									// do nothing
								} else {
									$this_BP_GROUP=3;
									$this_BP=$BPk;
									break;
								}
							} else {
								$this_BP_GROUP=3;
								$this_BP=$BPk;
								break;
							}
						}
					}
					if ($this_BP_LONG==$BPv){
						// matches one of the 4-element business processes
						$this_BP=$BPk;
						if ($BPk>=13 && $BPk<=21) $this_BP_GROUP=4;
						break;
					}
				}

			// (1-21) cq COB Membership Review Que  HAIS_MBRSHIP_COBREV
				if ($this_BP>=1 && $this_BP<=21 && isset($PENDCD_flip['ca'])){
					$ret='HAIS_MBRSHIP_COBREV';
				}

			// (1-21) ca COB Membership Review Que  HAIS_MBRSHIP_HOLD
				if ($this_BP>=1 && $this_BP<=21 && isset($PENDCD_flip['CQ'])){
					$ret='HAIS_MBRSHIP_HOLD';
				}

			// (1-8) p2 COB Customer Account Posting Review Que  HAIS_MBRSHIP_PSTERR
				if ($this_BP>=1 && $this_BP<=8 && isset($PENDCD_flip['p2'])){
					$ret='HAIS_MBRSHIP_PSTERR';
				}

			// (9-21) p2 COB Customer Account Posting Review Que (ARBenefits) HAIS_MBRSHIP_PSTERR_ARBENE
				if ($this_BP>=9 && $this_BP<=21 && isset($PENDCD_flip['p2'])){
					$ret='HAIS_MBRSHIP_PSTERR_ARBENE';
				}

			// (1-8) p0 COB Membership Posting Review Error  HAIS_MBRSHIP_PSTREV
				if ($this_BP>=1 && $this_BP<=8 && isset($PENDCD_flip['p0'])){
					$ret='HAIS_MBRSHIP_PSTREV';
				}

			// (9-21) p0 COB Membership Posting Review Error (ARBenefits) HAIS_MBRSHIP_PSTREV_ARBENE	
				if ($this_BP>=9 && $this_BP<=21 && isset($PENDCD_flip['p0'])){
					$ret='HAIS_MBRSHIP_PSTREV_ARBENE';
				}

		}

		if ($ret==''){
			// route based upon BP_GROUP and PENDCD combinations
			$BP_ROUTING=array(
				array('BP_GROUP'=>1, 'QUEUE'=>'HAIS_COM_COB_ADJ ', 'PENDCD'=>array('a1')),
				array('BP_GROUP'=>1, 'QUEUE'=>'HAIS_COM_COB_COR ', 'PENDCD'=>array('56','3p','9L','ap','BS','FL','HL','LB','mb','mv','ns','p1','p2','S1','TY','US',
																																					 'pr','q1','q4','q5','q6',
																																					 'UU','V5','vz')),
				array('BP_GROUP'=>1, 'QUEUE'=>'HAIS_COM_COB_PNDAILY', 'PENDCD'=>array('pn')),
				array('BP_GROUP'=>1, 'QUEUE'=>'HAIS_COM_NOCOB_ADJ ', 'PENDCD'=>array('a2','Bc','Bk')),
				array('BP_GROUP'=>1, 'QUEUE'=>'HAIS_COM_NOCOB_CC', 'PENDCD'=>array('y7')),
				array('BP_GROUP'=>1, 'QUEUE'=>'HAIS_COM_NOCOB_INT ', 'PENDCD'=>array('ac','J4','J7','p4')),
				array('BP_GROUP'=>1, 'QUEUE'=>'HAIS_COM_NOCOB_SPEC ', 'PENDCD'=>array('1J','2Y','2y','3M','4P','5P','6P','7P','7S','8P','9S','a5','Af','AW','aw','B1',
																																							'BC','BF','bh','bs','cc','cd','CK','CL','CN','CW','CX','D0','DH','DM','Dt','DV',
																																							'EE','EK','EN','EO','er','ER','GO','gs','H2','H3','hh','HN','HO','IM','IP','JB',
																																							'L2','L6','L8','L9','LV','md','ms','MU','n1','N7','NC','NN','NO','NX','OR','P3',
																																							'P5','P7','p8','PA','Pb','PD','pe','PH','pi','PJ','PK','pM','PO','PQ','PR','PU',
																																							'PV','R8','Ra','RE','RN','RO','Rt','rT','rr','RU','SG','Sh','sI','t1','T4','T5',
																																							'tb','TH','TR','UA','W1','W2','W3','W4','W5','W6','W7','W8','XC','XX','XY','y6',
																																							'T8','yi','yL','X6',
																																							'bb','Z3',
																																							'YC','z4','z5','Z6','Zd','ZZ','Br','lp','FI')),
				array('BP_GROUP'=>1, 'QUEUE'=>'HAIS_COM_NOCOB_UR ', 'PENDCD'=>array('K4','UE','UF')),
				array('BP_GROUP'=>2, 'QUEUE'=>'HAIS_EMP_COB_ADJ ', 'PENDCD'=>array('a1')),
				array('BP_GROUP'=>2, 'QUEUE'=>'HAIS_EMP_COB_COR ', 'PENDCD'=>array('56','3p','9L','ap','BS','FL','HL','LB','mb','mv','ns','p1','pr','S1','TY','US','UU',
																																					 'q1','q4','q5','q6',
																																					 'V5','vz')),
				array('BP_GROUP'=>2, 'QUEUE'=>'HAIS_EMP_NOCOB_ADJ ', 'PENDCD'=>array('a2','Bc','Bk')),
				array('BP_GROUP'=>2, 'QUEUE'=>'HAIS_EMP_NOCOB_CC', 'PENDCD'=>array('y7')),
				array('BP_GROUP'=>2, 'QUEUE'=>'HAIS_EMP_NOCOB_INT ', 'PENDCD'=>array('ac','J4','J7','p4')),
				array('BP_GROUP'=>2, 'QUEUE'=>'HAIS_EMP_NOCOB_SPEC ', 'PENDCD'=>array('1J','1m','2Y','2y','3M','4P','5P','6P','7P','7S','8P','9S','a5','Af','AW','aw','B1',
																																							'BC','BF','bh','bs','cc','cd','CK','CL','CN','CW','CX','D0','DH','DM','Dt','DV',
																																							'EE','EK','EN','EO','er','ER','GO','gs','H2','H3','hh','HN','HO','IM','IP','JB',
																																							'L2','L6','L8','L9','LV','md','ms','MU','n1','N7','NC','NN','NO','NX','OR','P3',
																																							'P5','P7','p8','PA','Pb','PD','pe','PH','pi','PJ','PK','pM','PO','PQ','PR','PU',
																																							'PV','R8','Ra','RE','RN','RO','rr','Rt','rT','RU','SG','Sh','sI','t1','T4','T5',
																																							'tb','TH','TR','UA','W1','W2','W3','W4','W5','W6','W7','W8','XC','XX','XY','y6',
																																							'T8','yi','yL','X6',
																																							'bb','Z3',
																																							'YC','z4','z5','Z6','Zd','ZZ','Br','lp','FI')),
				array('BP_GROUP'=>2, 'QUEUE'=>'HAIS_EMP_NOCOB_UR ', 'PENDCD'=>array('K4','UE','UF')),
				array('BP_GROUP'=>3, 'QUEUE'=>'HAIS_ARBEN_COB_ADJ ', 'PENDCD'=>array('a1')),
				array('BP_GROUP'=>3, 'QUEUE'=>'HAIS_ARBEN_COB_COR ', 'PENDCD'=>array('56','3p','9L','ap','BS','FL','HL','LB','mb','mv','ns','p1','pr','S1','TY','US',
																																						 'UU','V5','vz','q1','q4','q5','q6')),
				array('BP_GROUP'=>3, 'QUEUE'=>'HAIS_ARBEN_COB_PN', 'PENDCD'=>array('pn')),
				array('BP_GROUP'=>3, 'QUEUE'=>'HAIS_ARBEN_NOCOB_ADJ ', 'PENDCD'=>array('a2','Bc','Bk')),
				array('BP_GROUP'=>3, 'QUEUE'=>'HAIS_ARBEN_NOCOB_CC', 'PENDCD'=>array('y7')),
				array('BP_GROUP'=>3, 'QUEUE'=>'HAIS_ARBEN_NOCOB_INT ', 'PENDCD'=>array('ac','J4','J7','p4')),
				array('BP_GROUP'=>3, 'QUEUE'=>'HAIS_ARBEN_NOCOB_SPEC ', 'PENDCD'=>array('1J','2Y','2y','3M','4P','5P','6P','7P','7S','8P','9S','a5','Af','AW','aw','B1',
																																								'BC','BF','bh','bs','cc','cd','CK','CL','CN','CW','CX','D0','DH','DM','Dt','DV',
																																								'EE','EK','EN','EO','er','ER','GO','gs','H2','H3','hh','HN','HO','IM','IP','JB',
																																								'L2','L6','L8','L9','LV','md','ms','MU','n1','N7','NC','NN','NO','NX','OR','P3',
																																								'P5','P7','p8','PA','Pb','PD','pe','PH','pi','PJ','PK','pM','PO','PQ','PR','PU',
																																								'PV','R8','Ra','RE','RN','RO','rr','RU','Rt','rT','SG','Sh','sI','t1','T4','T5',
																																								'tb','TH','TR','UA','W1','W2','W3','W4','W5','W6','W7','W8','XC','XX','XY','y6',
																																								'T8','yi','yL','X6',
																																								'bb','Z3',
																																								'YC','z4','z5','Z6','Zd','ZZ','Br','lp','FI')),
				array('BP_GROUP'=>3, 'QUEUE'=>'HAIS_ARBEN_NOCOB_UR ', 'PENDCD'=>array('K4','UE','UF')),
				array('BP_GROUP'=>4, 'QUEUE'=>'HAIS_ARRET_COB_ADJ ', 'PENDCD'=>array('a1','Bc','Bk')),
				array('BP_GROUP'=>4, 'QUEUE'=>'HAIS_ARRET_COB_CC', 'PENDCD'=>array('y7')),
				array('BP_GROUP'=>4, 'QUEUE'=>'HAIS_ARRET_COB_COR ', 'PENDCD'=>array('56','3p','9L','ap','BS','FL','HL','LB','mb','mv','ns','p1','pr','S1','TY','US',
																																						 'UU','V5','vz')),
				array('BP_GROUP'=>4, 'QUEUE'=>'HAIS_ARRET_COB_INT ', 'PENDCD'=>array('ac','J4','J7','p4')),
				array('BP_GROUP'=>4, 'QUEUE'=>'HAIS_ARRET_COB_PN', 'PENDCD'=>array('pn')),
				array('BP_GROUP'=>4, 'QUEUE'=>'HAIS_ARRET_COB_SPEC ', 'PENDCD'=>array('1J','cc','2Y','3M','4P','5P','6P','7P','7S','8P','9S','a5','Af','AW','aw','B1',
																																							'BC','BF','bh','bs','cc','cd','CK','CL','CN','CW','CX','D0','DH','DM','Dt','DV',
																																							'EE','EK','EN','EO','er','ER','GO','gs','H2','H3','hh','HN','HO','IM','IP','JB',
																																							'L2','L6','L8','L9','LV','md','ms','MU','n1','N7','NC','NN','NO','NX','OR','P3',
																																							'P5','P7','p8','PA','Pb','PD','pe','PH','pi','PJ','PK','pM','PO','PQ','PR','PU',
																																							'PV','R8','Ra','RE','RN','RO','rr','RU','Rt','rT','SG','Sh','sI','t1','T4','T5',
																																							'T8','yi','yL',
																																							'bb','Z3',
																																							'tb','TH','TR','UA','W1','W2','W3','W4','W5','W6','W7','W8','XC','XX','XY','y6',
																																							'YC','z4','z5','Z6','Zd','ZZ','Br','lp','q1','q2','q3','q5','q6','FI')),
				array('BP_GROUP'=>4, 'QUEUE'=>'HAIS_ARRET_COB_UR ', 'PENDCD'=>array('k4','ue','uf','K4','UE','UF'))
			);

			// loop through each of the $BP_ROUTING rules and look for a matching group+pendcode
				foreach ($BP_ROUTING as $BPR){
					if ($BPR['BP_GROUP']==$this_BP_GROUP){
						$this_errors=$BPR['PENDCD'];
						$this_errors_flip=array_flip($this_errors);
						foreach($PENDCD as $PC){
							if (isset($this_errors_flip[$PC])){
								$ret=$BPR['QUEUE'];
								break;
							}
						}
						if ($ret<>''){
							// we found a match in the previous foreach, so we're done
							break;
						}
					}
				}
		}

	// see if any edits got a hit
		if ($ret==''){
			// no edit matched, so set default queue
			$ret='HAIS_MISC_IMP_ISS';
		}

	// finished with all edits. return $ret with destination queue name (st_Name)
	return $ret;
}


function attach_default_doc_if($post=array(), $user='')
{
  if (!isset($post['fco_ID']) || (!is_numeric($post['fco_ID'])))
  {
	  $recips = 'No record ID passed.';
  	$comment = 'Failed to attach default doc: ' .$recips . ' (' . __LINE__ .')';
    $noters=myload("CALL sp_add_activity_note (".sql_escape_clean($co_ID).",'NOTE',".sql_escape_clean($comment).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");
 		return array(1,$recips);
 	}

	$co_ID = @$post['fco_ID'];

	if (($user == '') && (isset($_SESSION['username'])) && ($_SESSION['username'] != ''))
		$user = $_SESSION['username'];
	else if ($user == '')
		$user = 'POWER';

	if (!isset($post['codeparams']) || (!preg_match('/^(.*)$/',$post['codeparams'])) || ($post['codeparams']=='()'))
	{
		$recips = "No parameters sent to attach_default_doc function. Setup error.";
		$comment = 'Failed to attach default doc: ' .$recips . ' (' . __LINE__ .')';
    $noters=myload("CALL sp_add_activity_note (".sql_escape_clean($co_ID).",'NOTE',".sql_escape_clean($comment).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");
		return array(1,$recips);
	}

	if ( (!isset($post['fst_id'])) || (!isset($post['fco_ID'])) || (!is_numeric($post['fst_id'])) || (!is_numeric($post['fco_ID'])) )
  {
	  $recips = 'Status or record # missing';
  	$comment = 'Failed to attach default doc: ' .$recips . ' (' . __LINE__ .')';
    $noters=myload("CALL sp_add_activity_note (".sql_escape_clean($co_ID).",'NOTE',".sql_escape_clean($comment).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");
 		return array(1,$recips);
	}

	$codeparams = $post['codeparams']; // this can be (id=12345,folder=worksheet,haltonerror=no,controlvar==someValue)
	$condition_is_met = false;
	if (preg_match('/^\((.*)\)$/', $codeparams, $matches))
	{
		$cfields = array();
		if (isset($matches[1]))
	  	$cfields = explode(',', $matches[1]);
		$folder = $coID = $haltonerror = $condition = array();
		foreach ($cfields as $field) {
			if (preg_match('/^id\=\.*/i', $field)) {
				//echo '<pre>'.$field.'</pre>';
				$coID = explode('=', $field);
			} else if (preg_match('/^folder\=\.*/i', $field)) {
				//echo '<pre>'.$field.'</pre>';
				$folder = explode('=', $field);
			} else if (preg_match('/^haltonerror\=\.*/i', $field)) {
				//echo '<pre>'.$field.'</pre>';
				$haltonerror = explode('=', $field);
			} else if (preg_match('/^\'([A-Za-z0-9_]*)(={2}|[<>]+)(.*)\'$/', $field, $matches)) {
				// at this point, we only allow SomeVar on the LHS
				//                              a constant or SomeVar on the RHS
				if (isset($matches[1])) {
					$lhs = get_field($post['fco_ID'], $matches[1]);
				}

				$ops = $matches[2];   // checked by regexp
				$rhs = is_string($matches[3]) ? $matches[3] : is_numeric($matches[3]) ? $matches[3] : null;
				if (!isset($rhs)) {
					return array(1,'Error in attach_default_doc_if condition parameter');
				}
				if (preg_grep('/[\#\!\/]./', explode('/', $rhs))) {
					// let's not allow #!/bin/sh and alike
					return array(1,'Error in attach_default_doc_if condition parameter. Shell escape is not allowed.');
				}

				if (!isset($lhs)) $lhs='';

				if (isset($lhs) && isset($ops) && isset($rhs)) {
					//echo 'Valid condition : ', $lhs . ' ' . $ops . ' ' . $rhs . "\n";
					if ($rhs=='NULL'){
						$rhs='';
					}
					$eval_code = "return '".$lhs."'".$ops."'".$rhs."';";

					if (eval($eval_code)) {
						$condition_is_met = true;
					}
				} else 
					return array(1,'Error in attach_default_doc_if condition parameter');
			}
		}

		if (!$condition_is_met) {
			// Do some basic testing on the ticket id passed in
			if (!is_numeric($coID[1])) {
	  		$recips = 'Invalid id passed in ('. $coID[1].'). Not numeric.';
  			$comment = 'Failed to attach default doc: ' .$recips . ' (' . __LINE__ .')';
    		$noters=myload("CALL sp_add_activity_note (".sql_escape_clean($co_ID).",'NOTE',".sql_escape_clean($comment).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");
				if (isset($haltonerror[1]) && $haltonerror[1] == 'yes')
 					return array(1,$recips);
				else 
 					return array(0,$recips);
			}
			$sql = 'SELECT co_region FROM tbl_corr WHERE co_ID='.$coID[1].' LIMIT 1';
			$rs=myload($sql);
			if (count($rs) < 1){
	  		$recips = 'Invalid id passed in ('. $coID[1].'). Does not exist.';
  			$comment = 'Failed to attach default doc: ' .$recips . ' (' . __LINE__ .')';
    		$noters=myload("CALL sp_add_activity_note (".sql_escape_clean($co_ID).",'NOTE',".sql_escape_clean($comment).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");
				if (isset($haltonerror[1]) && $haltonerror[1] == 'yes')
 					return array(1,$recips);
				else 
 					return array(0,$recips);
			}
	
			if (isset($folder[1]) && !doc_repo_valid_folder($folder[1])){
	  		$recips = 'Invalid foldername passed in ('. $folder[1].').';
  			$comment = 'Failed to attach default doc: ' .$recips . ' (' . __LINE__ .')';
    		$noters=myload("CALL sp_add_activity_note (".sql_escape_clean($co_ID).",'NOTE',".sql_escape_clean($comment).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");
				if (isset($haltonerror[1]) && $haltonerror[1] == 'yes')
 					return array(1,$recips);
				else 
 					return array(0,$recips);
			}
	
			$docrs=doc_repo_default_details($coID[1]);
			if (isset($docrs['docID']) && $docrs['docID']>0) {
	
				// we have a valid foldername passed in, create it if it doesn't exist yet
  			if (isset($folder[1]) && !doc_repo_folder_exists($co_ID, $folder[1]))
    			doc_repo_create_folder($co_ID, $folder[1], '', '', $user, '');
	
				// get default doc
				$image_array=get_filecache($docrs['docID'],false);
	
				if (isset($image_array['data'])){
					$fi_filename = doc_repo_new_filename($co_ID, $docrs['filename']);
    			$mime = get_mimetype($fi_filename,'');
					$bytelen=strlen($image_array['data']);
	
					if (isset($folder[1]) && $folder[1] != '')
						$fi_filename = $folder[1].'|'.$fi_filename;
	
					$new_doc = @doc_repo_add_file($co_ID, 'copy of file', '', $fi_filename, $bytelen, $image_array['description'], $mime);
					if (!$new_doc['docID']) {
						if (isset($haltonerror[1]) && $haltonerror[1] == 'yes')
							return array(1,$co_ID.': Errors encountered. File copy from document repository of '.$coID[1].' failed.');
						else 
							return array(0,$co_ID.': Errors encountered. File copy from document repository of '.$coID[1].' failed.');
					}
	
					$dirarr=make_filename($new_doc['docID']);
					$imageArray=array();
					$imageArray["Content-Type"]=$mime;
					$imageArray["Content-Length"]=$bytelen;
					$imageArray["Content-Disposition"]="filename=\"".$fi_filename."\"";
					$imageArray["data"]=$image_array['data'];
					$ctser=serialize($imageArray);
					upload_filecache( $dirarr["dir"], $dirarr["filename"], $ctser, $new_doc['docID'] );
	
					// mark the newly copied doc in this new ticket, as the default doc
					doc_repo_set_default_file($co_ID, $new_doc['fi_ID'], $user);
	
				} else {
					// no data from default doc
					if (isset($haltonerror[1]) && $haltonerror[1] == 'yes')
						return array(1,$co_ID.': Errors encountered. No data associated with default doc in repository of '.$coID[1]);
					else 
						return array(0,$co_ID.': Errors encountered. No data associated with default doc in repository of '.$coID[1]);
				}
			} else {
				// no default doc available
				if (isset($haltonerror[1]) && $haltonerror[1] == 'yes')
					return array(1,$co_ID.': Errors encountered. No default doc in repository of '.$coID[1]);
				else 
					return array(0,$co_ID.': Errors encountered. No default doc in repository of '.$coID[1]);
			}
		} else {
			$currdef = myload("SELECT fi_ID, fi_category FROM tbl_filerefs WHERE fi_co_ID=$co_ID AND fi_category LIKE '%default%'");
			// there should be only 1, but loop through just in case - unsetting them from being the default for this co_ID
			for ($i = 0, $cnt=count($currdef); $i < $cnt; $i++)
			{
				if ( (isset($currdef[$i]['fi_ID'])) && (isset($currdef[$i]['fi_category'])) )
				{
					$def_fid = $currdef[$i]['fi_ID'];
					$category = $currdef[$i]['fi_category'];
					$category = str_replace('default','',$category);	// strip out default
					$category = str_replace(',,',',',$category); // expect it to be csv
					if ($category == '')
		 	  		$ret=myexecute("UPDATE tbl_filerefs SET fi_category=NULL WHERE fi_ID=$def_fid");
					else
		 	  		$ret=myexecute("UPDATE tbl_filerefs SET fi_category='$category' WHERE fi_ID=$def_fid");			
		  		if (!(isset($ret[0])) || (!is_numeric($ret[0]))){
						if (isset($haltonerror[1]) && $haltonerror[1] == 'yes')
							return array(1,$co_ID.': Errors encountered. Cannot detach default doc.');
						else 
							return array(0,$co_ID.': Errors encountered. Cannot detach default doc.');
					}
				}
			}
		}
	} // end of if (preg_match('/^\((.*)\)$/', $codeparams, $matches))
}

function populate_region($post=array(), $user='')
{
  if (!isset($post['fco_ID']) || (!is_numeric($post['fco_ID'])))
  {
	  $recips = 'No record ID passed.';
  	$comment = 'Failed to populate region field: ' .$recips . ' (' . __LINE__ .')';
    $noters=myload("CALL sp_add_activity_note (".sql_escape_clean($co_ID).",'NOTE',".sql_escape_clean($comment).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");
 		return array(1,$recips);
 	}

	$co_ID = @$post['fco_ID'];

	if (($user == '') && (isset($_SESSION['username'])) && ($_SESSION['username'] != ''))
		$user = $_SESSION['username'];
	else if ($user == '')
		$user = 'POWER';

	if ( (!isset($post['fst_id'])) || (!isset($post['fco_ID'])) || (!is_numeric($post['fst_id'])) || (!is_numeric($post['fco_ID'])) )
  {
	  $recips = 'Status or record # missing';
  	$comment = 'Failed to populate region field: ' .$recips . ' (' . __LINE__ .')';
    $noters=myload("CALL sp_add_activity_note (".sql_escape_clean($co_ID).",'NOTE',".sql_escape_clean($comment).",".sql_escape_clean($user).",'".date("Y-m-d G:i:s")."')");
 		return array(1,$recips);
	}
  
	$county_region_map = array(
		'Benton'      => 'Northwest', 'Carroll'    => 'Northwest', 'Washington'   => 'Northwest', 'Madison'    => 'Northwest',
		'Newton'      => 'Northwest', 'Boone'      => 'Northwest', 'Searcy'       => 'Northwest', 'Marion'     => 'Northwest',
		'Baxter'      => 'Northwest', 'Fulton'     => 'Northeast', 'Izard'        => 'Northeast', 'Stone'      => 'Northeast',
		'Randolph'    => 'Northeast', 'Sharp'      => 'Northeast', 'Independence' => 'Northeast', 'Lawrence'   => 'Northeast',
		'Jackson'     => 'Northeast', 'Woodruff'   => 'Northeast', 'Clay'         => 'Northeast', 'Greene'     => 'Northeast',
		'Graighead'   => 'Northeast', 'Poinsett'   => 'Northeast', 'Cross'        => 'Northeast', 'St.Francis' => 'Northeast',
		'Mississippi' => 'Northeast', 'Crittenden' => 'Northeast', 

		'Crawford'  => 'West Central', 'Franklin' => 'West Central', 'Johnson' => 'West Central', 'Logan' => 'West Central', 
		'Sebastian' => 'West Central', 'Scott'    => 'West Central', 'Polk'    => 'West Central', 

		'Van Buren' => 'Central', 'Pope'      => 'Central', 'Conway' => 'Central', 'Cleburne'  => 'Central', 'Yell'    => 'Central', 
		'Perry'     => 'Central', 'Faulkner'  => 'Central', 'White'  => 'Central', 'Saline'    => 'Central', 'Pulaski' => 'Central', 
		'Lonoke'    => 'Central', 'Prairie'   => 'Central', 'Grant'  => 'Central', 
		
		'Howard'    => 'Southwest', 'sevier'    => 'Southwest', 'Little River' => 'Southwest', 'Hempstead' => 'Southwest', 
		'Miller'    => 'Southwest', 'Lafayette' => 'Southwest', 'Nevada'       => 'Southwest', 'Columbia'  => 'Southwest', 
		'Ouachita'  => 'Southwest', 'Calhoun'   => 'Southwest', 'Union'        => 'Southwest', 
		
		'Lee'       => 'Southeast', 'Monroe'  => 'Southeast', 'Phillips' => 'Southeast', 'Arkansas'  => 'Southeast', 
		'Jefferson' => 'Southeast', 'Lincoln' => 'Southeast', 'Desha'    => 'Southeast', 'Cleveland' => 'Southeast', 
		'Drew'      => 'Southeast', 'Bradley' => 'Southeast', 'Ashley'   => 'Southeast', 'Chicot'    => 'Southeast', 
		'Dallas'    => 'Southeast', 
		
		'Montgomery' => 'South Central', 'Pike' => 'South Central', 'Garland' => 'South Central', 'Hot Springs' => 'South Central', 
		'Clerk'      => 'South Central'
	);

	$county = get_field($co_ID, 'CSW_MEMB_COUNTY');
	if (isset($county)) {
		if (!set_control_field ($co_ID, 'REGION', $county_region_map[$county], $user))
			return array(1,$co_ID.': Error - Cannot set REGION control field.');
		else
			return array(0,$co_ID.': REGION control field set to'.$county_region_map[$county].' for county '.$county);
	}
	return array(0,$co_ID.': Warning - Cannot set REGION control field. County not specified in ticket or not in county list.');
}

/**
 *
 *  
 *
 *
 */     
function ods_member($post=array(), $user='') {

  //  Remove leading and trailing ()'"
  $odsMemberFieldName = preg_replace( '/(^\s*\(\s*)["\']*|["\']*(\s*\)\s*$)/', '', $post['codeparams'] );

  if( empty( $odsMemberFieldName ) )
      return( array( 1, 'ODS Member ID field not provided.' ) );
  
  $odsMemberID = get_field($post['fco_ID'], $odsMemberFieldName );
  if( empty( $odsMemberID ) )
      return( array( 0, 'ODS Member ID field is empty - no ODS lookup performed.' ) );
  
  //  Call web services
  require_once( 'soapClientClasses.php' );
  
  $odsMember = new odsMemberSoapClientClass();

  //  Get member info
  $ret = $odsMember->MemberExtended( array( 
       'cntIdNbr'   => substr( $odsMemberID, 0, -2 ), 
       'mbrIdNbr'   => $odsMemberID, 
       'coIdNbr'    => 'U' ) );
  if( empty( $ret->MemberExtendedResult->ExtMemberRec ) )
      return( array( 0, 'Member not found.' ) );
      
  //  Build fields array    
  $fields = array();
  foreach( (array)$ret->MemberExtendedResult->ExtMemberRec as $key => $value ) {
    $fv = trim( $value );
    if( ! empty( $fv ) )
        $fields[ "ODS_$key" ] = $fv;  
  }
  //  Get contract address info
  $ret = $odsMember->ContractPhysicalAddr( array( 
       'cntIdNbr'   => substr( $odsMemberID, 0, -2 ), 
       'coIdNbr'    => 'U', 
       'srchDate'   => date('Y-m-d\T00:00:00') ) );
       
  //  Add contract_address to field info array     
  if( ! empty( $ret->ContractPhysicalAddrResult->ContractPhysAddrRec ) ) {
    foreach( (array)$ret->ContractPhysicalAddrResult->ContractPhysAddrRec as $key => $value ) {
      $fv = trim( $value );
      if( ! empty( $fv ) )
          $fields[ "ODS_CONT_$key" ] = $fv;
    }
  }

  //  Set field values (from array)
  if( ! empty( $fields )  ) {
  
    //  get queue info
    $recQueue = $_POST['fst_id'];
    
    //  Build Import Aliases if there are any
    $query = "select fi_Name, fa_group
              from tbl_field_associations
              join tbl_fields on fi_ID = fa_fi_ID and fa_Active = 'y'
              join tbl_statuses on fa_ct_ID = st_ct_ID and st_Active = 'y'
              where st_ID = $recQueue
                    and fa_Group like '%:alias=%'";
    $res = myload( $query );

    //  Check to see if there are field aliases for this workflow 
    $alias = array();
    if( ! empty( $res ) ) {
      foreach( $res as $row ) {
        preg_match( '/:alias=([^:]+):/i', $row['fa_group'], $matches );
        if( ! empty( $matches ) )
            $alias[ $matches[1] ] = $row['fi_Name'];     
      }
    }

    //  Add Field
    foreach( $fields as $name => $value ) {

      //  Reformat data if needed
      switch( $name ) {
        case "ODS_MBR_BIRTH_DT":
        case "ODS_MBR_ORIG_EFF_DT":
        
           // eat dates that are obviously invalid
           if( substr( $value, 0, 8) == '1/1/0001' )
              continue(2);
              
           $value = date( 'Y-m-d', strtotime( $value ) );
      }
      
      if( empty( $alias[ $name ] ) ) {
      
        //  Skip field if the field value is already set 
        if( get_field( $post['fco_ID'], $name ) != '' )
            continue;
            
        put_field( $post['fco_ID'], $name, $value, $user );
      } else {
       
        //  Skip field if the field value is already set 
        if( get_field( $post['fco_ID'], $alias[ $name ] ) != '' )
            continue;
            
        put_field( $post['fco_ID'], $alias[ $name ], $value, $user );
      }
    }
  }

  return( array( 0, 'ODS Lookup Complete' ) );
}


?>
