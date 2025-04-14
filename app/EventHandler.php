<?php declare(strict_types = 1);


namespace TelegramRepost;

use danog\AsyncOrm\Annotations\OrmMappedArray;
use danog\AsyncOrm\DbArray;
use danog\AsyncOrm\KeyType;
use danog\AsyncOrm\Serializer\Json;
use danog\AsyncOrm\ValueType;
use danog\MadelineProto\Logger;
use danog\MadelineProto\ParseMode;
use Revolt\EventLoop;
use function Amp\File\write;
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

    public static bool $repostMessages = true;

    public static bool $onlineStatus = false;

    public static bool $saveMessages = false;

    public static bool $sendLinks = false;

    public static int $duplicatesTTL = 0;

    /** @var array<int, null> */
    private static array $sourcesIds = [];

    /** @var list<int> */
    private static array $recipientsIds = [];

    private array $sentMessages = [];

    #[OrmMappedArray(keyType: KeyType::INT, valueType:ValueType::SCALAR, cacheTtl: 0, serializer: new Json())]
    protected DbArray $messagesDb;

    #[OrmMappedArray(keyType: KeyType::INT, valueType: ValueType::STRING, cacheTtl: 0)]
    protected DbArray $sourcesDb;


    public function onStart(): void
    {
        $this->updateSources();
        EventLoop::repeat(60.0, function() {
            $this->updateSources();
            $this->clearDuplicates();
        });


        if (static::$onlineStatus) {
            EventLoop::repeat(60.0, function() {
                $this->account->updateStatus(offline: false);
            });
        }

        $this->healthcheck();
        EventLoop::repeat(60.0, $this->healthcheck(...));

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

        $sourcePeerId =  $this->getId($update['message']['peer_id']);

        $fromId = array_key_exists('from_id', $update['message']) ? $this->getId($update['message']['from_id']) : null;
        if (!empty(self::$sourcesIds) && !array_key_exists($sourcePeerId, self::$sourcesIds) && !array_key_exists($fromId, self::$sourcesIds)) {
            $this->logger("Skip forwarding message {$update['message']['id']} from {$sourcePeerId} from wrong peer_id");
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
                    $this->logger("Match {$update['message']['id']} from {$sourcePeerId}  by keyword: $keyword", Logger::WARNING);
                    break;
                }
            }

            if (!$matches) {
                $this->logger("{$update['message']['id']} - no matches", Logger::WARNING);
                return;
            }
        }

        $this->saveMessageToDb($update);


        if ($fromId !== null && $this->isDuplicate($fromId, $update['message']['message'])) {
            return;
        }

        foreach (static::$recipientsIds as $targetPeerId) {
            $this->processMessages($update['message']['id'], $sourcePeerId, $targetPeerId, $update['message']['message'], $res);
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
        $timeMs = (int)(microtime(true)*1000.*1000.);
        $this->messagesDb[$timeMs] = $update;
    }

    private function healthcheck(): void
    {
        $self = $this->fullGetSelf();
        write('/root/.healthcheck', json_encode($self));
    }

    private function processMessages($id, int $sourcePeerId, int $targetPeerId, string $text, bool|string $res): void
    {
        $this->logger(date('Y-m-d H:i:s') . " Forwarding message {$id} from {$sourcePeerId}  to {$targetPeerId}", Logger::WARNING);
        try {
            $sourcePeerId = (string)$sourcePeerId;
            $fromChannel = str_starts_with($sourcePeerId, '-100');

            $originalSent = false;
            if (self::$repostMessages || (self::$sendLinks && !$fromChannel)) {
                $this->messages->forwardMessages(
                    from_peer: $sourcePeerId,
                    to_peer: $targetPeerId,
                    id: [$id],
                );
                $originalSent = true;
                $this->logger(date('Y-m-d H:i:s') . " Sent successfully: {$id} to {$targetPeerId}", Logger::WARNING);
            }

            if (self::$sendLinks && $fromChannel) {
                $sourcePeerId = str_replace('-100', '', $sourcePeerId);
                if ($originalSent) {
                    $message = <<<HTML
                        <a href="https://t.me/c/$sourcePeerId/$id">https://t.me/c/$sourcePeerId/$id</a>
                        HTML
                    ;
                } else {
                    $trimmedText = mb_strimwidth($text, 0, 100, '...');
                    $message = <<<HTML
                        Link to message: <a href="https://t.me/c/$sourcePeerId/$id">https://t.me/c/$sourcePeerId/$id</a>
                        Text:
                        $trimmedText
                        HTML
                    ;
                }
                $this->messages->sendMessage(
                    peer: $targetPeerId,
                    parse_mode: ParseMode::HTML,
                    message: $message,
                );
                $this->logger(date('Y-m-d H:i:s') . " Sent successfully: {$id} to {$targetPeerId}", Logger::WARNING);
            }
        } catch (\Throwable $e) {
            $this->logger($e, Logger::ERROR);
            $this->logger(date('Y-m-d H:i:s') . " Error while forwarding message: {$res}", Logger::ERROR);
        }
    }

    private function clearDuplicates(): void
    {
        if (self::$duplicatesTTL === 0) {
            return;
        }

        $actualMessages = [];
        foreach ($this->sentMessages as $authorId => $messages) {
            foreach ($messages as $hash => $time) {
                if ($time + self::$duplicatesTTL >= time()) {
                    $actualMessages[$authorId][$hash] = $time;
                }
            }
        }

        //Fix memory leaks in hashmap
        $this->sentMessages = $actualMessages;
    }

    private function isDuplicate(int $authorId, string $messageText): bool
    {
        if (self::$duplicatesTTL === 0) {
            return false;
        }

        $messageHash = md5($messageText);
        if (!empty($this->sentMessages[$authorId][$messageHash])) {
            $this->logger("Duplicate message; author id:  {$authorId}", Logger::WARNING);
            $this->sentMessages[$authorId][$messageHash] = time();
            return true;
        } else {
            $this->sentMessages[$authorId][$messageHash] = time();
        }
        return false;
    }
}
