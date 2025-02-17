<?php

namespace Bojler;

use Collator;
use Discord\DiscordCommandClient;
use Discord\Parts\Embed\Embed;
use React\Promise\PromiseInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

# TODO investigate and improve help message
final class CustomCommandClient extends DiscordCommandClient
{
    private Collator $collator;

    public const MAX_EMBEDS = 25;

    public function __construct(array $options = [])
    {
        $own_options = $this->resolveCustomOptions($options['customOptions']);
        unset($options['customOptions']);

        parent::__construct($options);

        $this->collator = new Collator($own_options['locale']);

        # This is completely idiotic, thank DiscordPHP
        $this->on('init', $this->monkeyPatching(...));
    }

    private function resolveCustomOptions(array $custom_options)
    {
        $resolver = new OptionsResolver();

        $resolver
            ->setRequired('locale')
            ->setAllowedTypes('locale', 'string');

        return $resolver->resolve($custom_options);
    }

    private function monkeyPatching()
    {
        $this->removeAllListeners('message');
        $this->on('message', $this->baseMessageHandler(...));

        if ($this->commandClientOptions['defaultHelpCommand']) {
            $this->unregisterCommand('help');
            $this->registerCommand(
                'help',
                $this->defaultHelp(...),
                [
                    'description' => 'Provides a list of commands available.',
                    'usage' => '[command]',
                ]
            );
        }
    }

    private function baseMessageHandler($message)
    {
        $this->logger->debug('Message event received...', [grapheme_substr($message->content, 0, 10)]);

        if ($message->author->id === $this->id) {
            return;
        }

        $withoutPrefix = $this->checkForPrefix($message->content);
        if (is_null($withoutPrefix)) {
            return;
        }

        $this->logger->debug('Message looked like a command...');

        $args = mb_split(' +', $withoutPrefix);
        $command = array_shift($args);

        if ($command !== null && $this->commandClientOptions['caseInsensitiveCommands']) {
            $command = strtolower($command);
        }

        $command = $this->getCommand($command);
        if (is_null($command)) {
            return;
        }

        $result = $command->handle($message, $args);
        if (is_string($result)) {
            $result = $message->reply($result);
        }

        if ($result instanceof PromiseInterface) {
            $result->then(null, function (\Throwable $e) {
                $this->logger->warning($e->getTraceAsString());
            });
        }
    }

    private function defaultHelp($message, $args)
    {
        $prefix = str_replace((string) $this->user, "@{$this->username}", $this->commandClientOptions['prefix']);

        if (count($args) > 0) {
            $this->defaultHelpWithArgs($message, $prefix, $args);
            return;
        }

        $embed = new Embed($this);
        $embed->setAuthor($this->commandClientOptions['name'], $this->client->avatar)
            ->setTitle($this->commandClientOptions['name'])
            ->setFooter($this->commandClientOptions['name']);

        $commandsDescription = '';
        $this->collator->asort($this->commands); # TODO make sure this causes no problems or retain consistent ordering some way
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
            $embed->addField($field);
        }
        $embed->setDescription(grapheme_substr("{$this->commandClientOptions['description']}$commandsDescription", 0, 2048));

        $message->channel->sendEmbed($embed);
    }

    private function defaultHelpWithArgs($message, $prefix, $args)
    {
        $command = $this;
        foreach ($args as $commandString) {
            $newCommand = $command->getCommand($commandString);

            if (is_null($newCommand)) {
                return "The command $commandString does not exist.";
            }

            $command = $newCommand;
        }

        $help = $command->getHelp($prefix);

        $embed = new Embed($this);
        $fullCommandString = implode(' ', $args);
        $embed->setAuthor($this->commandClientOptions['name'], $this->client->user->avatar)
            ->setTitle("$prefix$fullCommandString {$help['usage']}")
            ->setDescription($help['longDescription'] ?: $help['description'])
            ->setFooter($this->commandClientOptions['name']);

        if (count($this->aliases) > 0) {
            $aliasesString = '';
            foreach ($this->aliases as $alias => $command) {
                if ($command !== $commandString) {
                    continue;
                }

                $aliasesString .= "$alias\r\n";
            }

            if ($aliasesString) {
                $embed->addFieldValues('Aliases', $aliasesString, true);
            }
        }

        foreach ($help['subCommandsHelp'] as $subCommandHelp) {
            $embed->addFieldValues($subCommandHelp['command'], $subCommandHelp['description'], true);
        }

        $message->channel->sendEmbed($embed);
    }

    private function embedPerCommand(string $prefix): array
    {
        $result = [];
        foreach ($this->commands as $command) {
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
        foreach ($this->commands as $command) {
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
            fn($grouped_texts, $no) =>
            [
                'name' => "Commands #$no.",
                'value' => grapheme_substr(implode("\n\n", $grouped_texts), 0, 1024), # TODO do something with the magic constant
                'inline' => true,
            ],
            array_chunk($texts, $groupSize),
            range(1, ceil(count($texts) / $groupSize))
        );
    }
}
