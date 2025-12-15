<?php

namespace Bojler;

class EnvironmentHandler
{
    public function getHomeServerId(): string
    {
        return $_ENV['HOME_SERVER'];
    }

    public function getHomeChannelId(): string
    {
        return $_ENV['HOME_CHANNEL'];
    }

    public function getDiscordToken(): string
    {
        return $_ENV['DC_TOKEN'];
    }

    public function isRunningInProduction(): bool
    {
        $field = $_ENV['PRODUCTION'];
        return isset($field) && $field === 'true';
    }
}
