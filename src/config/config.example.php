<?php
use meriksk\MessageQueue\Queue;

// set config values in your application bootstrap file
Queue::config([
	'temp_dir' => '/tmp/message_queue',
	'max_attemps' => 5,
	'db' => [
		'host' => 'localhost',
		'username' => 'root',
		'password' => '',
		'database' => '',
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

]);