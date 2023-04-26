<?php

use danog\MadelineProto\APIWrapper;
use TelegramRepost\EventHandler;

if (!is_file(__DIR__ . '/vendor/autoload.php')) {
    passthru('composer install --no-dev');
}
require_once __DIR__ . '/vendor/autoload.php';

Dotenv\Dotenv::createImmutable(__DIR__, '.env')->load();

$settings = require('config.php');

EventHandler::$sources = $settings['sources'];
EventHandler::$recipients = $settings['recipients'];
EventHandler::$keywords = $settings['keywords'];
EventHandler::$onlineStatus = $settings['online_status'];

$madelineProto = new danog\MadelineProto\API('session/session.madeline', $settings['telegram']);
$madelineProto->unsetEventHandler();

$property = new ReflectionProperty($madelineProto, "wrapper");
/** @var APIWrapper $wrapper */
$wrapper = $property->getValue($madelineProto);
$wrapper->getAPI()->setEventHandler(EventHandler::class);
$madelineProto->start();

// Await SIGINT or SIGTERM to be received.
$signal = Amp\trapSignal([SIGINT, SIGTERM]);
echo sprintf("Received signal %d", $signal) . PHP_EOL;