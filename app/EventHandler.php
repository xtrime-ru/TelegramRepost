<?php declare(strict_types = 1);


namespace TelegramRepost;

use danog\AsyncOrm\Annotations\OrmMappedArray;
use danog\AsyncOrm\DbArray;
use danog\AsyncOrm\KeyType;
use danog\AsyncOrm\ValueType;
use danog\MadelineProto\Logger;
use Revolt\EventLoop;
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
    /** @var string[] */
    public static array $stopWords = [];

    public static bool $onlineStatus = false;

    public static bool $saveMessages = false;

    /** @var array<int, null> */
    private static array $sourcesIds = [];

    /** @var list<int> */
    private static array $recipientsIds = [];

    #[OrmMappedArray(keyType: KeyType::INT, valueType:ValueType::SCALAR, cacheTtl: 0)]
    protected DbArray $messagesDb;

    #[OrmMappedArray(keyType: KeyType::INT, valueType: ValueType::STRING, cacheTtl: 0)]
    protected DbArray $sourcesDb;


    public function onStart(): void
    {
        $this->updateSources();
        EventLoop::repeat(60.0, function() {
            $this->updateSources();
        });


        if (static::$onlineStatus) {
            EventLoop::repeat(60.0, function() {
                $this->account->updateStatus(offline: false);
            });
        }

        foreach (static::$recipients as $peer) {
            try {
                $info = $this->getInfo($peer);
                $id = $info['bot_api_id'];
                if (!is_int($id)) {
                    throw new \InvalidArgumentException("Cant get recipient peer id: {$peer}");
                }
                self::$recipientsIds[] = $id;
                Logger::log("Forwarding to peer: {$peer}; #{$id}");
            } catch (\Throwable $e) {
                Logger::log("Cant forward messages to: {$peer}; Error: {$e->getMessage()}", Logger::ERROR);
                continue;
            }
        }

        if (empty(self::$recipientsIds)  || empty(self::$sourcesIds)) {
            Logger::log("No recipients or no sources", Logger::FATAL_ERROR);
        }

        Logger::log('Event handler started');
    }

    public function onUpdateNewChannelMessage($update): void
    {
        $this->onUpdateNewMessage($update);
    }

    public function onUpdateNewMessage($update): void
    {
        $res = json_encode($update, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE);
        $this->logger('Update: ' . $res, Logger::NOTICE);

        if (isset($update['message']['out']) && $update['message']['out']) {
            return;
        }

        $peerId =  $this->getId($update['message']['peer_id']);

        $fromId = array_key_exists('from_id', $update['message']) ? $this->getId($update['message']['from_id']) : null;
        if (!empty(self::$sourcesIds) && !array_key_exists($peerId, self::$sourcesIds) && !array_key_exists($fromId, self::$sourcesIds)) {
            $this->logger("Skip forwarding message {$update['message']['id']} from {$peerId} from wrong peer_id");
            return;
        }

        if (!empty(static::$keywords) && empty($update['message']['message'])) {
            $this->logger('Skip empty message');
            return;
        }

        foreach (static::$stopWords as $stopWord) {
            if (preg_match("~{$stopWord}~iuS", $update['message']['message'])) {
                $this->logger("Skip by stop word: $stopWord", Logger::WARNING);
                return;
            }
        }

        if (static::$keywords) {
            $matches = 0;
            foreach (static::$keywords as $keyword) {
                $matches += preg_match("~{$keyword}~iuS", $update['message']['message']);
                if ($matches > 0) {
                    $this->logger("Match {$update['message']['id']} from {$peerId}  by keyword: $keyword", Logger::WARNING);
                    break;
                }
            }

            if (!$matches) {
                $this->logger("{$update['message']['id']} - no matches", Logger::WARNING);
                return;
            }
        }

        $this->saveMessageToDb($update);

        foreach (static::$recipientsIds as $peer) {
            $this->logger(date('Y-m-d H:i:s') . " Forwarding message {$update['message']['id']} from {$peerId}  to {$peer}", Logger::WARNING);
            try {
                $this->messages->forwardMessages(
                    from_peer: $peerId,
                    to_peer: $peer,
                    id: [$update['message']['id']],
                );
                $this->logger(date('Y-m-d H:i:s') . " Sent successfully: {$update['message']['id']} to {$peer}", Logger::WARNING);
            } catch (\Throwable $e) {
                $this->logger($e, Logger::ERROR);
                $this->logger(date('Y-m-d H:i:s') . " Error while forwarding message: {$res}", Logger::ERROR);
            }
        }
    }

    private function updateSources(): void
    {
        $sources = array_merge(self::$sources, $this->sourcesDb->getArrayCopy());
        $sourcesIds = [];
        foreach ($sources as $source) {
            try {
                $peer = $this->getInfo($source);
                $id = $peer['bot_api_id'];
                if (!is_int($id)) {
                    throw new \InvalidArgumentException("Cant get source peer id: {$source}");
                }
                $isPublicChannel = in_array($peer['type'], ['channel', 'supergroup']);
                if ($isPublicChannel) {
                    $this->subscribeToUpdates($id);
                }
            } catch (\Throwable $e) {
                Logger::log("Cant monitor updates from: {$source}; Error: {$e->getMessage()}", Logger::ERROR);
                continue;
            }

            $sourcesIds[$id] = null;
            Logger::log("Monitoring peer: {$source}; #{$id}");
        }

        if ($sourcesIds !== self::$sourcesIds) {
            self::$sourcesIds = $sourcesIds;
        }
    }

    private function saveMessageToDb(array $update): void
    {
        if (!self::$saveMessages) {
            return;
        }
        $timeMs = (int)(microtime(true)*1000*1000);
        $this->messagesDb[$timeMs] =  json_encode($update, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
