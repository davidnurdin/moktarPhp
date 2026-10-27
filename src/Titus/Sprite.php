<?php

namespace App\Titus;

/** TITUS_sprite. The C pointer "animation" is $animTable (the table) + $anim (the index in it). */
final class Sprite
{
    public int $x = 0;
    public int $y = 0;
    public int $speedX = 0;
    public int $speedY = 0;
    public int $number = 0;
    /** On screen or not (set by display_sprite) */
    public bool $visible = false;
    public bool $flash = false;
    public bool $flipped = false;
    public bool $enabled = false;
    public ?SpriteData $spritedata = null;
    /** 0: big spring, 1: small spring because of another object on top, 2: small spring because player on top */
    public int $UNDER = 0;
    /** Object on top of the spring */
    public ?Sprite $ONTOP = null;
    /** @var int[]|null */
    public ?array $animTable = null;
    public int $anim = 0;
    public bool $droptobottom = false;
    public bool $killing = false;
    /** Set by "hidden" enemies */
    public bool $invisible = false;

    /** *(animation + $offset) */
    public function animValue(int $offset = 0): int
    {
        return $this->animTable[$this->anim + $offset] ?? 0;
    }

    /** @param int[]|null $table */
    public function setAnimation(?array $table, int $index = 0): void
    {
        $this->animTable = $table;
        $this->anim = $index;
    }
}
