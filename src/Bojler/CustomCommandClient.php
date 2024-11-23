<?php

namespace Bojler;

use Collator;
use Discord\DiscordCommandClient;
use Discord\Parts\Embed\Embed;
use React\Promise\PromiseInterface;

# TODO investigate and improve help message
class CustomCommandClient extends DiscordCommandClient
{
    private Collator $collator;

    public function __construct(array $options = [])
    {
        $own_options = $options['customOptions']; # TODO do some validation here as well
        unset($options['customOptions']);

        parent::__construct($options);

        $this->collator = new Collator($own_options['locale']);

        # This is completely idiotic, thank DiscordPHP
        $this->on('ready', $this->monkeyPatching(...));
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
        if ($message->author->id == $this->id) {
            return;
        }

        if ($withoutPrefix = $this->checkForPrefix($message->content)) {
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
        $embedfields = [];
        foreach ($this->commands as $command) {
            $help = $command->getHelp($prefix);
            $embedfields[] = [
                'name' => $help['command'],
                'value' => $help['description'],
                'inline' => true,
            ];
            $commandsDescription .= <<<END
                
                
                `{$help['command']}`
                {$help['description']}
                END;

            foreach ($help['subCommandsHelp'] as $subCommandHelp) {
                $embedfields[] = [
                    'name' => $subCommandHelp['command'],
                    'value' => $subCommandHelp['description'],
                    'inline' => true,
                ];
                $commandsDescription .= <<<END
                    
                    
                    `{$subCommandHelp['command']}`
                    {$subCommandHelp['description']}
                    END;
            }
        }
        // Use embed fields in case commands count is below limit
        if (count($embedfields) <= 25) {
            foreach ($embedfields as $field) {
                $embed->addField($field);
            }
            $commandsDescription = '';
        }

        $embed->setDescription(substr("{$this->commandClientOptions['description']}$commandsDescription", 0, 2048));

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
            ->setTitle("$prefix$fullCommandString's Help")
            ->setDescription($help['longDescription'] ?: $help['description'])
            ->setFooter($this->commandClientOptions['name']);

        if ($help['usage']) {
            $embed->addFieldValues('Usage', "``{$help['usage']}``", true);
        }

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
}
