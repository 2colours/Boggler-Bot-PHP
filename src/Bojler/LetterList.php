<?php

namespace Bojler;

use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Intervention\Image\Typography\Font;

class LetterList
{
    public const SIZE = 16;

    public array $list;
    public array $lower_cntdict;

    public function __construct(array $data, bool $preshuffle = false, bool $just_regenerate = false)
    {
        $this->list = $data;
        $this->lower_cntdict = array_count_values(array_map(mb_strtolower(...), array_filter($data, fn ($letter) => isset($letter))));
        if ($preshuffle) {
            $this->shuffle();
        } elseif ($just_regenerate) {
            $this->drawImageMatrix(...DISPLAY_NORMAL);
            $this->drawImageMatrix(...DISPLAY_SMALL);
        }
    }

    public function shuffle()
    {
        shuffle($this->list);
        $this->drawImageMatrix(...DISPLAY_NORMAL);
        $this->drawImageMatrix(...DISPLAY_SMALL);
    }

    private function drawImageMatrix(int $space_top, int $space_left, int $distance_vertical, int $distance_horizontal, int $font_size, string $image_filename, int $img_h, int $img_w)
    {
        $manager = new ImageManager(Driver::class);
        $image = $manager->create($img_w, $img_h)->fill('white');
        $font = new Font('param/arial.ttf');
        $font->setSize($font_size);
        $font->setColor('rgb(0, 178, 238)');

        foreach ($this->list as $i => $item) {
            $image->text(
                $item,
                $space_left + $distance_horizontal * ($i % 4),
                $space_top + $distance_vertical * intdiv($i, 4),
                $font
            );
        }

        $image->save("live_data/$image_filename");
    }

    public function isAbnormal()
    {
        return count($this->list) != self::SIZE;
    }
}
