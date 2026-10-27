<?php

namespace App\Titus;

/** TITUS_spritedata: one of the 356 sprites of SPRITES.SQZ (pixels = palette indexes, 0 = transparent). */
final class SpriteData
{
    public function __construct(
        public readonly int $index,
        public readonly int $w,
        public readonly int $h,
        public readonly int $collwidth,
        public readonly int $collheight,
        public readonly int $refwidth,
        public readonly int $refheight,
        /** w * h bytes, one palette index (0-15) per pixel */
        public readonly string $pixels,
    ) {
    }
}
