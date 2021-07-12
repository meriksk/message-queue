<?php

namespace meriksk\MessageQueue\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use meriksk\MessageQueue\Queue;
use PHPUnit\DbUnit\Constraint;

/**
 * Base class for unit tests.
 */
class BaseUnitTestCase extends TestCase
{

	
	protected static $attachmentImage;
	protected static $pdo;	
	/* @var $queue \meriksk\MessageQueue\Queue */
	protected static $queue;
	
	
	public static function setUpBeforeClass(): void
	{
		//if (!extension_loaded('PDO') || !class_exists('SQLite3')) {
        //   self::markTestIncomplete('The PDO extension is not available or SQLite3 is missing!');
       // }
		
		self::prepareDatabase();
		self::resetTestConfiguration();
		self::$queue = new Queue();
	}
	
    public static function tearDownAfterClass(): void
    {
		self::$pdo = null;
    }
    
	public static function resetTestConfiguration()
	{
		// config 
		Queue::config([
			'temp_dir' => 'D:/www/tmp/msg_queue',
			'max_attemps' => 5,
			'db' => [
				'dsn' => 'sqlite::memory:',
				'username' => null,
				'password' => null,
			],
			'handlers' => [
				'email' => [
					'transport' => 'smtp',
					'host' => '',
					'username' => '',
					'password' => '',
					'encryption' => 'ssl',
					'port' => 465,
					'from' => '',
					'stream_options' => [],
				],
			],
		], true);
	}
	
	protected static function prepareDatabase()
	{			
		// read table schema
		$schema = file_get_contents(DIR_ROOT . '/src/db/schema.sqlite');

		// create PDO object
		self::$pdo = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_PERSISTENT => true));
		self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

		// create tables
		$result = self::$pdo->exec($schema);
		if ($result===false) {
			throw new \Exception('Failed to create a memory database. ' . json_encode(self::$pdo->errorInfo()));
		}
	}
	
	public function getFilePath($filename)
	{
		return DIR_ASSETS . DIRECTORY_SEPARATOR . $filename;
	}
	
	public function createTestMessage()
	{
		// with params
		return Queue::message(
			Queue::EMAIL, 
			['none@null.cc' => 'Example 1'], 
			'This is a test message.',
			'Message body.',
		);
	}
	
}