<?php

namespace App\Titus;

/**
 * OpenTitus src/enemies.c: handles enemies.
 *
 * Global functions:
 * MOVE_NMI(level): Move enemies, is called by main game loop
 * SET_NMI(level): Collision detection, animation, is called by main game loop
 * MOVE_TRASH(level): Move objects thrown by enemies
 *
 * Note: inside the switches, the C `continue` (next enemy) is `continue 2` (one switch) or
 * `continue 3` (two nested switches) in PHP.
 */
trait EnemiesTrait
{
    /**
     * C unsigned int comparison a < b (both operands converted to unsigned int, 32 bits).
     * Used where the C code compares a signed value with an "unsigned int" field (delay, rangeY).
     */
    private static function uint_lt(int $a, int $b): bool
    {
        if (($a < 0) !== ($b < 0)) {
            return $a >= 0; // a negative value is a huge unsigned value
        }
        return $a < $b;
    }

    public function MOVE_NMI(Level $level): void
    {
        $bullet = null;
        for ($i = 0; $i < $level->enemycount; $i++) {
            $enemy = $level->enemy[$i];
            $player = $level->player;

            if (!($enemy->sprite->enabled)) continue; //Skip unused enemies

            switch ($enemy->type) {
            case 0:
            case 1:
                //Noclip walk
                if ($enemy->dying != 0) { //If not 0, the enemy is dying or dead, and have special movement
                    $this->DEAD1($level, $enemy);
                    continue 2;
                }
                $enemy->sprite->x -= $enemy->sprite->speedX; //Move the enemy
                if (abs($enemy->sprite->x - $enemy->centerX) > $enemy->rangeX) { //If the enemy is rangeX from center, turn direction
                    if ($enemy->sprite->x >= $enemy->centerX) { //The enemy is at rightmost edge
                        $enemy->sprite->speedX = abs($enemy->sprite->speedX);
                    } else { //The enemy is at leftmost edge
                        $enemy->sprite->speedX = 0 - abs($enemy->sprite->speedX);
                    }
                }
                break;

            case 2:
                //Shoot
                if ($enemy->dying != 0) { //If not 0, the enemy is dying or dead, and have special movement
                    $this->DEAD1($level, $enemy);
                    continue 2;
                }
                if (!$enemy->visible) { //Skip if not on screen
                    continue 2;
                }
                //Give directions!
                if ($enemy->direction == 0) { //Both ways
                    $enemy->sprite->speedX = 0;
                    if ($enemy->sprite->x < $player->sprite->x) {
                        $enemy->sprite->speedX = -1;
                    }
                } elseif ($enemy->direction == 2) { //Right only
                    $enemy->sprite->speedX = -1; //Flip the sprite
                } else { //Left only
                    $enemy->sprite->speedX = 0; //Not flipped (facing left)
                }
                switch ($enemy->phase) { //State dependent actions
                case 0:
                    //Scans the horizon!
                    $this->subto0($enemy->counter);
                    if ($enemy->counter != 0) { //Decrease delay timer
                        continue 3;
                    }
                    if (abs($player->sprite->y - $enemy->sprite->y) > 24) {
                        continue 3;
                    }
                    if ($enemy->rangeX < abs($player->sprite->x - $enemy->sprite->x)) { //if too far apart
                        continue 3;
                    }
                    if ($enemy->direction != 0) {
                        if ($enemy->direction == 2) { //Right only
                            if ($enemy->sprite->x > $player->sprite->x) { //Skip shooting if player is in opposite direction
                                continue 3;
                            }
                        } else {
                            if ($player->sprite->x > $enemy->sprite->x) { //Skip shooting if player is in opposite direction
                                continue 3;
                            }
                        }
                    }
                    $enemy->phase = 30; //change state
                    $this->UP_ANIMATION($enemy->sprite);
                    break;
                default:
                    $enemy->phase = self::u8($enemy->phase - 1);
                    if (!$enemy->trigger) {
                        continue 3;
                    }
                    $enemy->sprite->anim += 2;
                    if ($this->FIND_TRASH($level, $bullet)) {
                        $this->PUT_BULLET($level, $enemy, $bullet);
                        //level->enemy[i].counter = NMI_FREQ; //set delay timer
                        $enemy->counter = self::u8($enemy->delay); //set delay timer
                    }
                    $enemy->phase = 0;
                    break;
                }
                break;

            case 3:
            case 4:
                //Noclip walk, jump to player (fish)
                if ($enemy->dying != 0) { //If not 0, the enemy is dying or dead, and have special movement
                    $this->DEAD1($level, $enemy);
                    continue 2;
                }
                switch ($enemy->phase) { //State dependent actions
                case 0:
                    $enemy->sprite->x -= $enemy->sprite->speedX; //Move the enemy
                    if (abs($enemy->sprite->x - $enemy->centerX) > $enemy->rangeX) { //If the enemy is rangeX from center, turn direction
                        if ($enemy->sprite->x >= $enemy->centerX) { //The enemy is at rightmost edge
                            $enemy->sprite->speedX = abs($enemy->sprite->speedX);
                        } else { //The enemy is at leftmost edge
                            $enemy->sprite->speedX = 0 - abs($enemy->sprite->speedX);
                        }
                    }
                    if (!$enemy->visible) { //Is the enemy on the screen?
                        continue 3;
                    }
                    if (($enemy->sprite->y < $player->sprite->y) || ($enemy->sprite->y >= ($player->sprite->y + 256))) { //Skip if player is below or >= 256 pixels above
                        continue 3;
                    }
                    if (self::uint_lt($enemy->rangeY, $enemy->sprite->y - $player->sprite->y)) { //Skip if player is above jump limit
                        continue 3;
                    }
                    //see if the hero is in the direction of movement of fish
                    if ($enemy->sprite->x > $player->sprite->x) { //The enemy is right for the player
                        if ($enemy->sprite->flipped == true) { //The enemy looks right, skip
                            continue 3;
                        }
                    } else { //The enemy is left for the player
                        if ($enemy->sprite->flipped == false) { //The enemy looks left, skip
                            continue 3;
                        }
                    }
                    if (abs($enemy->sprite->x - $player->sprite->x) >= 48) { //Fast calculation
                        continue 3;
                    }
                    //See if the hero is above the area of fish
                    if (abs($player->sprite->x - $enemy->centerX) > $enemy->rangeX) {
                        continue 3;
                    }
                    $enemy->phase = 1; //Change state
                    //Calculation speed to the desired height
                    $enemy->sprite->speedY = 0;
                    $j = 0;
                    do {
                        $enemy->sprite->speedY++; //Set init jump speed
                        $j += $enemy->sprite->speedY;
                    } while (($enemy->sprite->y - $player->sprite->y) > $j); //Make sure the enemy will jump high enough to hit the player
                    $enemy->sprite->speedY = 0 - $enemy->sprite->speedY; //Init speed must be negative
                    $enemy->delay = $enemy->sprite->y; //Delay: Last Y position, reuse of the delay variable
                    $this->UP_ANIMATION($enemy->sprite);
                    break;
                case 1:
                    if (!$enemy->visible) { //Is the enemy on the screen?
                        continue 3;
                    }
                    $enemy->sprite->x -= $enemy->sprite->speedX << 2;
                    $enemy->sprite->y += $enemy->sprite->speedY;
                    if ($enemy->sprite->speedY + 1 < 0) {
                        $enemy->sprite->speedY++;
                        if (self::uint_lt($enemy->delay - $enemy->rangeY, $enemy->sprite->y)) { //Delay: Last Y position, reuse of the delay variable
                            continue 3;
                        }
                    }
                    $this->UP_ANIMATION($enemy->sprite);
                    $enemy->phase = 2;
                    $enemy->sprite->speedY = 0;
                    if ($enemy->sprite->x <= $enemy->centerX) {
                        $enemy->sprite->speedX = abs($enemy->sprite->speedX);
                    } else {
                        $enemy->sprite->speedX = 0 - abs($enemy->sprite->speedX);
                    }
                    break;
                case 2:
                    if (!$enemy->visible) { //Is the enemy on the screen?
                        continue 3;
                    }
                    $enemy->sprite->x -= $enemy->sprite->speedX;
                    $enemy->sprite->y += $enemy->sprite->speedY; //2: fall!
                    $enemy->sprite->speedY++;
                    if (self::uint_lt($enemy->sprite->y, $enemy->delay)) { //3: we hit bottom? //Delay: Last Y position, reuse of the delay variable
                        continue 3;
                    }
                    $enemy->sprite->y = self::s16($enemy->delay); //Delay: Last Y position, reuse of the delay variable
                    $enemy->sprite->x -= $enemy->sprite->speedX;
                    $enemy->phase = 0;
                    $this->DOWN_ANIMATION($enemy->sprite);
                    $this->DOWN_ANIMATION($enemy->sprite);
                    break;
                }
                break;

            case 5:
            case 6:
                //Noclip walk, move to player (fly)
                if ($enemy->dying != 0) { //If not 0, the enemy is dying or dead, and have special movement
                    $this->DEAD1($level, $enemy);
                    continue 2;
                }
                $enemy->sprite->x -= $enemy->sprite->speedX; //Move the enemy
                if (abs($enemy->sprite->x - $enemy->centerX) > $enemy->rangeX) { //If the enemy is rangeX from center, turn direction
                    if ($enemy->sprite->x >= $enemy->centerX) { //The enemy is at rightmost edge
                        $enemy->sprite->speedX = abs($enemy->sprite->speedX);
                    } else { //The enemy is at leftmost edge
                        $enemy->sprite->speedX = 0 - abs($enemy->sprite->speedX);
                    }
                }
                if (!$enemy->visible) { //Is the enemy on the screen?
                    continue 2;
                }
                switch ($enemy->phase) { //State dependent actions
                case 0:
                    //Forward
                    if (abs($enemy->sprite->y - $player->sprite->y) > $enemy->rangeY) { //Too far away
                        continue 3;
                    }
                    if (abs($enemy->sprite->x - $player->sprite->x) > 40) { //Too far away
                        continue 3;
                    }
                    $enemy->delay = $enemy->sprite->y; //Delay: Last Y position, reuse of the delay variable
                    if ($enemy->sprite->y < $player->sprite->y) { //Player is below the enemy
                        $enemy->sprite->speedY = 2;
                    } else { //Player is above the enemy
                        $enemy->sprite->speedY = -2;
                    }
                    $enemy->phase = 1; //Change state
                    $this->UP_ANIMATION($enemy->sprite);
                    break;
                case 1:
                    //Attack
                    $enemy->sprite->y += $enemy->sprite->speedY;
                    if (abs($enemy->sprite->y - $enemy->delay) < $enemy->rangeY) { //Delay: Last Y position, reuse of the delay variable
                        continue 3;
                    }
                    $enemy->sprite->speedY = 0 - $enemy->sprite->speedY;
                    $this->UP_ANIMATION($enemy->sprite);
                    $enemy->phase = 2;
                    break;
                case 2:
                    //Back up!
                    $enemy->sprite->y += $enemy->sprite->speedY;
                    if ($enemy->sprite->y != $enemy->delay) { //Delay: Last Y position, reuse of the delay variable
                        continue 3;
                    }
                    $this->DOWN_ANIMATION($enemy->sprite);
                    $this->DOWN_ANIMATION($enemy->sprite);
                    $enemy->phase = 0;
                    break;
                }
                break;

            case 7:
                //Gravity walk, hit when near
                if ($enemy->dying != 0) { //If not 0, the enemy is dying or dead, and have special movement
                    $this->DEAD1($level, $enemy);
                    continue 2;
                }
                switch ($enemy->phase) { //State dependent actions
                case 0:
                    //Waiting
                    if ($enemy->sprite->y > $player->sprite->y) {
                        continue 3;
                    }
                    if ($enemy->rangeX < abs($enemy->sprite->x - $player->sprite->x)) {
                        continue 3;
                    }
                    if (abs($enemy->sprite->y - $player->sprite->y) > 200) {
                        continue 3;
                    }
                    $enemy->phase = 1;
                    $this->UP_ANIMATION($enemy->sprite);
                    if ($enemy->sprite->x > $player->sprite->x) { //Enemy is right for the player
                        $enemy->sprite->speedX = $enemy->walkspeedX; //Move left
                    } else { //Enemy is left for the player
                        $enemy->sprite->speedX = 0 - $enemy->walkspeedX; //Move right
                    }
                    break;
                case 1:
                    //Gravity walk
                    if ($this->get_floorflag($level, ($enemy->sprite->y >> 4), ($enemy->sprite->x >> 4)) == self::FFLAG_NOFLOOR) {
                        if ($enemy->sprite->speedY < 16) { //16 = Max yspeed
                            $enemy->sprite->speedY++;
                        }
                        $enemy->sprite->y += $enemy->sprite->speedY;
                        continue 3;
                    }
                    if ($enemy->sprite->speedY != 0) {
                        if ($enemy->sprite->x > $player->sprite->x) { //Enemy is right for the player
                            $enemy->sprite->speedX = $enemy->walkspeedX; //Move left
                        } else { //Enemy is left for the player
                            $enemy->sprite->speedX = 0 - $enemy->walkspeedX; //Move right
                        }
                    }
                    $enemy->sprite->speedY = 0;
                    $enemy->sprite->y = self::s16($enemy->sprite->y & 0xFFF0);
                    if ($enemy->sprite->speedX > 0) {
                        $j = -1; //moving left
                    } else {
                        $j = 1; //moving right
                    }
                    $hflag = self::u8($this->get_horizflag($level, ($enemy->sprite->y >> 4) - 1, ($enemy->sprite->x >> 4) + $j));
                    if (($hflag == self::HFLAG_WALL) ||
                      ($hflag == self::HFLAG_DEADLY) ||
                      ($hflag == self::HFLAG_PADLOCK)) { //Next tile is wall, change direction
                        $enemy->sprite->speedX = 0 - $enemy->sprite->speedX;
                    }
                    $enemy->sprite->x -= $enemy->sprite->speedX;
                    if ($enemy->sprite->x < 0) {
                        $enemy->sprite->speedX = 0 - $enemy->sprite->speedX;
                        $enemy->sprite->x -= $enemy->sprite->speedX;
                    }
                    if (abs($player->sprite->x - $enemy->sprite->x) > 320 * 2) { //Too far away from the player in X, reset
                        $enemy->phase = 2;
                        continue 3;
                    }
                    if (abs($player->sprite->y - $enemy->sprite->y) >= 200 * 2) { //Too far away from the player in Y, reset
                        $enemy->phase = 2;
                        continue 3;
                    }
                    if (abs($player->sprite->x - $enemy->sprite->x) > $enemy->sprite->spritedata->w + 6) {
                        continue 3;
                    }
                    if (abs($player->sprite->y - $enemy->sprite->y) > 8) {
                        continue 3;
                    }
                    $enemy->phase = 3; //The player is close to the enemy, strike!
                    $this->UP_ANIMATION($enemy->sprite);
                    break;
                case 2:
                    //Reset the enemy
                    if ((($enemy->initY >> 4) - $this->BITMAP_Y <= 13) && //13 tiles in Y
                      (($enemy->initY >> 4) - $this->BITMAP_Y >= 0) &&
                      (($enemy->initX >> 4) - $this->BITMAP_X < 21) && //21 tiles in X
                      (($enemy->initX >> 4) - $this->BITMAP_X >= 0)) {
                        continue 3; //Player is too close to the enemy's spawning point
                    }
                    $enemy->sprite->y = $enemy->initY;
                    $enemy->sprite->x = $enemy->initX;
                    $enemy->phase = 0;
                    $this->DOWN_ANIMATION($enemy->sprite);
                    break;
                case 3:
                    //Strike!
                    if ($enemy->trigger) { //End of strike animation (TODO: check if this will ever be executed)
                        $enemy->phase = 1;
                        continue 3;
                    }
                    //Gravity walk (equal to the first part of "case 1:")
                    if ($this->get_floorflag($level, ($enemy->sprite->y >> 4), ($enemy->sprite->x >> 4)) == self::FFLAG_NOFLOOR) {
                        if ($enemy->sprite->speedY < 16) { //16 = Max yspeed
                            $enemy->sprite->speedY++;
                        }
                        $enemy->sprite->y += $enemy->sprite->speedY;
                        continue 3;
                    }
                    if ($enemy->sprite->speedY != 0) {
                        if ($enemy->sprite->x > $player->sprite->x) { //Enemy is right for the player
                            $enemy->sprite->speedX = $enemy->walkspeedX; //Move left
                        } else { //Enemy is left for the player
                            $enemy->sprite->speedX = 0 - $enemy->walkspeedX; //Move right
                        }
                    }
                    $enemy->sprite->speedY = 0;
                    $enemy->sprite->y = self::s16($enemy->sprite->y & 0xFFF0);
                    if ($enemy->sprite->speedX > 0) {
                        $j = -1; //moving left
                    } else {
                        $j = 1; //moving right
                    }
                    $hflag = self::u8($this->get_horizflag($level, ($enemy->sprite->y >> 4) - 1, ($enemy->sprite->x >> 4) + $j));
                    if (($hflag == self::HFLAG_WALL) ||
                      ($hflag == self::HFLAG_DEADLY) ||
                      ($hflag == self::HFLAG_PADLOCK)) { //Next tile is wall, change direction
                        $enemy->sprite->speedX = 0 - $enemy->sprite->speedX;
                    }
                    $enemy->sprite->x -= $enemy->sprite->speedX;
                    if ($enemy->sprite->x < 0) {
                        $enemy->sprite->speedX = 0 - $enemy->sprite->speedX;
                        $enemy->sprite->x -= $enemy->sprite->speedX;
                    }
                    if (abs($player->sprite->x - $enemy->sprite->x) > 320 * 2) { //Too far away from the player in X, reset
                        $enemy->phase = 2;
                        continue 3;
                    }
                    if (abs($player->sprite->y - $enemy->sprite->y) >= 200 * 2) { //Too far away from the player in Y, reset
                        $enemy->phase = 2;
                        continue 3;
                    }
                    break;
                }
                break;

            case 8: //Gravity walk when off-screen
            case 14: //Gravity walk when off-screen (immortal)
                if ($enemy->type == 14) {
                    $enemy->dying = 0; //Immortal
                } elseif ($enemy->dying != 0) { //If not 0, the enemy is dying or dead, and have special movement
                    $this->DEAD1($level, $enemy);
                    continue 2;
                }
                switch ($enemy->phase) { //State dependent actions
                case 0:
                    //waiting
                    if ((abs($enemy->sprite->x - $player->sprite->x) > 340) ||
                      (abs($enemy->sprite->y - $player->sprite->y) >= 230)) {
                        $enemy->phase = 1;
                        $this->UP_ANIMATION($enemy->sprite);
                        if ($enemy->sprite->x > $player->sprite->x) { //Enemy is right for the player
                            $enemy->sprite->speedX = $enemy->walkspeedX; //Move left
                        } else { //Enemy is left for the player
                            $enemy->sprite->speedX = 0 - $enemy->walkspeedX; //Move right
                        }
                    }
                    break;
                case 1:
                    if ($this->get_floorflag($level, ($enemy->sprite->y >> 4), ($enemy->sprite->x >> 4)) == self::FFLAG_NOFLOOR) {
                        if ($enemy->sprite->speedY < 16) { //16 = Max yspeed
                            $enemy->sprite->speedY++;
                        }
                        $enemy->sprite->y += $enemy->sprite->speedY;
                        continue 3;
                    }
                    if ($enemy->sprite->speedY != 0) {
                        if ($enemy->sprite->x > $player->sprite->x) { //Enemy is right for the player
                            $enemy->sprite->speedX = $enemy->walkspeedX; //Move left
                        } else { //Enemy is left for the player
                            $enemy->sprite->speedX = 0 - $enemy->walkspeedX; //Move right
                        }
                    }
                    $enemy->sprite->speedY = 0;
                    $enemy->sprite->y = self::s16($enemy->sprite->y & 0xFFF0);
                    if ($enemy->sprite->speedX > 0) {
                        $j = -1; //moving left
                    } else {
                        $j = 1; //moving right
                    }
                    $hflag = self::u8($this->get_horizflag($level, ($enemy->sprite->y >> 4) - 1, ($enemy->sprite->x >> 4) + $j));
                    if (($hflag == self::HFLAG_WALL) ||
                      ($hflag == self::HFLAG_DEADLY) ||
                      ($hflag == self::HFLAG_PADLOCK)) { //Next tile is wall, change direction
                        $enemy->sprite->speedX = 0 - $enemy->sprite->speedX;
                    }
                    $enemy->sprite->x -= $enemy->sprite->speedX;
                    if ($enemy->sprite->x < 0) {
                        $enemy->sprite->speedX = 0 - $enemy->sprite->speedX;
                        $enemy->sprite->x -= $enemy->sprite->speedX;
                    }
                    if (abs($player->sprite->x - $enemy->sprite->x) < 320 * 2) {
                        continue 3;
                    }
                    $enemy->phase = 2;
                    break;
                case 2:
                    //Reset the enemy
                    if ((($enemy->initY >> 4) - $this->BITMAP_Y < 12) && //12 tiles in Y
                      (($enemy->initY >> 4) - $this->BITMAP_Y >= 0) &&
                      (($enemy->initX >> 4) - $this->BITMAP_X < 19) && //19 tiles in X
                      (($enemy->initX >> 4) - $this->BITMAP_X >= 0)) {
                        continue 3; //Player is too close to the enemy's spawning point
                    }
                    $enemy->sprite->y = $enemy->initY;
                    $enemy->sprite->x = $enemy->initX;
                    $enemy->phase = 0;
                    $this->DOWN_ANIMATION($enemy->sprite);
                    break;
                }
                break;

            case 9:
                //Walk and periodically pop-up
                if ($enemy->dying != 0) { //If not 0, the enemy is dying or dead, and have special movement
                    $this->DEAD1($level, $enemy);
                    continue 2;
                }
                switch ($enemy->phase) { //State dependent actions
                case 0:
                    //wait for its prey!
                    if ($enemy->rangeX < abs($player->sprite->x - $enemy->sprite->x)) {
                        continue 3;
                    }
                    if (abs($player->sprite->y - $enemy->sprite->y) > 60) {
                        continue 3;
                    }
                    $enemy->phase = 1;
                    $this->UP_ANIMATION($enemy->sprite);
                    if ($enemy->sprite->x > $player->sprite->x) { //Enemy is right for the player
                        $enemy->sprite->speedX = $enemy->walkspeedX; //Move left
                    } else { //Enemy is left for the player
                        $enemy->sprite->speedX = 0 - $enemy->walkspeedX; //Move right
                    }
                    break;
                case 1:
                    //Special animation?
                    $this->TAUPE_FLAG = self::u8($this->TAUPE_FLAG + 1);
                    if ((($this->TAUPE_FLAG & 0x04) == 0) && //xxxxx0xx //true 4 times, false 4 times,
                      (($this->IMAGE_COUNTER & 0x01FF) == 0)) { //xxxxxxx0 00000000 //true 1 time, false 511 times
                        $this->UP_ANIMATION($enemy->sprite);
                    }
                    if (($this->IMAGE_COUNTER & 0x007F) == 0) { //xxxxxxxx x0000000 //true 1 time, false 127 times
                        $enemy->phase = 3;
                        $this->UP_ANIMATION($enemy->sprite);
                        //Same as "case 3:"
                        //Remove the head or Periskop!
                        if (!$enemy->visible) { //Is it on the screen?
                            //Give the sequence # 2 and Phase # 1
                            $this->UP_ANIMATION($enemy->sprite);
                            $enemy->sprite->anim--; //Previous animation frame
                            $this->GAL_FORM($level, $enemy);
                            if ($enemy->sprite->x > $player->sprite->x) { //Enemy is right for the player
                                $enemy->sprite->speedX = $enemy->walkspeedX; //Move left
                            } else { //Enemy is left for the player
                                $enemy->sprite->speedX = 0 - $enemy->walkspeedX; //Move right
                            }
                            $enemy->phase = 1;
                        } elseif ($enemy->trigger) {
                            if ($enemy->sprite->x > $player->sprite->x) { //Enemy is right for the player
                                $enemy->sprite->speedX = $enemy->walkspeedX; //Move left
                            } else { //Enemy is left for the player
                                $enemy->sprite->speedX = 0 - $enemy->walkspeedX; //Move right
                            }
                            $enemy->phase = 1;
                        }
                        continue 3;
                    }
                    if ($this->get_floorflag($level, ($enemy->sprite->y >> 4), ($enemy->sprite->x >> 4)) == self::FFLAG_NOFLOOR) {
                        $enemy->sprite->speedX = abs($enemy->sprite->speedX);
                        if ($enemy->initX > $enemy->sprite->x) {
                            $enemy->sprite->speedX = 0 - $enemy->sprite->speedX;
                        }
                    }
                    $enemy->sprite->y = self::s16($enemy->sprite->y & 0xFFF0);
                    if ($enemy->sprite->speedX > 0) {
                        $j = -1; //moving left
                    } else {
                        $j = 1; //moving right
                    }
                    $hflag = self::u8($this->get_horizflag($level, ($enemy->sprite->y >> 4) - 1, ($enemy->sprite->x >> 4) + $j));
                    if (($hflag == self::HFLAG_WALL) ||
                      ($hflag == self::HFLAG_DEADLY) ||
                      ($hflag == self::HFLAG_PADLOCK)) { //Next tile is wall, change direction
                        $enemy->sprite->speedX = 0 - $enemy->sprite->speedX;
                    }
                    $enemy->sprite->x -= $enemy->sprite->speedX;
                    if ($enemy->sprite->x < 0) {
                        $enemy->sprite->speedX = 0 - $enemy->sprite->speedX;
                        $enemy->sprite->x -= $enemy->sprite->speedX;
                    }
                    if (abs($player->sprite->x - $enemy->sprite->x) < 320 * 4) {
                        continue 3;
                    }
                    $enemy->phase = 2;
                    break;
                case 2:
                    //Reset, if not visible on the screen
                    if ((($enemy->initY >> 4) - $this->BITMAP_Y <= 12) && //12 tiles in Y
                      (($enemy->initY >> 4) - $this->BITMAP_Y >= 0) &&
                      (($enemy->initX >> 4) - $this->BITMAP_X < 25) && //25 tiles in X
                      (($enemy->initX >> 4) - $this->BITMAP_X >= 0)) {
                        continue 3; //Player is too close to the enemy's spawning point
                    }
                    $enemy->sprite->y = $enemy->initY;
                    $enemy->sprite->x = $enemy->initX;
                    $enemy->phase = 0;
                    $this->DOWN_ANIMATION($enemy->sprite);
                    break;
                case 3:
                    //Remove the head or Periskop!
                    if (!$enemy->visible) { //Is it on the screen?
                        $this->UP_ANIMATION($enemy->sprite);
                        $enemy->sprite->anim--; //Previous animation frame
                        $this->GAL_FORM($level, $enemy);
                        if ($enemy->sprite->x > $player->sprite->x) { //Enemy is right for the player
                            $enemy->sprite->speedX = $enemy->walkspeedX; //Move left
                        } else { //Enemy is left for the player
                            $enemy->sprite->speedX = 0 - $enemy->walkspeedX; //Move right
                        }
                        $enemy->phase = 1;
                    } elseif ($enemy->trigger) {
                        if ($enemy->sprite->x > $player->sprite->x) { //Enemy is right for the player
                            $enemy->sprite->speedX = $enemy->walkspeedX; //Move left
                        } else { //Enemy is left for the player
                            $enemy->sprite->speedX = 0 - $enemy->walkspeedX; //Move right
                        }
                        $enemy->phase = 1;
                    }
                    break;
                }
                break;

            case 10:
                //Alert when near, walk when nearer
                if ($enemy->dying != 0) { //If not 0, the enemy is dying or dead, and have special movement
                    $this->DEAD1($level, $enemy);
                    continue 2;
                }
                switch ($enemy->phase) { //State dependent actions
                case 0:
                    if ($this->FURTIF_FLAG != 0) {
                        continue 3;
                    }
                    if ($enemy->rangeX < abs($player->sprite->x - $enemy->sprite->x)) {
                        continue 3;
                    }
                    if (abs($player->sprite->y - $enemy->sprite->y) > 26) {
                        continue 3;
                    }
                    $enemy->phase = 1;
                    $this->UP_ANIMATION($enemy->sprite);
                    if ($enemy->sprite->x > $player->sprite->x) { //Enemy is right for the player
                        $enemy->sprite->speedX = $enemy->walkspeedX; //Move left
                    } else { //Enemy is left for the player
                        $enemy->sprite->speedX = 0 - $enemy->walkspeedX; //Move right
                    }
                    // no break: fall through to case 1 (as in the C code)
                case 1:
                    //wait
                    if ($this->FURTIF_FLAG != 0) {
                        continue 3;
                    }
                    if ($enemy->rangeX < abs($player->sprite->x - $enemy->sprite->x)) {
                        //Switch back to state 0
                        $this->DOWN_ANIMATION($enemy->sprite);
                        $enemy->phase = 0;
                        continue 3;
                    }
                    // rangeX - 50 is unsigned in C (huge if rangeX < 50)
                    if ((!self::uint_lt($enemy->rangeX - 50, abs($player->sprite->x - $enemy->sprite->x))) &&
                      (abs($player->sprite->y - $enemy->sprite->y) <= 60)) {
                        $enemy->phase = 2;
                        $this->UP_ANIMATION($enemy->sprite);
                    }
                    break;
                case 2:
                    //run
                    if ($this->get_floorflag($level, ($enemy->sprite->y >> 4), ($enemy->sprite->x >> 4)) == self::FFLAG_NOFLOOR) {
                        $enemy->sprite->speedX = abs($enemy->sprite->speedX);
                        if ($enemy->initX > $enemy->sprite->x) {
                            $enemy->sprite->speedX = 0 - $enemy->sprite->speedX;
                        }
                    }
                    $enemy->sprite->y = self::s16($enemy->sprite->y & 0xFFF0);
                    if ($enemy->sprite->speedX > 0) {
                        $j = -1; //moving left
                    } else {
                        $j = 1; //moving right
                    }
                    $hflag = self::u8($this->get_horizflag($level, ($enemy->sprite->y >> 4) - 1, ($enemy->sprite->x >> 4) + $j));
                    if (($hflag == self::HFLAG_WALL) ||
                      ($hflag == self::HFLAG_DEADLY) ||
                      ($hflag == self::HFLAG_PADLOCK)) { //Next tile is wall, change direction
                        $enemy->sprite->speedX = abs($enemy->sprite->speedX);
                        if ($enemy->initX > $enemy->sprite->x) {
                            $enemy->sprite->speedX = 0 - $enemy->sprite->speedX;
                        }
                    }
                    $enemy->sprite->x -= $enemy->sprite->speedX;
                    if ($enemy->sprite->x < 0) {
                        $enemy->sprite->speedX = 0 - $enemy->sprite->speedX;
                        $enemy->sprite->x -= $enemy->sprite->speedX;
                    }
                    if (abs($player->sprite->x - $enemy->sprite->x) >= 320 * 2) {
                        $enemy->phase = 3;
                    }
                    break;
                case 3:
                    //Reset, if not visible on the screen
                    if ((($enemy->initY >> 4) - $this->BITMAP_Y <= 13) && //13 tiles in Y
                      (($enemy->initY >> 4) - $this->BITMAP_Y >= 0) &&
                      (($enemy->initX >> 4) - $this->BITMAP_X < 21) && //21 tiles in X
                      (($enemy->initX >> 4) - $this->BITMAP_X >= 0)) {
                        continue 3; //Spawning point is visible on screen
                    }
                    $enemy->sprite->y = $enemy->initY;
                    $enemy->sprite->x = $enemy->initX;
                    $this->DOWN_ANIMATION($enemy->sprite);
                    $this->DOWN_ANIMATION($enemy->sprite);
                    $enemy->phase = 0;
                    break;
                }
                break;

            case 11:
                //Walk and shoot
                if ($enemy->dying != 0) { //If not 0, the enemy is dying or dead, and have special movement
                    $this->DEAD1($level, $enemy);
                    continue 2;
                }
                switch ($enemy->phase) { //State dependent actions
                case 0:
                    //wait
                    if ($enemy->rangeX < abs($player->sprite->x - $enemy->sprite->x)) {
                        continue 3;
                    }
                    if (abs($player->sprite->y - $enemy->sprite->y) > 26) {
                        continue 3;
                    }
                    $enemy->phase = 1;
                    $this->UP_ANIMATION($enemy->sprite);
                    if ($enemy->sprite->x > $player->sprite->x) { //Enemy is right for the player
                        $enemy->sprite->speedX = $enemy->walkspeedX; //Move left
                    } else { //Enemy is left for the player
                        $enemy->sprite->speedX = 0 - $enemy->walkspeedX; //Move right
                    }
                    break;
                case 1:
                    if ($this->get_floorflag($level, ($enemy->sprite->y >> 4), ($enemy->sprite->x >> 4)) == self::FFLAG_NOFLOOR) {
                        $enemy->sprite->speedX = abs($enemy->sprite->speedX);
                        if ($enemy->initX > $enemy->sprite->x) {
                            $enemy->sprite->speedX = 0 - $enemy->sprite->speedX;
                        }
                    }
                    $enemy->sprite->y = self::s16($enemy->sprite->y & 0xFFF0);
                    $hflag = self::u8($this->get_horizflag($level, ($enemy->sprite->y >> 4) - 1, $enemy->sprite->x >> 4));
                    if (($hflag == self::HFLAG_WALL) ||
                      ($hflag == self::HFLAG_DEADLY) ||
                      ($hflag == self::HFLAG_PADLOCK)) { //Next tile is wall, change direction
                        $enemy->sprite->speedX = 0 - $enemy->sprite->speedX;
                    }
                    $enemy->sprite->x -= $enemy->sprite->speedX;
                    if ($enemy->sprite->x < 0) {
                        $enemy->sprite->speedX = 0 - $enemy->sprite->speedX;
                        $enemy->sprite->x -= $enemy->sprite->speedX;
                    }
                    if (abs($player->sprite->x - $enemy->sprite->x) >= 320 * 2) {
                        $enemy->phase = 2;
                    }
                    $this->subto0($enemy->counter);
                    if ($enemy->counter != 0) {
                        continue 3;
                    }
                    if (abs($player->sprite->x - $enemy->sprite->x) > 64) {
                        continue 3;
                    }
                    if (abs($player->sprite->y - $enemy->sprite->y) > 20) {
                        continue 3;
                    }
                    if ($enemy->sprite->x > $player->sprite->x) { //Enemy is right for the player
                        $enemy->sprite->speedX = $enemy->walkspeedX; //Move left
                    } else { //Enemy is left for the player
                        $enemy->sprite->speedX = 0 - $enemy->walkspeedX; //Move right
                    }
                    $enemy->phase = 3; //phase started!
                    $this->UP_ANIMATION($enemy->sprite);
                    $enemy->counter = 20;
                    break;
                case 2:
                    //Reset enemy when spawn point isn't visible for the player
                    if ((($enemy->initY >> 4) - $this->BITMAP_Y > 13) || //13 tiles in Y (Spawn is below)
                      (($enemy->initY >> 4) - $this->BITMAP_Y < 0) || // (Spawn is above)
                      (($enemy->initX >> 4) - $this->BITMAP_X >= 21) || //21 tiles in X (Spawn is to the right)
                      (($enemy->initX >> 4) - $this->BITMAP_X < 0)) { //(Spawn is to the right)
                        $enemy->sprite->x = $enemy->initX;
                        $enemy->sprite->y = $enemy->initY;
                        $this->DOWN_ANIMATION($enemy->sprite);
                        $enemy->phase = 0;
                    }
                    break;
                case 3:
                    //Shoot
                    if (!$enemy->trigger) {
                        continue 3;
                    }
                    if ($this->FIND_TRASH($level, $bullet)) {
                        $enemy->sprite->anim += 2;
                        $this->PUT_BULLET($level, $enemy, $bullet);
                    }
                    $this->DOWN_ANIMATION($enemy->sprite);
                    $enemy->phase = 1;
                    break;
                }
                break;

            case 12:
                //Jump (fireball) (immortal)
                $enemy->dying = 0; //Immortal
                switch ($enemy->phase) { //State dependent actions
                case 0:
                    //init
                    $this->UP_ANIMATION($enemy->sprite);
                    $enemy->sprite->speedY = self::s16($enemy->rangeY);
                    $enemy->initY = $enemy->sprite->y;
                    $enemy->phase = 1;
                    break;
                case 1:
                    //Fireball moving up
                    $enemy->sprite->y -= $enemy->sprite->speedY;
                    $enemy->sprite->speedY--;
                    if ($enemy->sprite->speedY == 0) {
                        $enemy->phase = 2;
                    }
                    break;
                case 2:
                    //Fireball falling down
                    $enemy->sprite->y += $enemy->sprite->speedY;
                    $enemy->sprite->speedY++;
                    if ($enemy->sprite->y >= $enemy->initY) {
                        $enemy->sprite->y = $enemy->initY;
                        $enemy->counter = self::u8($enemy->delay);
                        $enemy->phase = 3;
                        $this->DOWN_ANIMATION($enemy->sprite);
                    }
                    break;
                case 3:
                    //Fireball delay
                    $enemy->counter = self::u8($enemy->counter - 1);
                    if ($enemy->counter == 0) {
                        $enemy->phase = 0;
                    }
                    break;
                }
                break;

            case 13:
                //Bounce (big baby)
                if ($enemy->dying != 0) { //If not 0, the enemy is dying or dead, and have special movement
                    $this->DEAD1($level, $enemy);
                    continue 2;
                }
                switch ($enemy->phase) { //State dependent actions
                case 0:
                    //remain at rest or attack!
                    if ($player->sprite->x >= $enemy->sprite->x) {
                        $enemy->sprite->speedX = 0 - abs($enemy->sprite->speedX);
                    } else {
                        $enemy->sprite->speedX = abs($enemy->sprite->speedX);
                    }
                    if ((abs($player->sprite->x - $enemy->sprite->x) <= $enemy->rangeX) &&
                      (abs($player->sprite->y - $enemy->sprite->y) <= 40)) {
                        $this->UP_ANIMATION($enemy->sprite);
                        $enemy->phase = 1;
                        $enemy->sprite->speedY = 10;
                    }
                    break;
                case 1:
                    //Jump, move upwards
                    $enemy->sprite->x -= $enemy->sprite->speedX;
                    $enemy->sprite->y -= $enemy->sprite->speedY;
                    $enemy->sprite->speedY--;
                    if ($enemy->sprite->speedY == 0) {
                        $this->UP_ANIMATION($enemy->sprite);
                        $enemy->phase = 2;
                    }
                    break;
                case 2:
                    //Fall down to the ground
                    $enemy->sprite->x -= $enemy->sprite->speedX;
                    $enemy->sprite->y += $enemy->sprite->speedY;
                    $enemy->sprite->speedY++;
                    if ($enemy->sprite->speedY > 10) {
                        $enemy->phase = 3;
                        $this->UP_ANIMATION($enemy->sprite);
                        $enemy->counter = self::u8($enemy->delay);
                    }
                    break;
                case 3:
                    //Stay on the ground for a while
                    $enemy->counter = self::u8($enemy->counter - 1);
                    if ($enemy->counter == 0) {
                        $this->DOWN_ANIMATION($enemy->sprite);
                        $this->DOWN_ANIMATION($enemy->sprite);
                        $this->DOWN_ANIMATION($enemy->sprite);
                        $enemy->phase = 0;
                    }
                    break;
                }
                break;

            //case 14:
            //Gravity walk when off-screen (immortal)
            //Located at case 8

            case 15:
                //Nothing (immortal)
                $enemy->dying = 0; //Immortal
                break;

            case 16:
                //Nothing
                if ($enemy->dying != 0) { //If not 0, the enemy is dying or dead, and have special movement
                    $this->DEAD1($level, $enemy);
                    continue 2;
                }
                break;

            case 17:
                //Drop (immortal)
                $enemy->dying = 0; //Immortal
                //Delay
                if ($enemy->counter + 1 < $enemy->delay) {
                    $enemy->counter = self::u8($enemy->counter + 1);
                    continue 2;
                }
                if ($enemy->rangeX < abs($enemy->sprite->x - $player->sprite->x)) { //hero too far! at x
                    $enemy->counter = 0;
                    continue 2;
                }
                if (self::uint_lt($enemy->rangeY, $player->sprite->y - $enemy->sprite->y)) { //hero too far! at y
                    continue 2;
                }
                //you attack, so finding a free object
                $j = 0;
                do {
                    $j++;
                    if ($j > $level->objectcount || !isset($level->object[$j])) {
                        $enemy->counter = 0;
                        // C: "continue" jumps to the while condition and reads past the end of the
                        // object array (undefined behaviour); here: skip to the next enemy
                        continue 3;
                    }
                } while ($level->object[$j]->sprite->enabled == true);
                //object[j] is free!
                $this->UP_ANIMATION($enemy->sprite);
                $this->updateobjectsprite($level, $level->object[$j], $enemy->sprite->animValue() & 0x1FFF, true);
                $level->object[$j]->sprite->flipped = true;
                $level->object[$j]->sprite->x = $enemy->sprite->x;
                $level->object[$j]->sprite->y = $enemy->sprite->y;
                $level->object[$j]->sprite->droptobottom = true;
                $level->object[$j]->sprite->killing = true;
                $level->object[$j]->sprite->speedY = 0;
                $this->GRAVITY_FLAG = 4;
                $this->DOWN_ANIMATION($enemy->sprite);
                $enemy->counter = 0;
                break;

            case 18:
                //Guard (helicopter guy)
                if ($enemy->dying != 0) { //If not 0, the enemy is dying or dead, and have special movement
                    $this->DEAD1($level, $enemy);
                    continue 2;
                }
                if (($player->sprite->x < self::s16($enemy->initX - $enemy->rangeX)) || //Player is too far left
                  ($player->sprite->x > self::s16($enemy->initX + $enemy->rangeX)) || //Player is too far right
                  ($player->sprite->y < self::s16($enemy->initY - $enemy->rangeY)) || //Player is too high above
                  ($player->sprite->y > self::s16($enemy->initY + $enemy->rangeY))) { //Player is too far below
                    //The player is too far away, move enemy to center
                    if ($enemy->initX != $enemy->sprite->x) {
                        $enemy->sprite->speedX = abs($enemy->sprite->speedX);
                        if ($enemy->initX > $enemy->sprite->x) {
                            $enemy->sprite->speedX = 0 - $enemy->sprite->speedX;
                        }
                        $enemy->sprite->x -= $enemy->sprite->speedX;
                    }
                    if ($enemy->initY != $enemy->sprite->y) {
                        if ($enemy->initY > $enemy->sprite->y) {
                            $enemy->sprite->y += $enemy->sprite->speedY;
                        } else {
                            $enemy->sprite->y -= $enemy->sprite->speedY;
                        }
                    }
                } else {
                    //The player is inside the guarded area, move enemy to player
                    if ($player->sprite->x != $enemy->sprite->x) {
                        $enemy->sprite->speedX = abs($enemy->sprite->speedX);
                        if ($player->sprite->x > $enemy->sprite->x) {
                            $enemy->sprite->speedX = 0 - $enemy->sprite->speedX;
                        }
                        $enemy->sprite->x -= $enemy->sprite->speedX;
                    }
                    if ($player->sprite->y != $enemy->sprite->y) {
                        if ($player->sprite->y > $enemy->sprite->y) {
                            $enemy->sprite->y += $enemy->sprite->speedY;
                        } else {
                            $enemy->sprite->y -= $enemy->sprite->speedY;
                        }
                    }
                }
                break;
            } //switch (level->enemy[i].NMI_ACTION & 0x1FFF)
        } //for (i = 0; i < NMI_BY_LEVEL; i++)
    }

    public function DEAD1(Level $level, Enemy $enemy): void
    {
        if ((($enemy->dying & 0x01) != 0) || //00000001 or 00000011
          ($enemy->dead_sprite == -1)) {
            if (($enemy->dying & 0x01) == 0) {
                $enemy->dying = $enemy->dying | 0x01;
                $enemy->sprite->speedY = -10;
                $enemy->phase = 0;
            }
            if ($enemy->phase != 0xFF) {
                $enemy->sprite->y += $enemy->sprite->speedY;
                if ($this->SEECHOC_FLAG != 0) {
                    $level->player->sprite2->y += $enemy->sprite->speedY;
                }
                if ($enemy->sprite->speedY < self::MAX_SPEED_DEAD) {
                    $enemy->sprite->speedY++;
                }
            }
        } else {
            $enemy->dying = $enemy->dying | 0x01;
            $this->updateenemysprite($level, $enemy, $enemy->dead_sprite, false);
            $enemy->sprite->flash = false;
            $enemy->sprite->visible = false;
            $enemy->sprite->speedY = 0;
            $enemy->phase = self::u8(-1);
        }
    }

    public function updateenemysprite(Level $level, Enemy $enemy, int $number, bool $clearflags): int
    {
        $this->updatesprite($level, $enemy->sprite, $number, $clearflags);

        if (($number >= 101) && ($number <= 105)) { //Walking man
            $enemy->carry_sprite = 105;
        } elseif (($number >= 126) && ($number <= 130)) { //Fly
            $enemy->carry_sprite = 130;
        } elseif (($number >= 149) && ($number <= 153)) { //Skeleton
            $enemy->carry_sprite = 149;
        } elseif (($number >= 157) && ($number <= 158)) { //Worm
            $enemy->carry_sprite = 158;
        } elseif (($number >= 159) && ($number <= 167)) { //Guy with sword
            $enemy->carry_sprite = 167;
        } elseif (($number >= 185) && ($number <= 191)) { //Zombie
            $enemy->carry_sprite = 186;
        } elseif (($number >= 197) && ($number <= 203)) { //Woman with pot
            $enemy->carry_sprite = 203;
        } else {
            $enemy->carry_sprite = -1;
        }

        if (($number >= 172) && ($number <= 184)) { //Periscope
            $enemy->dead_sprite = 184;
        } elseif (($number >= 192) && ($number <= 196)) { //Camel
            $enemy->dead_sprite = 196;
        } elseif (($number >= 210) && ($number <= 213)) { //Old man with TV
            $enemy->dead_sprite = 213;
        } elseif (($number >= 214) && ($number <= 220)) { //Snake in pot
            $enemy->dead_sprite = 220;
        } elseif (($number >= 221) && ($number <= 226)) { //Man throwing knives
            $enemy->dead_sprite = 226;
        } elseif (($number >= 242) && ($number <= 247)) { //Carnivorous plant in pot
            $enemy->dead_sprite = 247;
        } else {
            $enemy->dead_sprite = -1;
        }

        if ((($number >= 248) && ($number <= 251)) || //Man throwing rocks (3rd level)
          (($number >= 252) && ($number <= 256)) || //Big baby (11th level)
          (($number >= 257) && ($number <= 261)) || //Big woman (7th level)
          (($number >= 263) && ($number <= 267)) || //Big man (15th level on Moktar only)
          (($number >= 284) && ($number <= 288)) || //Mummy (9th level)
          (($number >= 329) && ($number <= 332))) { //Ax man (5th level)
            $enemy->boss = true;
        } else {
            $enemy->boss = false;
        }

        return 0;
    }

    public function SET_NMI(Level $level): void
    {
        //Clear enemy sprites
        //If an enemy is on the screen
        // - Set bit 13
        // - Animate
        // - Collision with player
        //   - Loose life and fly
        // - Collision with object
        //   - Decrease enemy's life

        $k = 0;
        for ($i = 0; $i < $level->enemycount; $i++) { //50
            $enemy = $level->enemy[$i];
            if (!($enemy->sprite->enabled)) continue; //Skip unused enemies
            $enemy->visible = false;
            //Is the enemy on the screen?
            if (($enemy->sprite->x + 32 < ($this->BITMAP_X << 4)) || //Left for the screen?
              ($enemy->sprite->x - 32 > ($this->BITMAP_X << 4) + self::screen_width * 16) || //Right for the screen?
              ($enemy->sprite->y < ($this->BITMAP_Y << 4)) || //Above the screen?
              ($enemy->sprite->y - 32 > ($this->BITMAP_Y << 4) + self::screen_height * 16)) { //Below the screen?
                if (($enemy->dying & 0x03) != 0) { //If the enemy is dying or dead and not on the screen, remove from the list!
                    $enemy->sprite->enabled = false;
                }
                continue;
            }
            $enemy->visible = true;
            $this->GAL_FORM($level, $enemy); //Animation
            if (($enemy->dying & 0x03) != 0) { //If the enemy is dying or dead and not on the screen, remove from the list!
                continue;
            }
            if (($this->KICK_FLAG == 0) && !$this->GODMODE) { //Collision with the hero?
                if ($enemy->sprite->invisible) {
                    continue;
                }
                $this->ACTIONC_NMI($level, $enemy);
            }
            $hit = 0;
            if ($this->GRAVITY_FLAG != 0) { //Collision with a moving object?
                for ($k = 0; $k < $level->objectcount; $k++) {
                    if ($level->object[$k]->sprite->speedX == 0) {
                        if ($level->object[$k]->sprite->speedY == 0) {
                            continue;
                        }
                        if ($level->object[$k]->mass < 10) {
                            continue;
                        }
                    }
                    if ($level->object[$k]->objectdata->no_damage) { //Is the object a weapon (false) or not (true)?
                        continue;
                    }
                    if ($this->NMI_VS_DROP($enemy->sprite, $level->object[$k]->sprite)) {
                        $hit = 1;
                        break;
                    }
                }
            }
            if (($hit == 0) &&
              $this->DROP_FLAG &&
              !$this->CARRY_FLAG &&
              ($level->player->sprite2->enabled)) {
                if ($this->NMI_VS_DROP($enemy->sprite, $level->player->sprite2)) {
                    $this->INVULNERABLE_FLAG = 0;
                    $level->player->sprite2->enabled = false;
                    $this->SEE_CHOC($level);
                    $hit = 2;
                }
            }
            if ($hit != 0) {
                if ($hit == 1) {
                    if ($level->object[$k]->sprite->number != 73) { //Change direction of the object, except if the object is a small iron ball
                        $level->object[$k]->sprite->speedX = 0 - $level->object[$k]->sprite->speedX;
                    }
                }
                //If final enemy, remove energy
                $this->FX_START(1);
                $this->DROP_FLAG = false;
                if ($enemy->boss) {
                    if ($this->INVULNERABLE_FLAG != 0) {
                        //j++;
                        continue;
                    }
                    $this->INVULNERABLE_FLAG = 10;
                    $enemy->sprite->flash = true; //flash
                    $this->BIGNMI_POWER = self::u8($this->BIGNMI_POWER - 1);
                    if ($this->BIGNMI_POWER != 0) {
                        //j++;
                        continue;
                    }
                    $this->boss_alive = false;
                }
                $enemy->dying = $enemy->dying | 0x02; //Kill the enemy
            }
        }
    }

    public function GAL_FORM(Level $level, Enemy $enemy): void //Enemy animation
    {
        $enemy->sprite->invisible = false;
        if (($enemy->dying & 0x03) != 0) {
            $enemy->sprite->visible = false;
            $enemy->visible = true;
            return;
        }
        $enemy->trigger = false;
        $table = $enemy->sprite->animTable;
        $image = $enemy->sprite->anim; //Animation pointer
        while ($table[$image] < 0) {
            $image += ($table[$image] >> 1); //jump back to start of animation
        }
        if ($table[$image] == 0x55AA) {
            $enemy->sprite->invisible = true;
            return;
        }
        $enemy->trigger = ($table[$image] & 0x2000) != 0;
        $this->updateenemysprite($level, $enemy, ($table[$image] & 0x00FF) + self::FIRST_NMI, true);
        $enemy->sprite->flipped = ($enemy->sprite->speedX < 0) ? true : false;
        $image++;
        if ($table[$image] < 0) {
            $image += ($table[$image] >> 1); //jump back to start of animation
        }
        $enemy->sprite->anim = $image;
        $enemy->visible = true;
    }

    public function ACTIONC_NMI(Level $level, Enemy $enemy): void
    {
        switch ($enemy->type) {
        case 0:
        case 1:
        case 2:
        case 3:
        case 4:
        case 5:
        case 6:
        case 7:
        case 8:
        case 9:
        case 10:
        case 11:
        case 12:
        case 13:
        case 14:
        case 18:
            if ($this->NMI_VS_DROP($enemy->sprite, $level->player->sprite)) {
                if ($enemy->type != 11) { //Walk and shoot
                    if ($enemy->sprite->number != 178) { //Periscope
                        $enemy->sprite->speedX = 0 - $enemy->sprite->speedX;
                    }
                }
                if (($enemy->sprite->number >= self::FIRST_NMI + 53) &&
                  ($enemy->sprite->number <= self::FIRST_NMI + 55)) { //Fireball
                    $this->GRANDBRULE_FLAG = true;
                }
                if ($enemy->power != 0) {
                    $this->KICK_ASH($level, $enemy->sprite, $enemy->power);
                }
            }
            break;
        }
    }

    public function KICK_ASH(Level $level, Sprite $enemysprite, int $power): void
    {
        $this->FX_START(4);
        $p_sprite = $level->player->sprite;
        $this->DEC_ENERGY($level);
        $this->DEC_ENERGY($level);
        $this->KICK_FLAG = 24;
        $this->CHOC_FLAG = 0;
        $this->LAST_ORDER = 0;
        $p_sprite->speedX = $power;
        if ($p_sprite->x <= $enemysprite->x) {
            $p_sprite->speedX = 0 - $p_sprite->speedX;
        }
        $p_sprite->speedY = -8 * 16;
        $this->FORCE_POSE($level);
    }

    public function NMI_VS_DROP(Sprite $enemysprite, Sprite $sprite): bool
    {
        if (abs($sprite->x - $enemysprite->x) >= 64) {
            return false;
        }
        if (abs($sprite->y - $enemysprite->y) >= 70) {
            return false;
        }

        if ($sprite->y < $enemysprite->y) {
            //Enemy is below the offending object
            if ($sprite->y <= $enemysprite->y - $enemysprite->spritedata->collheight + 3) return false; //The offending object is too high for collision
        } else {
            //Offending object is below the enemy
            if ($enemysprite->y <= $sprite->y - $sprite->spritedata->collheight + 3) return false; //The enemy is too high for collision
        }
        $enemyleft = self::s16($enemysprite->x - $enemysprite->spritedata->refwidth);
        $objectleft = self::s16($sprite->x - $sprite->spritedata->refwidth);
        if ($enemyleft >= $objectleft) {
            //The object is left for the enemy
            if (($objectleft + ($sprite->spritedata->collwidth >> 1)) <= $enemyleft) {
                return false; //The object is too far left
            }
        } else {
            //Enemy is left for the object
            if (($enemyleft + ($enemysprite->spritedata->collwidth >> 1)) <= $objectleft) {
                return false; //The enemy is too far left
            }
        }
        return true; //Collision!
    }

    public function SEE_CHOC(Level $level): void
    {
        $this->updatesprite($level, $level->player->sprite2, self::FIRST_OBJET + 15, true); //Hit (a throw hits an enemy)
        $level->player->sprite2->speedX = 0;
        $level->player->sprite2->speedY = 0;
        $this->SEECHOC_FLAG = 5;
    }

    public function MOVE_TRASH(Level $level): void
    {
        for ($i = 0; $i < $level->trashcount; $i++) {
            $trash = $level->trash[$i];
            if (!$trash->enabled) continue;
            if ($trash->speedX != 0) {
                $trash->x += ($trash->speedX >> 4);
                $tmp = self::s16(($trash->x >> 4) - $this->BITMAP_X);
                if (($tmp < 0) || ($tmp > self::screen_width)) {
                    $trash->enabled = false;
                    continue;
                }
                if ($tmp != 0) { //Bug in the code
                    $trash->y += ($trash->speedY >> 4);
                    $tmp = self::s16(($trash->y >> 4) - $this->BITMAP_Y);
                    if (($tmp < 0) || ($tmp > self::screen_height * 16)) { //Bug?
                        $trash->enabled = false;
                        continue;
                    }
                }
            }
            if (!$this->GODMODE && $this->NMI_VS_DROP($trash, $level->player->sprite)) { //Trash vs player
                $trash->x -= $trash->speedX;
                $this->KICK_ASH($level, $trash, 70);
                $trash->enabled = false;
                continue;
            }
        }
    }

    public function FIND_TRASH(Level $level, ?Sprite &$trash): bool
    {
        for ($i = 0; $i < $level->trashcount; $i++) {
            if ($level->trash[$i]->enabled == false) {
                $trash = $level->trash[$i];
                return true;
            }
        }
        return false;
    }

    public function PUT_BULLET(Level $level, Enemy $enemy, Sprite $bullet): void
    {
        $bullet->x = $enemy->sprite->x;
        $bullet->y = $enemy->sprite->y - self::s8($enemy->sprite->animValue(-1) & 0x00FF);
        $this->updatesprite($level, $bullet, ($enemy->sprite->animValue(-2) & 0x1FFF) + self::FIRST_OBJET, true);
        if ($enemy->sprite->x < $level->player->sprite->x) {
            $bullet->speedX = 16 * 11;
            $bullet->flipped = true;
        } else {
            $bullet->speedX = -16 * 11;
            $bullet->flipped = false;
        }
        $bullet->speedY = 0;
        $bullet->x += $bullet->speedX >> 4;
    }

    public function UP_ANIMATION(Sprite $sprite): void
    {
        $last = count($sprite->animTable ?? []) - 1;
        do {
            $sprite->anim++;
        } while ($sprite->anim < $last && $sprite->animValue() >= 0); // (bounded: the C code reads outside the table)
        $sprite->anim++;
    }

    public function DOWN_ANIMATION(Sprite $sprite): void
    {
        do {
            $sprite->anim--;
        } while ($sprite->anim > 0 && $sprite->animValue() >= 0); // (bounded: the C code reads outside the table)
        $sprite->anim--;
    }
}
