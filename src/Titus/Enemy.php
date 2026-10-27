<?php

namespace App\Titus;

/** TITUS_enemy */
final class Enemy
{
    /** 00: alive, not 00: dying/dead */
    public int $dying = 0;
    /** the current phase of the enemy */
    public int $phase = 0;
    public Sprite $sprite;
    /** What kind of enemy */
    public int $type = 0;
    public int $power = 0;
    public int $centerX = 0;
    public int $rangeX = 0;
    public int $delay = 0;
    public int $direction = 0;
    public int $rangeY = 0;
    public int $initsprite = 0;
    public int $initX = 0;
    public int $initY = 0;
    public bool $init_enabled = false;
    public int $initspeedX = 0;
    public int $initspeedY = 0;
    public int $carry_sprite = -1;
    public int $dead_sprite = -1;
    public bool $boss = false;
    public bool $trigger = false;
    public bool $visible = false;
    public int $counter = 0;
    public int $walkspeedX = 0;

    public function __construct()
    {
        $this->sprite = new Sprite();
    }
}
