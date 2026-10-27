<?php

namespace App\Titus;

/**
 * scroll.c (screen scrolling by whole tiles), tile_animation.c and the tile flag getters of level.c.
 */
trait ScrollTrait
{
    /** Scroll screen and update tile animation */
    public function scroll(Level $level): void
    {
        $this->loop_cycle++; // Cycle from 0 to 3
        if ($this->loop_cycle > 3) {
            $this->loop_cycle = 0;
        }
        if ($this->loop_cycle === 0) { // Every 4th call
            $this->tile_anim++; // Cycle tile animation (0-1-2)
            if ($this->tile_anim > 2) {
                $this->tile_anim = 0;
            }
        }
        if (!$this->NOSCROLL_FLAG) {
            $this->X_ADJUST($level);
            $this->Y_ADJUST($level);
        }
    }

    public function X_ADJUST(Level $level): void
    {
        $player = $level->player;
        $pstileX = ($player->sprite->x >> 4) - $this->BITMAP_X; // Player screen tile X (0 to 19)
        if (!$this->XSCROLL_CENTER) { // if not scrolling, check if it should scroll
            // If scroll timer isn't activated, scroll if the player is close to the screen edge
            if ($this->ACTION_TIMER < self::LIMIT_TIMER) {
                if ($pstileX > self::screen_width - intdiv(self::ALERT_X, 16) || $pstileX < intdiv(self::ALERT_X, 16)) {
                    $this->XLIMIT_SCROLL = intdiv(self::screen_width, 2); // Scroll until player is in center
                    $this->XSCROLL_CENTER = true;
                }
            } elseif (($this->LAST_ORDER & 0x0F) === 1) { // Walking, scroll timer is activated
                if (abs($player->sprite->speedX) !== self::MAX_X * 16) { // not at max speed (walking against a wall?)
                    if (!$player->sprite->flipped) {
                        $this->XLIMIT_SCROLL = 2; // Scroll until player is in 2nd screen tile
                    } else {
                        $this->XLIMIT_SCROLL = self::screen_width - 2; // Scroll until player is in 18th screen tile
                    }
                    $this->XSCROLL_CENTER = true;
                } else { // Walking at full speed
                    if ($player->sprite->speedX < 0) { // Walking left
                        if ($pstileX <= intdiv(self::screen_width, 2) - 3) { // Center tile - 3
                            $this->XLIMIT_SCROLL = self::screen_width - 2;
                            $this->XSCROLL_CENTER = true;
                        }
                    } elseif ($pstileX >= intdiv(self::screen_width, 2) + 3) { // Center tile + 3
                        $this->XLIMIT_SCROLL = 2;
                        $this->XSCROLL_CENTER = true;
                    }
                }
            } else { // Not walking
                if ($player->sprite->speedX === 0 && $player->sprite->speedY === 0
                    && $this->ACTION_TIMER === intdiv(self::LIMIT_TIMER * 3, 2)) {
                    $this->XLIMIT_SCROLL = $this->BARRYCENTRE($level); // Center if visible enemy behind, if not, scroll until 3rd tile
                    $this->XSCROLL_CENTER = true;
                } elseif ($pstileX > self::screen_width - intdiv(self::ALERT_X, 16) || $pstileX < intdiv(self::ALERT_X, 16)) {
                    $this->XLIMIT_SCROLL = intdiv(self::screen_width, 2); // Place the player in the center
                    $this->XSCROLL_CENTER = true;
                }
            }
        }
        if ($this->XSCROLL_CENTER) {
            if ($this->XLIMIT_SCROLL === $pstileX) {
                $this->XSCROLL_CENTER = false; // Stop scrolling
            } elseif ($this->XLIMIT_SCROLL > $pstileX) {
                if ($this->L_SCROLL($level)) {
                    $this->XSCROLL_CENTER = false;
                }
            } elseif ($this->R_SCROLL($level)) {
                $this->XSCROLL_CENTER = false;
            }
        }
    }

    public function Y_ADJUST(Level $level): void
    {
        $player = $level->player;
        if ($player->sprite->speedY === 0) {
            $this->YSCROLL_CENTER = false;
        }
        $pstileY = ($player->sprite->y >> 4) - $this->BITMAP_Y; // Player screen tile Y (0 to 11)
        if (!$this->YSCROLL_CENTER) {
            if ($player->sprite->speedY === 0 && !$this->LADDER_FLAG) {
                if ($pstileY >= self::screen_height - 1) {
                    $this->YLIMIT_SCROLL = self::screen_height - 2;
                    $this->YSCROLL_CENTER = true;
                } elseif ($pstileY <= 2) {
                    $this->YLIMIT_SCROLL = self::screen_height - 3;
                    $this->YSCROLL_CENTER = true;
                }
            } else {
                if ($pstileY >= self::screen_height - 2) { // The player is at the bottom of the screen, scroll down!
                    $this->YLIMIT_SCROLL = 3;
                    $this->YSCROLL_CENTER = true;
                } elseif ($pstileY <= 2) { // The player is at the top of the screen, scroll up!
                    $this->YLIMIT_SCROLL = self::screen_height - 3;
                    $this->YSCROLL_CENTER = true;
                }
            }
        }
        if ($player->sprite->y <= (($this->ALTITUDE_ZERO + self::screen_height) << 4) // player above the horizontal limit
            && $this->BITMAP_Y > $this->ALTITUDE_ZERO + 1) { // ... and the screen have scrolled below the horizontal limit
            if ($this->U_SCROLL($level)) {
                $this->YSCROLL_CENTER = false;
            }
        } elseif ($this->BITMAP_Y > $this->ALTITUDE_ZERO - 5 // the screen is less than 5 tiles above the horizontal limit
            && $this->BITMAP_Y <= $this->ALTITUDE_ZERO // ... and still above the horizontal limit
            && $player->sprite->y + (7 * 16) > (($this->ALTITUDE_ZERO + self::screen_height) << 4)) {
            if ($this->D_SCROLL($level)) {
                $this->YSCROLL_CENTER = false;
            }
        } elseif ($this->YSCROLL_CENTER) {
            if ($this->YLIMIT_SCROLL === $pstileY) {
                $this->YSCROLL_CENTER = false;
            } elseif ($this->YLIMIT_SCROLL > $pstileY) {
                if ($this->U_SCROLL($level)) {
                    $this->YSCROLL_CENTER = false;
                }
            } elseif ($player->sprite->y <= (($this->ALTITUDE_ZERO + self::screen_height) << 4)
                && $this->BITMAP_Y > $this->ALTITUDE_ZERO) {
                $this->YSCROLL_CENTER = false; // Stop scrolling
            } elseif ($this->D_SCROLL($level)) {
                $this->YSCROLL_CENTER = false;
            }
        }
    }

    /**
     * If an enemy is behind the player, max. 12.5 tiles away horizontally, scroll until player is in the
     * middle. If not, scroll until the player is in the 3rd screen tile.
     */
    private function BARRYCENTRE(Level $level): int
    {
        for ($i = 0; $i < $level->enemycount; $i++) {
            $enemy = $level->enemy[$i];
            if (!$enemy->sprite->enabled || !$enemy->visible) {
                continue;
            }
            $enemyLeft = $enemy->sprite->x < $level->player->sprite->x; // True if enemy is left for the player
            if ($enemyLeft !== $level->player->sprite->flipped // Enemy is behind the player
                && abs($enemy->sprite->x - $level->player->sprite->x) < 200) {
                return intdiv(self::screen_width, 2);
            }
        }
        return $level->player->sprite->flipped ? self::screen_width - 3 : 3;
    }

    /** Scroll left */
    public function L_SCROLL(Level $level): bool
    {
        if ($this->BITMAP_X === 0) {
            return true; // Stop scrolling
        }
        $this->BITMAP_X--;
        if ($this->BITMAP_XM <= 0) {
            $this->BITMAP_XM = self::screen_width;
        }
        $this->BITMAP_XM--;
        $this->REFRESH_COLUMNS($level, 0);
        return false;
    }

    /** Scroll right */
    public function R_SCROLL(Level $level): bool
    {
        if ((($level->player->sprite->x >> 4) - self::screen_width) > $this->XLIMIT) { // Scroll limit
            $maxX = $level->width - self::screen_width; // 256 - 20
        } else {
            $maxX = $this->XLIMIT & 0xFF; // uint8
        }
        if ($this->BITMAP_X >= $maxX) {
            return true; // Stop scrolling
        }
        $this->BITMAP_X++;
        $this->BITMAP_XM++;
        if ($this->BITMAP_XM >= self::screen_width) {
            $this->BITMAP_XM = 0;
        }
        $this->REFRESH_COLUMNS($level, self::screen_width - 1);
        return false;
    }

    /** Scroll up */
    public function U_SCROLL(Level $level): bool
    {
        if ($this->BITMAP_Y === 0) {
            return true;
        }
        $this->BITMAP_Y--;
        if ($this->BITMAP_YM <= 0) {
            $this->BITMAP_YM = self::screen_height;
        }
        $this->BITMAP_YM--;
        $this->REFRESH_LINE($level, 0);
        return false;
    }

    /** Scroll down */
    public function D_SCROLL(Level $level): bool
    {
        if ($this->BITMAP_Y >= $level->height - self::screen_height) { // The screen is already at the bottom
            return true;
        }
        $this->BITMAP_Y++;
        $this->BITMAP_YM++;
        if ($this->BITMAP_YM >= self::screen_height) {
            $this->BITMAP_YM = 0;
        }
        $this->REFRESH_LINE($level, self::screen_height - 1);
        return false;
    }

    /** The screen is scrolled left or right: redraw one column on the hidden tile screen */
    private function REFRESH_COLUMNS(Level $level, int $column): void
    {
        $tmpX = ($this->BITMAP_X + $column) & 0xFF;
        $tmpY = $this->BITMAP_Y;
        $tmpYM = $this->BITMAP_YM;
        $tmpXM = $this->BITMAP_XM + $column;
        if ($tmpXM >= self::screen_width) {
            $tmpXM -= self::screen_width;
        }
        for ($i = 0; $i < self::screen_height; $i++) {
            $cur = $level->tilemap[$tmpY][$tmpX] ?? 0;
            $this->PERMUT_FLAG = $this->PERMUT_FLAG || $level->tile[$cur]->animated;
            $this->DISPLAY_CHAR($level, $cur, $tmpYM, $tmpXM);
            $tmpY++;
            $tmpYM++;
            if ($tmpYM >= self::screen_height) {
                $tmpYM -= self::screen_height;
            }
        }
    }

    /** The screen is scrolled up or down: redraw one line on the hidden tile screen */
    private function REFRESH_LINE(Level $level, int $line): void
    {
        $tmpX = $this->BITMAP_X;
        $tmpY = $this->BITMAP_Y + $line;
        $tmpYM = $this->BITMAP_YM + $line;
        $tmpXM = $this->BITMAP_XM;
        if ($tmpYM >= self::screen_height) {
            $tmpYM -= self::screen_height;
        }
        for ($i = 0; $i < self::screen_width; $i++) {
            $cur = $level->tilemap[$tmpY][$tmpX & 0xFF] ?? 0;
            $this->PERMUT_FLAG = $this->PERMUT_FLAG || $level->tile[$cur]->animated;
            $this->DISPLAY_CHAR($level, $cur, $tmpYM, $tmpXM);
            $tmpX++;
            $tmpXM++;
            if ($tmpXM >= self::screen_width) {
                $tmpXM -= self::screen_width;
            }
        }
    }

    /** Update the tile surface */
    public function DISPLAY_CHAR(Level $level, int $tile, int $y, int $x): void
    {
        $this->tilescreen[$y * 20 + $x] = $level->tile[$level->tile[$tile & 0xFF]->animation[$this->tile_anim]]->current;
    }

    /** tile_animation.c: redraw the animated tiles of the screen (every 4th loop) */
    public function BLOC_ANIMATION(Level $level): void
    {
        if ($this->PERMUT_FLAG && $this->loop_cycle === 0) {
            $this->PERMUT_FLAG = false;
            $tmpYM = $this->BITMAP_YM;
            $curY = $this->BITMAP_Y;
            for ($i = 0; $i < self::screen_height; $i++) {
                $tmpXM = $this->BITMAP_XM;
                $curX = $this->BITMAP_X;
                for ($j = 0; $j < self::screen_width; $j++) {
                    $tile = $level->tilemap[$curY][$curX & 0xFF] ?? 0;
                    if ($level->tile[$tile]->animated) {
                        $this->PERMUT_FLAG = true;
                        $this->DISPLAY_CHAR($level, $tile, $tmpYM, $tmpXM);
                    }
                    $tmpXM++;
                    if ($tmpXM >= self::screen_width) {
                        $tmpXM = 0;
                    }
                    $curX++;
                }
                $tmpYM++;
                if ($tmpYM >= self::screen_height) {
                    $tmpYM = 0;
                }
                $curY++;
            }
        }
    }

    // --- level.c ----------------------------------------------------------------------------------------

    public function get_horizflag(Level $level, int $tileY, int $tileX): int
    {
        if ($tileX < 0 || $tileX >= $level->width) {
            return self::HFLAG_WALL;
        }
        if ($tileY < 0 || $tileY >= $level->height) {
            return self::HFLAG_NOWALL;
        }
        return $level->tile[$level->tilemap[$tileY][$tileX]]->horizflag;
    }

    public function get_floorflag(Level $level, int $tileY, int $tileX): int
    {
        if ($tileX < 0 || $tileX >= $level->width) {
            return self::FFLAG_FLOOR;
        }
        if ($tileY < 0 || $tileY >= $level->height) {
            return self::FFLAG_NOFLOOR;
        }
        return $level->tile[$level->tilemap[$tileY][$tileX]]->floorflag;
    }

    public function get_ceilflag(Level $level, int $tileY, int $tileX): int
    {
        if ($tileY < 0 || $tileY >= $level->height || $tileX < 0 || $tileX >= $level->width) {
            return self::CFLAG_NOCEILING;
        }
        return $level->tile[$level->tilemap[$tileY][$tileX]]->ceilflag;
    }
}
