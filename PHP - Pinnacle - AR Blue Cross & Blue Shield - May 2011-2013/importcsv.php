<?php 
/*
 * this program imports new POWER records from a CSV file
 * the data is expected to be in the following format:
 *
 * "LASTNAME","FIRSTNAME","HIREDT","RNCCMCDT","RNCCMCPSV","LICENSE","DIVERSITYDT","EDULOG","LICPSV","SUPDOC","LICENSERENEW|LICSTATE:LICEXP"
 * "ADAMS"," BS","2000-01-10","","No","LPN","2010-08-19","Yes","No","select-multi:|RESUME/CV|Driver's License|Job Description|Evidence of Orientation of EMM","group:|AR:2012-05-31"
 * "ANASTASI"," JG","2007-01-03","","No","LPN","2010-08-19","Yes","No","select-multi:|RESUME/CV|Driver's License|Evidence of Orientation of EMM","group:|AR:2011-09-30|TN:2011-09-30"
 * "ARCHER"," JL","2010-03-29","","No","RN","2010-08-19","Yes","Yes","select-multi:|RESUME/CV|Driver's License|Job Description|Evidence of Orientation of EMM","group:|AR:2012-03-31|AK:2012-11-30"
 * "BAKER"," BM","2011-02-07","","No","RN","1900-01-00","No","Yes","select-multi:|RESUME/CV|Driver's License|Job Description|Evidence of Orientation of EMM","group:|AR:2011-09-30|IL:2012-05-31|MN:2013-09-30"
 * "BARTON"," KK","2009-02-17","2015-11-20","Yes","RN","2010-11-16","Yes","Yes","select-multi:|RESUME/CV|Driver's License|Job Description|Evidence of Orientation of EMM","group:|AR:2013-01-31|TN:2013-01-31|MO:2011-04-30|WA:2013-01-02|NV:2013-01-02|CA:2013-02-28"
 *
 * that example includes select-multi fields, date fields, and group fields.
 * The first row is a header row that contains the control field names. Make note that group field header row names are a little different, as described below.
 * ALL fields should be enclosed in double quotes, and separated by a comma.
 * Any double quotes should be escaped with a backslash.
 * Dates should be in YYYY-MM-DD format
 * select-multi fields should be in the following format:
 *    "select-multi:|value1|value2|value3"
 * group fields should be in the following format:
 *    "group:R1V1:R1V2|R2V1:R2V2|R3V1:R3V2"
 * group fields should have a header label in the following format:
 *    "GROUPFIELDNAME|SUBFIELDNAME1:SUBFIELDNAME2"
 *
 * alternative group field format:
 * group field header format:
 *		"GROUPFIELDNAME|SUBFIELDNAME|ROWNO"
 * group field data row format:
 *		"xxxxx" (simply contains data value)
 * so, each group subfield gets its own column in the file. The various rows are identified in the header.
 *
 * override the receive date by naming a field co_receivedate
 *
 * test usage :
 * http://lrd1pwrdev/nick/utils/importcsv.php?queue=ABCBS_BPTA_REC&ifile=/tmp/import-09-14.txt
 *
 * create database records or production usage (change host part) :
 * http://lrd1pwrdev/nick/utils/importcsv.php?queue=ABCBS_BPTA_REC&ifile=/tmp/import-09-14.txt&mode=prod
 *
 * one-off processing can be invoked via profile=xxxx
 * valid profiles so far:
 *  - qmcso
 *     - process for qmcso files
 *     - dynamically changes import queue depending upon filename
 *     - dynamically remaps column #1 for mapped technical field names
 *
 * See $_GET params for URL options
 *
 */
	ini_set('memory_limit', '2048M');
	set_time_limit(3600);

require_once (dirname(__FILE__)."/../includes/config.inc");
require_once (dirname(__FILE__)."/../includes/dbfunctions.inc");
//require_once (dirname(__FILE__)."/../includes/menuhead.inc"); 
require_once (dirname(__FILE__)."/../includes/status.inc.php"); 
require_once (dirname(__FILE__).'/../includes/xml_functions.php');
require_once (dirname(__FILE__)."/../includes/document_repository.inc.php");
require_once (dirname(__FILE__)."/../includes/filecache.inc");
require_once (dirname(__FILE__)."/../includes/mimetypes.inc");

// get $_GET via http $_GET or via command-line arguments
	$www=false; if (isset($_SERVER['QUERY_STRING'])) $www = true;
	if ($www){
	} else {
		$_GET=arguments($_SERVER['argv']);
	}

// define import variables
	$mode='test';if (isset($_GET['mode']) && $_GET['mode'] == 'prod') $mode='prod';
	$processrecords=0;if (isset($_GET['recs'])) $processrecords=$_GET['recs'];
	$sortkey='';if (isset($_GET['sort'])) $sortkey=$_GET['sort'];
	$USERNAME='POWER';if (isset($_SESSION['username'])) $USERNAME=$_SESSION['username'];
	$profile='';if (isset($_GET['profile'])) $profile=$_GET['profile'];
	$archive_queue='';
	$ret='';

// allow profile to override import variables
	if ($profile=='qmcso'){
		// qmcso one-off processing
		// set import queue based on qm records, but it may change later via hardcode depending on filename
		$st_Name='BANA_MEMB_QMCSO_QM_REC';
		$dest_st_ID=get_st_ID($st_Name);

		$pm_st_Name='BANA_MEMB_QMCSO_PM_REC';
		$pm_dest_st_ID=get_st_ID($pm_st_Name);

		if (!is_numeric($dest_st_ID) || $dest_st_ID==0) die ('destination queue is not valid: '.$dest_st_ID);
		$ret.=page_log('Importing with QMCSO profile. Import queue determined by CSV filename.');

		// set source and archive queues
		$_GET['iqueue']='BANA_IMPORT_QMPM';
		$_GET['archivequeue']='BANA_IMPORT_CL_QMPM';
	} elseif ($profile=='milliman_prenote'){
		// milliman->prenote one-off processing
		// set import queue based on qm records, but it may change later via hardcode depending on filename
		$st_Name='BANA_PRE_NOTE_NN4';
		$dest_st_ID=get_st_ID($st_Name);

		if (!is_numeric($dest_st_ID) || $dest_st_ID==0) die ('destination queue is not valid: '.$dest_st_ID);
		$ret.=page_log('Importing with '.$profile.' profile.');

		// set source and archive queues
		$_GET['iqueue']='BANA_IMPORT_MILLIMAN_PN';
		$_GET['archivequeue']='BANA_IMPORT_CL_MILLIMAN_PN';
	} else {
		// no valid profile stated, do generic import
		if (!isset($_GET['queue'])) {
			die("Queue name is required. Use --queue=MyQueueName to specify it.\n");
		}
		$st_Name = $_GET['queue'];
		$dest_st_ID=get_st_ID($st_Name);
		if (!is_numeric($dest_st_ID) || $dest_st_ID==0) die ('destination queue is not valid: '.$dest_st_ID);
		$ret.=page_log('Importing into queue '.$st_Name.' - '.$dest_st_ID);
	}

// gather input files into $ifile_arr
	$ifile_arr=array();
	if (isset($_GET['ifile'])) {
		// direct reference to a file on this machine
		$ifile = $_GET['ifile'];
		if (!file_exists($ifile)) die ('no input file found: '.$ifile);
	} else {
		if (isset($_GET['iqueue'])) {
			// reference to a queue that will hopefully have records with csv attachments
			$iqueue=$_GET['iqueue'];
			$i_st_ID=get_st_ID($iqueue);
			if ($i_st_ID>0) {
				$ret.=page_log('Importing from queue '.$iqueue.' ('.$i_st_ID.')');
				// found source queue, need to get records from that queue
				$sql="SELECT co_ID FROM tbl_corr WHERE co_queue=".$i_st_ID;
				$source_rs=myload($sql);
				if (count($source_rs)>0){
					// get all csv files from all records in source queue

					// see if we need to archive the tickets after importing its attachments
					if (isset($_GET['archivequeue'])){
						$a_st_ID=get_st_ID($_GET['archivequeue']);
						if ($a_st_ID>0){
							$archive_queue=$a_st_ID;
						}
					}

					$ret.=page_log('Scanning queue '.$iqueue);
					foreach ($source_rs as $srec){
						$ret.=page_log(' - found record '.$srec['co_ID']);
						$sql="SELECT fi_ID, 
												 fi_docID, 
												 fi_mimetype, 
												 fi_active,
												 fi_filename
									FROM tbl_filerefs
									WHERE fi_co_ID=".$srec['co_ID']." 
										AND fi_active='y'
										AND (fi_mimetype='text/csv' OR fi_mimetype='application/vnd.ms-excel')";
						$frs=myload($sql);
						if (count($frs)>0){
							foreach ($frs as $file){
								$ret.=page_log('   - found CSV docID '.$file['fi_docID'].', filename='.$file['fi_filename']);
								$docrs=doc_repo_get_doc_details($srec['co_ID'], $file['fi_ID']);
								$image_array=get_filecache($docrs['docID'],false);
								if (isset($image_array['data'])){
									$tempname=tempnam('/tmp/', 'qmcso_import_');
									file_put_contents($tempname, $image_array['data']);
									$ifile_arr[]=array('tempname'=>$tempname, 'filename'=>$file['fi_filename'], 'co_ID'=>$srec['co_ID']);
									$ret.=page_log("   - saving docID ".$file['fi_docID']." as ".$tempname);
								}
							}
						} else {
							$ret.=page_log('skipping record '.$srec['co_ID'].' - no active csv files attached');
						}
					}
				} else {
					die ('no records found in source queue ('.$iqueue.'). program halting.');
				}
			} else {
				die ('source queue is not valid: '.$i_st_ID);
			}

		} else {
			die("Input filename or queue is required. Use --ifile=MyFileName or --iqueue=MyQueue to specify one or the other.\n");
		}
	}

// build a full $ifile_arr if processing a single local file, similar to what we have if processing via a queue
	if (count($ifile_arr)==0){
		$ifile_arr[]=array('tempname'=>$ifile, 'filename'=>$ifile, 'co_ID'=>'');
	}

// import records from files
	if (count($ifile_arr)>0){
		foreach ($ifile_arr as $ifile){
			$ret.=page_log('------ starting processing of '.$ifile['tempname'].' --------');;
			if (!file_exists($ifile['tempname'])) {
				$ret.=page_log('no input file found: '.$ifile['tempname']);
				continue;
			}

			// get array representation of csv input file
			$csvarr=get2DArrayFromCsv($ifile['tempname'], ',');

			// see if the csv importer resulted in records
			if (count($csvarr)==0) die ('no input records found in '.$ifile['tempname']);

			// find group fields
			$groupfields=array();
			$groupfieldlist=array();
			foreach ($csvarr[0] as $ck=>$cv){
				if (substr($cv, 0, 6)=='group:'){
					$thisgroup=preg_split('/\|/', substr($cv, 7, -1));
					$groupfields[$thisgroup[0]][$thisgroup[2]][$thisgroup[1]]=$ck;
					$groupfieldlist[$ck]=array('group'=>$thisgroup[0], 'field'=>$thisgroup[1], 'row'=>$thisgroup[2]);
				} elseif (stristr($cv, '|')) {
					$thisgroup=preg_split('/\|/', $cv);
					$groupfields[$thisgroup[0]][$thisgroup[2]][$thisgroup[1]]=$ck;
					$groupfieldlist[$ck]=array('group'=>$thisgroup[0], 'field'=>$thisgroup[1], 'row'=>$thisgroup[2]);
				}
			}

			// parse the csv file into a data array that is ready for import
			$ret.=page_log('Capturing input data from '.$ifile['tempname']);
			$dataarr=page_process_input_array($csvarr);
			$ret.=page_log($dataarr['log']);

			// stop processing if errors were encountered
			if ($dataarr['errors']<>'') die ('input processing errors: <pre>'.$dataarr['errors'].'</pre>');

			if ($dataarr['warnings']<>''){
				$ret.=page_log("Warnings encountered: \n".$dataarr['warnings']);
			}

			// create the POWER records
			$ret.=page_create_records($dataarr['data']);
			$ret.=page_log("Deleting temporary import file ".$ifile['tempname']);
			unlink ($ifile['tempname']);
			$ret.=page_log('------ finished processing of '.$ifile['tempname'].' --------');;
		}
	} else {
		$ret.=page_log('no input files found to process.');
	}

// archive the source tickets
	if (isset($source_rs) && count($source_rs)>0 && $archive_queue>0 && $mode=='prod'){
		foreach ($source_rs as $srec){
			$import_status_note='Archived after import';
			$post=array();
			$trs=set_corr_status($srec['co_ID'], $archive_queue, $import_status_note, $post, 'POWER');
			if (isset($trs[0]) && $trs[0]==true){
				$ret.=page_log('Archived import ticket '.$srec['co_ID'].' to queue '.$archive_queue);
			} else {
				$ret.=page_log('Could not archive import ticket to '.$archive_queue.': '.print_r($trs, true));
			}
		}
	}

// report the results of the import
	if ($www){
		// formate for browser display if invoked via www
		echo '<pre>'.$ret.'</pre>';
	} else {
		echo $ret;
	}

exit;


function page_process_input_array($csvarr){
	global $ifile, $processrecords, $groupfields, $groupfieldlist, $profile;
	$errors=$warnings='';
	$data=array();
	if (count($csvarr)>0){
		if ($profile=='qmcso'){
			// translate column headers into a technical-field name map
			$mapping = array(
				'Associate BID' => 'QMCSO_ASSO_BID',
				'Associate First Name' => 'QMCSO_ASSO_FNAME',
				'Associate Last Name' => 'QMCSO_ASSO_LNAME',
				'Gardian First Name' => 'QMCSO_GUA_FNAME',
				'Gardian Last Name' => 'QMCSO_GUA_LNAME',
				'Addr 1' => 'QMCSO_ADDR_1',
				'Addr 2' => 'QMCSO_ADDR_2',
				'City' => 'QMCSO_CITY',
				'State' => 'QMCSO_STATE',
				'Zip' => 'QMCSO_ZIP',
				'Gardian BID' => 'QMCSO_GUA_BID',
				'Dependent SSN' => 'QMCSO_DEP_SSN',
				'Dependent BID' => 'QMCSO_DEP_BID',
				'Dependent First Name' => 'QMCSO_DEP_FNAME',
				'Dependent Last Name' => 'QMCSO_DEP_LNAME',
				'Dependent Gender' => 'QMCSO_DEP_GEND',
				'Date of Birth' => 'QMCSO_DOB',
				'Effective date' => 'QMCSO_EFF_DATE',
				'Member Number' => 'QMCSO_MEMB_NUMB',
				'STATUS' => 'QMCSO_STATUS'
				);
			$cols=array();
			foreach ($csvarr[0] as $colk=>$colv){
				if (isset($mapping[$colv])){
					// mapping field found
					$cols[$colk]=$mapping[$colv];
				} else {
					// mapping field not found. we will probably have problems if we ever wind up here
					$cols[$colk]='';
					$warnings.="Row 1 contains an invalid column header in column ".$colk.": ".$colv."\n";
				}
			}
		} elseif ($profile=='milliman_prenote'){
			$cols=$csvarr[2];
		} else {
			$cols=$csvarr[0];
		}
		$row=0;
		foreach($csvarr as $c){
			$thisgrouparr=array();
			$row++;
			if (count($c)<>count($cols)){
				if ($profile=='milliman_prenote'){
					if ($row<4){
						$warnings.='Row '.$row.' skipped.'."\n";
					} else {
						$warnings.="Row ".$row." (".$c[0].") has ".count($c)." columns, should be ".count($cols)." columns.\n";
					}
				} else {
					$errors.="Row ".$row." (".$c[0].") has ".count($c)." columns, should be ".count($cols)." columns.\n";
				}
			} else {
				if (count($c)>0){
					foreach ($c as $i=>$v){
						// skip first row, it's a header row. Don't process more than requested.
						if ($row>1 && ($processrecords==0 || $row<=$processrecords+1)){

							// detect array for select-multi fields
							if (substr($v, 0, 13)=='select-multi:'){
								$v=substr($v, 14);
								$varr=array();
								if (strlen($v)){
									$v_exp = array();
									$v_exp=explode('|', $v);
									$varr=array('type'=>'select-multi', 'records'=>$v_exp);
								}
								$data[$row-2][$cols[$i]]=$varr;

							// new group detection method
							} elseif (isset($groupfieldlist[$i])) {
								if (!isset($thisgrouparr[$groupfieldlist[$i]['group']])){
									// need to populate this group
									$thisgrouparr[$groupfieldlist[$i]['group']]=array();
									foreach ($groupfields[$groupfieldlist[$i]['group']] as $gfk=>$gfv){
										foreach ($gfv as $gfvk=>$gfvv){
											$thisgrouparr[$groupfieldlist[$i]['group']][$gfk][$gfvk]=$c[$gfvv];
										}
									}
									$data[$row-2][$groupfieldlist[$i]['group']]=array('type'=>'group', 'records'=>$thisgrouparr[$groupfieldlist[$i]['group']]);
								}
							// detect group fields
							} elseif (substr($v, 0, 6)=='group:'){

								// get field name for each subcolumn. I know... we theoretically could have done this just once above, but we didn't know for sure that it was a group field til we got into a data row.
								$gcolname=$cols[$i];
								if (strpos($cols[$i], ':') !== FALSE){
									$gcolname=substr($cols[$i], 0, strpos($cols[$i], '|'));
									$gcols=explode(':', substr($cols[$i], strpos($cols[$i], '|')+1));
								} else {
									$gcols=array();
								}
								$v=substr($v, 7);
								$varr=array();

								// see if there is actually any data after the group definition
								if (strlen($v)){
									$garr=explode('|', $v);
									// add each group
									$gv=array();
									if (count($garr)>0){
										foreach ($garr as $garrv){
											$gk=explode(':', $garrv);
											if (count($gk)>0){
												$gcnt=0;
												$gkvarr=array();

												// add each subfield
												foreach ($gk as $gkv){
													$gkvarr[$gcols[$gcnt]]=$gkv;
													$gcnt++;
												}
												$gv[]=$gkvarr;
											}
										}
									}
									$varr=array('type'=>'group', 'records'=>$gv);
								}
								$data[$row-2][$gcolname]=$varr;
							} else {
								$data[$row-2][$cols[$i]]=$v;
							}
						} else {
							// skipping header row
						}
					}
				}
			}
		}
	}

	$log='Found '.count($cols).' column headers.';
	return array('errors'=>$errors, 'data'=>$data, 'warnings'=>$warnings, 'log'=>$log);
}

function page_create_records($data){
	global $ifile, $mode, $USERNAME, $dest_st_ID, $st_Name, $sort, $profile, $pm_dest_st_ID, $pm_st_Name;
	if (strlen($sort)) sksort($data, $sort, true);

	if ($mode=='prod'){
		$ret=page_log('Importing records from '.$ifile['tempname']);

		$this_dest_st_Name=$st_Name;
		$this_dest_st_ID=$dest_st_ID;
		if ($profile=='qmcso'){
			if (substr($ifile['filename'], 0, 2)=='pm'){
				// file is a pm file, redirect to pm queue
				$this_dest_st_Name=$pm_st_Name;
				$this_dest_st_ID=$pm_dest_st_ID;
			}
			$qmpm_duplicate_st_Name='BANA_MEMB_QMCSO_DUP_CL';
			$qmpm_duplicate_st_ID=get_st_ID($qmpm_duplicate_st_Name);
			$QMCSO_ASSO_BID_fi_ID=get_fid('QMCSO_ASSO_BID');
		}

		if ($profile=='milliman_prenote'){
			$milliman_duplicate_st_Name='BANA_PRE_NOTE_CL_INV';
			$milliman_duplicate_st_ID=get_st_ID($milliman_duplicate_st_Name);
			$CSW_INQUIRY_ID_fi_ID=get_fid('CSW_INQUIRY_ID');
		}
		$ret.=page_log('Setting destination queue: '.$this_dest_st_Name.' ('.$this_dest_st_ID.')');

		if (count($data)>0){
			$created=0;
			foreach ($data as $d){
				$this_dupe_co_ID=0;
				$thisrec_dest_st_ID=$this_dest_st_ID;
				$thisrec_dest_st_Name=$this_dest_st_Name;
				if ($profile=='qmcso'){
					$duplicates=0;
					// dupe check for a matching record in destination queue with a matching QMCSO_BID
					if (isset($d['QMCSO_ASSO_BID']) && $d['QMCSO_ASSO_BID']<>''){
						$sql="SELECT co_ID,
												 QMCSO_ASSO_BID.fv_Value as QMCSO_ASSO_BID
									FROM tbl_corr
									INNER JOIN tbl_field_values AS QMCSO_ASSO_BID ON QMCSO_ASSO_BID.fv_co_ID=co_ID AND QMCSO_ASSO_BID.fv_fi_ID=".$QMCSO_ASSO_BID_fi_ID."
									WHERE QMCSO_ASSO_BID.fv_Value=".sql_escape_clean($d['QMCSO_ASSO_BID'])."
										AND co_queue=".$this_dest_st_ID;
						$dupers=myload($sql);
						if (count($dupers)>0){
							// there is a duplicate record in the destination queue
							// skip creation of this record
							$ret.=page_log(' - Duplicate QMCSO_ASSO_BID '.$d['QMCSO_ASSO_BID'].' found: '.$dupers[0]['co_ID']);
							if ($qmpm_duplicate_st_ID>0){
								$thisrec_dest_st_Name=$qmpm_duplicate_st_Name;
								$thisrec_dest_st_ID=$qmpm_duplicate_st_ID;
							} else {
								$ret.=page_log(' - Cannot change destination queue to '.$qmpm_duplicate_st_Name.'. Queue not found.');
							}
							$duplicates++;
						}
					}
				}

				if ($profile=='milliman_prenote'){
					$duplicates=0;
					$milliman_dupe_co_ID=0;
					// dupe check for a matching record in destination queue with a matching CSW_INQUIRY_ID
					if (isset($d['CSW_INQUIRY_ID']) && $d['CSW_INQUIRY_ID']<>''){
						$sql="SELECT co_ID,
												 CSW_INQUIRY_ID.fv_Value as CSW_INQUIRY_ID,
												 st_Name
									FROM tbl_corr
									INNER JOIN tbl_statuses ON co_queue=st_ID
									INNER JOIN tbl_corr_types ON st_ct_ID=ct_ID
									INNER JOIN tbl_field_values AS CSW_INQUIRY_ID ON CSW_INQUIRY_ID.fv_co_ID=co_ID AND CSW_INQUIRY_ID.fv_fi_ID=".$CSW_INQUIRY_ID_fi_ID."
									WHERE CSW_INQUIRY_ID.fv_Value=".sql_escape_clean($d['CSW_INQUIRY_ID'])."
										AND ct_Name='BANA_PRE_NOTE'";
						$dupers=myload($sql);
						if (count($dupers)>0){
							// there is a duplicate record in the destination queue
							// skip creation of this record
							$ret.=page_log(' - Duplicate CSW_INQUIRY_ID '.$d['CSW_INQUIRY_ID'].' found in '.$dupers[0]['st_Name'].' queue: '.$dupers[0]['co_ID']);
							if ($milliman_duplicate_st_ID>0){
								$thisrec_dest_st_Name=$milliman_duplicate_st_Name;
								$thisrec_dest_st_ID=$milliman_duplicate_st_ID;
							} else {
								$ret.=page_log(' - Cannot change destination queue to '.$milliman_duplicate_st_Name.'. Queue not found.');
							}
							$duplicates++;
							$this_dupe_co_ID=$dupers[0]['co_ID'];
						}
					}
				}

				$co_receivedate='';
				if (isset($d['co_receivedate'])){
					$co_receivedate=date('Y-m-d', strtotime($d['co_receivedate']));
				}
				// create the new POWER ticket
				$lic_coid=create_new_corr($USERNAME, $co_receivedate);

				// set the new record's initial queue
				$msg='set initial queue';
				$post=array();

				$upd_queue=set_corr_status($lic_coid, $thisrec_dest_st_ID, $msg, $post, $USERNAME, false, false, false, false, false, 'b');
				$created++;

				// populate import record control field, if found
				if ($ifile['co_ID']<>'') {
					put_field($lic_coid, 'IMPORT_REC_ID', $ifile['co_ID'], $USERNAME);
				}

				if (isset($upd_queue[0]) && $upd_queue[0]==1){
					// transfer successful
				// populate the control fields
					if (count($d>0)){
						// set default to update even if new record is empty
						$only_update_with_nonempty=false;
						foreach ($d as $k=>$v){
							if ($profile=='milliman_prenote'){
								// for milliman, don't update the dupe if the incoming record's field is empty
								$only_update_with_nonempty=true;
								if ($k=='BANA_DX_CPT_CODE'){
									// concatenate BANA_DX_CPT_CODE and BANA_DX_CPT_CODE2
									if (isset($d['BANA_DX_CPT_CODE2'])){
										$v=$v.trim($d['BANA_DX_CPT_CODE2']);
									}
								}

								if ($k=='BANA_DX_CPT_CODE2'){
									// skip population of this field, it is concatenated into another field above
									continue;
								}

								// special processing for PHNNO
								if ($k=='PHNNO'){
									if (trim($v)<>''){
										$varr=array();
										$varr[]=array(
											'PHNTYPE'=>'Home',
											'PHNNO'=>trim($v)
										);
										$thisput=put_group_field($lic_coid, 'PHONE', $varr, $USERNAME);
										if ($this_dupe_co_ID>0){
											put_group_field($this_dupe_co_ID, 'PHONE', $varr, $USERNAME);
										}
									}
									continue;
								}
							}

							// see if this is a group-type field
							if (is_array($v)){
							
								// see if it's a select-multi field
								if (isset($v['type']) && 
										$v['type']=='select-multi' && 
										isset($v['records']) && 
										is_array($v['records']) && 
										count($v['records'])>0){
									put_multi_field($lic_coid, $k, $v['records'], $USERNAME);
									if ($this_dupe_co_ID>0){
										put_multi_field($this_dupe_co_ID, $k, $v['records'], $USERNAME);
									}
								}

								// see if it's a group field
								if (isset($v['type']) && 
										$v['type']=='group' && 
										isset($v['records']) && 
										is_array($v['records']) && 
										count($v['records'])>0){
									put_group_field($lic_coid, $k, $v['records'], $USERNAME);
									if ($this_dupe_co_ID>0){
										put_group_field($this_dupe_co_ID, $k, $v['records'], $USERNAME);
									}
								}
							} else {
								put_field($lic_coid, $k, trim($v), $USERNAME);
								if ($this_dupe_co_ID>0){
									if (($only_update_with_nonempty==false) ||
											($only_update_with_nonempty && trim($v)<>'')){
										put_field($this_dupe_co_ID, $k, trim($v), $USERNAME);
									}
								}
							}
						}
					}
					$ret.=page_log(' - '.$lic_coid.' created in '.$thisrec_dest_st_Name.' ('.$thisrec_dest_st_ID.'), populated with '.count($d).' control fields.');
				} else {
					echo '<pre>'.print_r($upd_queue, true).'</pre>';
				}
			}
			if ($profile=='qmcso'){
				$ret.=page_log('Duplicate check found '.$duplicates.' records');
			}
			$ret.=page_log('Created '.$created.' records');
		}
	} else {
		$ret=page_log('in test mode, no data imported.');
		//print_r($data);
	}
	return $ret;
}

function sksort(&$array, $subkey="id", $sort_ascending=false) {
	if (count($array))
		$temp_array[key($array)] = array_shift($array);
	foreach($array as $key => $val){
		$offset = 0;
		$found = false;
		foreach($temp_array as $tmp_key => $tmp_val) {
			if(!$found and strtolower($val[$subkey]) > strtolower($tmp_val[$subkey])) {
				$temp_array = array_merge((array)array_slice($temp_array,0,$offset),
			 														array($key => $val),
																	array_slice($temp_array,$offset)
																	);
				$found = true;
			}
			$offset++;
		}
		if(!$found) $temp_array = array_merge($temp_array, array($key => $val));
	}
	if ($sort_ascending) $array = array_reverse($temp_array);
	else $array = $temp_array;
}

function get2DArrayFromCsv($file,$delimiter) { 
	if (($handle = fopen($file, "r")) !== FALSE) { 
		$i = 0; 
		while (($lineArray = fgetcsv($handle, 4000, $delimiter)) !== FALSE) { 
			for ($j=0; $j<count($lineArray); $j++) { 
				$data2DArray[$i][$j] = $lineArray[$j]; 
			} 
			$i++; 
		} 
		fclose($handle); 
	} 
	return $data2DArray; 
} 

function arguments($argv) {
  $_ARG = array();
	if (count($argv)>0){
		foreach ($argv as $arg) {
			if (preg_match('#^-{1,2}([a-zA-Z0-9]*)=?(.*)$#', $arg, $matches)) {
				$key = $matches[1];
				switch ($matches[2]) {
					case '':
					case 'true':
						$arg = true;
						break;
					case 'false':
						$arg = false;
						break;
					default:
						$arg = $matches[2];
				}
				$_ARG[$key] = $arg;
			} else {
				$_ARG['input'][] = $arg;
			}
		}
	}
  return $_ARG;
}

function page_log($str){
	return date('Y-m-d H:i:s').' - '.$str."\n";
}
?>
