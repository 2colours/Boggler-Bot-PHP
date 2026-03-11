<?php

namespace Bojler\Discord;

use Psr\Log\LoggerInterface;

readonly class CommandClientOptions
{
    public function __construct(
        public string $description,
        public string $prefix,
        public string $locale,
        public LoggerInterface $logger,
        public bool $caseInsensitiveCommands,
        public bool $caseInsensitivePrefix = false,
        public bool $defaultHelpCommand = true
    ) {
    }

    public function withLowerCasePrefix(): self
    {
        return clone($this, ['prefix' => mb_strtolower($this->prefix)]);
    }
}
