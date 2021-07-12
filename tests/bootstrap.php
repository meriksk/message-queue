<?php

include_once 'vendor/autoload.php';

// Directory separator
defined('DS') or define('DS', DIRECTORY_SEPARATOR);

// "tests" base directory
define('DIR_ROOT', dirname(__DIR__));

// "tests" base directory
define('DIR_TESTS', __DIR__);

// assets
define('DIR_ASSETS', DIR_TESTS . DS . 'assets');
define('ASSET_IMAGE', DIR_ASSETS . DS . 'image_landscape.jpg');	