<?php
use meriksk\MessageQueue\Queue;

// set config values in your application bootstrap file
Queue::config([
	'temp_dir' => '/tmp/message_queue',
	'max_attemps' => 5,
	'db' => [
		'dsn' => 'mysql:host=localhost;dbname=test_db',
		'username' => '',
		'password' => '',
	],
	'handlers' => [
		'email' => [
			'dsn' => 'smtp://user:pass@smtp.example.com:port',
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