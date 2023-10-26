<?php

namespace meriksk\MessageQueue\handlers;

/**
 * BaseHandler class file.
 * @property string $error Last error
 * @property array $failed 
 * @property array $config 
 * @property int $antiflood The number of recipients to send before restarting handler.
 * @property int $antifloodSleep The number of seconds to sleep for during a restart.
 * @property
 */
abstract class BaseHandler
{
	
	public $error;
	public $failed = [];
    public $antiflood = 0;
    public $antifloodSleep = 3;
	
	
	/** @var array */
	protected $config = [];
	
    /** @var int The internal counter. */
    private $antifloodCounter = 0;

	/** @var array Default handler configuration. */
	protected $defaultConfig;
	
	

	/**
	 * Init handler
	 */
	public function init()
	{
	}

	/**
	 * Set handler configuration
	 * @param array $config
	 * @return $this
	 */
	public function setConfig(array $config)
	{
		$this->config = $config;
		return $this;
	}

	/**
	 * Send a message
	 * @param array $destination
	 * @param mixed $body
	 * @param string $subject
	 * @return bool|int An integer is returned which includes the number
	 * of successful recipients or FALSE if an error occurred
	 */
	public abstract function send($destination, $body);

	/**
	 * Returns a string with last error message
	 * @return string
	 */
	public function getLastError()
	{
		return $this->error;
	}

	/**
	 * Returns whether message was delivered successfully or not.
	 * @return bool
	 */
	public function success()
	{
		return empty($this->error) && empty($this->failed);
	}
	
    /**
     * Set anti-flood configuration
     * @param int $threshold
     * @param int $sleep
     */
    public function antiflood($threshold, $sleep = 5)
    {
        $this->antiflood = (int)$threshold;
        $this->antifloodSleep = (int)$sleep;
    }

}