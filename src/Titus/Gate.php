<?php

namespace App\Titus;

/** TITUS_gate */
final class Gate
{
    public bool $exists = false;
    public int $entranceX = 0;
    public int $entranceY = 0;
    public int $exitX = 0;
    public int $exitY = 0;
    public int $screenX = 0;
    public int $screenY = 0;
    public bool $noscroll = false;
}
