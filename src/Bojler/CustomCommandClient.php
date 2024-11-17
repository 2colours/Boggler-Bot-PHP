<?php

namespace Bojler;

use Discord\DiscordCommandClient;
use Discord\Parts\Embed\Embed;
use React\Promise\PromiseInterface;

# TODO investigate and improve help message
class CustomCommandClient extends DiscordCommandClient
{
    public function __construct(array $options = [])
    {
        parent::__construct($options);

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
            if ($command === null) {
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
        $prefix = str_replace((string) $this->user, '@' . $this->username, $this->commandClientOptions['prefix']);
        $fullCommandString = implode(' ', $args);

        if (count($args) > 0) {
            $command = $this;
            while (count($args) > 0) {
                $commandString = array_shift($args);
                $newCommand = $command->getCommand($commandString);

                if (is_null($newCommand)) {
                    return "The command {$commandString} does not exist.";
                }

                $command = $newCommand;
            }

            $help = $command->getHelp($prefix);

            $embed = new Embed($this);
            $embed->setAuthor($this->commandClientOptions['name'], $this->client->user->avatar)
                ->setTitle($prefix . $fullCommandString . '\'s Help')
                ->setDescription(! empty($help['longDescription']) ? $help['longDescription'] : $help['description'])
                ->setFooter($this->commandClientOptions['name']);

            if (! empty($help['usage'])) {
                $embed->addFieldValues('Usage', '``' . $help['usage'] . '``', true);
            }

            if (! empty($this->aliases)) {
                $aliasesString = '';
                foreach ($this->aliases as $alias => $command) {
                    if ($command != $commandString) {
                        continue;
                    }

                    $aliasesString .= "{$alias}\r\n";
                }

                if (! empty($aliasesString)) {
                    $embed->addFieldValues('Aliases', $aliasesString, true);
                }
            }

            if (! empty($help['subCommandsHelp'])) {
                foreach ($help['subCommandsHelp'] as $subCommandHelp) {
                    $embed->addFieldValues($subCommandHelp['command'], $subCommandHelp['description'], true);
                }
            }

            $message->channel->sendEmbed($embed);

            return;
        }

        $embed = new Embed($this);
        $embed->setAuthor($this->commandClientOptions['name'], $this->client->avatar)
            ->setTitle($this->commandClientOptions['name'])
            ->setType(Embed::TYPE_RICH)
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
            $commandsDescription .= "\n\n`" . $help['command'] . "`\n" . $help['description'];

            foreach ($help['subCommandsHelp'] as $subCommandHelp) {
                $embedfields[] = [
                    'name' => $subCommandHelp['command'],
                    'value' => $subCommandHelp['description'],
                    'inline' => true,
                ];
                $commandsDescription .= "\n\n`" . $subCommandHelp['command'] . "`\n" . $subCommandHelp['description'];
            }
        }
        // Use embed fields in case commands count is below limit
        if (count($embedfields) <= 25) {
            foreach ($embedfields as $field) {
                $embed->addField($field);
            }
            $commandsDescription = '';
        }

        $embed->setDescription(substr($this->commandClientOptions['description'] . $commandsDescription, 0, 2048));

        $message->channel->sendEmbed($embed);
    }
}
