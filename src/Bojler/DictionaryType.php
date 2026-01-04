<?php

namespace Bojler;

class DictionaryType
{
    public static function fromDictstring(ConfigHandler $config, string $dictstring): self
    {
        return new self($config, ...explode('-', $dictstring));
    }

    private ConfigHandler $config;

    public readonly string $src_lang;
    public readonly string $target_lang;

    public function __construct(ConfigHandler $config, string $src_lang, string $target_lang)
    {
        $this->config = $config;
        $this->src_lang = $src_lang;
        $this->target_lang = $target_lang;
    }

    public function asDictstring(): string
    {
        return "{$this->src_lang}-{$this->target_lang}";
    }

    public function asDictcode(): int
    {
        return $this->config->getDictionaries()[$this->asDictstring()];
    }
}
