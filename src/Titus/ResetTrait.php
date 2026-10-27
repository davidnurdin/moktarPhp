<?php

namespace App\Titus;

/**
 * reset.c: end-of-loop checks (new level, game over, death), the final sequence of the last level, and the
 * reset of all the level data before a (re)start.
 */
trait ResetTrait
{
    /**
     * Possible return states: 0 - No action, 1 - New level, 2 - Game over, 3 - Death
     * (negative: quit)
     */
    public function RESET_LEVEL(Level $level): \Generator
    {
        $player = $level->player;
        if ($this->NEWLEVEL_FLAG) {
            return 1;
        }
        if ($this->GAMEOVER_FLAG) {
            return 2;
        }
        if ($this->RESETLEVEL_FLAG === 1) {
            return 3;
        }
        if ($level->levelid === 16) {
            $this->NOSCROLL_FLAG = true;
            // Prepare Moktar
            $this->updatesprite($level, $player->sprite, 343, true);
            $player->sprite->x = -100;
            $player->sprite->y = 180;
            $player->sprite->setAnimation(OriginalData::ANIM_MOKTAR);
            // Prepare Zoubida
            $this->updatesprite($level, $player->sprite2, 337, true);
            $player->sprite2->x = 420;
            $player->sprite2->y = 180;
            $player->sprite2->setAnimation(OriginalData::ANIM_ZOUBIDA);
            do {
                $this->scroll($level);
                $this->BLOC_ANIMATION($level);
                $player->sprite->x += 3;
                $this->MOVE_HIM($level, $player->sprite);
                $player->sprite2->x -= 3;
                $this->MOVE_HIM($level, $player->sprite2);
                $this->TFR_SCREENM();
                $this->DISPLAY_SPRITES($level);
                yield from $this->flip_screen(true);
            } while ($player->sprite2->x > $player->sprite->x + 28);
            // Lovers in one sprite
            $this->updatesprite($level, $player->sprite2, 346, true);
            $player->sprite2->flipped = true;
            $player->sprite2->x -= 24;
            // Smoke
            $player->sprite->setAnimation(OriginalData::ANIM_SMOKE);
            $player->sprite->y -= 16;
            for ($i = 0; $i < 16; $i++) {
                $this->MOVE_HIM($level, $player->sprite);
                $this->scroll($level);
                $this->BLOC_ANIMATION($level);
                $this->TFR_SCREENM();
                $this->DISPLAY_SPRITES($level);
                yield from $this->flip_screen(true);
                $player->sprite->y++;
            }
            // Display hearts
            $this->updatesprite($level, $player->sprite, 355, true);
            $heart = 0; // index in COEUR_POS
            $pass = false;
            do {
                if (OriginalData::COEUR_POS[$heart] < 0) {
                    $heart += OriginalData::COEUR_POS[$heart]; // jump back
                }
                $player->sprite->x = OriginalData::COEUR_POS[$heart++];
                $player->sprite->y = OriginalData::COEUR_POS[$heart++];
                // the C table has no jump back in its 40 entries (it reads past its end): loop on the
                // 6 real heart positions
                if ($heart >= 12) {
                    $heart = 0;
                }
                $this->scroll($level);
                $this->BLOC_ANIMATION($level);
                $this->TFR_SCREENM();
                $this->DISPLAY_SPRITES($level);
                yield from $this->flip_screen(true);
                foreach ($this->pollEvents() as [$type, $key]) {
                    if ($type === 'down') {
                        if ($key === self::KEY_ESC) {
                            return self::TITUS_ERROR_QUIT;
                        }
                        $this->musicKey($key);
                    } elseif ($key === self::KEY_RETURN || $key === self::KEY_SPACE) {
                        $pass = true;
                        break;
                    }
                }
            } while (!$pass);
            // Display THE END
            $this->updatesprite($level, $player->sprite, 335, true); // THE
            $player->sprite->x = ($this->BITMAP_X << 4) - (120 - 2);
            $player->sprite->y = ($this->BITMAP_Y << 4) + 100;
            $this->updatesprite($level, $player->sprite3, 336, true); // END
            $player->sprite3->x = ($this->BITMAP_X << 4) + (320 + 120 - 2);
            $player->sprite3->y = ($this->BITMAP_Y << 4) + 100;
            for ($i = 0; $i < 31; $i++) {
                $this->TFR_SCREENM();
                $this->DISPLAY_SPRITES($level);
                yield from $this->flip_screen(true);
                $player->sprite->x += 8;
                $player->sprite3->x -= 8;
            }
            $retval = yield from $this->waitforbutton();
            if ($retval < 0) {
                return $retval;
            }
            $this->NEWLEVEL_FLAG = true;
            return 3;
        }
        return 0;
    }

    public function MOVE_HIM(Level $level, Sprite $spr): void
    {
        $pointer = $spr->anim + 1;
        while (($spr->animTable[$pointer] ?? -2) < 0) {
            $pointer += intdiv($spr->animTable[$pointer] ?? -2, 2); // End of animation, jump back
            if ($pointer < 0) {
                $pointer = 0;
            }
        }
        $table = $spr->animTable;
        $this->updatesprite($level, $spr, $table[$pointer], true);
        $spr->setAnimation($table, $pointer);
    }

    public function CLEAR_DATA(Level $level): void
    {
        $this->loop_cycle = 0;
        $this->tile_anim = 0;
        $this->BIGNMI_NBR = 0;
        $this->IMAGE_COUNTER = 0;
        $this->TAUPE_FLAG = 0;
        $this->GRANDBRULE_FLAG = false;
        $this->NOSCROLL_FLAG = false;
        $this->TAPISWAIT_FLAG = 0;
        $this->TAPISFLY_FLAG = 0;
        $this->FUME_FLAG = 0;
        $this->BAR_FLAG = 0;
        $this->X_FLAG = false;
        $this->Y_FLAG = false;
        $this->CARRY_FLAG = false;
        $this->DROP_FLAG = false;
        $this->DROPREADY_FLAG = false;
        $this->POSEREADY_FLAG = false;
        $this->LADDER_FLAG = false;
        $this->PRIER_FLAG = false;
        $this->SAUT_FLAG = 0;
        $this->CROSS_FLAG = 0;
        $this->FURTIF_FLAG = 0;
        $this->CHOC_FLAG = 0;
        $this->KICK_FLAG = 0;
        $this->SEECHOC_FLAG = 0;
        $this->RESETLEVEL_FLAG = 0;
        $this->GAMEOVER_FLAG = false;
        $this->NEWLEVEL_FLAG = false;
        $this->NFC_FLAG = false;
        $this->INVULNERABLE_FLAG = 0;
        $this->POCKET_FLAG = false;
        $this->SAUT_COUNT = 0;
        $this->ACTION_TIMER = 0;
        $this->XSCROLL_CENTER = false;
        $this->YSCROLL_CENTER = false;
        $this->XLIMIT_SCROLL = 0;
        $this->YLIMIT_SCROLL = 0;
        $this->YFALL = 0;
        $this->GRAVITY_FLAG = 4;
        $this->SENSX = 0;
        $this->LAST_ORDER = 0;
        $this->SET_ALL_SPRITES($level);
        $this->SET_DATA_NMI($level);
    }

    public function SET_DATA_NMI(Level $level): void
    {
        $this->boss_alive = false;
        $count = count(OriginalData::ANIM_ENEMY);
        for ($i = 0; $i < $level->enemycount; $i++) {
            $enemy = $level->enemy[$i];
            if (!$enemy->init_enabled) {
                continue;
            }
            $anim = -1;
            do {
                $anim++;
            } while ($anim < $count - 1 && OriginalData::ANIM_ENEMY[$anim] + self::FIRST_NMI !== $enemy->sprite->number);
            $enemy->sprite->setAnimation(OriginalData::ANIM_ENEMY, $anim);
            if ($enemy->boss) {
                $this->boss_alive = true;
            }
        }
        $this->BIGNMI_POWER = OriginalData::NMI_POWER[$level->levelid];
    }

    public function clearsprite(Sprite $spr): void
    {
        $spr->enabled = false;
        $spr->x = 0;
        $spr->y = 0;
        $spr->speedX = 0;
        $spr->speedY = 0;
        $spr->number = 0;
        $spr->UNDER = 0;
        $spr->ONTOP = null;
        $spr->spritedata = null;
        $spr->flipped = false;
        $spr->flash = false;
        $spr->visible = false;
        $spr->animTable = null;
        $spr->anim = 0;
        $spr->droptobottom = false;
        $spr->killing = false;
        $spr->invisible = false;
    }

    public function SET_ALL_SPRITES(Level $level): void
    {
        $player = $level->player;
        for ($i = 0; $i < $level->trashcount; $i++) {
            $this->clearsprite($level->trash[$i]);
        }
        for ($i = 0; $i < $level->enemycount; $i++) {
            $enemy = $level->enemy[$i];
            $this->clearsprite($enemy->sprite);
            $enemy->dying = 0;
            $enemy->carry_sprite = -1;
            $enemy->dead_sprite = -1;
            $enemy->phase = 0;
            $enemy->counter = 0;
            $enemy->trigger = false;
            $enemy->visible = false;
            if ($enemy->init_enabled) {
                $this->updateenemysprite($level, $enemy, ($enemy->initsprite & 0x1FFF) + 101, true);
                $enemy->sprite->flipped = ($enemy->initsprite & 0x8000) === 0x8000;
                $enemy->sprite->x = $enemy->initX;
                $enemy->sprite->y = $enemy->initY;
                $enemy->sprite->speedX = $enemy->initspeedX;
                $enemy->sprite->speedY = $enemy->initspeedY;
            }
        }
        for ($i = 0; $i < $level->elevatorcount; $i++) {
            $el = $level->elevator[$i];
            $this->clearsprite($el->sprite);
            if ($el->init_enabled) {
                $this->updatesprite($level, $el->sprite, ($el->initsprite & 0x1FFF) + 30, true);
                $el->sprite->visible = ($el->initsprite & 0x2000) === 0x2000;
                $el->sprite->flash = ($el->initsprite & 0x4000) === 0x4000;
                $el->sprite->flipped = ($el->initsprite & 0x8000) === 0x8000;
                $el->sprite->x = $el->initX;
                $el->sprite->y = $el->initY;
                $el->counter = 0;
                $el->sprite->speedX = $el->initspeedX;
                $el->sprite->speedY = $el->initspeedY;
            }
        }
        for ($i = 0; $i < $level->objectcount; $i++) {
            $obj = $level->object[$i];
            $this->clearsprite($obj->sprite);
            $obj->mass = 0;
            if ($obj->init_enabled) {
                $this->updateobjectsprite($level, $obj, $obj->initsprite & 0x1FFF, true);
                $obj->sprite->visible = ($obj->initsprite & 0x2000) === 0x2000;
                $obj->sprite->flash = ($obj->initsprite & 0x4000) === 0x4000;
                $obj->sprite->flipped = ($obj->initsprite & 0x8000) === 0x8000;
                $obj->sprite->x = $obj->initX;
                $obj->sprite->y = $obj->initY;
                if ($player->cageY !== 0
                    && ($obj->sprite->number === self::FIRST_OBJET + 26 || $obj->sprite->number === self::FIRST_OBJET + 27)) {
                    $obj->sprite->x = $player->cageX;
                    $obj->sprite->y = $player->cageY;
                }
            }
        }
        $this->GRAVITY_FLAG = 4;
        $this->clearsprite($player->sprite);
        $this->clearsprite($player->sprite2);
        $this->clearsprite($player->sprite3);
        $player->sprite->x = $player->initX;
        $player->sprite->y = $player->initY;
        $player->hp = $player->inithp;
        $player->animcycle = 0;
        $this->updatesprite($level, $player->sprite, 0, true);
    }
}
