<?php

namespace App\Titus;

/**
 * sprites.c: sprite animation (cage, carpet, springs, idle player) and sprite updates.
 * (The C sprite surface cache is replaced by the 'sprites' / 'sprites-flash' atlases of Screen.)
 */
trait SpritesTrait
{
    public function SPRITES_ANIMATION(Level $level): void
    {
        // Animate player
        if ($this->LAST_ORDER === 0 && $this->POCKET_FLAG && $this->ACTION_TIMER >= 35 * 4) {
            $this->updatesprite($level, $level->player->sprite, 29, false); // "Pause"-sprite
            if ($this->ACTION_TIMER >= 35 * 5) {
                $this->updatesprite($level, $level->player->sprite, 0, false); // Normal player sprite
                $this->ACTION_TIMER = 0;
            }
        }
        // Animate other objects
        $this->animate_sprite($level, $level->player->sprite2);
        $this->animate_sprite($level, $level->player->sprite3);
        for ($i = 0; $i < $level->objectcount; $i++) {
            $this->animate_sprite($level, $level->object[$i]->sprite);
        }
        for ($i = 0; $i < $level->enemycount; $i++) {
            $this->animate_sprite($level, $level->enemy[$i]->sprite);
        }
        for ($i = 0; $i < $level->elevatorcount; $i++) {
            $this->animate_sprite($level, $level->elevator[$i]->sprite);
        }
    }

    public function animate_sprite(Level $level, Sprite $spr): void
    {
        if (!$spr->visible || !$spr->enabled) {
            return;
        }
        if ($spr->number === self::FIRST_OBJET + 26) { // Cage
            if (($this->IMAGE_COUNTER & 0x0007) === 0) {
                $this->updatesprite($level, $spr, self::FIRST_OBJET + 27, false);
            }
        } elseif ($spr->number === self::FIRST_OBJET + 27) { // Cage, 2nd sprite
            if (($this->IMAGE_COUNTER & 0x003F) === 0) {
                $this->updatesprite($level, $spr, self::FIRST_OBJET + 26, false);
            }
        } elseif ($spr->number === self::FIRST_OBJET + 21) { // Flying carpet
            if (($this->IMAGE_COUNTER & 0x0007) === 0) {
                $this->updatesprite($level, $spr, self::FIRST_OBJET + 22, false);
            }
        } elseif ($spr->number === self::FIRST_OBJET + 22) { // Flying carpet, 2nd sprite
            if (($this->IMAGE_COUNTER & 0x0007) === 0) {
                $this->updatesprite($level, $spr, self::FIRST_OBJET + 21, false);
            }
        } elseif ($spr->number === self::FIRST_OBJET + 24) { // Small spring
            if (($this->IMAGE_COUNTER & 0x0001) === 0) {
                if ($spr->UNDER === 0) { // Spring is not loaded; convert into big spring
                    $this->updatesprite($level, $spr, self::FIRST_OBJET + 25, false);
                } elseif ($this->GRAVITY_FLAG > 1) { // if not gravity, not clear
                    $spr->UNDER = 0;
                } else {
                    $spr->UNDER &= 0x01; // Keep eventually object load, remove player load
                }
            }
        } elseif ($spr->number === self::FIRST_OBJET + 25) { // Big spring
            if (($this->IMAGE_COUNTER & 0x0001) === 0) {
                if ($spr->UNDER === 0) {
                    return; // Spring is not loaded; remain big
                } elseif ($this->GRAVITY_FLAG > 1) {
                    $spr->UNDER = 0;
                } else {
                    $spr->UNDER &= 0x01;
                }
                if ($spr->ONTOP !== null) {
                    $spr->ONTOP->y += 5;
                }
                $this->GRAVITY_FLAG = 3;
                $this->updatesprite($level, $spr, self::FIRST_OBJET + 24, false); // Small spring
            }
        }
    }

    public function updatesprite(Level $level, Sprite $spr, int $number, bool $clearflags): int
    {
        $spr->number = $number;
        $spr->spritedata = $level->spritedata[$number] ?? $level->spritedata[0];
        $spr->enabled = true;
        if ($clearflags) {
            $spr->flipped = false;
            $spr->flash = false;
            $spr->visible = false;
            $spr->droptobottom = false;
            $spr->killing = false;
        }
        $spr->invisible = false;
        return 0;
    }

    public function copysprite(Level $level, Sprite $dest, Sprite $src): int
    {
        $dest->number = $src->number;
        $dest->spritedata = $level->spritedata[$src->number] ?? $level->spritedata[0];
        $dest->enabled = $src->enabled;
        $dest->flipped = $src->flipped;
        $dest->flash = $src->flash;
        $dest->visible = $src->visible;
        $dest->invisible = false;
        return 0;
    }
}
