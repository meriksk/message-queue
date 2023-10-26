<?php
namespace meriksk\MessageQueue\handlers;

use RuntimeException;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use meriksk\MessageQueue\handlers\BaseHandler;


/**
 * EmailHandler class file.
 * @see https://symfony.com/doc/current/reference/configuration/swiftmailer.html
 */
class EmailHandler extends BaseHandler
{

	/**
	 * Mailer instance
	 * @var Mailer
	 */
	private static $mailer;

	public $transportFactory = null;

	/**
	 * @var array Handler default configuration.
	 */
	protected $defaultConfig = [
		'dsn' => 'smtp://user:pass@smtp.example.com:25',
		'scheme' => 'smtp',
		'host' => '',
		'username' => '',
		'password' => '',
		'port' => 465,		
		'from' => '',
		'charset' => 'utf-8',
		'options' => [],
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
			throw new RuntimeException('Missing handler configuration.');
		}

		// from:
		if (empty($this->config['from'])) {
			throw new RuntimeException('A "from" address must be specified.');
		}

		// port
		if (empty($this->config['port']) || !is_numeric($this->config['port'])) {
			$this->config['port'] = 25;
		}

		// init transport
		$transport = $this->createTransport($this->config);
		self::$mailer = new Mailer($transport);
	}

    private function getTransportFactory()
    {
        if (isset($this->transportFactory)) {
            return $this->transportFactory;
        }


        $defaultFactories = Transport::getDefaultFactories();
        return new Transport($defaultFactories);
    }

	private function createTransport(array $config = [])
    {
        //if (array_key_exists('enableMailerLogging', $config)) {
        //    $this->enableMailerLogging = $config['enableMailerLogging'];
        //    unset($config['enableMailerLogging']);
        //}

		$transportFactory = $this->getTransportFactory();

        if (array_key_exists('dsn', $config)) {
            $transport = $transportFactory->fromString($config['dsn']);
        } elseif (array_key_exists('dsn', $config) && $config['dsn'] instanceof Dsn) {
            $transport = $transportFactory->fromDsnObject($config['dsn']);
        } elseif(array_key_exists('scheme', $config) && array_key_exists('host', $config)) {
            $dsn = new Dsn(
                $config['scheme'],
                $config['host'],
                $config['username'] ?? '',
                $config['password'] ?? '',
                $config['port'] ?? '',
                $config['options'] ?? [],
            );

			
			// stream options (ssl)
			/*
			if (!empty($this->config['stream_options'])) {
				$transport->setStreamOptions($this->config['stream_options']);
			}
			// localhost
			if (php_sapi_name()==='cli' || (isset($_SERVER['REMOTE_ADDR']) && !in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1']))) {
				$transport->setLocalDomain('[127.0.0.1]');
			}
			*/

			$transport = $transportFactory->fromDsnObject($dsn);
        } else {
            throw new RuntimeException('Transport configuration array must contain either "dsn", or "scheme" and "host" keys.');
        }

        return $transport;
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

		// from
		if (is_string($this->config['from'])) {
			$from = new Address($this->config['from']);
		} elseif (is_array($this->config['from'])) {
			$address = array_keys($this->config['from']);
			$name = array_values($this->config['from']);
			$from = new Address($address[0], $name[0]);
		}

		// check data
		$recipients = [];
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
		//$transport = self::$mailer->getTransport();
		//if (!$transport->ping()) {
		//	$transport->stop();
		//	$transport->start();
		//}

		$email = new Email();
		$email->from($from);
		$email->html($body);
		$email->subject((string)$subject);

		// attachments
		if ($attachments && is_array($attachments)) {
			foreach ($attachments as $file) {
				if (!empty($file['path']) && file_exists($file['path'])) {					
					if (empty($file['filename'])) {
						$file['filename'] = basename($file['path']);
					}
					if (empty($file['type'])) {
						$file['type'] = mime_content_type($file['path']);						
					}

					$email->attachFromPath($file['path'], $file['filename'], $file['type']);
				}
			}
		}

		$numSent = 0;
		foreach ($recipients as $address => $name) {
			
			$email->to(new Address($address, $name));

			try {
				self::$mailer->send($email);
				$numSent++;
			} catch (TransportExceptionInterface $e) {
				$this->error = $e->getMessage();
				$this->failed[] = $address;				
			}
		}

		return $numSent;
	}

}
