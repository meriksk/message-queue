<?php

namespace meriksk\MessageQueue;

use PDO;
use PDOException;
use Exception;
use meriksk\MessageQueue\handlers\BaseHandler;

/**
 * Message Queue Agent
 *
 * @sample
 * <code>
 * <pre>
 *
 * // Example 1: Basic
 *
 * use meriksk\MessageQueue\Queue;
 * $message = Queue::message(Queue::EMAIL);
 * $message->body = 'Message body';
 * $message->subject = 'Subject';
 * $message->addDestination('recipient1@email.com');
 * $message->addDestination('recipient2@email.com');
 * 
 * // attachment
 * $message->addAttachment($filePath2, 'custom_name', 'application/pdf');
 * 
 * // save
 * $message->save();
 *
 * // Example 2: Cron job
 * Queue::antiflood(1, 3)
 * Queue::cron();
 *
 * <pre>
 * </code>
 *
 * @version 1.0
 */

/**
 * Queue class file.
 */
class Queue
{

	/**
	 * @var string
	 */
	const EMAIL = 'email';
	const SMS = 'sms';
	const FILE = 'file';
	const SOCKET = 'socket';

	/**
	 * Supported message types
	 */
	const HANDLERS = [
		self::EMAIL,
		self::SMS,
	];

	/**
	 * @var string
	 */
	const STATUS_ENABLED = 'enabled';
	const STATUS_DISABLED = 'disabled';

	/** @var array Default Queue configuration */
	private static $defaultConfig = [
		'temp_dir' => '/tmp/message_queue',
		'max_attemps' => 5,
		'db' => [
			'host' => 'localhost',
			'username' => 'root',
			'password' => '',
			'database' => '',
		],
	];

    /** @var int the number of recipients to send before restarting handler. */
    private static $antifloodThreshold = 0;

	 /** @var int The number of seconds to sleep for during a restart. */
    private static $antifloodSleep = 3;

	/** @var \PDO An object which represents the connection to a MySQL Server. */
	private static $db;
	private static $lastDbError;
	private static $config;
	private static $handlers = [];

	/**
	 * Class destructor
	 */
	public final function __destruct()
	{
		self::$db = null;
	}

	/**
	 * Get DB Connection
	 * @return PDO object
	 */
	public static function getDb()
	{
		if (self::$db===NULL) {

			// read config
			$db = self::configGet('db');
			if (!$db || !is_array($db)) {
				throw new Exception('Invalid configuration');
			}

			$host = isset($db['host']) ? $db['host'] : 'localhost';
			$database = isset($db['database']) ? $db['database'] : 'message_queue';
			$username =  isset($db['username']) ? $db['username'] : 'web';
			$password =  isset($db['password']) ? $db['password'] : '';
			
			$dsn = 'mysql:host='. $host .';dbname='. $database;
			self::$db = new PDO($dsn, $username, $password);
		}

		return self::$db;
	}

	/**
	 * Set DB Connection
	 * @param PDO $pdo connection
	 * @return bool
	 */
	public function setDb(PDO $pdo)
	{
		if ($pdo && is_object($pdo) && $pdo instanceof PDO) {
			self::$db = $pdo;
			return true;
		}

		return false;
	}

	/**
	 * Returns table name
	 * @return string
	 */
	public static function tableName()
	{
		return 'tbl_messages_queue';
	}

	/**
	 * Get/Set configuration
	 * @param array $cfg
	 * @return array
	 */
	public static function config(array $cfg = null)
	{
		// set
		if (is_array($cfg)) {

			// set deufalt configuration
			self::$config = self::$defaultConfig;

			foreach ($cfg as $k1 => $v1) {
				if (is_array($v1)) {
					foreach ($v1 as $k2 => $v2) {
						self::$config[$k1][$k2] = $v2;
					}
				} else {
					self::$config[$k1] = $v1;
				}
			}
		}

		// set deufalt configuration
		if (self::$config===null) {
			self::$config = self::$defaultConfig;
		}

		// return
		return self::$config;
	}

	/**
	 * Get a configuration value
	 * @param string $key attribute value or path (e.g. "db.hostname")
	 * @return mixed
	 */
	public static function configGet($key)
	{
		// invalid attribute
		if ($key==='' || $key===null || $key===false) {
			return false;
		}
		
		// config
		$cfg = self::config();

		// path value
		$separator = '.';
		if (strpos($key, $separator)!==0) {
			$path = explode($separator, $key);
			$path = array_filter($path, function($val) { return !($val===null || $val===''); });
			if ($path) {

				// value reference
				$value = &$cfg;
				foreach ($path as $key) {
					if (is_string($key)) { $key = trim($key); }
					if ($key==='' || $key===false || $key===null)
						continue;

					$value = &$value[$key];
				}

				return $value;
			} else {
				return null;
			}
		} else {
			return array_key_exists($key, $cfg) ? $cfg[$key] : null;
		}

	}

	/**
	 * Set a configuration value
	 * @param string $key attribute value or path (e.g. "db.hostname")
	 * @param mixed $value
	 * @return bool|array Returns configuration or <b>FALSE</b> on failure.
	 */
	public static function configSet($key, $value)
	{

		// invalid attribute
		if ($key==='' || $key===null || $key===false) {
			return false;
		}
		
		// config 
		$cfg = self::config();

		// path value
		$separator = '.';
		if (strpos($key, $separator)!==0) {

			$path = explode($separator, $key);
			$path = array_filter($path, function($val) { return !($val===null || $val===''); });
			if ($path) {

				// value reference
				$attr = &$cfg;
				foreach ($path as $key) {
					if (is_string($key)) { $key = trim($key); }
					if ($key==='' || $key===false || $key===null)
						continue;

					$attr = &$attr[$key];
				}

				$attr = $value;
				return self::$config;

			} else {
				return null;
			}
		} else {
			self::$config[$key] = $value;
			return self::$config;
		}
	}

	/**
	 * Creates a new message
	 * @param string $type Message type
	 * @param string|array $destination
	 * @param string $subject
	 * @param string $body
	 * @return Message
	 */
	public static function message($type, $destination=NULL, $subject=NULL, $body=NULL)
	{
		// new message
		$message = new Message($type);

		// destination
		if ($destination) {
			$message->addDestination($destination);
		}

		// subject
		if (is_string($subject)) {
			$message->setSubject($subject);
		}

		// body
		if (is_string($body)) {
			$message->setBody($body);
		}

		return $message;
	}

	/**
	 * Reset current queue
	 */
	public static function reset()
	{
		// reset last error
		self::$lastDbError = NULL;
	}

	/**
	 * Add a message to the Queue
	 * @param \meriksk\MessageQueue\Message $message
	 * @return Message object or <b>FALSE</b> on failure.
	 */
	public static function add(Message $message)
	{
		if ($message) {
			$msg = clone $message;
			return $msg->save(true);
		}

		return false;
	}

	/**
	 * Get message(s) from queue (database)
	 * @param int|array $ids
	 * @return Message|Message[]
	 */
	public static function get($ids)
	{
		$msgIds = [];

		// find one message
		if (is_numeric($ids)) {
			$msgIds[] = $ids;
		// find multiple messages
		} elseif (is_array($ids)) {
			foreach ($ids as $m) {
				if ($m && is_numeric($m)) {
					$msgIds[] = (int)$m;
				}
			}
		}

		$sql = 'SELECT * FROM '. Queue::tableName() .' WHERE messageId_n IN ('. implode(',', $msgIds) .')';
		$stm = self::getDb()->query($sql);
		if ($stm) {
			$data = [];
			$result = $stm->fetchAll(PDO::FETCH_ASSOC);
			foreach ($result as $row) {
				$data[] = Message::instantiate($row);
			}

			if (is_numeric($ids)) {
				return !empty($data) ? $data[0] : null;
			} else {
				return $data;
			}

		} else {
			return [];
		}
	}

	/**
	 * Returns the number of messages in queue.
	 * @return int
	 */
	public static function count()
	{
		$sql = 'SELECT COUNT(*) AS cnt FROM '. Queue::tableName();
		$count = self::getDb()->query($sql)->fetchColumn();
		return (int)$count;
	}

	/**
	 * Returns first (oldest) message in the queue.
	 * @return Message object or <b>NULL</b> on failure.
	 */
	public static function first()
	{
		$sql = 'SELECT * FROM '. Queue::tableName() .' ORDER BY messageId_n ASC LIMIT 1';
		$row = self::getDb()->query($sql)->fetch(PDO::FETCH_ASSOC);
		return Message::instantiate($row);
	}

	/**
	 * Returns last (newest) message in the queue.
	 * @return Message object or <b>NULL</b> on failure.
	 */
	public static function last()
	{
		$sql = 'SELECT * FROM '. Queue::tableName() .' ORDER BY messageId_n DESC LIMIT 1';
		$row = self::getDb()->query($sql)->fetch(PDO::FETCH_ASSOC);
		return Message::instantiate($row);
	}

	/**
	 * Deletes all messages in the queue.
	 * @param int $days Deletes messages older than N-days
	 * @return int returns the number of messages that were deleted
	 */
	public static function clear($days = NULL)
	{
		$condition = '';
		$filterDays = is_numeric($days) && $days > 0;

		$pdoParams = [];
		$tableName = self::tableName();

		if ($filterDays) {
			$days = (int)$days;
			if ($days > 0) {
				$dt = new \DateTime();
				$dt->setTimezone(new \DateTimeZone('UTC'));
				$dt->modify("-$days days");

				$condition = ' WHERE dateAdded_d < :dateAdded';
				$params[':dateAdded'] = $dt->getTimestamp();
			}
		}

		// delete data
		$sql = 'DELETE FROM '. $tableName . $condition;
		$stmt = self::getDb()->prepare($sql);
		$deleted = $stmt->execute($pdoParams);

		// reset auto increment value
		if ($filterDays === false && $deleted !== false) {
			$sql = 'DELETE FROM sqlite_sequence WHERE name = :tableName';
			$stmt = self::getDb()->prepare($sql);
			$stmt->execute([':tableName' => $tableName]);
		}
	}

	/**
	 * Delete message from the queue
	 * @param mixed $messages Numeric value, Message object or array of
	 * numeric values or array of Message objects.
	 * @return bool
	 */
	public static function delete($messages)
	{
		// ids to delete
		$ids = [];

		// numeric
		if (is_numeric($messages)) {
			$ids[] = (int)$messages;

		// Message object
		} else if (is_object($messages) && $messages instanceof Message) {
			$ids[] = $messages->getId();

		// find multiple messages
		} elseif (is_array($messages)) {
			foreach ($messages as $m) {
				// numeric
				if (is_numeric($m)) {
					$ids[] = (int)$m;
				} else if (is_object($m) && $m instanceof Message) {
					$ids[] = $m->getId();
				}
			}
		}

		// remove empty values
		$ids = array_filter($ids);

		if ($ids) {
			$sql = 'DELETE FROM '. Queue::tableName() .' WHERE messageId_n IN ('. implode(',', $ids) .')';
			$count = self::getDb()->exec($sql);
			return $count!==false;
		} else {
			return false;
		}
	}

	/**
	 * Returns path to the temporary directory
	 * @return string
	 */
	public static function getTempDirectoryPath()
	{
		$tempDir = self::configGet('temp_dir');
		if (empty($tempDir)) {
			$tempDir = __DIR__ . '/tmp';
		}

		return $tempDir;
	}

	/**
	 * Returns last error message
	 * @return string
	 */
	public static function getLastError()
	{
		return self::$lastDbError;
	}

    /**
     * Set anti-flood configuration
     * @param int $threshold
     * @param int $sleep time in seconds
     */
    public static function antiflood($threshold, $sleep = 5)
    {
        self::$antifloodThreshold = (int)$threshold;
        self::$antifloodSleep = (int)$sleep;
    }

    /**
     * Get anti-flood configuration
	 * @return array
     */
    public static function getAntifloodConfig()
    {
        return [self::$antifloodThreshold, self::$antifloodSleep];
    }


	// -------------------------------------------------------------------------
	//
	// CRON
	//
	// -------------------------------------------------------------------------


	public static function console($text)
	{
		echo $text;
	}

	private static function parseArguments()
	{
		global $argv;
		$arr = [];

		for ($i = 1; $i < count($argv); $i++) {
			if (preg_match('/^--([^=]+)(=(.*))?/', $argv[$i], $match)) {
				$arr[$match[1]] = isset($match[3]) ? $match[3] : NULL;
			}
		}

		return $arr;
	}

	/**
	 * Process queue
	 */
	public static function cron()
	{
		// command line arguments
		$args = self::parseArguments();

		// arguments
		$id = isset($args['id']) ? (int)$args['id'] : 0;
		$forceRun = array_key_exists('force', $args);
		$help = array_key_exists('help', $args);
		$maxAttempts = array_key_exists('max_attemps', $args) ? (int)$args['max_attemps'] : NULL;
		if ($maxAttempts <= 0) { $maxAttempts = NULL; }

		// run
		self::console("\n");
		self::console("MESSAGE QUEUE AGENT");
		self::console("\n\n");

		// help
		if ($help===true) {
			self::console("Usage: command [--] [args...]");
			self::console("\n");
			self::console("\n  --id                 Process message with specific ID (primary key)");
			self::console("\n  --force              Force run (skip checking last attempt date");
			self::console("\n  --help               This help");
			self::console("\n  --max_attemps        Override maximum allowed attempts for sending a message (default: 2)");
			self::console("\n\n");
		}

		if ($maxAttempts === null) {
			$maxAttempts = (int)self::configGet('max_attemps');
			if ($maxAttempts <= 0) {
				$maxAttempts = self::$defaultConfig['max_attemps'];
			}
		}

		// WHERE condition
		$where = [];

		if ($id > 0) {
			$forceRun = true;
			$where[] = ['AND', 'messageId_n=' . $id];
		}

		if ($forceRun !== true) {
			$where[] = ['AND', 'processing_n=0 AND attempts_n<'. $maxAttempts .' AND (lastAttemptDate_d IS NULL OR lastAttemptDate_d > '.(time()-600) . ')'];
		}

		// where sql
		$whereSql = '';
		if ($where) {
			$whereSql .= ' WHERE';
			foreach ($where as $i => $val) {
				if ($i > 0) { $whereSql .= ' ' . $val[0]; }
				$whereSql .= ' '. $val[1];
			}
		}

		// count messages
		$sql = 'SELECT COUNT(*) FROM '. Queue::tableName() . $whereSql;
		$messagesCount = (int)self::getDb()->query($sql)->fetchColumn();

		self::console("# of messages in queue: $messagesCount\n\n");

		if ($messagesCount) {

			self::console("\tProcessing:\n");

			// get messages
			$sql = 'SELECT messageId_n FROM '. Queue::tableName() . $whereSql;

			$result = self::$db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

			// process data
			$i = 1;

			foreach ($result as $row) {

				// load message
				$message = self::get($row['messageId_n']);
				if (!$message) {
					continue;
				}

				self::console("\n\tMSG #$i");

				// send
				$result = $message->send();

				if ($message->lastError) {
					self::console("\n\tError: " . $message->lastError);
				}

				++$i;

			}

			self::console("\n\n");
			self::console("DONE ...");
			self::console("\n\n");
			return 0;

		} else {

			self::console("\nDONE ...\n\n");
			return 0;
		}
	}

	/**
	 * Init message handler
	 * @param string $type
	 * @return BaseHandler
	 * @throws \Exception
	 */
	public static function getHandler($type)
	{
		if (!isset(self::$handlers[$type])) {

			$className = ucfirst($type).'Handler';
			$classNamespace = __NAMESPACE__ . '\\handlers\\' . $className;

			// check if class exists
			if (!class_exists($classNamespace)) {
				throw new \Exception('Handler class "'. $className .'" does not exists.', 500);
			}

			// init handler
			$cfg = isset(self::$config['handlers'][$type]) ? self::$config['handlers'][$type] : [];
			$handler = new $classNamespace();

			if (!($handler instanceof handlers\BaseHandler)) {
				throw new \Exception('"'. $className .'" must be an instance of BaseHandler.', 500);
			}

			$handler->setConfig($cfg);
			$handler->init();

			self::$handlers[$type] = $handler;
		}

		return self::$handlers[$type];
	}

}
