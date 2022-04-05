<?php

use TelegramRepost\EventHandler;

require_once __DIR__ . '/vendor/autoload.php';

Dotenv\Dotenv::createImmutable(__DIR__, '.env')->load();

$settings = require('config.php');

$madelineProto = new danog\MadelineProto\API('session/session.madeline', $settings['telegram']);
$madelineProto->async(true);

EventHandler::$recipients = $settings['recipients'];
EventHandler::$keywords = $settings['keywords'];

$madelineProto->setEventHandler(EventHandler::class);
$madelineProto->loop(static function() use($madelineProto) {
        yield $madelineProto->start();
        yield $madelineProto->setEventHandler(EventHandler::class);
});
$madelineProto->loop();
