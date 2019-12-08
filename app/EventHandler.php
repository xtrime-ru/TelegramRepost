<?php


namespace TelegramRepost;

use danog\MadelineProto\Logger;

class EventHandler extends \danog\MadelineProto\EventHandler
{
    private $startTime = 0;
    public static $recipients = [];
    public static $keywords = [];

    public function __construct($MadelineProto)
    {
        $this->startTime = strtotime('-5 minute');
        parent::__construct($MadelineProto);
    }
    public function onUpdateSomethingElse($update)
    {
        // See the docs for a full list of updates: http://docs.madelineproto.xyz/API_docs/types/Update.html
    }
    public function onUpdateNewChannelMessage($update)
    {
        yield $this->onUpdateNewMessage($update);
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
                'from_peer' => $update['message']['from_id'],
                'id' => [$update['message']['id']],
                'to_peer' => $peer,
            ]);
        }
    }
}
