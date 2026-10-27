<?php

namespace App\Titus;

/** TITUS_tile */
final class Tile
{
    /** @var int[] index of the 3 animation tiles */
    public array $animation = [0, 0, 0];
    public bool $animated = false;
    public int $horizflag = 0;
    public int $floorflag = 0;
    public int $ceilflag = 0;
    public int $current = 0;
    /** 16 * 16 palette indexes */
    public string $pixels = '';
}
