<?php

namespace Bojler;

class Counter
{
    private $current_value;
    public readonly int $threshold;

    public function __construct($threshold)
    {
        $this->current_value = 0;
        $this->threshold = $threshold;
    }

    public function reset()
    {
        $this->current_value = 0;
    }

    public function trigger()
    {
        $this->current_value++;
        if ($this->current_value === $this->threshold) {
            $this->current_value = 0;
            return true;
        }
        return false;
    }
}
