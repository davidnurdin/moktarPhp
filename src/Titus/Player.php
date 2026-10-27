<?php

namespace App\Titus;

/** TITUS_player */
final class Player
{
    public Sprite $sprite;
    public Sprite $sprite2;
    public Sprite $sprite3;
    public int $animcycle = 0;
    public int $cageX = 0;
    public int $cageY = 0;
    public int $hp = 0;
    public int $initX = 0;
    public int $initY = 0;
    public int $inithp = 16;
    /** Friction (0-3). 0: full friction, 3: max sliding */
    public int $GLISSE = 0;

    public function __construct()
    {
        $this->sprite = new Sprite();
        $this->sprite2 = new Sprite();
        $this->sprite3 = new Sprite();
    }
}
