<?php

include __DIR__ . '/vendor/autoload.php';

use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

// create new manager instance with desired driver
$manager = new ImageManager(Driver::class);

$image = $manager->create(512, 480)->text('Bullshit', 120, 100, function ($font) {
    $font->filename('./arial.ttf');
    $font->color('rgb(0, 178, 238)');
    $font->size(70);
});

$image->save('./example.png');
