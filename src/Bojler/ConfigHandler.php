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

    public function getDictionaries()
    {
        return $this->config['dictionaries'];
    }

    public function getWordCountForEmoji()
    {
        return $this->config['rewards']['words_for_emoji'];
    }

    public function getPlayerDefaults()
    {
        return $this->config['default_player'];
    }

    public function getDisplayNormalRecord()
    {
        return $this->config['display']['normal'];
    }

    public function getDisplaySmallRecord()
    {
        return $this->config['display']['small'];
    }

    public function getDisplayNormalFilePath(string $prefix)
    {
        return $prefix . $this->getDisplayNormalRecord()['image_filename'];
    }

    public function getDisplaySmallFilePath(string $prefix)
    {
        return $prefix . $this->getDisplaySmallRecord()['image_filename'];
    }

    public function getSavesFileName()
    {
        return $this->config['saves_filename'];
    }

    public function getCurrentGameFileName()
    {
        return $this->config['current_game'];
    }

    public function getExamples()
    {
        return $this->config['examples'];
    }

    public function getAvailableLanguages()
    {
        return array_keys($this->config['dice']);
    }

    public function getCommunityWordlists()
    {
        return $this->config['community_wordlists'];
    }

    public function getCustomEmojis()
    {
        return $this->config['custom_emojis'];
    }

    public function getProgressBarVersion()
    {
        return $this->config['progress_bar_version'];
    }

    public function getDefaultTranslation()
    {
        return $this->config['default_translation'];
    }

    public function getDice()
    {
        return $this->config['dice'];
    }

    public function getWordlists()
    {
        return $this->config['wordlists'];
    }

    public function getDefaultEndAmount()
    {
        return $this->config['default_end_amount'];
    }

    public function getLocale(string $languageName)
    {
        return $this->config['locale'][$languageName];
    }
}
