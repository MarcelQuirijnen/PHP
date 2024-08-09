<?php

class HealthCheckIndicatorsApiException extends \Exception
{
	private $_errorCode;

	public function __construct($message, $errorCode = null, $code = 0, Exception $previous = null)
	{
		parent::__construct($message, $code, $previous);

		$this->_errorCode = $errorCode;
	}

	public function getErrorCode()
	{
		return $this->_errorCode;
	}
}