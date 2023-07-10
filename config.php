<?php

$settings = [
    'sources' => array_filter(
        array_map('trim',
            explode(',', getenv('SOURCES'))
        )
    ),
    'recipients' => array_filter(
        array_map('trim',
            explode(',', getenv('RECIPIENTS'))
        )
    ),
    'keywords' => array_filter(
        array_map('trim',
            explode(',', getenv('KEYWORDS'))
        )
    ),
    'stop_words' => array_filter(
        array_map('trim',
            explode(',', getenv('STOP_WORDS'))
        )
    ),
    'online_status' => (bool)filter_var(getenv('ONLINE_STATUS'), FILTER_VALIDATE_BOOL),
    'save_messages' => (bool)filter_var(getenv('SAVE_MESSAGES'), FILTER_VALIDATE_BOOL),
    'telegram' => [
        'app_info' => [ // obtained in https://my.telegram.org
            'api_id' => (int)getenv('TELEGRAM_API_ID'),
            'api_hash' => (string)getenv('TELEGRAM_API_HASH'),
        ],
        'logger' => [ // Logger settings
            'logger' => \danog\MadelineProto\Logger::ECHO_LOGGER, //  0 - Logs disabled, 3 - echo logs.
            'logger_level' => (int)getenv('LOGGER_LEVEL'), // Logging level, available logging levels are: ULTRA_VERBOSE - 5, VERBOSE - 4 , NOTICE - 3, WARNING - 2, ERROR - 1, FATAL_ERROR - 0.
        ],
        'flood_timeout' => [
            'wait_if_lt' => 5,
        ],
        'connection_settings' => [
            'all' => [
                'drop_timeout' => 10,
                'proxy' => '\SocksProxy',
                'proxy_extra' => [
                    'address' => (string)getenv('TELEGRAM_PROXY_ADDRESS'),
                    'port' => (int)getenv('TELEGRAM_PROXY_PORT'),
                    'username' => getenv('TELEGRAM_PROXY_USERNAME'),
                    'password' => getenv('TELEGRAM_PROXY_PASSWORD'),
                ]
            ],
        ],
        'serialization' => [
            'serialization_interval' => 60,
        ],
        'db' => [
            'type' => getenv('DB_TYPE'),
            getenv('DB_TYPE') => [
                'host' => (string)getenv('DB_HOST'),
                'port' => (int)getenv('DB_PORT'),
                'user' => getenv('DB_USER'),
                'password' => getenv('DB_PASSWORD'),
                'database' => getenv('DB_DATABASE'),
                'max_connections' => (int)getenv('DB_MAX_CONNECTIONS'),
                'idle_timeout' => (int)getenv('DB_IDLE_TIMEOUT'),
                'cache_ttl' => getenv('DB_CACHE_TTL'),
            ]
        ],
        'download' => [
            'report_broken_media' => false,
        ],
    ],
];

if (empty($settings['telegram']['connection_settings']['all']['proxy_extra']['address'])) {
    $settings['telegram']['connection_settings']['all']['proxy'] = '\Socket';
    $settings['telegram']['connection_settings']['all']['proxy_extra'] = [];
}

if (empty($settings['telegram']['app_info']['api_id'])) {
    throw new InvalidArgumentException('Need to fill TELEGRAM_API_ID AND HASH in .env');
}

foreach ($_ENV as $name => $value) {
    if (str_contains($name, 'MADELINE')) {
        define($name, $value);
    }
}

return $settings;