<?php

namespace App\Titus;

/** TITUS_object: things the player can carry / throw / stand on. */
final class TitusObject
{
    public Sprite $sprite;
    public int $initsprite = 0;
    public int $initX = 0;
    public int $initY = 0;
    public bool $init_enabled = false;
    /** momentum, must be >= 10 to cause a falling object to hit an enemy or the player */
    public int $mass = 0;
    public ?ObjectData $objectdata = null;

    public function __construct()
    {
        $this->sprite = new Sprite();
    }
}
