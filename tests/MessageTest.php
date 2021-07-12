<?php

namespace meriksk\MessageQueue\Tests;

use PDO;
use meriksk\MessageQueue\Queue;
use meriksk\MessageQueue\Message;
use meriksk\MessageQueue\Tests\BaseUnitTestCase;

/**
 * Tests for \meriksk\MessageQueue\Message.
 * Run:
 *
 * ./vendor/bin\/phpunit tests\QueueTest.php
 * ./vendor/bin/phpunit /^testConstruct$/ tests\QueueTest.php
 *
 * @covers \meriksk\MessageQueue\Message
 */
class MessageTest extends BaseUnitTestCase
{
	
	public function setUp(): void 
	{
		self::$queue->setDb(self::$pdo);
	}

    public function testConstruct()
    {
		$msg = new Message(Queue::EMAIL);

		$this->assertIsObject($msg);
		$this->assertInstanceOf('\meriksk\MessageQueue\Message', $msg);
		$this->assertEquals(Queue::EMAIL, $msg->getType());
    }
	
	public function testClearDestination()
	{
		$msg = new Message(Queue::EMAIL);
		$msg->addDestination('email1@null.null');
		$msg->clearDestination();
		
		$this->assertEquals([], $msg->getDestination());
	}
	
	public function testAddAttachment()
	{
		// valid image attachment
		$msg = new Message(Queue::EMAIL);
		$msg->addDestination('email1@null.null');
		$msg->addAttachment(self::$attachmentImage, 'Image.jpg');
		$result = $msg->save();
		
		$this->assertTrue($result);
	}
	
	public function testSave()
	{
		/* @var $message \meriksk\MessageQueue\Message */
		$message = $this->createTestMessage();
		$result = $message->save();		
		$this->assertTrue($result);		
	}
	
	
	public function testDelete()
	{
		// reset
		self::$queue->clear();

		/* @var $message \meriksk\MessageQueue\Message */
		$message = $this->createTestMessage();
		$message->save();
		$message = null;
		
		// read from db
		$message = Queue::findOne(1);
		$this->assertIsObject($message);
		
		// delete
		$result = $message->delete();
		$this->assertTrue($result);
		
		// read from db
		$message = Queue::findOne(1);
		$this->assertNull($message);
	}
	
	
}
