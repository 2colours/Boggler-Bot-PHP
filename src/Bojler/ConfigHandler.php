<?php

namespace Bojler;

class ConfigHandler
{
    private const CONFIG_PATH = 'param/config.json';

    private readonly array $config;

    public function __construct()
    {
        $this->config = json_decode(file_get_contents(self::CONFIG_PATH), true);
    }

    public function getDictionaries(): array
    {
        return $this->config['dictionaries'];
    }

    public function getWordCountForEmoji(): int
    {
        return $this->config['rewards']['words_for_emoji'];
    }

    public function getPlayerDefaults(): array
    {
        return $this->config['default_player'];
    }

    public function getDisplayNormalRecord(): array
    {
        return $this->config['display']['normal'];
    }

    public function getDisplaySmallRecord(): array
    {
        return $this->config['display']['small'];
    }

    public function getDisplayNormalFilePath(string $prefix): string
    {
        return $prefix . $this->getDisplayNormalRecord()['image_filename'];
    }

    public function getDisplaySmallFilePath(string $prefix): string
    {
        return $prefix . $this->getDisplaySmallRecord()['image_filename'];
    }

    public function getSavesFileName(): string
    {
        return $this->config['saves_filename'];
    }

    public function getCurrentGameFileName(): string
    {
        return $this->config['current_game'];
    }

    public function getExamples(): array
    {
        return $this->config['examples'];
    }

    public function getAvailableLanguages(): array
    {
        return array_keys($this->config['dice']);
    }

    public function getCommunityWordlists(): array
    {
        return $this->config['community_wordlists'];
    }

    public function getCustomEmojis(): array
    {
        return $this->config['custom_emojis'];
    }

    public function getProgressBarVersion(): array
    {
        return $this->config['progress_bar_version'];
    }

    public function getDefaultTranslation(): array
    {
        return $this->config['default_translation'];
    }

    public function getDice(): array
    {
        return $this->config['dice'];
    }

    public function getWordlists(): array
    {
        return $this->config['wordlists'];
    }

    public function getDefaultEndAmount(): int
    {
        return $this->config['default_end_amount'];
    }

    public function getLocale(string $languageName): string
    {
        return $this->config['locale'][$languageName];
    }
}
