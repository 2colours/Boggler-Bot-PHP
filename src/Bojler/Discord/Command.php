<?php

namespace Bojler\Discord;

use Closure;
use Ragnarok\Fenrir\Gateway\Events\MessageCreate;

class Command
{
    public function __construct(
        private Closure $callable,
        public readonly string $command,
        public readonly string $description,
        public readonly string $longDescription,
        public readonly string $usage,
        public readonly bool $showhelp = true
    ) {
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
