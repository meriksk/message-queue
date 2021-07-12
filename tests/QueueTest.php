<?php

namespace meriksk\MessageQueue\Tests;

use meriksk\MessageQueue\Queue;
use meriksk\MessageQueue\Tests\BaseUnitTestCase;

/**
 * Tests for MessageQueue\MessageQueue.
 * Run:
 *
 * vendor\bin\phpunit.bat --filter /^testClear$/ tests\QueueTest.php
 *
 * @covers \meriksk\MessageQueue
 */
class QueueTest extends BaseUnitTestCase
{
	
	public function setUp(): void 
	{
		self::$queue->setDb(self::$pdo);
	}

    public function testInstance()
    {
		$this->assertIsObject(self::$queue);
		$this->assertInstanceOf('\meriksk\MessageQueue\Queue', self::$queue);
    }

	public function testConfig()
	{
		// getter
		$cfg = Queue::config();
		$this->assertIsArray($cfg);
		$this->assertArrayHasKey('temp_dir', $cfg);
		$this->assertArrayHasKey('db', $cfg);

		// setter (update only selected attributes)
		$cfg = Queue::config([
			'temp_dir' => '/aaa/bbb',
			'db' => [
				'username' => 'test_user',
			],
		], true);

		$this->assertIsArray($cfg);
		$this->assertArrayHasKey('temp_dir', $cfg);
		$this->assertArrayHasKey('max_attemps', $cfg);
		$this->assertArrayHasKey('db', $cfg);
		$this->assertEquals('/aaa/bbb', $cfg['temp_dir']);
		$this->assertEquals('test_user', $cfg['db']['username']);	
		
		$db = Queue::configGet('db');
		$this->assertArrayHasKey('host', $db);
		$this->assertArrayHasKey('database', $db);
		$this->assertEquals('localhost', $db['host']);
		$this->assertEquals('test_user', $db['username']);
		
		// restore configuration
		self::resetTestConfiguration();
	}

	public function testConfigGet()
	{
		// 1-level
		$cfg = Queue::configGet('db');
		$this->assertIsArray($cfg);
		$this->assertArrayHasKey('host', $cfg);

		// 2-levels
		$dsn = Queue::configGet('db.dsn');
		$invalid = Queue::configGet('attr.invalid_attribute');

		$this->assertIsString($dsn);
		$this->assertEquals('sqlite::memory:', $dsn);
		$this->assertNull($invalid);
	}

	public function testConfigSet()
	{
		// invalid parameters
		$cfg = Queue::configSet('', 'test');
		$this->assertFalse($cfg);
		$cfg = Queue::configSet(null, 'test');
		$this->assertFalse($cfg);

		// simple attribute
		$cfg = Queue::configSet('db', ['password' => 'test']);
		$cfg = Queue::configSet(0, 'first_item');
		$db = Queue::configGet('db');

		$this->assertIsArray($cfg);
		$this->assertIsArray($db);
		$this->assertArrayHasKey(0, $cfg);
		$this->assertEquals('first_item', $cfg[0]);
		$this->assertArrayHasKey('password', $db);
		$this->assertArrayNotHasKey('dsn', $db);
		$this->assertEquals('test', $db['password']);

		// simple attribute (new attribute)
		$cfg = Queue::configSet('name', 'alice');
		$cfg = Queue::configSet('address', ['city' => 'nyc']);

		$this->assertIsArray($cfg);
		$this->assertArrayHasKey('name', $cfg);
		$this->assertArrayHasKey('address', $cfg);
		$this->assertEquals('alice', $cfg['name']);
		$this->assertEquals('nyc', $cfg['address']['city']);

		// path
		$cfg = Queue::configSet('db.dsn', 'test');
		$invalid = Queue::configGet('attr.invalid_attribute');

		$this->assertIsArray($cfg);
		$this->assertEquals('test', $cfg['db']['dsn']);
		$this->assertNull($invalid);

		// path - invalid key
		$cfg = Queue::configSet('posts. .name', 'Article name');
		$this->assertArrayHasKey('posts', $cfg);
		$this->assertEquals('Article name', $cfg['posts']['name']);

		// path (new attribute)
		$cfg = Queue::configSet('users.1.name', 'marek');
		$this->assertArrayHasKey('users', $cfg);
		$this->assertEquals('marek', $cfg['users'][1]['name']);
		
		// reset config
		$this->resetTestConfiguration();
	}

    public function testGetDb()
    {
		$db = self::$pdo;
		$this->assertIsObject($db);
		$this->assertInstanceOf('PDO', $db);
    }

	public function testCreateMessage()
	{
		// empty message
		self::$queue = new Queue();

		$message = self::$queue->createMessage(Queue::EMAIL);
		$this->assertIsObject($message);
		$this->assertInstanceOf('\meriksk\MessageQueue\Message', $message);

		$subject = 'This is a test message.';
		$body = 'Message body.';

		// with params
		$message = self::$queue->createMessage(Queue::EMAIL, ['none@null.cc' => 'Example 1', 'none2@null.cc', 'none2@null.cc', '', null, 'invalid'], $subject, $body);

		$data = [
			'type' => $message->getType(),
			'destination' => $message->getDestination(),
			'subject' => $message->getSubject(),
			'body' => $message->getBody(),
		];

		$this->assertIsObject($message);
		$this->assertInstanceOf('\meriksk\MessageQueue\Message', $message);
		$this->assertEquals(Queue::EMAIL, $data['type']);
		$this->assertEquals(['none@null.cc' => 'Example 1', 'none2@null.cc', 'none2@null.cc'], $data['destination']);
		$this->assertEquals($subject, $data['subject']);
		$this->assertEquals($body, $data['body']);

		// set content using public methods
		$message = Queue::message(Queue::EMAIL);
		$message->addDestination(['none@null.cc' => 'Example 1', 'none2@null.cc', '', null, 'invalid']);
		$message->addDestination('none2@null.cc');
		$message->setSubject($subject);
		$message->setBody($body);

		$data = [
			'type' => $message->getType(),
			'destination' => $message->getDestination(),
			'subject' => $message->getSubject(),
			'body' => $message->getBody(),
		];

		$this->assertIsObject($message);
		$this->assertInstanceOf('\meriksk\MessageQueue\Message', $message);
		$this->assertEquals(Queue::EMAIL, $data['type']);
		$this->assertEquals(['none@null.cc' => 'Example 1', 'none2@null.cc', 'none2@null.cc'], $data['destination']);
		$this->assertEquals($subject, $data['subject']);
		$this->assertEquals($body, $data['body']);
	}

	public function testMessage()
	{
		// email
		$message = Queue::message(Queue::EMAIL, ['none@null.cc' => 'Example 1', 'none2@null.cc', 'none2@null.cc', '', null, 'invalid'], 'This is a test message.', 'Message body.');

		$data = [
			'type' => $message->getType(),
			'destination' => $message->getDestination(),
			'subject' => $message->getSubject(),
			'body' => $message->getBody(),
		];

		$this->assertIsObject($message);
		$this->assertInstanceOf('\meriksk\MessageQueue\Message', $message);
		$this->assertEquals(Queue::EMAIL, $data['type']);
		$this->assertEquals(['none@null.cc' => 'Example 1', 'none2@null.cc', 'none2@null.cc'], $data['destination']);
		$this->assertEquals('This is a test message.', $data['subject']);
		$this->assertEquals('Message body.', $data['body']);
	}

	public function testGet()
	{
		/* @var $message \meriksk\MessageQueue\Message */
				
		// reset
		self::$queue->clear();
		
		$message = $this->createTestMessage();
		self::$queue::add($message);

		$msg1 = self::$queue->get(1); // exists
		$msg2 = self::$queue->get(2); // not exists

		$this->assertIsObject($msg1);
		$this->assertNull($msg2);
		$this->assertInstanceOf('\meriksk\MessageQueue\Message', $msg1);
		$this->assertEquals(1, $msg1->getId());
	}
	
	public function testFindOne()
	{
		// reset
		self::$queue->clear();
		
		$message = $this->createTestMessage();
		self::$queue::add($message);
		self::$queue::add($message);

		// found
		$msg = Queue::findOne(2);
		$this->assertIsObject($msg);
		$this->assertInstanceOf('\meriksk\MessageQueue\Message', $msg);
		
		// not found
		$msg = Queue::findOne(3);		
		$this->assertNull($msg);
		
		// invalid parameters
		$msg = Queue::findOne('');		
		$this->assertNull($msg);
	}

	public function testCount()
	{
		// clear db
		self::$queue->clear();
		
		$this->assertEquals(0, self::$queue->getCount());

		$message = $this->createTestMessage();
		self::$queue::add($message);
		self::$queue::add($message);
		$this->assertEquals(2, self::$queue->getCount());

		self::$queue::add($message);
		$this->assertEquals(3, self::$queue->getCount());
	}

	public function testGetFirst()
	{
		/* @var $first \meriksk\MessageQueue\Message */

		// clear db
		self::$queue::clear();
		
		$first = self::$queue::first();
		$this->assertEquals(null, $first);

		$message = $this->createTestMessage();
		self::$queue::add($message);
		self::$queue::add($message);
		$first = self::$queue::first();

		$this->assertIsObject($first);
		$this->assertEquals(1, $first->getId());
	}

	public function testGetLast()
	{
		/* @var $last \meriksk\MessageQueue\Message */

		// clear db
		self::$queue->clear();
		
		$last = self::$queue->getLast();
		$this->assertEquals(null, $last);

		$message = $this->createTestMessage();
		self::$queue::add($message);
		self::$queue::add($message);
		$last = self::$queue->getLast();

		$this->assertIsObject($last);
		$this->assertEquals(2, $last->getId());
	}

	public function testClear()
	{
		// clear db
		self::$queue::clear();

		$message = $this->createTestMessage();
		self::$queue::add($message);
		self::$queue::add($message);
		$this->assertEquals(2, self::$queue->getCount());

		self::$queue->clear();
		$this->assertEquals(0, self::$queue->getCount());
	}

	public function testDelete()
	{
		// clear db
		self::$queue->clear();

		$message = $this->createTestMessage();

		$msg01 = self::$queue::add($message);
		$msg02 = self::$queue::add($message);
		$msg03 = self::$queue::add($message);
		$msg04 = self::$queue::add($message);
		$msg05 = self::$queue::add($message);
		$msg06 = self::$queue::add($message);
		$msg07 = self::$queue::add($message);
		$msg08 = self::$queue::add($message);
		$msg09 = self::$queue::add($message);
		$msg10 = self::$queue::add($message);

		// added 10
		$this->assertEquals(10, self::$queue->getCount());

		// numeric
		self::$queue->delete(2);
		// message
		self::$queue->delete($msg03);
		// array
		self::$queue->delete([$msg06, 7, $msg08, 9]);

		// deleted 6 - queue count should be 4
		$this->assertEquals(4, self::$queue->getCount());

	}
}