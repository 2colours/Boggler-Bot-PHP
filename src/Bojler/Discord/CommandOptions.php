<?php

namespace Bojler\Discord;

readonly class CommandOptions
{
    public array $aliases;
    public string $description;
    public string $long_description;
    public string $usage;
    public bool $show_help;

    public function __construct(array $options_array)
    {
        $this->aliases = $options_array['alias'] ?? [];
        $this->description = $options_array['description'] ?? 'No description provided.';
        $this->long_description = $options_array['longDescription'] ?? '';
        $this->usage = $options_array['usage'] ?? '';
        $this->show_help = $options_array['showHelp'] ?? true;
    }
}
