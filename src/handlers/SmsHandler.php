<?php

namespace meriksk\MessageQueue\handlers;

use meriksk\MessageQueue\Queue;
use meriksk\MessageQueue\handlers\BaseHandler;


/**
 * SmsHandler class file.
 */
class SmsHandler implements BaseHandler
{

	/**
	 * List of successful recipients.
	 * @var array
	 */
	public $successRecipients = [];

	/**
	 * List of failures by-reference
	 * @var array
	 */
	public $failedRecipients = [];

	/** 
	 * @var array Handler default configuration. 
	 */
	protected $defaultConfig = [
		'method' => 'smsd',
		'methods' => [
			'smsd' => [
				'queue' => '',
			],
			'http' => [
				'url' => '',
			],
			'thetexting' => [
				'api_key' => '',
				'api_secret' => '',
				'from' => '',
			],
			'twillio' => [
				'username' => '',
				'password' => '',
				'from' => '',
			],
		],
	];
	
	/**
	 * @var string describing a socket error
	 */
	private $error;

	/**
	 * @var int error code
	 */
	private $errorCode;
	



	// -------------------------------------------------------------------------
	// Interface methods
	// -------------------------------------------------------------------------


	/**
	 * Returns a string with last error message
	 * @return string
	 */
	public function getLastError()
	{
		return $this->error;
	}

	/**
	 * Returns a number with last error code
	 * @return int
	 */
	public function getLastErrorCode()
	{
		return (int)$this->errorCode;
	}

	/**
	 * Returns of successful recipients.
	 * @return array
	 */
	public function getSuccessfulRecipients()
	{
		return $this->successRecipients;
	}

	/**
	 * Returns list of failures by-reference.
	 * @return array
	 */
	public function getFailedRecipients()
	{
		return $this->failedRecipients;
	}

	/**
	 * Handle message
	 * @param array $recipients
	 * @param MessageQueue $model
	 * @return int|bool An integer is returned which includes the number of
	 * successful recipients.
	 */
	public function handleMessage($recipients, $model)
	{
		
		$settings = getGlobalSettings('notifications', 'sms', []);
		if (empty($settings) || empty($settings['method'])) {
			$this->error = 'SMS configration missing';
			return 0;
		}
		
		if (empty($model->body_c)) {
			return 0;
		}

		$successful = 0;
		$rest = Yii::app()->RestClient;

		foreach ($recipients as $recipient) {

			switch ($settings['method']) {
				// via smsd gate (file)
				case 'smsd': {
					$message = 'To: ' . $recipient . "\r\n\r\n" . strip_tags($model->body_c);
					$file = $settings['queue'] . '/' . md5($recipient . $message);

					if (is_writable($file) && file_put_contents($file, $message)) {
						$successful++;
					}
				} break;

				// via http gate
				case 'http': {
					$message = strip_tags($model->body_c);
					$params = json_encode([
						'recipient' => $recipient,
						'message' => $message,
						'checksum' => md5($recipient . $message)
					]);

					$response = $rest->post($settings['url'], ['json'=>$params]);
					if ($response->isSuccess()) {
						$successful++;
					}

				} break;

				// via thetexting
				case 'thetexting': {
					$message = strip_tags($model->body_c);
					$params = [
						'api_key' => 'txga7hykv5qtpt8',
						'api_secret' => 'txga7hykv5qtpt8',
						'from' => '16195033441',
						'to' => $recipient,
						'text' => $message,
					];

					$response = $rest->get($settings['url'], $params);
					if ($response->isSuccess()) {
						$successful++;
					}

				} break;

				// via twillio
				case 'twillio': {
					$message = strip_tags($model->body_c);
					$params = [
						'From' => '19042012042',
						'To' => $recipient,
						'Body' => $message,
					];

					$rest->basicAuth($settings['username'], $settings['password']);
					$response = $rest->post($settings['url'], $params);
					if ($response->isSuccess()) {
						$successful++;
					}

				} break;
			}
		}

		return $successful;
	}

}
