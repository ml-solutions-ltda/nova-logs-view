<?php

return [
    'root_path' => storage_path('logs'),
    'patterns' => ['*.log'],
    'max_files' => 31,
    'max_bytes_per_file' => 4 * 1024 * 1024,
    'max_entries_per_file' => 2500,
    'max_entries' => 6000,
    'default_per_page' => 50,
    'max_per_page' => 100,
    'redacted_keys' => [
        'authorization', 'cookie', 'password', 'password_confirmation',
        'secret', 'token', 'access_token', 'refresh_token', 'api_key',
        'client_secret', 'private_key', 'session', 'set-cookie',
    ],
];
