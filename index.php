<?php

use danog\MadelineProto\APIWrapper;
use danog\MadelineProto\Magic;
use danog\MadelineProto\Settings;
use danog\MadelineProto\Settings\Database\SerializerType;
use danog\MadelineProto\SettingsAbstract;
use TelegramRepost\EventHandler;

if (!is_file(__DIR__ . '/vendor/autoload.php')) {
    passthru('composer install --no-dev');
}
require_once __DIR__ . '/vendor/autoload.php';

$shortopts = 's::';
$longopts = [
    'session::', //префикс session файла
];
$options = getopt($shortopts, $longopts);
$options = [
    'session' => (string)($options['session'] ?? $options['s'] ?? 'session'),
];

Dotenv\Dotenv::createUnsafeImmutable(__DIR__, '.env')->load();

$settings = require('config.php');

EventHandler::$sources = $settings['sources'];
EventHandler::$recipients = $settings['recipients'];
EventHandler::$keywords = $settings['keywords'];
EventHandler::$stopWords = $settings['stop_words'];
EventHandler::$repostMessages = $settings['repost_messages'];
EventHandler::$onlineStatus = $settings['online_status'];
EventHandler::$saveMessages = $settings['save_messages'];
EventHandler::$sendLinks = $settings['send_links'];
EventHandler::$duplicatesTTL = $settings['duplicates_ttl'];
EventHandler::$maxTextSimilarity = $settings['duplicates_similarity'];

function getSettingsFromArray(string $session, array $settings, SettingsAbstract $settingsObject = new Settings()): SettingsAbstract {
    foreach ($settings as $key => $value) {
        if (\is_array($value) && $key !== 'proxies') {
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
                if (\count($value) === 0) {
                    continue;
                }
            }

            $method = 'get' . \ucfirst(\str_replace('_', '', \ucwords($key, '_')));
            getSettingsFromArray($session, $value, $settingsObject->$method());
        } else {
            if ($key === 'serializer' && \is_string($value)) {
                $value = SerializerType::from($value);
            }
            $method = 'set' . \ucfirst(\str_replace('_', '', \ucwords($key, '_')));
            $settingsObject->$method($value);
        }
    }
    return $settingsObject;
}

foreach (glob("session/*/*ipc") as $file) {
    printf("removing: %s\n", $file);
    unlink($file);
}
if ((string)getenv('DB_TYPE') !== 'memory') {
    foreach (glob("session/safe.php*") as $file) {
        printf("removing: %s\n", $file);
        unlink($file);
    }
}

\define('MADELINE_WORKER_TYPE', 'madeline-ipc');
Magic::$isIpcWorker = true;

$madelineProto = new danog\MadelineProto\API("session/{$options['session']}.madeline", getSettingsFromArray($options['session'], $settings['telegram']));
EventHandler::cachePlugins(EventHandler::class);
$madelineProto->start();
$property = new ReflectionProperty($madelineProto, "wrapper");
/** @var APIWrapper $wrapper */
$wrapper = $property->getValue($madelineProto);
if (!$wrapper->getAPI()->getEventHandler() instanceof EventHandler) {
    $wrapper->getAPI()->setEventHandler(EventHandler::class);
}

// Await SIGINT or SIGTERM to be received.
$signal = Amp\trapSignal([SIGINT, SIGTERM]);
printf("Received signal %d %s", $signal, PHP_EOL);