<?php

namespace meriksk\MessageQueue\handlers;

use meriksk\MessageQueue\Queue;
use meriksk\MessageQueue\handlers\BaseHandler;


/**
 * SmsHandler class file.
 */
class SocketHandler implements BaseHandler
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
	 * @var string describing a socket error
	 */
	private $error;

	/**
	 * @var int error code
	 */
	private $errorCode;

	/**
	 * @var resource
	 */
	private $socket;

	/**
	 * @var string IP address
	 */
	private $address;

	/**
	 * @var int port number
	 */
	private $port;

	/**
	 * @var bool
	 */
	private $debug;


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
		$successful = 0;

		foreach ($recipients as $recipient) {
			if (!empty($model->body_c)) {

				$tmp = explode(':', $recipient);

				$this->address = $tmp[0];
				$this->port = (isset($tmp[1]) && is_numeric($tmp[1])) ? (int)$tmp[1] : 0;

				if (!empty($this->address) && !empty($this->port)) {

					// write to socket
					$this->send($model->body_c);

					// close socket
					$this->closeSocket();

					// wait 200ms
					usleep(200 * 1000);

				}
			}
		}

		return $successful;
	}

	// -------------------------------------------------------------------------
	// Private methods
	// -------------------------------------------------------------------------

	/**
	 * Write to a socket
	 * @param string $data
	 * @return bool
	 */
	private function send($data)
	{
		if (!empty($data) && $this->openSocket()) {

			$datagram = sprintf('%c%c%s%c', self::STX, self::ADR, $data, self::ETX);
			$res = socket_write($this->socket, $datagram);

			if ($this->debug) {
				consoleLog('Sending message ... ' . $data);
			}

			if ($res !== false) {
				//$this->closeSocket();
				$this->error = null;
				$this->errorCode = 0;
				return true;
			}

			$this->error = socket_strerror(socket_last_error());
			$this->errorCode = socket_last_error();

		}

		return false;
	}

	/**
	 * Connect to a socket
	 * @return bool
	 */
	private function openSocket()
	{
		if (!empty($this->socket)) {

			$this->error = null;
			$this->errorCode = 0;

			return true;

		} else {
			if ($this->debug) {
				consoleLog("Opening socket ... ", false);
			}

			$this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
			if ($this->socket !== false ) {

				socket_set_nonblock($this->socket);
				$error = null;
				$attempts = 0;
				$timeout = 3000;
				$connected = false;

				while (
					!($connected = @socket_connect($this->socket, $this->address, $this->port))
					&&
					($attempts++ < $timeout)
				) {

					$error = socket_last_error();

					// already connected
					if ($error == SOCKET_EISCONN) {
						$connected = true;
						break;
					}

					if ($error != SOCKET_EINPROGRESS && $error != SOCKET_EALREADY) {
						echo "Error Connecting Socket: ".socket_strerror($error) . "\n";
						socket_close($this->socket);
						return false;
					}

					usleep(1000);

				}//while

				if ($connected) {
					consoleLog("success", true, true);

					$this->error = null;
					$this->errorCode = 0;
					return true;

				} else {

					$this->error = socket_strerror(socket_last_error());
					$this->errorCode = socket_last_error();
					socket_close($this->socket);
					throw new Exception("Error Connecting Socket: Connect Timed Out After " . $timeout/1000 . " seconds. ". $this->error, 500);
				}
			}

		}

		return false;
	}

	/**
	 * Disconnect from socket
	 * @return void
	 */
	private function closeSocket()
	{
		if ($this->socket) {

			if( $this->debug ) {
				consoleLog('Closing socket ... ', false);
			}

			socket_close($this->socket);
			$this->socket = null;

			if ($this->debug) {
				consoleLog('success', true, true);
			}
		}
	}

	/**
	 * Test if socket is writable
	 * @return bool
	 */
	private function checkSocket()
	{
		if ($this->socket) {
			$res = socket_write($this->socket, 'test');
			if ($res !== false) {
				return true;
			}
		}

		return false;
	}

}
