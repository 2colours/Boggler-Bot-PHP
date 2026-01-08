<?php

namespace Bojler;

readonly class NewGamePayload
{
    public function __construct(public int $games_so_far, public string $planned_language)
    {
    }
}
