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
            'type' => \danog\MadelineProto\Logger::ECHO_LOGGER, //  0 - Logs disabled, 3 - echo logs.
            'level' => (int)getenv('LOGGER_LEVEL'), // Logging level, available logging levels are: ULTRA_VERBOSE - 5, VERBOSE - 4 , NOTICE - 3, WARNING - 2, ERROR - 1, FATAL_ERROR - 0.
        ],
        'rpc' => [
            'flood_timeout' => 5,
            'rpc_drop_timeout' => 11,
        ],
        'connection' => [
            'max_media_socket_count' => 10
        ],
        'serialization' => [
            'interval' => 600,
        ],
        'db' => [
            'enable_min_db' => (bool)filter_var((string)getenv('DB_ENABLE_MIN_DATABASE'), FILTER_VALIDATE_BOOL),
            'enable_file_reference_db' => (bool)filter_var((string)getenv('DB_ENABLE_FILE_REFERENCE_DATABASE'), FILTER_VALIDATE_BOOL),
            'type' => (string)getenv('DB_TYPE'),
            getenv('DB_TYPE') => [
                'uri' => 'tcp://' . getenv('DB_HOST') . ':' . (int)getenv('DB_PORT'),
                'username' => (string)getenv('DB_USER'),
                'password' => (string)getenv('DB_PASSWORD'),
                'database' => (string)getenv('DB_DATABASE'),
                'max_connections' => (int)getenv('DB_MAX_CONNECTIONS'),
                'idle_timeout' => (int)getenv('DB_IDLE_TIMEOUT'),
                'cache_ttl' => (string)getenv('DB_CACHE_TTL'),
                'serializer' => (string)getenv('DB_SERIALIZER'),
            ]
        ],
        'metrics' => [
            'enable_prometheus_collection' => false,
        ],
        'files' => [
            'report_broken_media' => false,
        ],
    ],
];

if (empty($settings['telegram']['app_info']['api_id'])) {
    throw new InvalidArgumentException('Need to fill TELEGRAM_API_ID AND HASH in .env');
}

foreach ($_ENV as $name => $value) {
    if (str_contains($name, 'MADELINE')) {
        define($name, $value);
    }
}

return $settings;