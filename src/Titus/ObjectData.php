<?php

namespace App\Titus;

/** TITUS_objectdata */
final class ObjectData
{
    public int $maxspeedY = 0;
    /** not support/support */
    public bool $support = false;
    /** not bounce/bounce against floor + player bounces (ball, all spring, yellow stone, squeezed ball, skateboard) */
    public bool $bounce = false;
    /** no gravity on throw/gravity */
    public bool $gravity = false;
    /** on drop, lands on ground/continue below ground */
    public bool $droptobottom = false;
    /** weapon/not weapon (cage) */
    public bool $no_damage = false;
}
