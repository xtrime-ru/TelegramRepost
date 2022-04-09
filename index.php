<?php

use TelegramRepost\EventHandler;

if (!is_file(__DIR__ . '/vendor/autoload.php')) {
    passthru('composer install --no-dev');
}
require_once __DIR__ . '/vendor/autoload.php';

Dotenv\Dotenv::createImmutable(__DIR__, '.env')->load();

$settings = require('config.php');

$madelineProto = new danog\MadelineProto\API('session/session.madeline', $settings['telegram']);
$madelineProto->unsetEventHandler();
$madelineProto->async(true);

EventHandler::$sources = $settings['sources'];
EventHandler::$recipients = $settings['recipients'];
EventHandler::$keywords = $settings['keywords'];

$madelineProto->loop(static function () use ($madelineProto) {
    $madelineProto->setEventHandler(EventHandler::class);
    yield $madelineProto->start();
});
$madelineProto->loop();