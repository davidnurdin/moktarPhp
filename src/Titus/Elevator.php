<?php

namespace App\Titus;

/** TITUS_elevator */
final class Elevator
{
    public bool $enabled = false;
    public Sprite $sprite;
    public int $counter = 0;
    public int $range = 0;
    public int $init_direction = 0;
    public bool $init_enabled = false;
    public int $initspeedX = 0;
    public int $initspeedY = 0;
    public int $initsprite = 0;
    public int $initX = 0;
    public int $initY = 0;

    public function __construct()
    {
        $this->sprite = new Sprite();
    }
}
