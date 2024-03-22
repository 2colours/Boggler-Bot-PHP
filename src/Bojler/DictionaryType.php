<?php

namespace Bojler;

class DictionaryType
{
    public static function fromDictstring(string $dictstring)
    {
        return new self(...explode('-', $dictstring));
    }

    public readonly string $src_lang;
    public readonly string $target_lang;

    public function __construct(string $src_lang, string $target_lang)
    {
        $this->src_lang = $src_lang;
        $this->target_lang = $target_lang;
    }

    public function asDictstring()
    {
        return "{$this->src_lang}-{$this->target_lang}";
    }

    public function asDictcode()
    {
        return ConfigHandler::getInstance()->getDictionaries()[$this->asDictstring()]; # TODO better injection of singleton
    }
}
