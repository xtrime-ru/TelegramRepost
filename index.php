<?php

use danog\MadelineProto\APIWrapper;
use danog\MadelineProto\Settings;
use danog\MadelineProto\SettingsAbstract;
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
EventHandler::$stopWords = $settings['stop_words'];
EventHandler::$onlineStatus = $settings['online_status'];
EventHandler::$saveMessages = $settings['save_messages'];

function getSettingsFromArray(string $session, array $settings, SettingsAbstract $settingsObject = new Settings()): SettingsAbstract {
    foreach ($settings as $key => $value) {
        if (is_array($value)) {
            if ($key === 'db' && isset($value['type'])) {
                $type = match ($value['type']) {
                    'memory' => new Settings\Database\Memory(),
                    'mysql' => new Settings\Database\Mysql(),
                    'postgres' => new Settings\Database\Postgres(),
                    'redis' => new Settings\Database\Redis(),
                };
                $settingsObject->setDb($type);

                if ($type instanceof Settings\Database\Memory) {
                    getSettingsFromArray($session, [], $type);
                } else {
                    $type->setEphemeralFilesystemPrefix($session);
                    getSettingsFromArray($session, $value[$value['type']], $type);
                }

                unset($value[$value['type']], $value['type'],);
                if (count($value) === 0) {
                    continue;
                }
            }

            $method = 'get' . ucfirst(str_replace('_', '', ucwords($key, '_')));
            getSettingsFromArray($session, $value, $settingsObject->$method());
        } else {
            $method = 'set' . ucfirst(str_replace('_', '', ucwords($key, '_')));
            $settingsObject->$method($value);
        }
    }
    return $settingsObject;
}

$madelineProto = new danog\MadelineProto\API('session/session.madeline', getSettingsFromArray('session', $settings['telegram']));
$madelineProto->start();
$property = new ReflectionProperty($madelineProto, "wrapper");
/** @var APIWrapper $wrapper */
$wrapper = $property->getValue($madelineProto);
if (!$wrapper->getAPI()->getEventHandler() instanceof EventHandler) {
    $wrapper->getAPI()->setEventHandler(EventHandler::class);
}

// Await SIGINT or SIGTERM to be received.
$signal = Amp\trapSignal([SIGINT, SIGTERM]);
echo sprintf("Received signal %d", $signal) . PHP_EOL;