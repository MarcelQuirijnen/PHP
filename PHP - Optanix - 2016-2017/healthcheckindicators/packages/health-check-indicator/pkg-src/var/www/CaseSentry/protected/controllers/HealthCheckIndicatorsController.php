<?php

class HealthCheckIndicatorsController extends Controller
{

	public function actionGraph()
	{
		$sql = "SELECT DATE(FROM_UNIXTIME(`create_time`)) AS `date`, " .
			"UNIX_TIMESTAMP(DATE(FROM_UNIXTIME(`create_time`))) AS `time`, " .
			"SUM(CASE WHEN `status` = 'FAIL' THEN 1 ELSE 0 END) AS `FAIL`, " .
			"SUM(CASE WHEN `status` = 'PASS' THEN 1 ELSE 0 END) AS `PASS`, " .
			"SUM(CASE WHEN `status` = 'AUTOREM' THEN 1 ELSE 0 END) AS `AUTOREM` " .
			"FROM `Healthcheck_Indicator`.`sca_indicator_detail` " .
			"WHERE DATE(FROM_UNIXTIME(`create_time`)) BETWEEN (CURDATE() - INTERVAL 31 DAY) AND CURDATE() " .
			"GROUP BY DATE(FROM_UNIXTIME(`create_time`)) " .
			"ORDER BY `create_time` ASC";

		$results = Yii::app()->db->createCommand($sql)->queryAll();

		$data = array();
		foreach($results as $result) {
			$data['failing'] .= '[' . $result['time'] . '000,' . $result['FAIL'] . '],';
			$data['remediating'] .= '[' . $result['time'] . '000,' . $result['AUTOREM'] . '],';
		}

		$details = $this->getDetails();

		$data['details'] = $this->renderPartial('details', array(
			'details' => $details
		), true);

		$this->render('graph', array(
			'data' => $data,
		));
	}

	public function actionGenerateDetailsByDateRange()
	{
		header('Content-type: application/json');

		if(!isset($_POST['startDate']) || !isset($_POST['endDate'])) {
			print json_encode(array(
				'status' => 'fail',
				'message' => 'StartDate and EndDate are both required.'
			));
			Yii::app()->end();
		}

		$startDate = $_POST['startDate'];
		$endDate = $_POST['endDate'];

		if(!$this->validateDate($startDate) || !$this->validateDate($endDate)) {
			print json_encode(array(
				'status' => 'fail',
				'message' => 'StartDate and EndDate must be in "YYYY-MM-DD HH:MM" format.'
			));
			Yii::app()->end();
		}

		$startDateUnixTime = strtotime($startDate);
		$endDateUnixTime = strtotime($endDate);

		$differenceInSeconds = ($endDateUnixTime - $startDateUnixTime);

		if($differenceInSeconds < 0) {
			print json_encode(array(
				'status' => 'fail',
				'message' => 'StartDate cannot be greater than EndDate.'
			));
			Yii::app()->end();
		}

		if(($differenceInSeconds / 86400) > 31) {
			print json_encode(array(
				'status' => 'fail',
				'message' => 'StartDate and EndDate must be within 31 days of each other.'
			));
			Yii::app()->end();
		}

		$details = $this->getDetails($startDate, $endDate);

		$html = $this->renderPartial('details', array(
			'details' => $details
		), true);

		print json_encode(array(
			'status' => 'success',
			'message' => $html
		));
	}

	public function actionExport()
	{
		$data = $this->getDetails(Yii::app()->session['hci_graph_start_date'], Yii::app()->session['hci_graph_end_date'], true);

		$csv = '"Date","Patch Level","Client","Appliance","Group","Indicator","Status","Results","Remediation Attempts","Remediation Details"' . "\n";

		foreach($data as $d) {
			$csv .= '"' . $d['time'] . '",';
			$csv .= '"' . $d['patch_level'] . '",';
			$csv .= '"' . $d['client'] . '",';
			$csv .= '"' . $d['appliance'] . '",';
			$csv .= '"' . $d['group'] . '",';
			$csv .= '"' . $d['indicator'] . '",';
			$csv .= '"' . $d['status'] . '",';
			$csv .= '"' . $d['notes'] . '",';
			$csv .= '"' . $d['remediation_attempts'] . '",';
			$csv .= '"' . $d['remediation_details'] . '"';
			$csv .= "\n";
		}

		header('Set-Cookie: fileDownload=true');
		header('Cache-Control: max-age=60, must-revalidate');

		return $this->getApp()->getRequest()->sendFile(
			'HCI Failures vs Auto-Remediation' . '-' . date('Y-m-d_H:i') . '.csv',
			$csv
		);
	}

	protected function getDetails($startDate = null, $endDate = null, $noLimit = false)
	{
		Yii::app()->session['hci_graph_start_date'] = $startDate;
		Yii::app()->session['hci_graph_end_date'] = $endDate;

		if($startDate && $endDate) {
			$sql = "SELECT FROM_UNIXTIME(`create_time`) AS `time`, " .
				"`client`, `appliance`, `patch_level`, `group`, " .
				"`indicator`, `status`, `notes`, " .
				"`remediation_details`, `remediation_attempts`, `create_time` " .
				"FROM `Healthcheck_Indicator`.`sca_indicator_detail` " .
				"WHERE `status` != 'PASS' " .
				"AND `create_time` BETWEEN UNIX_TIMESTAMP(:start) AND " .
				"UNIX_TIMESTAMP(:end) " .
				"ORDER BY `create_time` DESC ";
		} else {
			$sql = "SELECT FROM_UNIXTIME(`create_time`) AS `time`, " .
				"`client`, `appliance`, `patch_level`, `group`, " .
				"`indicator`, `status`, `notes`, " .
				"`remediation_details`, `remediation_attempts`, `create_time` " .
				"FROM `Healthcheck_Indicator`.`sca_indicator_detail` " .
				"WHERE `status` != 'PASS' " .
				"ORDER BY `create_time` DESC ";
		}
		if($noLimit === false) {
			$sql .= "LIMIT 1000";
		}

		$command = Yii::app()->db->createCommand($sql);
		if($startDate && $endDate) {
			$command->bindParam(':start', $startDate, PDO::PARAM_STR);
			$command->bindParam(':end', $endDate, PDO::PARAM_STR);
		}
		return $command->queryAll();
	}

	protected function validateDate($date)
	{
		$d = DateTime::createFromFormat('Y-m-d H:i', $date);
		return $d && $d->format('Y-m-d H:i') === $date;
	}
}