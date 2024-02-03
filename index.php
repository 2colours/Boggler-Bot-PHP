<?php

include __DIR__ . '/vendor/autoload.php';

declare(strict_types=1);

use Discord\Discord;
use Discord\Parts\Channel\Message;
use Discord\WebSockets\Intents;
use Discord\WebSockets\Event;
use Symfony\Component\Dotenv\Dotenv;

$dotenv = new Dotenv();
$dotenv->load(__DIR__ . '/.env');

$discord = new Discord([
    'token' => $_ENV['DC_TOKEN'],
    'intents' => Intents::getDefaultIntents()
    //      | Intents::MESSAGE_CONTENT, // Note: MESSAGE_CONTENT is privileged, see https://dis.gd/mcfaq
]);


$discord->on('ready', function (Discord $discord) {
    echo "Bot is ready!", PHP_EOL;

    // Listen for messages.
    $discord->on(Event::MESSAGE_CREATE, function (Message $message, Discord $discord) {
        echo "{$message->author->username}: {$message->content}", PHP_EOL;
        // Note: MESSAGE_CONTENT intent must be enabled to get the content if the bot is not mentioned/DMed.
    });
});

$discord->run();
