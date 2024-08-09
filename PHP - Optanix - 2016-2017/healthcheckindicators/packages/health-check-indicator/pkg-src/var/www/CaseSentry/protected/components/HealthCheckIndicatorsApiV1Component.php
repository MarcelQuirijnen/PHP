<?php

class HealthCheckIndicatorsApiV1Component extends YiiComponent
{
	
	/**
	 * Initiate the health check code on the CCA.
	 * @return string
	 */
	public function hciResults()
	{
		return shell_exec('/usr/local/bin/healthcheck_indicator_email_wrapper.pl --api 2>&1');
	}
}