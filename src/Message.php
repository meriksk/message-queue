<?php

namespace meriksk\MessageQueue;

use PDO;
use Exception;

/**
 * This is the model class for table "tbl_messages_queue".
 */
class Message
{

	/** @var int */
	private $_id;
	/** @var int */
	private $_dateAdded;
	/** @var int */
	private $_processing = 0;
	/** @var string */
	private $_type;
	/** @var array */
	private $_destination = [];
	/** @var string */
	private $_body;
	/** @var string */
	private $_subject;
	/** @var array */
	private $_attachments = [];
	/** @var int */
	private $_attempts = 0;
	/** @var int */
	private $_lastAttemptDate;
	/** @var string */
	private $_lastError;
	/** @var array */
	private $_failed = [];

	private $_attachmentDir;
	private $_attachmentsList;
	private static $_checkTempDir;


	/**
	 * Class constructor
	 * @param string $type Message type
	 * @param string|array $destination
	 * @param string $subject
	 * @param string $body
	 * @return Message
	 */
	public function __construct($type, $destination=NULL, $subject=NULL, $body=NULL)
	{
		$this->setType($type);

		// destination
		if ($destination) {
			$this->addDestination($destination);
		}

		// subject
		if (is_string($subject)) {
			$this->setSubject($subject);
		}

		// body
		if (is_string($body)) {
			$this->setBody($body);
		}
	}

	/**
	 * Returns the value of a component property
     * @param string $name the property name
     * @return mixed the property value or the value of a behavior's property
	 * @throws Exception
	 */
	public function __get($name)
	{
		$getter = 'get'.$name;
		if (method_exists($this,$getter)) { return $this->$getter(); }
		throw new Exception('Property "'. get_class($this) .'.'. $name .'" is not defined.');
	}

    /**
     * Sets the value of a component property.
     * @param string $name the property name or the event name
     * @param mixed $value the property value
     * @throws Exception if the property is not defined
     * @see __get()
     */
	public function __set($name, $value)
	{
		$setter = 'set'.$name;
		if (method_exists($this, $setter)) { return $this->$setter($value); }
		throw new Exception('Property "'. get_class($this) .'.'. $name .'" is not defined.');
	}

	/**
	 * Checks if a property is set, i.e. defined and not null.
     * @param string $name the property name or the event name
     * @return bool whether the named property is set
     * @see http://php.net/manual/en/function.isset.php
	 */
	public function __isset($name)
	{
		$getter = 'get'.$name;
		if (method_exists($this,$getter)) { return $this->$getter()!==NULL; }
		return false;
	}

	/**
	 * Sets a component property to be null.
     * @param string $name the property name
     * @throws Exception if the property is read only.
     * @see http://php.net/manual/en/function.unset.php
	 */
	public function __unset($name)
	{
		$setter = 'set'.$name;
		if (method_exists($this,$setter)) {
			$this->$setter(null);
		} elseif (method_exists($this,'get'.$name)) {
			throw new Exception('Property "'. get_class($this) .'.'. $name .'" is read only.');
		}
	}

	/**
	 * Send the message
	 * @return bool|int An integer is returned which includes the number
	 * of successful deliveries or FALSE if an error occurred
	 */
	public function send()
	{
		// get destination
		$destination = $this->getDestination();
		if (empty($destination)) {
			$this->_lastError = 'No recipient addresses.';
			$this->save();
			return 0;
		}

		$num = 0;

		$this->_lastError = NULL;
		$this->_processing = 1;
		$this->_attempts += 1;
		$this->_lastAttemptDate = time();
		$this->_failed = [];
		$this->save();

		// message handler (init by message type)
		$handler = Queue::getHandler($this->_type);

		try {

			// handle message
			// ---------------------------------------------
			$num += $handler->send($destination, $this->_body, $this->_subject, $this->_attachments);

			// error or failed recipients
			if (!$handler->success()) {

				$this->_lastError = $handler->getLastError();
				$this->_failed = $handler->failed;

				if ($handler->failed) {

					// unset success recipients
					$dest = $this->_destination;
					foreach ($handler->failed as $f) {
						$key = array_search($f, $dest);
						if ($key === false) {
							unset($dest[$key]);
						}
					}

					$this->_destination = $dest;
				}

			}
			// ---------------------------------------------

		} catch (Exception $e) {
			$this->_lastError = $e->getMessage();
		}

		// post processing
		if ($handler->success()) {

			$this->delete();

		} else {

			$this->_processing = 0;
			$this->save();
		}

		return $num;
	}

	/**
	 * Istantiate a message
	 * @param array $data A database result set
	 * @return \meriksk\MessageQueue\Message
	 */
	public static function instantiate($data)
	{
		if (empty($data) || !is_array($data)) {
			return null;
		}

		$message = new Message($data['type_c']);
		$message->subject = $data['subject_c'];
		$message->body = $data['body_c'];
		$message->destination = json_decode($data['destination_c'], true);

		// private properties
		$refClass = new \ReflectionClass($message);

		$refs = [
			'_id' => ($data['messageId_n'] > 0 ? (int)$data['messageId_n'] : null),
			'_dateAdded' => ($data['dateAdded_d'] > 0 ? (int)$data['dateAdded_d'] : null),
			'_processing' => (int)$data['processing_n'],
			'_attempts' => (int)$data['attempts_n'],
			'_lastAttemptDate' => ($data['lastAttemptDate_d'] > 0 ? (int)$data['lastAttemptDate_d'] : null),
			'_lastError' => (!empty($data['lastError_c']) ? $data['lastError_c'] : null),
			'_failed' => (!empty($data['failed_c']) ? json_decode($data['failed_c'], true) : []),
			'_attachments' => (!empty($data['attachments_c']) ? json_decode($data['attachments_c'], true) : []),
		];

		foreach ($refs as $priv => $value) {
			$prop = $refClass->getProperty($priv);
			$prop->setAccessible(true);
			$prop->setValue($message, $value);
		}

		return $message;
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
		$stm = Queue::getDb()->query($sql);
		if ($stm) {
			$data = [];
			$result = $stm->fetchAll(PDO::FETCH_ASSOC);
			foreach ($result as $row) {
				$data[] = self::instantiate($row);
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

	// -------------------------------------------------------------------------
	// HELPER FUNCTIONS
	// -------------------------------------------------------------------------


	/**
	 * Returns message ID.
	 * @return int
	 */
	public function getId()
	{
		return (int)$this->_id;
	}

	/**
	 * Return <b>TRUE</b> if a message is being processed, <b>FALSE</b> otherwise.
	 * @return bool
	 */
	public function isProcessing()
	{
		return (int)$this->processing_n===1;
	}

	/**
	 * Returns whether message is a valid message
	 * @return bool
	 */
	public function isValid()
	{
		return $this->_type && $this->_destination;
	}

	/**
	 * Returns the number of attempts.
	 * @return int
	 */
	public function getAttemptsCount()
	{
		return (int)$this->attempts_n;
	}

	/**
	 * Returns date of create a message.
	 * @param mixed $format
	 * @return int
	 */
	public function getDateAdded($format = 'd.m.Y H:i:s')
	{
		if (!empty($this->_dateAdded)) {
			if ($format && is_string($format)) {
				$dt = new \DateTime('@'.$this->_dateAdded);
				$dt->setTimezone(new \DateTimeZone('UTC'));
				return $dt->format($format);
			} else {
				return $this->_dateAdded;
			}
		}

		return NULL;
	}

	/**
	 * Get message type (Email, SMS, Socket, ...)
	 * @return string
	 */
	public function getType()
	{
		return $this->_type;
	}

	/**
	 * Set message type
	 * @param string $type
	 * @throws Exception
	 */
	public function setType($type)
	{
		if ($type && in_array($type, Queue::HANDLERS)) {
			$this->_type = $type;
		} else {
			throw new Exception('Not supported message type.');
		}
	}

	/**
	 * Get message body
	 * @return string
	 */
	public function getSubject()
	{
		return $this->_subject;
	}

	/**
	 * Set message subject
	 * @param string $subject
	 * @return $this instance
	 */
	public function setSubject($subject)
	{
		if ($subject && is_string($subject)) {
			$this->_subject = trim((string)$subject);
		}

		return $this;
	}

	/**
	 * Returns <b>TRUE</b> if subject is set, <b>FALSE</b> otherwise.
	 * @return bool
	 */
	public function isSubject()
	{
		return !empty($this->_subject);
	}

	/**
	 * Returns message body
	 * @return string
	 */
	public function getBody()
	{
		return $this->_body;
	}

	/**
	 * Set message body
	 * @param string $body
	 * @return $this instance
	 */
	public function setBody($body)
	{
		if ($body && is_string($body)) {
			$this->_body = trim((string)$body);
		}

		return $this;
	}

	/**
	 * Returns <b>TRUE</b> if message body is set, <b>FALSE</b> otherwise.
	 * @return bool
	 */
	public function isBody()
	{
		return !empty($this->_body);
	}

	/**
	 * Returns message destination
	 * @return array
	 */
	public function getDestination()
	{
		return $this->_destination;
	}

	/**
	 * Set destination
	 * @param string $destination
	 * @return $this
	 * @see addDetination()
	 */
	public function setDestination($destination)
	{
		// reset
		$this->_destination = [];

		// add
		$this->addDestination($destination);

		return $this;
	}

	/**
	 * Adds message destination
	 * @param string|array $destination
	 */
	public function addDestination($destination)
	{
		switch ($this->_type) {
			case Queue::EMAIL:
				$this->setEmailDestination($destination);
				break;
			case Queue::SMS:
				$this->setSmsDestination($destination);
				break;
			case Queue::FILE:
				$this->setFileDestination($destination);
				break;
			case Queue::SOCKET:
				$this->setSocketDestination($destination);
				break;
			default:
				throw new Exception('Not supported message type.');
		}

		return $this;
	}

	/**
	 * Returns <b>TRUE</b> if destination is set, <b>FALSE</b> otherwise.
	 * @return bool
	 */
	public function isDestination()
	{
		return !empty($this->_destination);
	}

	/**
	 * Push message
	 * @param bool $forceInsert
	 * @return Message object or <b>FALSE</b> on failure.
	 */
	public function save($forceInsert = false)
	{
		// is valid?
		if (!$this->isValid()) {
			return false;
		}

		$db = Queue::getDb();
		if (!$db) { return false; }

		// new record
		if (empty($this->_id) || $forceInsert===true) {

			// create a prepared statement
			$sql = '
				INSERT INTO tbl_messages_queue (dateAdded_d, processing_n, type_c, destination_c, body_c, subject_c, attachments_c, attempts_n, lastAttemptDate_d, lastError_c, failed_c)
				VALUES(:dateAdded, :processing, :type, :destination, :body, :subject, :attachments, :attempts, :lastAttemptDate, :lastError, :failed)';
			$stmt = $db->prepare($sql);
			if ($stmt) {

				// attachments
				$this->processAttachments();

				// set private attributes
				$this->_dateAdded = time();

				// statement data
				$data = [
					':dateAdded' => $this->_dateAdded,
					':processing' => $this->_processing,
					':type' => $this->_type,
					':destination' => json_encode($this->_destination, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
					':body' => $this->_body,
					':subject' => $this->_subject,
					':attachments' => (!empty($this->_attachments) ? json_encode($this->_attachments, JSON_UNESCAPED_SLASHES) : NULL),
					':attempts' => $this->_attempts,
					':lastAttemptDate' => (!empty($this->_lastAttemptDate) ? $this->_lastAttemptDate : NULL),
					':lastError' => (!empty($this->_lastError) ? $this->_lastError : NULL),
					':failed' => (!empty($this->_failed) ? json_encode($this->_failed, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : NULL),
				];

				if ($stmt->execute($data)) {
					$this->_id = (int)$db->lastInsertId();
					return $this;
				}
			}

		// update record
		} else {

			// create a prepared statement
			$sql = '
				UPDATE tbl_messages_queue SET
					processing_n = :processing,
					destination_c = :destination,
					attempts_n = :attempts,
					lastAttemptDate_d = :lastAttemptDate,
					lastError_c = :lastError,
					failed_c = :failed
				WHERE messageId_n = :id
			';

			$stmt = $db->prepare($sql);

			if ($stmt) {
				// statement data
				$data = [
					':id' => $this->_id,
					':processing' => $this->_processing,
					':destination' => json_encode($this->_destination),
					':attempts' => $this->_attempts,
					':lastAttemptDate' => $this->_lastAttemptDate,
					':lastError' => (!empty($this->_lastError) ? $this->_lastError : NULL),
					':failed' => (!empty($this->_failed) ? json_encode($this->_failed) : NULL),
				];

				if ($stmt->execute($data)) {
					return $this;
				}
			}
		}//if

		return false;
	}

	/**
	 * Delete a message from the queue
	 * @return bool
	 */
	public function delete()
	{
		if ($this->_id > 0) {
			$sql = 'DELETE FROM '. Queue::tableName() .' WHERE messageId_n=:id';
			$stmt = Queue::getDb()->prepare($sql);
			$stmt->execute([':id' => $this->_id]);
			$count = $stmt->rowCount();
			if ($count!==false) {
				$this->deleteAttachmentFiles();
				return true;
			}
		}

		return false;
	}

	/**
	 * Clear destination
	 * @return static instance
	 */
	public function clearDestination()
	{
		$this->_destination = [];
		return $this;
	}

	/**
	 * Adds email addresses to message destination
	 * @param string|array $dest
	 * @return MessageQueue model
	 * @throws Exception
	 */
	private function setEmailDestination($dest)
	{
		$arr = [];
		if (is_string($dest)) {
			$arr = [$dest];
		} else if (is_array($dest)) {
			$arr = $dest;
		}

		if (!empty($arr)) {
			foreach ($arr as $key => $val) {

				$emailAddress = false;
				$recipientName = false;

				// key is an email address
				// [email address => name]
				if (is_string($key) && strpos($key, '@')!==false) {

					$emailAddress = trim($key);
					$recipientName = trim($val);

				// key is a numeric value
				// [0 => email address]
				} elseif (is_numeric($key)) {
					$emailAddress = trim($val);
				}

				if ($emailAddress) {
					if (filter_var($emailAddress, FILTER_VALIDATE_EMAIL)) {
						if ($recipientName) {
							$this->_destination[$emailAddress] = $recipientName;
						} else {
							$this->_destination[] = $emailAddress;
						}
					} else {
						$this->_failed[$emailAddress] = $recipientName;
					}
				}
			}
		}//if

		return $this;
	}

	/**
	 * Adds SMS message destination
	 * @param string|array $dest
	 * @return MessageQueue model
	 * @throws Exception
	 */
	private function setSmsDestination($dest)
	{
		$arr = [];
		if (is_string($dest)) {
			$dest = trim($dest);
			$arr = [$dest];
		} else if (is_array($dest)) {
			$arr = array_unique(array_filter(array_map('trim', $dest)));
		}

		if (!empty($arr)) {
			foreach ($arr as $val) {
				$v = preg_replace('/[^0-9\+\-]/', '', trim($val));
				if (!empty($v)) {
					if (preg_match('/[0-9\+\-]+/', $v)) {
						$this->_destination[] = $v;
					} else {
						$this->_failed[] = $v;
					}
				}
			}
		}

		return $this;
	}

	/**
	 * Adds FILE message destination
	 * @param string|array $dest
	 * @return MessageQueue model
	 * @throws Exception
	 */
	private function setFileDestination($dest)
	{
		$arr = [];
		if (is_string($dest)) {
			$dest = trim($dest);
			$arr = [$dest];
		} else if (is_array($dest)) {
			$arr = array_unique(array_filter(array_map('trim', $dest)));
		}

		if (!empty($arr)) {
			foreach ($arr as $val) {
				if ($val && strpos($val, DIRECTORY_SEPARATOR)!==false) {
					$this->_destination[] = $val;
				}
			}
		}

		return $this;
	}

	/**
	 * Adds SOCKET destination
	 * @param string|array $dest
	 * @return static instance
	 * @throws Exception
	 */
	private function setSocketDestination($dest)
	{
		$arr = [];
		if (is_string($dest)) {
			$dest = trim($dest);
			$arr = [$dest];
		} else if (is_array($dest)) {
			$arr = array_unique(array_filter(array_map('trim', $dest)));
		}

		if (!empty($arr)) {
			foreach ($arr as $val) {
				$val = explode(':', $val);
				$ipAddress = trim($val[0]);
				$port = isset($val[1]) ? (int)$val[1] : 0;

				if (!empty($ipAddress)) {
					if (filter_var($ipAddress, FILTER_VALIDATE_IP)) {
						$this->_destination[] = $ipAddress . (($port>0) ? ':'.$port : '');
					}
				} else {
					$this->_failed[] = $ipAddress . (($port>0) ? ':'.$port : '');
				}
			}
		}

		return $this;
	}

	/**
	 * Returns last error message
	 * @return string
	 */
	public function getLastError()
	{
		return $this->_lastError;
	}

	/**
	 * Returns list of failed recipients
	 * @return array
	 */
	public function getFailedRecipients($destination = NULL)
	{
		$json = json_decode($this->failed_c, true);
		$arr = is_array($json) ? $json : [];
		return $destination!==NULL ? (isset($arr[$destination]) ? $arr[$destination] : []) : $arr;
	}

    /**
	 * Set failed recipients
	 * @param array $recipients
	 * @return static instance
     */
	public function setFailedRecipients(array $recipients)
	{
		if (is_array($recipients) && !empty($recipients)) {
			$recipients = array_filter($recipients);
			$this->failed_c = !empty($recipients) ? json_encode($recipients) : NULL;
		} else {
			$this->failed_c = NULL;
		}

		return $this;
	}

	/**
	 * Get message attachments
	 * @return array
	 */
	public function getAttachments()
	{
		return $this->_attachments;
	}

    /**
     * Add an attachment from a path on the filesystem or multiple attachments.
     * @param string|array $path Path to the attachment or array for multiple files
     * @param string $name Overrides the attachment name
     * @param string $type File extension (MIME) type
	 * @throws Exception
     * @return $this
	 * @uses attachFromPath()
     */
	public function addAttachment($path, $name = NULL, $type = NULL)
	{
		// multiple attachments
		if (is_array($path)) {
			foreach ($path as $file) {
				if (is_array($file)) {
					if (isset($file[0])) {
						$path = isset($att[0]) ? $att[0] : null;
						$name = isset($att[1]) ? $att[1] : null;
						$type = isset($att[2]) ? $att[2] : null;
						$this->attachFromPath($path, $name, $type);
					}
				} elseif (is_string($file)) {
					$this->attachFromPath($file);
				}
			}
		// single file
		} else {
			$this->attachFromPath($path, $name, $type);
		}

		return $this;
	}

	/**
	 * Add an attachment from a path on the filesystem.
	 * @param string $path Path to the attachment
	 * @param string $name Overrides the attachment name
	 * @param string $type
	 * @return bool
	 * @throws Exception
	 */
	public function attachFromPath($path, $name = NULL, $type = NULL)
	{

		$path = trim((string)$path);
		if (empty($path)) {
			return false;
		}

		// check source file
		$path = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $path);
		if (!file_exists($path)) {
			throw new Exception('Source file "'. $path .'" does not exists.', 404);
		}

		// temporary directory
		$tempDir = Queue::getTempDirectoryPath();

		// check temporary directory
		if (self::$_checkTempDir===NULL) {
			self::$_checkTempDir = true;

			// temporary directory - test
			if (!is_dir($tempDir)) {
				mkdir($tempDir); chmod($tempDir, 0777);
			}

			if (!is_writable($tempDir)) {
				throw new Exception('"'. $tempDir . '" is not writeable or does not exists.', 500);
			}
		}

		// is already attached?
		if (is_array($this->_attachmentsList) && isset($this->_attachmentsList[$path])) {
			return $this;
		}

		$this->_attachmentsList[$path] = [$name, $type];

		return $this;
	}

	/**
	 * Save attachments
	 * @return $this
	 */
	private function processAttachments()
	{
		// empty list
		if (empty($this->_attachmentsList)) {
			return;
		}

		$this->_attachments = [];

		foreach ($this->_attachmentsList as $path => $file) {

			// temporary directory
			if ($this->_attachmentDir === null) {
				$tempDir = Queue::getTempDirectoryPath();
				$this->_attachmentDir = $tempDir . DIRECTORY_SEPARATOR . uniqid();

				if (!file_exists($this->_attachmentDir)) {
					if (mkdir($this->_attachmentDir)) { chmod($this->_attachmentDir, 0777); }
				}
			}

			$filename = !empty($file[0]) && is_string($file[0]) ? trim($file[0]) : basename($path);
			$type = !empty($file[1]) && is_string($file[1]) ? trim($file[1]) : mime_content_type($path);
			$destPath = $this->_attachmentDir . DIRECTORY_SEPARATOR . $filename;

			// copy
			if (copy($path, $destPath)) {
				$this->_attachments[] = [
					'filename' => $filename,
					'path' => $destPath,
					'type' => $type,
				];
			}
		}//foreach
	}

	/**
	 * Delete temporary files
	 * @param static instance
	 */
	public function deleteAttachmentFiles()
	{
		// attachments
		$attachments = $this->getAttachments();

		if (!empty($attachments)) {
			$first = current($attachments);
			$dir = !empty($first['path']) ? dirname($first['path']) : false;
			if ($dir && is_dir($dir)) {
				$files = glob($dir . DIRECTORY_SEPARATOR . '*');

				// file
				foreach ($files as $file) { @unlink($file); }

				// directory
				if (is_writable($dir)) { @rmdir($dir); }
			}
		}
	}

	/**
	 * Delete message by Id
	 * @param int $id of message
	 * @return bool
	 */
	public static function deleteById($id)
	{
		$message = Queue::get($id);
		return $message ? $message->delete() : false;
	}

}
