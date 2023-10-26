# MessageQueue PHP

A fast, simple DB driven message queuing library for PHP/MySQL.

## Install

Via Composer

``` bash
$ composer require meriksk/message-queue
```

## How to install

* Message-queue depends on the great Swift Mailer, which is included using composer (https://getcomposer.org/). All packages will be installed by composer.

* Create a database in your server with your desired name. e.g: message_queue.

* Run the provided SQL code found on install/schema.sql on that database to create the initial database structure.

* Setup two cronjobs in your linux to execute regularly the delivery and purge scripts. ([Cron-job setup](#cron-job))

## Usage

Example 1:

```php
$queue = new \meriksk\MessageQueue\Queue();
$message = new Message(Queue::EMAIL, 'recipient1@email.com', 'Subject', 'message body');
$queue->add($message);
```

Example2:

```php
use meriksk\MessageQueue\Queue;
$queue = new Queue();
$message = new Message(Queue::EMAIL, 'recipient1@email.com', 'Subject', 'message body');
$message->save();
```

Example 3:

```php
use meriksk\MessageQueue\Queue;
$message = Queue::message(Queue::EMAIL);
$message->body = 'Message body';
$message->subject = 'Subject';
$message->addDestination('recipient1@email.com');
$message->addDestination('recipient2@email.com');

// attachment
$message->addAttachment($filePath2, 'custom_name', 'application/pdf');

// save
$message->save();
```

## Cron job

The delivery script delivers pending emails in the queue. Running it every minute is recommended.
Be sure the shell scripts are executable.

`$ crontab -e`

Add the following lines:
`* * * * * /var/www/htdocs/your_app/scripts/deliver`
`0 6 * * * /var/www/htdocs/your_app/scripts/purge`

```php
Queue::antiflood(1, 3)
Queue::deliver(['max_attemps' => 3]);
```

## Testing

``` bash
$ composer test
```

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.