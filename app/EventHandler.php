<?php


namespace TelegramRepost;

use danog\MadelineProto\Logger;
use function date;
use function json_encode;
use function preg_match;

class EventHandler extends \danog\MadelineProto\EventHandler
{
    /** @var string[] */
    public static array $sources = [];
    /** @var string[] */
    public static array $recipients = [];
    /** @var string[] */
    public static array $keywords = [];

    private int $startTime = 0;
    /** @var array<int, null> */
    private static array $sourcesIds = [];

    public function onStart()
    {
        $this->startTime = strtotime('-30 minute');
        foreach (self::$sources as $source) {
            $id = yield $this->getId($source);
            self::$sourcesIds[$id] = null;
            Logger::log("Monitoring peer: {$source}; #{$id}");
        }
        Logger::log('Event handler started');
    }

    public function onUpdateNewChannelMessage($update)
    {
        $this->onUpdateNewMessage($update);
    }
    public function onUpdateNewMessage($update)
    {
        if (isset($update['message']['out']) && $update['message']['out']) {
            return;
        }

        if ($update['message']['date'] < $this->startTime) {
            $this->logger(
                'Skip message with date: ' . date('Y-m-d H:i:s', $update['message']['date']),
                Logger::WARNING
            );
            return;
        }

        if (!empty(self::$sourcesIds) && !array_key_exists($this->getId($update['message']['peer_id']), self::$sourcesIds)) {
            return;
        }

        if (empty($update['message']['message'])) {
            return;
        }

        $matches = 0;
        foreach (static::$keywords as $keyword) {
            $matches += preg_match("~{$keyword}~i", $update['message']['message']);
            if ($matches > 0) {
                break;
            }
        }

        if (!$matches) {
            $this->logger(date('Y-m-d H:i:s', $update['message']['date']) . ' - no matches', Logger::WARNING);
            return;
        }

        $res = json_encode($update, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE);
        $this->logger($res, Logger::NOTICE);

        foreach (static::$recipients as $peer) {
            $this->logger(date('Y-m-d H:i:s') . " forwarding message to {$peer}", Logger::WARNING);
            $this->messages->forwardMessages([
                'from_peer' => $update,
                'id' => [$update['message']['id']],
                'to_peer' => $peer,
            ]);
        }
    }
}
