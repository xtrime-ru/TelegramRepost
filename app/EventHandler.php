<?php


namespace TelegramRepost;

use danog\MadelineProto\Logger;
use Revolt\EventLoop;
use function Amp\async;
use function Amp\Future\await;
use function Amp\Future\awaitAll;
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

    public static bool $onlineStatus = false;

    private int $startTime = 0;
    /** @var array<int, null> */
    private static array $sourcesIds = [];

    /** @var list<int> */
    private static array $recipientsIds = [];

    public function onStart()
    {
        $this->startTime = strtotime('-30 minute');
        foreach (self::$sources as $source) {
            try {
                $peer = $this->getInfo($source);
                $id = $peer['bot_api_id'];
                if (!is_int($id)) {
                    throw new \InvalidArgumentException("Cant get source peer id: {$source}");
                }
            } catch (\Throwable $e) {
                Logger::log("Cant monitor updates from: {$source}; Error: {$e->getMessage()}", Logger::ERROR);
                continue;
            }

            self::$sourcesIds[$id] = null;
            Logger::log("Monitoring peer: {$source}; #{$id}");
        }

        if (self::$onlineStatus) {
            EventLoop::repeat(60.0, fn() => $this->account->updateStatus(['offline' => false]));
        }

        foreach (self::$recipients as $peer) {
            try {
                $info = $this->getInfo($peer);
                $id = $info['bot_api_id'];
                if (!is_int($id)) {
                    throw new \InvalidArgumentException("Cant get recipient peer id: {$peer}");
                }
                self::$recipientsIds[] = $id;
            } catch (\Throwable $e) {
                Logger::log("Cant forward messages to updates from: {$peer}; Error: {$e->getMessage()}", Logger::ERROR);
                continue;
            }
        }

        Logger::log('Event handler started');
    }

    public function onUpdateNewChannelMessage($update)
    {
        return $this->onUpdateNewMessage($update);
    }

    public function onUpdateNewMessage($update)
    {
        $res = json_encode($update, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE);
        $this->logger('Update: ' . $res, Logger::NOTICE);

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

        $peerId =  $this->getId($update['message']['peer_id']);
        $fromId = array_key_exists('from_id', $update['message']) ? $this->getId($update['message']['from_id']) : null;
        if (!empty(self::$sourcesIds) && !array_key_exists($peerId, self::$sourcesIds) && !array_key_exists($fromId, self::$sourcesIds)) {
            $this->logger('Skip forwarding message from wrong peer_id');
            return;
        }

        if (empty($update['message']['message'])) {
            $this->logger('Skip empty message');
            return;
        }

        $matches = 0;
        foreach (static::$keywords as $keyword) {
            $matches += preg_match("~{$keyword}~iuS", $update['message']['message']);
            if ($matches > 0) {
                break;
            }
        }

        if (!$matches) {
            $this->logger(date('Y-m-d H:i:s', $update['message']['date']) . ' - no matches', Logger::WARNING);
            return;
        }

        $promises = [];
        foreach (static::$recipients as $peer) {
            $this->logger(date('Y-m-d H:i:s') . " forwarding message to {$peer}", Logger::WARNING);
            $promises[] = async($this->messages->forwardMessages(...),[
                'from_peer' => $update,
                'id' => [$update['message']['id']],
                'to_peer' => $peer,
            ]);
        }
        awaitAll($promises);
    }
}
