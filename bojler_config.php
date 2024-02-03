<?php

declare(strict_types=1);

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


    private $config;

    private function __construct()
    {
        $this->config = json_decode(file_get_contents('param/config.json'), true);
    }

    public function get($key)
    {
        return $this->config[$key];
    }
}
