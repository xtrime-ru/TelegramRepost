<?php

require_once __DIR__ . '/vendor/autoload.php';

$dotenv = \Dotenv\Dotenv::create(__DIR__);
$dotenv->load();

$settings = require('config.php');
$session = __DIR__ . '/session.madeline';

$madelineProto = new danog\MadelineProto\API($session, $settings['telegram']);
$madelineProto->async(true);

\TelegramRepost\EventHandler::$recipients = $settings['recipients'];
\TelegramRepost\EventHandler::$keywords = $settings['keywords'];

$madelineProto->loop(static function() use($madelineProto){
        yield $madelineProto->start();
        yield $madelineProto->setEventHandler(\TelegramRepost\EventHandler::class);
});
$madelineProto->loop();
