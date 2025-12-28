<?php

namespace Bojler;

class NewGamePayload
{
    public function __construct(public readonly int $games_so_far, public readonly string $planned_language)
    {
    }
}
