<?php declare(strict_types = 1);


namespace TelegramRepost;

use Amp\Sync\LocalKeyedMutex;
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

    public static float $maxTextSimilarity = 0.5;
    public static string $lang;

    /** @var array<int, null> */
    private static array $sourcesIds = [];

    /** @var list<int> */
    private static array $recipientsIds = [];

    /** @var array<int, MessagesByAuthor>  */
    private array $messageAccumulators;

    /**
     * @var array<int, string>
     */
    private array $delayedSends;

    #[OrmMappedArray(keyType: KeyType::INT, valueType:ValueType::SCALAR, cacheTtl: 0, serializer: new Json())]
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

    public function onStop()
    {
        foreach ($this->delayedSends as $future) {
            EventLoop::cancel($future);
        }
        foreach ($this->messageAccumulators as $messageAccumulator) {
            $this->processAuthor($messageAccumulator);
        }
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

        $chatId =  $this->getId($update['message']['peer_id']);

        if (!empty($update['message']['from_id']) || !empty($update['message']['fwd_from']['from_id'])) {
            $authorId = $this->getId($update['message']['from_id'] ?? $update['message']['fwd_from']['from_id']);
        } else {
            $authorId = $chatId;
        }

        if (!empty(self::$sourcesIds) && !array_key_exists($chatId, self::$sourcesIds) && !array_key_exists($authorId, self::$sourcesIds)) {
            $this->logger("Skip forwarding message {$update['message']['id']} from {$chatId} from wrong peer_id");
            return;
        }

        if (!isset($this->messageAccumulators[$authorId])) {
            $this->messageAccumulators[$authorId] = $messagesAccumulator = new MessagesByAuthor($authorId, static::$maxTextSimilarity);
            $this->delayedSends[$authorId] = EventLoop::delay(static::$duplicatesTTL, fn() => $this->processAuthor($messagesAccumulator));
        } else {
            $messagesAccumulator = $this->messageAccumulators[$authorId];
        }

        $messagesAccumulator->push($update['message']);
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

    private function repostMessages(array $messages, int $sourcePeerId, int $targetPeerId): void
    {
        static $mutex = new LocalKeyedMutex();
        $lock = $mutex->acquire((string)$targetPeerId);

        try {
            $sourcePeerId = (string)$sourcePeerId;
            $usernameSource = $this->getUsername($sourcePeerId);
            $usernameAuthor = $this->getUsername($messages[0]['from_id'] ?? $messages[0]['fwd_from']['from_id']);
            $fromChannel = str_starts_with($sourcePeerId, '-100');
            $ids = array_column($messages, 'id');

            $originalSent = false;
            if (self::$repostMessages || (self::$sendLinks && !$fromChannel)) {
                $this->messages->forwardMessages(
                    from_peer: $sourcePeerId,
                    to_peer: $targetPeerId,
                    id: $ids,
                );
                $originalSent = true;
                $this->logger(date('Y-m-d H:i:s') . " Sent successfully: to {$targetPeerId}", Logger::WARNING);
            }

            if (self::$sendLinks && $fromChannel) {
                $title = $this->getTitle($sourcePeerId);

                $sourcePeerId = str_replace('-100', '', $sourcePeerId);
                $firstId = reset($ids);

                $sourceLink = $usernameSource ? "https://t.me/$usernameSource/$firstId" : "https://t.me/c/$sourcePeerId/$firstId";
                if ($originalSent) {
                    $message = $this->getMessage($title, $sourceLink, $usernameAuthor);
                } else {
                    $text = implode(PHP_EOL, array_column($messages, 'message'));
                    $trimmedText = mb_strimwidth($text, 0, 1000, '...');
                    $message = $this->getMessage($title, $sourceLink, $usernameAuthor, $trimmedText);
                }
                $this->messages->sendMessage(
                    peer: $targetPeerId,
                    parse_mode: ParseMode::HTML,
                    message: $message,
                    no_webpage: true,
                );
                $this->logger(date('Y-m-d H:i:s') . " Sent successfully: {$firstId} to {$targetPeerId}", Logger::WARNING);
            }
        } catch (\Throwable $e) {
            $this->logger($e, Logger::ERROR);
            $this->logger(sprintf("[%s] Error while forwarding message: %s", date('Y-m-d H:i:s'), json_encode($messages, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)), Logger::ERROR);
        } finally {
            $lock->release();
        }
    }

    private function processAuthor(MessagesByAuthor $messageAccumulator): void {
        $authorId = $messageAccumulator->authorId;
        unset($this->delayedSends[$authorId]);
        unset($this->messageAccumulators[$authorId]);

        $messagesByChats = $messageAccumulator->getUniqMessages();

        $this->logger(sprintf("Processing author: %d; %s", $authorId, json_encode($messagesByChats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)), Logger::NOTICE);

        foreach ($messagesByChats as $chatId => $messages) {
            $allText = MessagesByAuthor::getAllText($messages);

            if (!empty(static::$keywords) && empty($allText)) {
                $this->logger('Skip empty message');
                return;
            }

            foreach (static::$stopWords as $stopWord) {
                if (preg_match("~{$stopWord}~iuS", $allText)) {
                    $this->logger("Skip by stop word: $stopWord", Logger::WARNING);
                    return;
                }
            }

            if (static::$keywords) {
                $matches = 0;
                foreach (static::$keywords as $keyword) {
                    $matches += preg_match("~{$keyword}~iuS", $allText);
                    if ($matches > 0) {
                        $this->logger("Match $authorId  by keyword: $keyword", Logger::WARNING);
                        break;
                    }
                }

                if (!$matches) {
                    $this->logger("{$authorId} - no matches", Logger::WARNING);
                    return;
                }
            }

            foreach ($messages as $message) {
                $this->saveMessageToDb($message);
            }

            foreach (static::$recipientsIds as $targetPeerId) {
                $this->repostMessages($messages, $chatId, $targetPeerId);
            }
        }

    }

    private function getUsername(string|int $id): string|null
    {
        $info = $this->getInfo($id);
        $info = $info[array_key_first($info)];

        return $info['username'] ?? $info['usernames'][0]['username'] ?? null;
    }

    private function getTitle(string|int $id): string|null
    {
        $info = $this->getInfo($id);
        $info = $info[array_key_first($info)];

        return $info['title'] ?? null;
    }

    private function getMessage(?string $title, string $sourceLink, ?string $username = null, ?string $trimmedText = null): string
    {

        switch (self::$lang) {
            case 'ru':
                $translationsName = 'Группа';
                $translationsUser = 'Пользователь';
                $translationsText = 'Текст';
                break;
            case 'en':
                $translationsName = 'Group';
                $translationsUser = 'User';
                $translationsText = 'Text';
                break;
            default:
                throw new \InvalidArgumentException('Unknown language');
        }

        $message = <<<HTML
            <b>$translationsName:</b> <a href="$sourceLink">$title</a>
            HTML
        ;
        if ($username) {
            $message .= <<<HTML
                
                <b>$translationsUser:</b> <a href="https://t.me/$username">$username</a>
                HTML
            ;
        }
        if ($trimmedText) {
            $message .= <<<HTML
                
                <b>$translationsText:</b>
                    <blockquote expandable>$trimmedText</blockquote>
                HTML
            ;

        }

        return $message;
    }
}
