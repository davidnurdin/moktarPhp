<?php

namespace App\Titus;

/**
 * elevators.c: moving platforms.
 */
trait ElevatorsTrait
{
    /** Move elevators, is called by main game loop */
    public function MOVE_TRP(Level $level): void
    {
        for ($i = 0; $i < $level->elevatorcount; $i++) {
            $elevator = $level->elevator[$i];
            if (!$elevator->enabled) {
                continue;
            }
            $elevator->sprite->x += $elevator->sprite->speedX;
            $elevator->sprite->y += $elevator->sprite->speedY;
            $this->MTSBR($elevator);
            if (($elevator->sprite->x + 16 - ($this->BITMAP_X << 4)) >= 0 // +16: closer to center
                && ($elevator->sprite->x - 16 - ($this->BITMAP_X << 4)) <= self::screen_width * 16 // -16: closer to center
                && ($elevator->sprite->y - ($this->BITMAP_Y << 4)) >= 0
                && ($elevator->sprite->y - ($this->BITMAP_Y << 4)) - 16 <= self::screen_height * 16) {
                $elevator->sprite->invisible = false;
            } else {
                $elevator->sprite->invisible = true; // Not necessary, but to mimic the original game
            }
        }
    }

    private function MTSBR(Elevator $elevator): void
    {
        $elevator->counter++;
        if ($elevator->counter >= $elevator->range) {
            $elevator->counter = 0;
            $elevator->sprite->speedX = -$elevator->sprite->speedX;
            $elevator->sprite->speedY = -$elevator->sprite->speedY;
        }
    }
}
