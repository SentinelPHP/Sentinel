<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

// Ensure Dotenv loads test overrides even when PHPUnit server vars are applied later.
$_SERVER['APP_ENV'] ??= 'test';
$_ENV['APP_ENV'] ??= 'test';
$_SERVER['PANTHER_APP_ENV'] ??= 'test';
$_ENV['PANTHER_APP_ENV'] ??= 'test';
$_SERVER['APP_DEBUG'] ??= '1';
$_ENV['APP_DEBUG'] ??= '1';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
