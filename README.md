# MessageQueue PHP

A fast, simple DB driven message queuing library for PHP/MySQL.


## Install

Via Composer

``` bash
$ composer require meriksk/message-queue
```

## How to install

* Clone the emailqueue repository wherever you want it.

`$ git clone https://github.com/meriksk/message-queue.git`

* Emailqueue depends on the great PHPMailer, which is included using composer (https://getcomposer.org/). Install PHPMailer by running this command:

    `$ composer update`

* Create a database in your server with your desired name. e.g: db_message_queue

* Run the provided SQL code found on install/schema.sql on that database to create the initial database structure.

* Be sure the shell scripts scripts/delivery and scripts/purge are executable

* Setup two cronjobs in your linux to execute regularly the delivery and purge scripts, e.g:
    
    `$ crontab -e`

    Add the following lines:
    `* * * * * /var/www/htdocs/emailqueue/scripts/delivery`
    `0 6 * * * /var/www/htdocs/emailqueue/scripts/purge`

    * The delivery script delivers pending emails in the queue. Running it every minute is recommended.

## Testing

``` bash
$ composer test
```

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.