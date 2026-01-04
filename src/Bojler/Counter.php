<?php

namespace Bojler;

class Counter
{
    private int $current_value;

    public function __construct(public readonly int $threshold)
    {
        $this->current_value = 0;
    }

    public function reset(): void
    {
        $this->current_value = 0;
    }

    public function trigger(): bool
    {
        $this->current_value++;
        if ($this->current_value === $this->threshold) {
            $this->current_value = 0;
            return true;
        }
        return false;
    }
}
