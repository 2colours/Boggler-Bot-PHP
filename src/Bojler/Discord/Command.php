<?php

namespace Bojler\Discord;

use Ragnarok\Fenrir\Gateway\Events\MessageCreate;

class Command
{
    private mixed $callable; // unfortunately callable cannot be used as type for  properties

    public function __construct(
        callable $callable,
        public readonly string $command,
        public readonly string $description,
        public readonly string $longDescription,
        public readonly string $usage,
        public readonly bool $showhelp = true
    ) {
        $this->callable = $callable;
    }

    public function handle(MessageCreate $message, array $args): mixed // void or PromiseInterface
    {
        return call_user_func_array($this->callable, [$message, $args]);
    }

    public function getHelp(string $prefix): ?array
    {
        if (!$this->showhelp) {
            return null;
        }

        return [
            'command' => $prefix . $this->command,
            'description' => $this->description,
            'longDescription' => $this->longDescription,
            'usage' => $this->usage
        ];
    }
}
