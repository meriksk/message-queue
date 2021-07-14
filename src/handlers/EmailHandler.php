<?php

namespace meriksk\MessageQueue\handlers;

use Swift_SmtpTransport;
use Swift_Mailer;
use Swift_Message;
use meriksk\MessageQueue\Queue;
use meriksk\MessageQueue\handlers\BaseHandler;


/**
 * EmailHandler class file.
 * @see https://symfony.com/doc/current/reference/configuration/swiftmailer.html
 */
class EmailHandler extends BaseHandler
{

	/**
	 * Mailer instance
	 * @var \Swift_Mailer
	 */
	private static $mailer;
	
	/** 
	 * @var array Handler default configuration. 
	 */
	protected $defaultConfig = [
		'transport' => 'smtp',
		'host' => '',
		'username' => '',
		'password' => '',
		'encryption' => 'ssl',
		'port' => 465,
		'from' => '',
		'stream_options' => [],
	];


	// -------------------------------------------------------------------------
	// Interface methods
	// -------------------------------------------------------------------------


	/**
	 * Class constructor
	 */
	public function init()
	{

		if (empty($this->config)) {
			throw new \Exception('Missing handler configuration.');
		}

		// host
		if (empty($this->config['host'])) {
			throw new \Exception('Unknown "host" configuration.');
		}

		// from:
		if (empty($this->config['from'])) {
			throw new \Exception('A "from" address must be specified.');
		}

		// port
		if (empty($this->config['port']) || !is_numeric($this->config['port'])) {
			$this->config['port'] = 25;
		}

		// init transport
		$transport = new Swift_SmtpTransport($this->config['host'], $this->config['port'], $this->config['encryption']);

		// username
		if (!empty($this->config['username'])) {
			$transport->setUsername($this->config['username']);
		}

		// password
		if (!empty($this->config['password'])) {
			$transport->setPassword($this->config['password']);
		}

		// stream options (ssl)
		if (!empty($this->config['stream_options'])) {
			$transport->setStreamOptions($this->config['stream_options']);
		}

		// localhost
		if (php_sapi_name()==='cli' || (isset($_SERVER['REMOTE_ADDR']) && !in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1']))) {
			$transport->setLocalDomain('[127.0.0.1]');
		}

		// init mailer
		self::$mailer = new Swift_Mailer($transport);

		// antiflood plugin
		$antiflood = Queue::getAntifloodConfig();
		if ($antiflood && $antiflood[0]>0) {
			self::$mailer->registerPlugin(new \Swift_Plugins_AntiFloodPlugin($antiflood[0], $antiflood[1]));
		}
	}

	/**
	 * Send a message
	 * @param array $destination
	 * @param mixed $body
	 * @param string $subject
	 * @return bool|int An integer is returned which includes the number
	 * of successful recipients or FALSE if an error occurred
	 */
	public function send($destination, $body, $subject = NULL, $attachments = [])
	{
		$this->error = NULL;
		$this->failed = [];

		// [ 'address' => 'name', 'address' => 'name', ... ]
		$recipients = [];

		// check data
		foreach ($destination as $address => $name) {

			// ['addr1@foo.com', 'addr2@foo.com', ...]
			if (is_int($address)) {

				if (!empty($name) && filter_var($name, FILTER_VALIDATE_EMAIL)) {
					$recipients[$name] = '';
				} else {
					$this->failed[] = $name;
					$this->error = '"'. $name . '" is not a valid email address.';
				}

			// ['addr1@foo.com' => 'John', 'addr2@foo.com' => 'David', ...]
			} else {
				if ($address && filter_var($address, FILTER_VALIDATE_EMAIL)) {
					$recipients[$address] = $name;
				} else {
					$this->failed[] = $address;
					$this->error = '"'. $address . '" is not a valid email address.';
				}
			}
		}

		// nothing to send
		if ($this->failed && count($destination)===count($this->failed)) {
			return 0;
		}

		// start transport
		$transport = self::$mailer->getTransport();
		if (!$transport->ping()) {
			$transport->stop();
			$transport->start();
		}

		$message = new Swift_Message();
		$message->setFrom($this->config['from']);
		$message->setBody($body, 'text/html');
		$message->setSubject((string)$subject);

		// attachments
		if ($attachments && is_array($attachments)) {
			foreach ($attachments as $file) {
				if (!empty($file['path']) && file_exists($file['path'])) {

					$attachment = \Swift_Attachment::fromPath($file['path']);
					if ($attachment) {

						if (!empty($file['contentType'])) {
							$attachment->setContentType($file['contentType']);
						}

						if (!empty($file['filename'])) {
							$attachment->setFilename($file['filename']);
						}

						$message->attach($attachment);
					}
				}
			}
		}

		$numSent = 0;

		try {

			foreach ($recipients as $address => $name) {

				if (!empty($name)) {
					$message->setTo([$address => $name]);
				} else {
					$message->setTo($address);
				}

				$f = [];
				$numSent += self::$mailer->send($message, $f);
				$this->failed = array_merge($this->failed, $f);
			}

		} catch (\Swift_SwiftException $e) {
			$this->error = $e->getMessage();
		}

		return $numSent;
	}

}
