<?php
use meriksk\MessageQueue\Queue;

// set config values in your application bootstrap file
Queue::config([
	'temp_dir' => '/tmp/message_queue',
	'max_attemps' => 5,
	'db' => [
		'dsn' => 'mysql:host=localhost;dbname=test_db',
		'username' => 'user',
		'password' => '',
	],
	'handlers' => [
		'email' => [
			'scheme' => 'smtp',
			'host' => '',
			'username' => '',
			'password' => '',
			'encryption' => 'ssl',
			'port' => 587,
			'from' => ['email@address.com' => 'Sender'],	
		],
	],

]);