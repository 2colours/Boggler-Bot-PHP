<?php

namespace Bojler;

readonly class DictionaryType
{
    public static function fromDictstring(ConfigHandler $config, string $dictstring): self
    {
        return new self($config, ...explode('-', $dictstring));
    }

    public function __construct(private ConfigHandler $config, public string $src_lang, public string $target_lang)
    {
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
