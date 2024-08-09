<?php
require_once (dirname(__FILE__).'/../includes/queue_pre_post.php'); // pre and post code functions
 
/*
 * 
 * Details on status queue change flow:
 * http://pbsipower/wiki/pmwiki.php?n=PowerDoc.QueueChangeFlow
 * 
 */

function set_corr_status ($co_id='', $st_id='', &$comment='', &$post=array(), $username='', $disablepostcode=false, $disableprecode=false, $disableinitcode=false, $disablenotify=false, $disablerequired=false, $source='u')
{
  global $config;
	// ini_set("max_execution_time", 120);
	// set_time_limit(120);

  // $st_id is the status id to move the $co_id INTO
  // $post is used by pre/post code
  $ret_msg = '';
  $co_id = trim($co_id);
	$st_id = trim($st_id);
	
  if ($co_id=='0' || (!is_numeric($co_id)))
    return array(false, "No record ($co_id).");
    
  if (!is_numeric($st_id)) 
    return array(false, "Invalid status queue ($st_id).");

  // get our current st_id for this co_id
  $cur_st_id = get_current_status($co_id);
  
  // load up post with some common values (used by pre/post functions)
  if (!isset($post['fco_ID']))
    $post['fco_ID'] = $co_id;
  if (!isset($post['fst_id']))
    $post['fst_id'] = $st_id;
  if (!isset($post['fComments']))
    $post['fComments'] = $comment;

  $st_Names = get_st_Names(); // used in logging and notification email
  $st_Descs = get_st_Descriptions();

  if (($username == '') && (isset($_SESSION['username'])))
    $username = $_SESSION['username'];
	else if ($username == '')
    $username = 'POWER';
		
  if ( (isset($post['fqname'])) && ($post['fqname'] != '') && (isset($st_Names[$cur_st_id])) ) 
	{
    // make sure its not a browser reload, or that this co_id was moved already
    if ($st_Names[$cur_st_id] != $post['fqname'] ) 
      return array(false, "Record ($co_id) is currently not in ".$post['fqname'].". Status queue change failed.<br>Record ($co_id) is in ". $st_Names[$cur_st_id]."<br>&nbsp;<br>");      
  }

  // this co_id isn't currently in any status queue - maybe *new* or an error
  if ($cur_st_id == '')
    return array(false, "Record ($co_id) is currently not in any status queue. Status queue change failed.<br>&nbsp;<br>");

  // can't set_corr_status to same status already in
  if ($cur_st_id == $st_id)
    return array(false, "Record ($co_id) already in ".$st_Names[$st_id].". Status queue change failed.<br>&nbsp;<br>");

	$orig_st_Name = '';
	$orig_st_Desc = '';

	if ($cur_st_id != 0) // if not in initial status
	{
		if (isset($st_Names[$cur_st_id]))
		  $orig_st_Name = $st_Names[$cur_st_id];
		if (isset($st_Descs[$cur_st_id]))
		  $orig_st_Desc = $st_Descs[$cur_st_id];
	}

	/* -- check if setting to a closed status queue and we must check required field  $disablerequired -- */
	if ( ($cur_st_id != 0) && (!$disablerequired) )
	{
		$required_fields = get_status_closed_required_field($cur_st_id,$st_id, $co_id);

		if (isset($required_fields[0]) && ($required_fields[0] == false) )
		{
			if (isset($required_fields[1]))
				return array(false, $st_Names[$cur_st_id] . " <b>required fields not populated.</b><br><pre>".htmlspecialchars($required_fields[1])."</pre><font color=red>Status change process halted.</font><br>&nbsp;<br>");
			else
				return array(false, $st_Names[$cur_st_id] . " <b>required fields not populated.</b><br><font color=red>Status change process halted.</font><br>&nbsp;<br>");
		}
		else if (isset($required_fields[1]) && ($required_fields[1] != '') )
		{
			$ret_msg .= $st_Names[$cur_st_id] . ' ' . $required_fields[1] . '<br>';
		}
	}

  /* --- curr status: post-code -- */
	$post_code = false;
	if ($cur_st_id != 0) // if not in initial status
	  $post_code = get_status_post_code($cur_st_id);

	if (($disablepostcode == true) && ($post_code)) // if POST code is disabled (mass move admin option)
		$post_code = false;
		
  if (($cur_st_id != 0) && ($post_code))
	{
    $pcode = exec_prepost_code($post_code, $post); // execute post code - if failed then we need to STOP!
    if (!$pcode[0]) 
      return array(false, $st_Names[$cur_st_id] . " <b>post-code ($post_code) failed.</b><br><pre>".htmlspecialchars($pcode[1])."</pre><font color=red>Status change process halted.</font><br>&nbsp;<br>");
		else 
      $ret_msg .= $st_Names[$cur_st_id] . " post-code successful: <br><pre>".htmlspecialchars($pcode[1])."</pre><br>";

    // if post-code changed current status then clean stop current change
    $post_cur_st_id = get_current_status($co_id);
    if ($post_cur_st_id != $cur_st_id) 
		{
			$ret_msg .= $st_Names[$cur_st_id] . " post-code changed status ".$st_Names[$post_cur_st_id]." ($post_cur_st_id)<br>";
		  return array(true, $ret_msg, $post_cur_st_id, $st_Names[$post_cur_st_id]); // return the st_id and its name
    }
  }

	/* -- new status: pre-code ** before setting new status ** -- */
  $pre_code = get_status_pre_code($st_id);

	if (($disableprecode == true) && ($pre_code)) // don't run first status pre-code if disable (mass move admin)
		$pre_code = false;

	if ($pre_code) 
	{
		$pcode = exec_prepost_code($pre_code, $post);
		if (!$pcode[0]) 
			return array(false, $ret_msg . $st_Names[$st_id] . " <b>pre code ($pre_code) failed.</b><br><pre>".htmlspecialchars($pcode[1])."</pre><font color=red>Status change process halted.</font><br>&nbsp;<br>");
		else 
			$ret_msg .= $st_Names[$st_id] . " pre-code successful: <br><pre>".htmlspecialchars($pcode[1])."</pre>";

		// if pre-code changed current status then clean stop current change
		$pre_cur_st_id = get_current_status($co_id);
		if ($pre_cur_st_id != $cur_st_id) 
		{
			$ret_msg .= $st_Names[$st_id] . " pre-code changed status ".$st_Names[$pre_cur_st_id]." ($pre_cur_st_id)<br>";
		  return array(true, $ret_msg, $pre_cur_st_id, $st_Names[$pre_cur_st_id]); // return the st_id and its name
    }
  }

  if (function_exists('filter_fancy_characters')) // remove MS Word special chars
	 $comment = filter_fancy_characters($comment);

	/* -- set new status queue -- */
  /*
   * Lets try calling this sp up to 3  times, (only if needed), to avoid pesky locks  
   * POWER #1471498
   * Jeff
   */
  $numTries = 0;
  $sprs = 1; // default to errror, we need a successfull return message
  while (($sprs == 1) && ($numTries < 4)) {
  	$spsql="CALL sp_change_queue (".sql_escape_clean($co_id).",".sql_escape_clean($st_id).",".sql_escape_clean($comment).",".sql_escape_clean($username).",'".date("Y-m-d G:i:s")."','$source')";
  	$sprs=myload($spsql);
	$numTries ++;
        if (($sprs ==1) && ($numTries < 4)) {
                error_log("SQL SP ERROR: ($spsql) Sleeping for 20 seconds then try again");
		sleep(20); // error, wait and try again
	}
  }


  $prev_st_id = $cur_st_id;
  $cur_st_id = get_current_status($co_id); // this will make sure the DB is correct before continuing (in case write failed)
  if ( ($sprs[0][0]>0) || ($cur_st_id != $st_id) ) 
	{
    $ret_msg .= "Queue change unsuccessful: ".$sprs[0][1]."<br>";
    return array(false, $ret_msg);
  }
	 
  if ($prev_st_id == 0) 
	{
    if (isset($st_Names[$cur_st_id]))
      $ret_msg .= "Queue set to ".$st_Names[$cur_st_id]." successful.<br>";     
    else
      $ret_msg .= "Queue set to $cur_st_id successful.<br>";              
  } 
	else 
	{
    if ( (isset($st_Names[$prev_st_id])) && (isset($st_Names[$cur_st_id])) )
      $ret_msg .= "Status queue change from ".$st_Names[$prev_st_id]." to ".$st_Names[$cur_st_id]." successful.<br>Took $numTries try(s)";
    else
      $ret_msg .= "Status queue change from $prev_st_id $cur_st_id successful.<br>Took $numTries try(s)";
  }

	/* -- email notification -- */
  $notify = get_status_notification($cur_st_id);
	
	if (($disablenotify == true) && ($notify)) // don't run first status pre-code if disable (mass move admin)
		$notify = false;	
	
  if ($notify) 
	{
	  $http_host = '';
	  if ((isset($_SERVER['HTTP_X_FORWARDED_HOST'])) && ($_SERVER['HTTP_X_FORWARDED_HOST'] != '') ) // accessed via web + proxy
	    $http_host = $_SERVER['HTTP_X_FORWARDED_HOST'];
	  else if ((isset($_SERVER['HTTP_HOST'])) && ($_SERVER['HTTP_HOST'] != '') ) // accessed via web
	    $http_host = $_SERVER['HTTP_HOST'];
	  else if ((isset($config['HTTP_HOST'])) && ($config['HTTP_HOST'] != '') ) // local process (i.e. batch)
	    $http_host = $config['HTTP_HOST'];
	  else
	    return array(false, "Failed sending email notification - HTTP_HOST not defined in config file.\n");
	  
	  if ($config['HTTP_SSL'] == 'on')
	    $http_host = 'https://' . $http_host;
	  else 
	    $http_host = 'http://' . $http_host;		

		$ct_email_subjects='POWER';
		if ($st_id>0){
			$sql="SELECT ct_email_subjects
						FROM tbl_statuses
						INNER JOIN tbl_corr_types ON st_ct_ID=ct_ID
						WHERE st_ID=".$st_id;
			$ct_email_subjects_rs=myload($sql);
			if (count($ct_email_subjects_rs)>0){
				$ct_email_subjects=$ct_email_subjects_rs[0]['ct_email_subjects'];
			}
		}

		$primaryfield = get_primary_fieldvalue($co_id);
		if ($primaryfield)
		{
			$subject="POWER: ".$primaryfield['fa_Name']." ".$primaryfield['fv_Value']." moved to '".$st_Names[$cur_st_id]."' status queue [c=".$co_id."]";
		} else {
			$subject="New ".$ct_email_subjects." document in the '".$st_Names[$cur_st_id]."' status queue [c=$co_id]";
		}

		$msg = "POWER record ".$co_id." has just entered the '".$st_Descs[$cur_st_id] .' ('.$st_Names[$cur_st_id].")' queue";
		if (($orig_st_Name != '') && ($orig_st_Desc != ''))
			$msg .= "<br>from the '$orig_st_Desc ($orig_st_Name)' queue";

		$qcmsg = $msg;
		$msg.= ".<br><br>You may click the following link to view this record:<br><br>";
		$msg.= "<a href='".$http_host.$config['systemroot']."view_rec.php?co_ID=".$co_id."'>POWER Record ".$co_id."</a><br><br>";
		$msg.= "Contact the <a href='mailto:powersupport@pinnaclebsi.com'>POWER support</a> team if you no longer wish to receive notifications when records enter the ".$st_Names[$cur_st_id]." queue.";

		$txtmsg = "POWER record ".$co_id." has just entered the '".$st_Descs[$cur_st_id] .' ('.$st_Names[$cur_st_id].")' queue";
		if (($orig_st_Name != '') && ($orig_st_Desc != ''))
			$txtmsg .= " from the '$orig_st_Desc ($orig_st_Name)' queue";
		$txtmsg.= ". Contact the POWER support team if you no longer wish to receive notifications when records enter the ".$st_Names[$cur_st_id]." queue.";

		if ($notify != '@QCSUBEMAIL') // if its not just QCSUBEMAIL
		{
	    $recips = send_status_change_notification($notify, $subject, $msg, $txtmsg, $co_id);
  	  $ret_msg .= $st_Names[$cur_st_id] . " " . $recips;
		}
		
  	if (preg_match('/@QCSUBEMAIL/', $notify))
		{
    	// lookup our submitter
    	$ssres=myloadslave("SELECT fv_Value FROM tbl_field_values WHERE fv_fi_id=(SELECT fi_id FROM tbl_fields WHERE fi_name ='QCSUBEMAIL' LIMIT 1) AND fv_co_ID=$co_id LIMIT 1");
    	if ( (isset($ssres[0]['fv_Value'])) && (preg_match('/@/', $ssres[0]['fv_Value'])) )
			{
        $subemail = $ssres[0]['fv_Value'];
				$qcmsg.= ".<br><br>";
				$qcmsg.= "This email was sent to inform the submitter of the request the status has changed. If this was sent in error - please contact the <a href='mailto:powersupport@pinnaclebsi.com'>POWER support</a> team.";				

				// lookup original description				
    		$ebsql="SELECT fv_Value FROM tbl_field_values WHERE fv_fi_id=(SELECT fi_id FROM tbl_fields WHERE fi_name ='EBODY' LIMIT 1) AND fv_Value is not null AND fv_co_ID=$co_id LIMIT 1";
    		$ebres=myloadslave($ebsql);
				if (isset($ebres[0]['fv_Value']))
				{
					$qcmsg.= "<br><p>Issue description:</p><pre>";
					$qcmsg.= $ebres[0]['fv_Value'] . "\n</pre>";
				}
		    $recips = send_status_change_notification($subemail, $subject, $qcmsg, $txtmsg, $co_id);
    		$ret_msg .= " QCSUBEMAIL:" . $recips;
			}
		} // end @QCSUBEMAIL
  } // end $notify

	/* -- auto-forward -- */
  $af_st_id = get_status_auto_forward($cur_st_id);
	if ($af_st_id)
	{
		if ($af_st_id == $cur_st_id) 
		{
	    // Auto-forward unsuccessful. Cannot forward a status to itself.  HALT!
			if (isset($st_Names[$af_st_id]))
	     $ret_msg .= "Auto-forward unsuccessful. Cannot forward a status to itself. ".$st_Names[$af_st_id]."<br>";
			else
			 $ret_msg .= "Auto-forward unsuccessful. Cannot forward a status to itself. $af_st_id<br>";
	    return array(false, $ret_msg);
	  } 

    $thiscomment = "Auto-forward to " . $st_Names[$af_st_id];

		$new_post = $post;
    $new_post['fst_id'] = $af_st_id;
    $new_post['fComments'] = $thiscomment;
		$new_post['fqname'] = '';
		$afscs = set_corr_status ($co_id, $af_st_id, $thiscomment, $new_post, $username, $disablepostcode, $disableprecode, $disableinitcode, $disablenotify, $disablerequired);

		if ($afscs[0] == true)
		{
      $ret_msg .= "<font color='red'>Record <a href=\"view_rec.php?co_ID=".$co_id."\">".$co_id."</a> Auto-Forwarded From ".$st_Names[$cur_st_id]." Queue To ";
      $ret_msg .= "<a href='view_status_queue.php?st_ID=$af_st_id'>".$st_Names[$af_st_id]."</a> Queue</font><br>";
			$ret_msg .= $afscs[1];
			$final_st_id = get_current_status($co_id);
      return array(true, $ret_msg, $cur_st_id, $st_Names[$final_st_id]);
		}
		else
		{		
      $ret_msg .= $thiscomment . ': ' . $afscs[1] . "<br>";
      return array(false, $ret_msg);
    }
  } // end auto-forward block	

	/* -- init code -- */
  $init_code = get_status_init_code($cur_st_id);
	if (($disableinitcode == true) && ($init_code)) // don't run first status pre-code if disable (mass move admin)
		$init_code = false;
		
	if ($init_code) 
	{
		$pcode = exec_prepost_code($init_code, $post);
		if (!$pcode[0]) 
			return array(false, $ret_msg . $st_Names[$cur_st_id] . " <b>init-code ($init_code) failed.</b><br><pre>".htmlspecialchars($pcode[1])."</pre><font color=red>Status change process halted.</font><br>&nbsp;<br>");
		else 
			$ret_msg .= $st_Names[$cur_st_id] . " init-code successful: <br><pre>".htmlspecialchars($pcode[1])."</pre>";
		
		// if init-code changed current status then clean stop current change
		$init_cur_st_id = get_current_status($co_id);
		if ($init_cur_st_id != $cur_st_id) 
		{
			if (isset($st_Names[$init_cur_st_id]))
			 $ret_msg .= $st_Names[$cur_st_id] . " init-code changed status ".$st_Names[$init_cur_st_id]." ($init_cur_st_id)<br>";
			else
			 $ret_msg .= $st_Names[$cur_st_id] . " init-code changed status ($init_cur_st_id)<br>";
		  return array(true, $ret_msg, $init_cur_st_id, $st_Names[$init_cur_st_id]); // return the st_id and its name
    }
  }
 
	if (isset($st_Names[$cur_st_id])){
		return array(true, $ret_msg, $cur_st_id, $st_Names[$cur_st_id]); // return the st_id and its name
	} else {
		$ret_msg.=' st_Name not found for cur_st_ID '.$cur_st_id;
		return array(true, $ret_msg, $cur_st_id, ''); // return the st_id and its name
	}
} // end function set_corr_status

function get_current_status($co_id='') 
{
  $cqrs=myload("SELECT co_queue FROM tbl_corr WHERE co_ID=$co_id");
  if ( (isset($cqrs[0]['co_queue'])) && ($cqrs[0]['co_queue'] != '') ) 
	{
    if (is_numeric($cqrs[0]['co_queue'])) 
      return($cqrs[0]['co_queue']);
  }
  return('');
}

/**
 * Quickly return the permissions on a combination of user, status and group category
 *
 * @param  string         - Power User ID or networkID
 * @param  string         - Power Status (queue)
 * @param  string         - Group catgory (from security group setup) 
 * @return string         - Comma seperated list of security options (S/W/R or none)
 */
function check_group_category( $user, $status, $category ) {

  $query = "select group_concat( DISTINCT p_Permission ) as permissions
            from tbl_permissions
            join tbl_groups on g_ID=p_g_ID
            join tbl_members on m_Type = 'g' and m_child_ID=g_ID
            join tbl_statuses on p_st_ID=st_ID
            join tbl_users on m_parent_ID=uID
            where (uNetID = '$user' or uUsername = '$user')
                  and st_Name = '$status'
                  and g_Category like '%:$category:%'
                  group by st_ID";
                  
  $ret = myload( $query );
  
  if( empty( $ret ) )
      return( '' );
  else
      return( $ret[0]['permissions'] );                  
}

/*  */
function get_status_post_code($st_id='') 
{
	if ($st_id == '')
		return false;
  $qc=myloadslave("SELECT st_Post_Code FROM tbl_statuses WHERE st_ID=$st_id AND st_Active='y' LIMIT 1");
  if ( (isset($qc[0]['st_Post_Code'])) && ($qc[0]['st_Post_Code'] != '') ) 
    return ($qc[0]['st_Post_Code']);
  return(false);
}

function get_status_pre_code($st_id='') 
{
  if ($st_id == '') 
		return false;
  $qc=myloadslave("SELECT st_Pre_Code FROM tbl_statuses WHERE st_ID=$st_id AND st_Active='y' LIMIT 1");
  if ( (isset($qc[0]['st_Pre_Code'])) && ($qc[0]['st_Pre_Code'] != '') ) 
    return ($qc[0]['st_Pre_Code']);
  return(false);
}

function get_status_init_code($st_id='') 
{
  if ($st_id == '') 
		return false;
  $qc=myloadslave("SELECT st_Init_Code FROM tbl_statuses WHERE st_ID=$st_id AND st_Active='y' LIMIT 1");
  if ( (isset($qc[0]['st_Init_Code'])) && ($qc[0]['st_Init_Code'] != '') ) 
    return ($qc[0]['st_Init_Code']);
  return(false);
}

function get_status_auto_forward($st_id='') 
{
  if ($st_id == '')
		return false;
  $af=myloadslave("SELECT st_Forward_To FROM tbl_statuses WHERE st_ID=$st_id AND st_Active='y' LIMIT 1");
  if ( (isset($af[0]['st_Forward_To'])) && ($af[0]['st_Forward_To'] != '') && (is_numeric($af[0]['st_Forward_To'])) && ($af[0]['st_Forward_To'] != '0') ) 
    return ($af[0]['st_Forward_To']);
  return(false);
}

function get_status_notification($st_id='') 
{
  if ($st_id == '') 
		return false;
  $af=myloadslave("SELECT st_NotifyOnImport FROM tbl_statuses WHERE st_ID=$st_id AND st_Active='y' LIMIT 1");
  if ( (isset($af[0]['st_NotifyOnImport'])) && ($af[0]['st_NotifyOnImport'] != '') )
    return ($af[0]['st_NotifyOnImport']);
  return(false);
}

function get_primary_fieldvalue($co_ID)
{
	if ($co_ID == '')
		return false;
	$primaryfieldrs = myloadslave("
		SELECT fa_Name,fv_Value
		FROM tbl_corr
		INNER JOIN tbl_statuses ON co_queue=st_ID
		INNER JOIN tbl_field_associations ON st_ct_ID=fa_ct_ID
		INNER JOIN tbl_field_values ON fv_fi_ID=fa_fi_ID AND fv_co_ID=co_ID
		WHERE co_ID=".$co_ID."
			AND fa_Active='y'
			AND fa_Group LIKE '%:primary:%'
		LIMIT 1");
	if (isset($primaryfieldrs[0]['fa_Name']) && 
			isset($primaryfieldrs[0]['fv_Value']) && 
			$primaryfieldrs[0]['fa_Name'] <> ''  &&
			$primaryfieldrs[0]['fv_Value'] <> '')
	{
		return $primaryfieldrs[0];
	} else {
		return false;
	}
}

function get_status_closed_required_field($cur_st_id='',$st_id='',$co_id='')
{
	// if current status is NOT a closed and new status IS closed - going from closed-to-closed don't check
	// then check if there are any required fields
	// then check that the corr has the necessary fields populated
  if ($cur_st_id == '') 
		return array(false,'Current status was not provided in call to get_status_closed_required_field.');

  if ($st_id == '') 
		return array(false,'Status was not provided in call to get_status_closed_required_field.');

  if ($co_id == '') 
		return array(false,'Record # was not provided in call to get_status_closed_required_field.');
	
	$fields = array();

  $qgrp=myloadslave("SELECT st_Group FROM tbl_statuses WHERE st_ID=$cur_st_id AND st_Active='y' LIMIT 1");	
  if ( (isset($qgrp[0]['st_Group'])) && (preg_match('/:cl:/',$qgrp[0]['st_Group']) ) ) // already in a closd - don't check
		return array(true,'');

  $qgrp=myloadslave("SELECT st_ct_ID, st_Group FROM tbl_statuses WHERE st_ID=$st_id AND st_Active='y' LIMIT 1");	
  if ( (!isset($qgrp[0]['st_Group'])) || (!preg_match('/:cl:/',$qgrp[0]['st_Group']) ) ) // not closing
  	return array(true,'');

	$ct_id = '';
	if ( !isset($qgrp[0]['st_ct_ID']) || !is_numeric($qgrp[0]['st_ct_ID']) ) 
		return array(false,"No workflow type ID found for this status id [$st_id]");
	$ct_id = $qgrp[0]['st_ct_ID'];
	
	// so we are moving to a closed status - see if there are any required fields for this status
	$qgrp = myloadslave("SELECT fa_fi_ID, fa_Name FROM tbl_field_associations WHERE fa_ct_ID=$ct_id AND fa_Required='y' AND fa_Active='y'");
		
	if (count($qgrp) == 0)
		return array(true,''); // no fields are set to be required - return true

	$reqf_cnt = count($qgrp);
	$missing = array();
	for($i=0;$i<$reqf_cnt;$i++) // step through the required fields and check that each one is populated for this record
	{
		if (isset($qgrp[$i]['fa_fi_ID']) && ($qgrp[$i]['fa_fi_ID'] != ''))
		{
			$fi_ID = $qgrp[$i]['fa_fi_ID'];
			$fext = myload("SELECT fv_Value FROM tbl_field_values WHERE fv_co_ID=$co_id AND fv_fi_ID=$fi_ID");
			
      $fcnt = count($fext);
      if ($fcnt == 0) // no field values in table
      {
        $missing[] = $qgrp[$i]['fa_Name'];
      }
      else if ( ($fcnt == 1) && (isset($fext[0][0])) && (trim($fext[0][0]) == '') ) // single value but actually blank
      {
				$missing[] = $qgrp[$i]['fa_Name'];
      }
      else if ($fcnt > 1) // multi-value - check that there is 1 that is not blank
      {
        $got1 = false;
        for ($k=0;$k<$fcnt;$k++)
        {
          if (trim($fext[$k][0]) != '')
          {
            $got1 = true;
            break;
          }
        }
        if (!$got1)
          $missing[] = $qgrp[$i]['fa_Name'];

      }
		}
	}

	if (count($missing) == 0)
		return array(true,'Required fields are populated to close record.');
	
	return array(false,'The following required fields are not populated: ' . implode(', ', $missing));
}

function get_st_Names() /* could be moving from an inactive status (admin doing a mass move) so include active and inactive statuses */
{
  $nms=myloadslave("SELECT st_ID, st_Name FROM tbl_statuses");
  $ret = array();
  for ($i=0;$i<count($nms);$i++) 
	{
    $ret[$nms[$i]["st_ID"]] = $nms[$i]["st_Name"];
  }
  return ($ret);
} 

function get_st_Descriptions($active='y') 
{
  $nms=myloadslave("SELECT st_ID, st_Desc FROM tbl_statuses WHERE st_Active='$active'");
  $ret = array();
  for ($i=0;$i<count($nms);$i++) 
	{
    $ret[$nms[$i]["st_ID"]] = $nms[$i]["st_Desc"];
  }
  return ($ret);
}

function exec_prepost_code($code='', &$post=array()) 
{
  if ($code == '') 
    return array(true,"No code to run ($code).");

  // handle multiple functions in pre/post/init code separate by semi-colon
	//  a function may be:
	//     funcname
	//     funcname(parmvals)
	//     funcname(parmvals);
	//     funcname(parmvals);funcname2(parmvals2);  ... and so on.

  $code = trim($code);
  $retval=1; // 1 is bad, 0 is good for these functions
  $retmsg='';

  /**
   *   Switch to new pre/post calling methodology
   *
   *   The format is:
   *   
   *      func1("parm1","parm2","etc");func2("parm1","parm2","etc");
   *      
   *   The $post('codeparams') will contain an array ( parm1, parm2, parm3 )      
   */           
  if( strpos( $code, '"' ) !== FALSE ) {

    //  Get List of functions to call
    $fctns = str_getcsv( $code, ';' );
    foreach( $fctns as $fctn ) {
    
      //  Get function name
      $fName = trim( substr( $fctn, 0, strpos( $fctn, '(') ) );
      
      if( empty( $fName ) )
          continue;
      
      //  Make sure function exists
      if( ! function_exists( $fName ) ) 
          return array(false, "Missing function ($fName).");
      
      //  Get parameters to send to call
      $post['codeparams'] = str_getcsv( substr( $fctn, strpos( $fctn, '(')+1, -1 ), ',' ); 

      //  Call function with parm array ($post)
			$code_ret = $fName( $post );

      if( isset($code_ret[1]) )
          $retmsg .= $code_ret[1] . "\n";
        
      //  Check result
		  if ( (isset($code_ret[0])) && ($code_ret[0] == 1) )  // bad - return now
          return array(false, $retmsg);
    }
    return array(true, $retmsg);
  }
   
  // Otherwise use old logic  
  if( preg_match_all('/([A-z0-9_]+(\([^\)]*\)){0,1};{0,1}\s{0,1})/i', $code, $sfunc, PREG_SET_ORDER) )
  {
    for($i=0;$i<count($sfunc);$i++)
    {
      if ( (preg_match('/^([A-z0-9]+)(\(.*\));{0,1}$/i', trim($sfunc[$i][0]), $matches)) ||
        (preg_match('/^([A-z0-9]+);{0,1}$/i', trim($sfunc[$i][0]), $matches)) )
      {
        $params = '';
        $code = $matches[1];
        if (isset($matches[2]))
          $params = $matches[2];
        $params = str_replace(', ',',',$params); // strip any spaces between commas
        $post['codeparams'] = $params;
        $code = str_replace(' ','',$code); // strip any spaces in function name

        if (!function_exists($code)) 
          return array(false, "Missing function ($code).");

			  $code_ret=$code($post);

			  if ( (isset($code_ret[0])) && ($code_ret[0] == 1) )  // bad - return now
				{
          if (isset($code_ret[1]))
            $retmsg .= $code_ret[1];
					return array(false, $retmsg);
				}
				
				if (isset($code_ret[0])) // good - set value as good
			    $retval = $code_ret[0];
        if (isset($code_ret[1]))
          $retmsg .= $code_ret[1] . "\n";
      }
    }
  }
  
  return array(true, $retmsg);
}

function send_status_change_notification($recipients,$subject="POWER",$msg="POWER Notification",$txtmsg="POWER Notification",$coID="0")
{
	global $config;
  // send email notification of new documents
  // if it contains @QCSUBEMAIL email the submitter
  if (preg_match('/@QCSUBEMAIL/', $recipients)) 
	{
    $recipients = preg_replace('/@QCSUBEMAIL/', '', $recipients);
    $recipients = preg_replace('/,,/', ',', $recipients);		
  }
  $recipients = preg_replace('/^,/', '', $recipients);		  
  $recipients = preg_replace('/,$/', '', $recipients);		  
  $recipients = preg_replace('/^;/', '', $recipients);		  
  $recipients = preg_replace('/;$/', '', $recipients);		  

	if ($recipients=='')
		return('');

  // fv_Username may be SA which and we may need to send a different message
  $recipients=preg_replace('/,/',';',$recipients);
  $recarr=preg_split('/;/',$recipients);

	$from=$config["contact_email"];
	$fromname=$config["contact_name"];
  
  require_once (dirname(__FILE__).'/phpmailer/class.phpmailer.php');
  $mail = new PHPMailer();
  $mail->IsSMTP();
  $mail->Host="mail.abcbs.net";
  $mail->From=$from;
  $mail->FromName=$fromname;
	$recipients.=' (';
	$retmsg='';
  for ($x=0;$x<count($recarr);$x++)
	{
    // allow notification to email address found in a particular fieldvalue if address starts with @ and a coID was passed in
    if (substr($recarr[$x],0,1)=="@" && intval($coID)>0)
		{
      $fiidrs=myloadslave("SELECT fi_ID FROM tbl_fields WHERE fi_Name=".sql_escape_clean(substr($recarr[$x],1))." LIMIT 1");
      if (count($fiidrs)>0 && $fiidrs[0][0]>0)
			{
        $fvrs=myload("SELECT fv_value FROM tbl_field_values WHERE fv_fi_ID=".$fiidrs[0][0]." AND fv_co_ID=$coID LIMIT 1");
        if (count($fvrs)>0 && $fvrs[0][0]!="")
				{
					// get the name of this field association so that we can explain in the email why this recipient was added
					$fva=substr($recarr[$x],1);
					$fvars=myloadslave("SELECT fa_Name FROM tbl_field_associations INNER JOIN tbl_statuses ON fa_ct_ID=st_ct_ID INNER JOIN tbl_corr ON co_queue=st_ID WHERE co_ID=".$coID." AND fa_fi_ID=".$fiidrs[0][0]);
					if (count($fvars)>0 && $fvars[0][0]<>''){
						$fva=$fvars[0][0];
					}
					// see if this is an email address
          if (stristr($fvrs[0][0],"@")){
						// it looks like an email address, we'll go with it
						$recarr[$x]=$fvrs[0][0];
						$retmsg.=' - found '.substr($recarr[$x],1).' value: '.$recarr[$x].'.';
						$msg.='<br>You are receiving this message because POWER ticket '.$coID.' has your email address in its "'.$fva.'" field.';
					} else {
						// not an email address, see if it's a user
						$userrs=myloadslave("SELECT uEmail from tbl_users where uUsername=".sql_escape_clean($fvrs[0][0])." OR uNetID=".sql_escape_clean($fvrs[0][0])." AND uActive='y'");
						if (count($userrs)>0 && $userrs[0][0]<>''){
							// it appears to be an active user
							// set this recipient to the desired fieldvalue for this record
							$recarr[$x]=$userrs[0][0];

							// add @pinnaclebsi.com if no @ is found
							if (!stristr($recarr[$x],"@"))
								$recarr[$x].="@pinnaclebsi.com";
							$retmsg.=' - found '.$userrs[0][0].' user, using email address: '.$recarr[$x].'.';
							$msg.='<br>You are receiving this message because POWER ticket '.$coID.' has your username in its "'.$fva.'" field.';
						} else {
							$retmsg.=' - found field '.substr($recarr[$x],1).' but could not find valid user or email for its value '.$fvrs[0][0].'.';
						}
					}
        } else {
					$retmsg.=' - no value found for control field '.substr($recarr[$x],1).'.';
				}
      } else {
				$retmsg.=' - control field '.substr($recarr[$x],1).' unrecognized.';
			}
    }
    $mail->AddAddress($recarr[$x]);
		$recipients.=$recarr[$x].' ';
  }
	$recipients.=')';


	if($config["system_short_name"]<>'POWERTEST'){
		$mail->ClearAddresses();
		$mail->ClearCCs();
		$mail->AddAddress("powertest@pinnaclebsi.com");
		$mail->AddAddress("jsweiss@pinnaclebsi.com");
		$mail->AddAddress("pnshaver@pinnaclebsi.com");
		$mail->AddAddress("jmmaxwell@pinnaclebsi.com");
		$mail->AddAddress("mjquirijnen@pinnaclebsi.com");
		$recipients = "The POWER team";
  }

  $mail->WordWrap=50;
  $mail->Subject=$subject;
  $mail->Body=$msg;
  $mail->AltBody=$txtmsg;

  if (!$mail->Send())
    return "<font color='red'>email notification failed. ".$mail->ErrorInfo.':'.implode(', ',$recarr)."</font><br>".$retmsg."<br>";
	else 
    return "email notification sent to: $recipients<br>".$retmsg."<br>";
}
?>
