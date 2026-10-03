<?php

declare(strict_types=1);

namespace App\Services\Audio;

use RuntimeException;

final class SyntheticThumbnailGenerator
{
    private const SIZE = 400;

    /** @var array<string, array{0: int, 1: int, 2: int}> */
    private const CATEGORY_COLORS = [
        'Tones and Frequencies' => [45, 55, 72],
        'Noise and Focus' => [71, 85, 105],
        'Rain and Storm Sounds' => [52, 73, 94],
        'Forest and Nature' => [39, 87, 66],
        'Ocean and Ambient' => [30, 58, 95],
        'Water and Streams' => [41, 128, 185],
        'Fire and Warmth' => [192, 57, 43],
        'Wind and Air' => [127, 140, 141],
        'Night and Ambience' => [44, 62, 80],
    ];

    public function generateToFile(string $name, string $category, string $destinationPath): void
    {
        if (! extension_loaded('gd')) {
            throw new RuntimeException('PHP GD extension is required to generate synthetic thumbnails.');
        }

        $rgb = self::CATEGORY_COLORS[$category] ?? [60, 60, 60];
        $image = imagecreatetruecolor(self::SIZE, self::SIZE);
        if ($image === false) {
            throw new RuntimeException('Unable to allocate GD image.');
        }

        $bg = imagecolorallocate($image, $rgb[0], $rgb[1], $rgb[2]);
        imagefilledrectangle($image, 0, 0, self::SIZE, self::SIZE, $bg);

        $textColor = imagecolorallocate($image, 245, 245, 245);
        $label = $this->truncateLabel($name);
        $font = 5;
        $textWidth = imagefontwidth($font) * strlen($label);
        $textHeight = imagefontheight($font);
        $x = (int) max(8, (self::SIZE - $textWidth) / 2);
        $y = (int) ((self::SIZE - $textHeight) / 2);
        imagestring($image, $font, $x, $y, $label, $textColor);

        if (! imagepng($image, $destinationPath)) {
            imagedestroy($image);
            throw new RuntimeException("Failed to write PNG to {$destinationPath}");
        }

        imagedestroy($image);
    }

    private function truncateLabel(string $name): string
    {
        $max = 28;
        if (strlen($name) <= $max) {
            return $name;
        }

        return substr($name, 0, $max - 1).'…';
    }
}
