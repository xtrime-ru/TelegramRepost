<?php

namespace TelegramRepost;

/**
 * @psalm-type ChatId = int
 * @psalm-type Message = array
 */
final class MessagesByAuthor
{
    /** @var array<int, list<Message>> */
    private array $messagesByChats = [];
    private int $firstChatId;

    /**
     * @param float $maxTextSimilarity
     * @param Message $message
     */
    public function __construct(public int $authorId, private float $maxTextSimilarity = 0.5)
    {
    }

    /**
     * @param Message $message
     */
    public function push(array $message): void
    {
        $chatId = $message['peer_id'];
        $this->firstChatId ??= $chatId;
        $this->authorId ??= $message['from_id'] ?? $this->firstChatId;
        $this->messagesByChats[$chatId][] = $message;
    }

    private function isDuplicate(string $existingMessage, string $newMessage): bool
    {
       if ($this->maxTextSimilarity <= 0.) {
           return false;
       }

       if ($newMessage === $existingMessage) {
           return true;
       } else if ($this->maxTextSimilarity >= 1.) {
           return false;
       }

       similar_text($existingMessage, $newMessage, $similarity);

       return $similarity >= $this->maxTextSimilarity;
    }

    public static function getAllText(array $messages): string
    {
        $text = '';
        foreach ($messages as $message) {
            $text .= trim($message['message'] . "\n" . ($message['media']['document']['attributes'][1]['file_name'] ?? '')) . "\n";
        }
        return trim($text);
    }

    /**
     * @return array<ChatId, list<Message>>
     */
    public function getUniqMessages(): array
    {
        return array_filter($this->messagesByChats, function(array $messages, int $chatId): bool {
            return
                $chatId === $this->firstChatId
                || !$this->isDuplicate(
                    $this->getAllText($this->messagesByChats[$this->firstChatId]),
                    $this->getAllText($messages)
                )
            ;
        }, ARRAY_FILTER_USE_BOTH);

    }

}