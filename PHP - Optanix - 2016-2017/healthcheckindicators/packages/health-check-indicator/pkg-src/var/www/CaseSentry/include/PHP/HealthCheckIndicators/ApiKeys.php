<?php
/**
 * Class HealthCheckIndicators_ApiKeys
 *
 * A class for HealthCheckIndicators to handle API keys.
 *
 * @copyright 2016, Optanix, Inc.  All Rights Reserved
 */

class HealthCheckIndicators_ApiKeys
{
	const CISCO_TO_ADDRESS = '';
	const CISCO_FROM_ADDRESS = '';

	protected $apiKey;
	protected $toAddress = '';
	protected $fromAddress = '';
	protected $subject = 'Add API Key: ';
	protected $sqlMode = 'UPDATE';
	protected $debug = false;

	protected $entityName;
	protected $objectDefId;
	protected $existingId;
	protected $logFile = '/var/log/health-check-indicator/api_keys.log';
	protected $configFile = '/etc/health-indicators/CCA.cfg';

	/**
	 * Main method.
	 * @param bool $pushToSca
	 * @param null $toAddress
	 * @return bool
	 */
	public function apiKeyGenerator($pushToSca = false, $toAddress = null)
	{
		try {
			$this->parseEntityName();
			$this->configureEmails($toAddress);
			$this->checkForExistingApiKey($pushToSca);
			$this->generateApiKey();
			$this->insertApiKeyOnCca($this->getApiKey(), $this->getSqlMode());

			if ($pushToSca === true) {
				$this->pushToSca($this->getApiKey(), $this->getToAddress(), $this->getFromAddress(), $this->getSubject(), $this->getEntityName());
			}

			return true;

		} catch (\Exception $e) {
			print $e->getMessage();

			return false;
		}
	}

	/**
	 * Checks for an existing API key.
	 * @param $pushToSca
	 * @return bool
	 * @throws Exception
	 */
	protected function checkForExistingApiKey($pushToSca)
	{
		$sql = "SELECT `value` FROM `CaseSentry`.`CaseSentryConfig` WHERE `parm` = 'api_key' AND `parm_group` = 'API'";

		$this->sendDebug($sql);

		$cmd = Yii::app()->database->CaseSentry->createCommand($sql);
		$res = $cmd->queryScalar();

		if ($res) {
			if (!$pushToSca) {
				throw new Exception("API key already exists: " . $res . ".\n");
			}
			$this->sendDebug("An API key already exists: " . $res . ".");
			$this->setApiKey($res);
			$this->pushToSca($this->getApiKey(), $this->getToAddress(), $this->getFromAddress(), $this->getSubject(), $this->getEntityName());
			exit(0);
		}

		if ($res === false) {
			$this->sendDebug('Setting SQL statement to insert since the record does not exist.');
			$this->setSqlMode('INSERT');
		}

		return true;
	}

	/**
	 * Generates the API key.
	 * @return bool
	 */
	protected function generateApiKey()
	{
		$this->sendDebug('Generating API key...');

		$length = 16;
		$chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
		$charsLength = strlen($chars);
		$apiKey = '';

		for ($i = 0; $i < $length; $i++) {
			$apiKey .= $chars[rand(0, $charsLength - 1)];
		}

		print "API Key: " . $apiKey . "\n";

		$this->setApiKey($apiKey);

		return true;
	}

	/**
	 * Inserts the API key into `CaseSentry`.`CaseSentryConfig` on the CCA.
	 * @param null $apiKey
	 * @param $sqlMode
	 * @return bool
	 * @throws Exception
	 */
	protected function insertApiKeyOnCca($apiKey = null, $sqlMode)
	{
		if ($apiKey) {
			if ($sqlMode === 'INSERT') {
				$sql = "INSERT IGNORE INTO `CaseSentry`.`CaseSentryConfig` (`parm`, `parm_group`, `value`, `description`)";
				$sql .= " VALUES ";
				$sql .= "('api_key', 'API', :apiKey, 'The key used to authenticate API requests')";
			}
			if ($sqlMode === 'UPDATE') {
				$sql = "UPDATE `CaseSentry`.`CaseSentryConfig` SET ";
				$sql .= "`value` = :apiKey ";
				$sql .= "WHERE `parm` = 'api_key' AND `parm_group` = 'API'";
			}

			$this->sendDebug(str_replace(':apiKey', "'" . $apiKey . "'", $sql));

			$cmd = Yii::app()->database->CaseSentry->createCommand($sql);
			$cmd->bindParam(':apiKey', $apiKey);
			$num = $cmd->execute();

			if ($num === 1) {
				print "The API Key has been saved in `CaseSentry`.`CaseSentryConfig`.\n";
				return true;
			}
		}

		throw new Exception("The API Key was unable to be saved in `CaseSentry`.`CaseSentryConfig`.\n");
	}

	/**
	 * Configures the email address to the SCA.
	 * @param null $toAddress
	 * @return bool
	 */
	protected function configureEmails($toAddress = null)
	{
		$sql = "SELECT `value` FROM `CaseSentry`.`CaseSentryConfig` WHERE `parm` = 'PRODUCT_TYPE'";
		$cmd = Yii::app()->database->CaseSentry->createCommand($sql);
		$res = $cmd->queryScalar();

		if ($res && $res === 'CROS') {
			$this->setToAddress(self::CISCO_TO_ADDRESS);
			$this->setFromAddress(self::CISCO_FROM_ADDRESS);
		}

		if ($toAddress) {
			$this->setToAddress($toAddress);
		}

		return true;
	}

	/**
	 * Push the API key to the SCA via an email.
	 * @param null $body
	 * @param null $to
	 * @param null $from
	 * @param null $subject
	 * @param null $hostname
	 * @return bool
	 * @throws Exception
	 */
	protected function pushToSca($body = null, $to = null, $from = null, $subject = null, $hostname = null)
	{
		if ($body && $to && $from && $subject && $hostname) {
			$subject = $subject . $hostname;

			$cmd = "/bin/echo $body | /usr/bin/mail -s '$subject' -aFrom:'$from' '$to'";
			$this->sendDebug($cmd);

			print "Pushing to SCA... ";

			`$cmd`;

			print "done.\n";

			return true;
		}

		throw new Exception("Unable to push the update to SCA. Please copy and paste the API Key manually into the SCA.");
	}

	/**
	 * Main method to process API keys.
	 * @param $subject
	 * @param $apiKey
	 * @return bool
	 */
	public function processApiKey($subject, $apiKey)
	{
		try {
			$this->log(' Received an API key push.');
			$this->parseApiKey($apiKey);
			$this->parseSubjectForEntityName($subject);
			$this->checkIfEntityExists($this->getEntityName());
			$this->checkIfApiKeyMetaAlreadyExists($this->getObjectDefId());
			$this->insertApiKeyOnSca($this->getApiKey(), $this->getObjectDefId(), $this->getSqlMode(), $this->getExistingId());

			return true;

		} catch (\Exception $e) {
			$this->log($e->getMessage());

			return false;
		}
	}

	/**
	 * Parse and set the API key.
	 * @param null $apiKey
	 * @return bool
	 * @throws Exception
	 */
	protected function parseApiKey($apiKey)
	{
		if ($apiKey) {
			$apiKey = trim($apiKey);
			$this->log('[INFO] API Key: ' . $apiKey);
			$this->setApiKey($apiKey);
			return true;
		}

		throw new Exception("[WARNING] API key is empty.");
	}

	/**
	 * Parse the EntityName from the config file located at $configFile.
	 * @return bool
	 * @throws Exception
	 */
	protected function parseEntityName()
	{
		$file = file_get_contents($this->getConfigFile());

		if (!$file) {
			throw new Exception("[WARNING] Unable to open the config file at " . $this->getConfigFile());
		}

		$json = json_decode($file);

		if (!$json) {
			throw new Exception("[WARNING] Config file JSON is empty.");
		}

		$entityName = $json->CentralControllerAppliance->EntityName;

		if (!$entityName) {
			throw new Exception("[WARNING] Unable to parse the EntityName from the config.");
		}

		$this->setEntityName($entityName);
		return true;
	}

	/**
	 * Parse and set the entity name.
	 * @param $subject
	 * @return bool
	 * @throws Exception
	 */
	protected function parseSubjectForEntityName($subject)
	{
		if ($subject) {
			$entityName = trim(ltrim($subject, 'Add API Key: '));
			$this->log('[INFO] Entity Name: ' . $entityName);
			$this->setEntityName($entityName);
			return true;
		}

		throw new Exception("[WARNING] Subject is empty.");
	}

	/**
	 * Check to see if the entity exists.
	 * @param $entityName
	 * @return bool
	 * @throws Exception
	 */
	protected function checkIfEntityExists($entityName)
	{
		if (!$entityName) {
			throw new Exception('[WARNING] Entity name is not set.');
		}

		$sql = "SELECT `id` FROM `CaseSentry`.`object_def` WHERE `name` = :entity_name AND `method` = 'GRP' and `instance` = 'NODE'";
		$cmd = Yii::app()->database->CaseSentry->createCommand($sql);
		$cmd->bindParam(':entity_name', $entityName);
		$objectDefId = $cmd->queryScalar();

		if ($objectDefId) {
			$this->log('[INFO] Object Def Id: ' . $objectDefId);
			$this->setObjectDefId($objectDefId);
			return true;
		}

		throw new Exception("[WARNING] Entity does not exist.");
	}

	/**
	 * Check to see if API key meta already exists and sets the SQL mode for operation.
	 * @param $objectDefId
	 * @return bool
	 * @throws Exception
	 */
	protected function checkIfApiKeyMetaAlreadyExists($objectDefId)
	{
		if (!$objectDefId) {
			throw new Exception('[WARNING] Object def id is not set.');
		}

		$sql = "SELECT `id` FROM `CaseSentry`.`object_def_meta` WHERE `object_def_id` = :object_def_id AND `meta_key` = 'api_key'";
		$cmd = Yii::app()->database->CaseSentry->createCommand($sql);
		$cmd->bindParam(':object_def_id', $objectDefId);
		$existingId = $cmd->queryScalar();

		if (!$existingId) {
			$this->log('[INFO] Setting the SQLMode to insert.');
			$this->setSqlMode('INSERT');
			return true;
		}

		$this->log('[INFO] Object Def Meta Id: ' . $existingId);
		$this->setExistingId($existingId);

		return true;
	}

	/**
	 * Add the API key to object_def_meta on the SCA.
	 * @param $apiKey
	 * @param $objectDefId
	 * @param $sqlMode
	 * @param $existingId
	 * @return bool
	 * @throws Exception
	 */
	protected function insertApiKeyOnSca($apiKey, $objectDefId, $sqlMode, $existingId)
	{
		if ($sqlMode === 'INSERT') {
			$this->insertApiEnabledOnSca($objectDefId);

			$sql = "INSERT INTO `CaseSentry`.`object_def_meta` (`object_def_id`, `meta_key`, `meta_value`) VALUES (:id, 'api_key', :meta_value)";
			$cmd = Yii::app()->database->CaseSentry->createCommand($sql);
			$cmd->bindParam(':meta_value', $apiKey);
			$cmd->bindParam(':id', $objectDefId);
		} else {
			$sql = "UPDATE `CaseSentry`.`object_def_meta` SET `meta_value` = :meta_value WHERE `id` = :id";
			$cmd = Yii::app()->database->CaseSentry->createCommand($sql);
			$cmd->bindParam(':meta_value', $apiKey);
			$cmd->bindParam(':id', $existingId);
		}

		try {
			$cmd->execute();
			$this->log(' API key was ' . ($existingId ? 'updated' : 'inserted') . ".");
			return true;
		} catch (\Exception $e) {
			$this->log('[ERROR] The API key was not ' . ($existingId ? 'updated' : 'inserted') . ". Error:" . $e->getMessage());
			return false;
		}
	}

	/**
	 * Inserts `api_enabled` key into `object_def_meta` on the SCA.
	 * @param null $objectDefId
	 * @return bool
	 */
	protected function insertApiEnabledOnSca($objectDefId = null)
	{
		if (!$objectDefId) {
			$this->log(' [WARNING] Unable to insert api_enabled meta field because object_def_id is missing.');
			return false;
		}

		try {
			$sql = "INSERT IGNORE INTO `CaseSentry`.`object_def_meta` (`object_def_id`, `meta_key`, `meta_value`) VALUES (:id, 'api_enabled', 1)";
			$cmd = Yii::app()->database->CaseSentry->createCommand($sql);
			$cmd->bindParam(':id', $objectDefId);
			$cmd->execute();
			$this->log(' API was enabled by setting api_enabled.');
			return true;
		} catch (\Exception $e) {
			$this->log('[ERROR] Unable to enabled the API. Error: ' . $e->getMessage());
			return false;
		}
	}

	/**
	 * Set debug mode.
	 * @param bool $mode
	 */
	public function setDebugMode($mode = false)
	{
		$this->debug = $mode;
	}

	/**
	 * Print debug information to STDOUT.
	 * @param $message
	 */
	protected function sendDebug($message)
	{
		if ($this->getDebugMode()) {
			print "\033[0;34m--" . $message . "\033[0m\n";
		}
	}

	/**
	 * Log a message to the log file.
	 * @param null $message
	 */
	protected function log($message = null)
	{
		$log = $this->getLogFile();
		if ($log && $message) {
			file_put_contents($log, '[' . time() . ']' . $message . "\n", FILE_APPEND);
		}
	}

	/**
	 * Sets the API key.
	 * @param $apiKey
	 */
	protected function setApiKey($apiKey)
	{
		$this->apiKey = $apiKey;
	}

	/**
	 * Sets the to email address.
	 * @param $toAddress
	 */
	protected function setToAddress($toAddress)
	{
		$this->toAddress = $toAddress;
	}

	/**
	 * Sets the from email address.
	 * @param $fromAddress
	 */
	protected function setFromAddress($fromAddress)
	{
		$this->fromAddress = $fromAddress;
	}

	/**
	 * Sets the SQL mode (either INSERT or UPDATE).
	 * @param $sqlMode
	 */
	protected function setSqlMode($sqlMode)
	{
		$this->sqlMode = $sqlMode;
	}

	/**
	 * Sets the entity name.
	 * @param $entityName
	 */
	protected function setEntityName($entityName)
	{
		$this->entityName = $entityName;
	}

	/**
	 * Sets the object def id.
	 * @param $objectDefId
	 */
	protected function setObjectDefId($objectDefId)
	{
		$this->objectDefId = $objectDefId;
	}

	/**
	 * Set the existing id.
	 * @param $existingId
	 */
	protected function setExistingId($existingId)
	{
		$this->existingId = $existingId;
	}

	/**
	 * Get the debug mode.
	 * @return bool
	 */
	protected function getDebugMode()
	{
		return $this->debug;
	}

	/**
	 * Get the API key.
	 * @return mixed
	 */
	protected function getApiKey()
	{
		return $this->apiKey;
	}

	/**
	 * Get the subject.
	 * @return string
	 */
	protected function getSubject()
	{
		return $this->subject;
	}

	/**
	 * Get the to address.
	 * @return stringG
	 */
	protected function getToAddress()
	{
		return $this->toAddress;
	}

	/**
	 * Get the from address.
	 * @return string
	 */
	protected function getFromAddress()
	{
		return $this->fromAddress;
	}

	/**
	 * Get the SQL mode.
	 * @return string
	 */
	protected function getSqlMode()
	{
		return $this->sqlMode;
	}

	/**
	 * Get the entity name.
	 * @return mixed
	 */
	protected function getEntityName()
	{
		return $this->entityName;
	}

	/**
	 * Get the object def id.
	 * @return mixed
	 */
	protected function getObjectDefId()
	{
		return $this->objectDefId;
	}

	/**
	 * Get the existing id.
	 * @return mixed
	 */
	protected function getExistingId()
	{
		return $this->existingId;
	}

	/**
	 * Get the log file name.
	 * @return mixed
	 */
	protected function getLogFile()
	{
		return $this->logFile;
	}

	/**
	 * Get the config file name.
	 * @return string
	 */
	protected function getConfigFile()
	{
		return $this->configFile;
	}
}
