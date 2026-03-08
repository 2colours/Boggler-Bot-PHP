<?php

namespace Bojler\Discord;

use Collator;
use React\Promise\PromiseInterface;
use Invoker\InvokerInterface;
use Psr\Log\LoggerInterface;
use Ragnarok\Fenrir\Constants\Events;
use Ragnarok\Fenrir\Discord;
use Ragnarok\Fenrir\Gateway\Events\MessageCreate;
use Ragnarok\Fenrir\Parts\User;
use Ragnarok\Fenrir\Rest\Helpers\Channel\EmbedBuilder;
use RuntimeException;

use function Bojler\message_reply;
use function Bojler\message_send_same_channel;
use function React\Async\async;
use function React\Async\await;

# TODO investigate and improve help message
final class CustomCommandClient
{
    private Collator $collator;
    private readonly InvokerInterface $invoker;
    private readonly Discord $discord;
    public private(set) CommandClientOptions $options;
    private LoggerInterface $logger;
    private User $bot;
    private array $commands = [];
    private array $aliases = [];

    public const MAX_EMBEDS = 25;

    public function __construct(InvokerInterface $invoker, Discord $discord, CommandClientOptions $options)
    {
        $this->invoker = $invoker;
        $this->discord = $discord;
        $this->options = $options;
        $this->logger = $options->logger;

        if ($this->options->caseInsensitivePrefix) {
            $this->options = $options->withLowerCasePrefix();
        }

        if ($this->options->defaultHelpCommand) {
            $this->registerCommand(
                'help',
                $this->defaultHelp(...),
                [
                    'description' => 'Provides a list of commands available.',
                    'usage' => '[command]',
                ]
            );
        }

        $this->collator = new Collator($this->options->locale);

        $discord->gateway->events->on(Events::READY, fn() => $this->bot = await($discord->rest->user->getCurrent()));
        $discord->gateway->events->on(Events::MESSAGE_CREATE, fn (MessageCreate $message) => $this->baseMessageHandler($message));
        ;
    }

    public function registerCommand(string $command, callable $callable, array $options = []): Command
    {
        if ($this->options->caseInsensitiveCommands) {
            $command = strtolower($command);
        }
        if (array_key_exists($command, $this->commands)) {
            throw new RuntimeException("A command with the name {$command} already exists.");
        }

        $commandInstance = $this->buildCommand($command, async(fn(MessageCreate $message, array $args) => $this->invoker->call($callable, ['ctx' => $message, 'args' => $args])), $options);
        $this->commands[$command] = $commandInstance;

        return $commandInstance;
    }

    private function buildCommand(string $command, callable $callable, array $options): Command
    {
        $command_options = new CommandOptions($options);

        foreach ($command_options->aliases as $alias) {
            if ($this->options->caseInsensitiveCommands) {
                $alias = strtolower($alias);
            }
            $this->aliases[$alias] = $command;
        }

        return new Command(
            $callable,
            $command,
            $command_options->description,
            $command_options->long_description,
            $command_options->usage,
            $command_options->show_help
        );
    }

    protected function checkForPrefix(string $content): ?string
    {
        $content_for_check = $this->options->caseInsensitivePrefix ? mb_strtolower($content) : $content;

        $prefix = $this->options->prefix;
        if (substr($content_for_check, 0, strlen($prefix)) === $prefix) {
            return substr($content, strlen($prefix));
        }

        return null;
    }

    public function getCommand(string $command, bool $aliases = true): ?Command
    {
        if (array_key_exists($command, $this->commands)) {
            return $this->commands[$command];
        }

        if (array_key_exists($command, $this->aliases) && $aliases) {
            return $this->commands[$this->aliases[$command]];
        }

        return null;
    }

    private function baseMessageHandler(MessageCreate $message): void
    {
        $this->logger->debug('Message event received...', [grapheme_substr($message->content, 0, 10)]);

        if ($message->author->id === $this->bot->id) {
            return;
        }

        $withoutPrefix = $this->checkForPrefix($message->content);
        if (is_null($withoutPrefix)) {
            return;
        }

        $this->logger->debug('Message looked like a command...');

        # MESSAGE_CREATE payloads don't have ->member->user assigned - pull it from ->author
        $message->member->user = $message->author;

        $args = mb_split(' +', $withoutPrefix);
        $command = array_shift($args);

        if (!is_null($command) && $this->options->caseInsensitiveCommands) {
            $command = mb_strtolower($command);
        }

        $command = $this->getCommand($command);
        if (is_null($command)) {
            return;
        }

        $result = $command->handle($message, $args);
        if ($result instanceof PromiseInterface) {
            $result->then(null, function (\Throwable $e) {
                $this->logger->warning($e->getMessage(), [$e->getFile(), $e->getLine(), $e->getTraceAsString()]);
            });
        }
    }

    private function defaultHelp(Discord $discord, MessageCreate $ctx, array $args): void
    {
        $prefix = $this->options->prefix;

        if (count($args) > 0) {
            $this->defaultHelpWithArgs($discord, $ctx, $prefix, $args);
            return;
        }

        $embed = new EmbedBuilder()
            ->setAuthor($this->bot->username, iconUrl: $this->bot->avatar)
            ->setTitle($this->bot->username)
            ->setFooter($this->bot->username);

        $commandsDescription = '';
        $embed_fields = $this->embedPerCommand($prefix);
        $texts = $this->textPerCommand($prefix);
        # Use embed fields in case commands count is below limit
        if (count($embed_fields) > self::MAX_EMBEDS) {
            $embed_fields = $this->groupTexts($texts, 10); # TODO do something with this magic number
        }
        if (count($embed_fields) > self::MAX_EMBEDS) {
            $embed_fields = [];
            $commandsDescription = implode("\n\n", $texts);
        }
        foreach ($embed_fields as $field) {
            $embed->addField(...$field);
        }
        $embed->setDescription(grapheme_substr("{$this->options->description}$commandsDescription", 0, 2048));

        message_send_same_channel($discord, $ctx, $embed);
    }

    private function defaultHelpWithArgs(Discord $discord, MessageCreate $message, string $prefix, array $args): void
    {
        $command = $this;
        foreach ($args as $commandString) {
            $newCommand = $command->getCommand($commandString);

            if (is_null($newCommand)) {
                message_reply($this->discord, $message, "The command $commandString does not exist.");
                return;
            }

            $command = $newCommand;
        }

        $help = $command->getHelp($prefix);

        $embed = new EmbedBuilder($this);
        $fullCommandString = implode(' ', $args);
        $embed
            ->setAuthor($this->bot->username, iconUrl: $this->bot->avatar)
            ->setTitle("$prefix$fullCommandString {$help['usage']}")
            ->setDescription($help['longDescription'] ?: $help['description'])
            ->setFooter($this->bot->username);

        if (count($this->aliases) > 0) {
            $aliasesString = '';
            foreach ($this->aliases as $alias => $command) {
                if ($command !== $commandString) {
                    continue;
                }

                $aliasesString .= "$alias\r\n";
            }

            if ($aliasesString) {
                $embed->addField('Aliases', $aliasesString, true);
            }
        }

        message_send_same_channel($discord, $message, $embed);
    }

    private function embedPerCommand(string $prefix): array
    {
        $result = [];
        foreach ($this->sortedCommands() as $command) {
            $help = $command->getHelp($prefix);
            $result[] = [
                'name' => $help['command'],
                'value' => $help['description'],
                'inline' => true,
            ];
            foreach ($help['subCommandsHelp'] as $subCommandHelp) {
                $result[] = [
                    'name' => $subCommandHelp['command'],
                    'value' => $subCommandHelp['description'],
                    'inline' => true,
                ];
            }
        }
        return $result;
    }

    private function textPerCommand(string $prefix): array
    {
        $result = [];
        foreach ($this->sortedCommands() as $command) {
            $help = $command->getHelp($prefix);
            $formatted_description = "`{$help['description']}`";
            $result[] = <<<END
                **{$help['command']}**
                $formatted_description
                END;

            foreach ($help['subCommandsHelp'] as $subCommandHelp) {
                $formatted_description = "`{$subCommandHelp['description']}`";
                #TODO it's not nice to have the array flat and allow separation of the command from its subcommands
                $result[] = <<<END
                    **{$subCommandHelp['command']}**
                    $formatted_description
                    END;
            }
        }
        return $result;
    }

    private function groupTexts(array $texts, int $groupSize): array
    {
        return array_map(
            fn(array $grouped_texts, int $no) =>
            [
                'name' => "Commands #$no.",
                'value' => grapheme_substr(implode("\n\n", $grouped_texts), 0, 1024), # TODO do something with the magic constant
                'inline' => true,
            ],
            array_chunk($texts, $groupSize),
            range(1, ceil(count($texts) / $groupSize))
        );
    }

    private function sortedCommands(): array
    {
        static $last_commands = null;
        static $last_sorted = null;
        if ($last_commands != $this->commands) {
            $last_commands = $this->commands;
            $last_sorted = $last_commands;
            uksort($last_sorted, $this->collator->compare(...));
        }

        return $last_sorted;
    }
}
