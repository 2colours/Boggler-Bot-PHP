<?php

declare(strict_types=1);

mb_internal_encoding('UTF-8');
mb_regex_encoding('UTF-8');

class ConfigHandler
{
    private static $instance;

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }


    private readonly mixed $config;

    private function __construct()
    {
        $this->config = json_decode(file_get_contents('param/config.json'), true);
    }

    public function get($key)
    {
        return $this->config[$key];
    }
}
