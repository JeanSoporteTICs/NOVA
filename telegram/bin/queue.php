#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/telegram.php';

$chatId = '';
$text = '';

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--chat=')) {
        $chatId = substr($arg, 7);
        continue;
    }
    if (str_starts_with($arg, '--text=')) {
        $text = substr($arg, 7);
    }
}

if ($text === '') {
    $text = 'Mensaje en cola desde NOVA Telegram Service: ' . date('d/m/Y H:i:s');
}

try {
    $id = telegram_queue_configured_message($text, [
        'chat_id' => $chatId,
        'source' => 'cli',
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, 'Mensaje encolado: ' . $id . PHP_EOL);
