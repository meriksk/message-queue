<?php

namespace meriksk\MessageQueue\handlers;

use meriksk\MessageQueue\Queue;
use meriksk\MessageQueue\handlers\BaseHandler;


/**
 * FileHandler class file.
 */
class FileHandler extends BaseHandler
{

	/**
	 * Send a message
	 * @param array $destination
	 * @param mixed $body
	 * @param string $subject
	 * @return bool|int An integer is returned which includes the number
	 * of successful recipients or FALSE if an error occurred
	 */
	public function send($destination, $body, $subject = NULL)
	{
		if (empty($destination)) {
			return 0;
		}
		
		// array support
		if (!is_array($destination)) {
			$destination = [$destination];
		}
		

		$successful = 0;
		foreach ($destination as $path) {
			if ($path && is_string($path) && @file_put_contents($path, $body)) {
				$successful++;
			} else {
				$this->failed[] = $path;
			}
		}

		return $successful;
	}

}