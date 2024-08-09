<?php
/**
 * HealthCheckIndicators Controller
 *
 * Controller class to handle all HealthCheckIndicator API calls.
 *
 * @copyright 2016, Optanix, Inc.  All Rights Reserved
 */

class HealthCheckIndicatorsController extends Controller
{
	const API_KEY_MISSING_ERROR_CODE = 'RequiredApiKey';
	const API_KEY_MISSING_MESSAGE = 'An API key is required.';

	const INVALID_API_KEY_ERROR_CODE = 'InvalidApiKey';
	const INVALID_API_KEY_MESSAGE = 'This API key is invalid.';

	const NOT_ENABLED_API_KEY_ERROR_CODE = 'ApiNotEnabled';
	const NOT_ENABLED_API_KEY_MESSAGE = 'The API functionality is not enabled.';

	const RESOURCE_MISSING_ERROR_CODE = 'MissingResource';
	const RESOURCE_MISSING_MESSAGE = 'The resource is missing.';

	const INVALID_RESOURCE_ERROR_CODE = 'InvalidResource';
	const INVALID_RESOURCE_MESSAGE = 'This resource is invalid.';

	/**
	 * If this controller needs to be authenticated.
	 * @var boolean
	 */
	protected $authenticateController = false;

	/**
	 * The API HTTP response once it has been validated.
	 * @var array
	 */
	protected $response = array();

	/**
	 * The API key once it has been validated.
	 * @var string
	 */
	protected $apiKey;

	/**
	 * Initialize the controller.
	 * @return bool 
	 */
	public function init() {        
		parent::init();
		return true;
	}

	/**
	 * API v1.
	 * @param string $apiKey
	 * @param string $resource
	 */
	public function actionV1($apiKey = null, $resource = null)
	{
		try {
			$this->checkApiIsTurnedOn();
			$this->validateApiKey($apiKey);
			$apiComponent = new HealthCheckIndicatorsApiV1Component;
			$this->validateResource($apiComponent, $resource);
			$return = $apiComponent->$resource();

			if(!$return) {
				$return = json_encode(array(
					'http_status_code' => 400,
					'error_code' => 'NoResults',
					'message' => 'There were no results.'
				));
			}
		} catch(HealthCheckIndicatorsApiException $e) {
			$return = json_encode(array(
				'http_status_code' => 400,
				'error_code' => $e->getErrorCode(),
				'message' => $e->getMessage()
			));
		}

		echo $return;
	}

	/**
	 * Checks to see if the API is enabled.
	 * @return bool
	 * @throws Exception
	 */
	protected function checkApiIsTurnedOn()
	{
		if(!CaseSentry_CaseSentryConfig::model()->find("parm = 'api_enabled'")->value) {
			throw new HealthCheckIndicatorsApiException(self::NOT_ENABLED_API_KEY_MESSAGE, self::NOT_ENABLED_API_KEY_ERROR_CODE);
		};

		return true;
	}

	/**
	 * Checks to see if the API key is valid.
	 * @param string $apiKey
	 * @return bool
	 * @throws Exception
	 */
	protected function validateApiKey($apiKey = null)
	{
		if($apiKey === null) {
			throw new HealthCheckIndicatorsApiException(self::API_KEY_MISSING_MESSAGE, self::API_KEY_MISSING_ERROR_CODE);
		}
		
		$storedApiKey = CaseSentry_CaseSentryConfig::model()->findByAttributes(array(
			'parm' => 'api_key',
			'parm_group' => 'API'
		))->value;

		if(!$storedApiKey || $apiKey != $storedApiKey) {
			throw new HealthCheckIndicatorsApiException(self::INVALID_API_KEY_MESSAGE, self::INVALID_API_KEY_ERROR_CODE);
		}
		
		$this->setApiKey($apiKey);
		return true;
	}

	/**
	 * Validate if the resource exists within the API component.
	 * @param $apiComponent
	 * @param string $resource
	 * @return bool
	 * @throws Exception
	 */
	protected function validateResource($apiComponent, $resource = null)
	{
		if($resource === null) {
			throw new HealthCheckIndicatorsApiException(self::RESOURCE_MISSING_MESSAGE, self::RESOURCE_MISSING_ERROR_CODE);
		}

		if(!method_exists($apiComponent, $resource)) {
			throw new HealthCheckIndicatorsApiException(self::INVALID_RESOURCE_MESSAGE, self::INVALID_RESOURCE_ERROR_CODE);
		}

		return true;
	}

	/**
	 * Set the API key.
	 * @param string $apiKey The API key
	 * @return bool
	 */
	protected function setApiKey($apiKey)
	{
		$this->apiKey = $apiKey;
		return true;
	}
}
