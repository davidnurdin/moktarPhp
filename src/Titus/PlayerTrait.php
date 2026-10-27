<?php

namespace App\Titus;

/**
 * OpenTitus src/player.c: handles player movement and keyboard handling.
 */
trait PlayerTrait
{
    public function move_player(Level $level): \Generator
    {
        //Part 1: Check keyboard input
        //Part 2: Determine the player's action, and execute action dependent code
        //Part 3: Move the player + collision detection
        //Part 4: Move the throwed/carried object
        //Part 5: decrease the timers

        $player = $level->player;
        $pause = false;

        //Part 1: Check keyboard input
        foreach ($this->pollEvents() as [$type, $key]) { //Check all events
            if ($type === 'down') {
                if (($key === self::KEY_GODMODE) && ($this->devmode === 1)) {
                    if ($this->GODMODE) {
                        $this->GODMODE = false;
                        $this->NOCLIP = false;
                    } else {
                        $this->GODMODE = true;
                    }
                } elseif (($key === self::KEY_NOCLIP) && ($this->devmode === 1)) {
                    if ($this->NOCLIP) {
                        $this->NOCLIP = false;
                    } else {
                        $this->NOCLIP = true;
                        $this->GODMODE = true;
                    }
                //Will display debug information
                } elseif ($key === self::KEY_DEBUG) {
                    $this->DISPLAYLOOPTIME = !$this->DISPLAYLOOPTIME;
                } elseif ($key === self::KEY_MUSIC) {
                    $this->musicKey($key);
                } elseif ($key === self::KEY_P) {
                    $pause = true;
                }
            }
        }
        if ($this->keystate(self::KEY_ESC)) {
            return self::TITUS_ERROR_QUIT;
        }
        if ($this->keystate(self::KEY_F1) && ($this->RESETLEVEL_FLAG === 0)) { //F1 = suicide
            $this->CASE_DEAD_IM($level);
            $this->RESETLEVEL_FLAG--;
            return 0;
        }
        if ($this->keystate(self::KEY_F2)) { //F2 = game over
            $this->GAMEOVER_FLAG = true;
            return 0;
        }
        if ($this->keystate(self::KEY_E)) { //E = display energy
            $this->BAR_FLAG = 50;
        }
        if ($this->keystate(self::KEY_SKIP) && ($this->devmode === 1)) { //S = skip to next level
            $this->NEWLEVEL_FLAG = true;
        }
        if ($this->keystate(self::KEY_F4)) { //F4 = view status page
            yield from $this->viewstatus($level, false);
        }
        // TODO: ADD!    SCREEN_3(); //Test for hidden credits screen
        if ($pause) {
            $retval = yield from $this->t_pause($level); //Apply pause
            if ($retval < 0) {
                return $retval;
            }
        }
        //JOYSTICK(); //Handle both joystick and keyboard movement keys

        //Part 2: Determine the player's action, and execute action dependent code
        $this->X_FLAG = $this->keystate(self::KEY_LEFT) || $this->keystate(self::KEY_RIGHT); //Set if either is true
        $this->Y_FLAG = $this->keystate(self::KEY_UP) || $this->keystate(self::KEY_JUMP) || $this->keystate(self::KEY_DOWN); //Set if either is true
        if ($this->NOCLIP) {
            if ($this->keystate(self::KEY_LEFT)) {
                $player->sprite->speedX = -100;
            } elseif ($this->keystate(self::KEY_RIGHT)) {
                $player->sprite->speedX = 100;
            } else {
                $player->sprite->speedX = 0;
            }
            if ($this->keystate(self::KEY_UP) || $this->keystate(self::KEY_JUMP)) {
                $player->sprite->speedY = -100;
            } elseif ($this->keystate(self::KEY_DOWN)) {
                $player->sprite->speedY = 100;
            } else {
                $player->sprite->speedY = 0;
            }
            $player->sprite->x += ($player->sprite->speedX >> 4);
            $player->sprite->y += ($player->sprite->speedY >> 4);
            return 0;
        }

        if ($this->CHOC_FLAG !== 0) {
            $this->action = 11; //Headache
        } elseif ($this->KICK_FLAG !== 0) {
            if ($this->GRANDBRULE_FLAG) {
                $this->action = 13; //Hit (burn)
            } else {
                $this->action = 12; //Hit
            }
        } else {
            $this->GRANDBRULE_FLAG = false;
            if ($this->LADDER_FLAG) {
                $this->action = 6; //Action: climb
            } elseif (!$this->PRIER_FLAG && ($this->keystate(self::KEY_UP) || $this->keystate(self::KEY_JUMP)) && ($this->SAUT_FLAG === 0)) {
                $this->action = 2; //Action: jump
                if ($this->LAST_ORDER === 5) { //Test if last order was kneestanding
                    $this->FURTIF_FLAG = 100; //If jump after kneestanding, init silent walk timer
                }
            } elseif ($this->PRIER_FLAG || (($this->SAUT_FLAG !== 6) && $this->keystate(self::KEY_DOWN))) {
                if ($this->X_FLAG) { //Move left or right
                    $this->action = 3; //Action: crawling
                } else {
                    $this->action = 5; //Action: kneestand
                }
            } elseif ($this->X_FLAG) {
                $this->action = 1; //Action: walk
            } else {
                $this->action = 0;  //Action: rest (no action)
            }
            //Is space button pressed?
            if ($this->keystate(self::KEY_SPACE) && !$this->PRIER_FLAG) {
                if (!$this->DROP_FLAG) {
                    if (($this->action === 3) || ($this->action === 5)) { //Kneestand
                        $this->DROPREADY_FLAG = false;
                        $this->action = 7; //Grab an object
                    } elseif ($this->CARRY_FLAG && $this->DROPREADY_FLAG) { //Fall
                        $this->action = 8; //Drop the object
                    }
                }
            } else {
                $this->DROPREADY_FLAG = true;
                $this->POSEREADY_FLAG = false;
            }
        }
        if ($this->CARRY_FLAG) {
            $this->action += 16;
        }

        if (($this->CHOC_FLAG !== 0) || ($this->KICK_FLAG !== 0)) {
            if ($this->SENSX < 0) { // -1
                $newsensX = -1;
            } else { // 0 or 1
                $newsensX = 0;
            }
        } elseif ($this->keystate(self::KEY_LEFT)) {
            $newsensX = -1;
        } elseif ($this->keystate(self::KEY_RIGHT)) {
            $newsensX = 1;
        } elseif ($this->SENSX === -1) {
            $newsensX = -1;
        } elseif ($this->action === 0) {
            $newsensX = 0;
        } else {
            $newsensX = 1;
        }

        if ($this->SENSX !== $newsensX) {
            $this->SENSX = $newsensX;
            $this->ACTION_TIMER = 1;
        } else {
            if ((($this->action === 0) || ($this->action === 1)) && ($this->FURTIF_FLAG !== 0)) {
                //Silent walk?
                $this->action += 9;
            }
            if ($this->action !== $this->LAST_ORDER) {
                $this->ACTION_TIMER = 1;
            } elseif ($this->ACTION_TIMER < 0xFF) {
                $this->ACTION_TIMER += 1;
            }
        }
        $this->ACTION_PRG($level, $this->action); //call movement function based on ACTION

        //Part 3: Move the player + collision detection
        //Move the player in X if the new position doesn't exceed 8 pixels from the edges
        if ((($player->sprite->speedX < 0) && (($player->sprite->x + ($player->sprite->speedX >> 4)) >= 8)) || //Going left
          (($player->sprite->speedX > 0) && (($player->sprite->x + ($player->sprite->speedX >> 4)) <= ($level->width << 4) - 8))) { //Going right
            $player->sprite->x += $player->sprite->speedX >> 4;
        }
        //Move player in Y
        $player->sprite->y += ($player->sprite->speedY >> 4);
        //Test for collisions
        yield from $this->BRK_COLLISION($level);

        //Part 4: Move the throwed/carried object
        //Move throwed/carried object
        if ($this->DROP_FLAG) {
            //sprite2: throwed or dropped object
            $newX = ($player->sprite2->speedX >> 4) + $player->sprite2->x;
            if (($newX < ($level->width << 4)) && //Left for right level edge
              ($newX >= 0) && //Right for level left edge
              ($newX >= ($this->BITMAP_X << 4) - self::GESTION_X) && //Max 40 pixels left for screen (bug: the purpose was probably one screen left for the screen)
              ($newX <= ($this->BITMAP_X << 4) + (self::screen_width << 4) + self::GESTION_X)) { //Max 40 pixels right for screen
                $player->sprite2->x = $newX;
                $newY = ($player->sprite2->speedY >> 4) + $player->sprite2->y;
                if (($newY < ($level->height << 4)) && //Above bottom edge of level
                  ($newY >= 0) && //Below top edge of level
                  ($newY >= ($this->BITMAP_Y << 4) - self::GESTION_Y) && //Max 20 pixels above the screen (bug: the purpose was probably one screen above the screen)
                  ($newY <= ($this->BITMAP_Y << 4) + (self::screen_height << 4) + self::GESTION_Y)) { //Max 20 pixels below the screen
                    $player->sprite2->y = $newY;
                } else {
                    $player->sprite2->enabled = false;
                    $this->DROP_FLAG = false;
                }
            } else {
                $player->sprite2->enabled = false;
                $this->DROP_FLAG = false;
            }
        } elseif ($this->CARRY_FLAG) { //Place the object on top of or beside the player
            if (!$this->LADDER_FLAG && (($this->LAST_ORDER === 16 + 5) || ($this->LAST_ORDER === 16 + 7))) { //Kneestand or take
                $player->sprite2->y = $player->sprite->y - 4;
                if ($player->sprite->flipped) {
                    $player->sprite2->x = $player->sprite->x - 10;
                } else {
                    $player->sprite2->x = $player->sprite->x + 12;
                }
            } else {
                if (($player->sprite->number === 14) ||  //Sliding down the ladder OR
                  ((($this->LAST_ORDER & 0x0F) !== 7) &&  //Not taking
                  (($this->LAST_ORDER & 0x0F) !== 8))) { //Not throwing/dropping
                    $player->sprite2->x = $player->sprite->x + 2;
                    if (($player->sprite->number === 23) || //Climbing (c)
                      ($player->sprite->number === 24)) { //Climbing (c) (2nd sprite)
                        $player->sprite2->x -= 10;
                        if ($player->sprite->flipped) {
                            $player->sprite2->x += 18;
                        }
                    }
                    $player->sprite2->y = $player->sprite->y - $player->sprite->spritedata->collheight + 1;
                }
            }
        }
        if ($this->SEECHOC_FLAG !== 0) {
            $this->SEECHOC_FLAG--;
            if ($this->SEECHOC_FLAG === 0) {
                $player->sprite2->enabled = false;
            }
        }

        //Part 5: decrease the timers
        $this->subto0($this->INVULNERABLE_FLAG);
        $this->subto0($this->RESETLEVEL_FLAG);
        $this->subto0($this->TAPISFLY_FLAG);
        $this->subto0($this->CROSS_FLAG);
        $this->subto0($this->GRAVITY_FLAG);
        $this->subto0($this->FURTIF_FLAG);
        $this->subto0($this->KICK_FLAG);
        if ($player->sprite->speedY === 0) {
            $this->subto0($this->CHOC_FLAG);
        }
        if (($player->sprite->speedX === 0) && ($player->sprite->speedY === 0)) {
            $this->KICK_FLAG = 0;
        }
        $this->subto0($this->FUME_FLAG); //Smoke when object hits the floor
        if (($this->FUME_FLAG !== 0) && (($this->FUME_FLAG & 0x03) === 0)) {
            //Smoke animation
            $this->updatesprite($level, $player->sprite2, $player->sprite2->number + 1, false);
            if ($player->sprite2->number === self::FIRST_OBJET + 19) {
                //Remove smoke
                $player->sprite2->enabled = false;
                $this->FUME_FLAG = 0;
            }
        }
        return 0;
    }

    public function CASE_DEAD_IM(Level $level): void
    {
        //Kill the player immediately (spikes/water/flames etc.
        //Sets RESET_FLAG to 2, in opposite to being killed as a result of 0 HP (then RESET_FLAG is 10)
        $this->DEC_LIFE($level);
        $this->RESETLEVEL_FLAG = 2;
    }

    public function DEC_LIFE(Level $level): void
    {
        //Kill the player, check for gameover, hide the energy bar
        $this->RESETLEVEL_FLAG = 10;
        $this->BAR_FLAG = 0;
        if ($level->lives === 0) {
            $this->GAMEOVER_FLAG = true;
        }
    }

    public function t_pause(Level $level): \Generator
    {
        $player = $level->player;
        $tmp = new Sprite();

        $this->TFR_SCREENM(); //Draw tiles
        $this->copysprite($level, $tmp, $player->sprite);
        $this->updatesprite($level, $player->sprite, 29, true); //Pause tile
        $this->DISPLAY_SPRITES($level); //Draw sprites
        yield from $this->flip_screen(true); //Display it
        $this->copysprite($level, $player->sprite, $tmp); //Reset player sprite
        do {
            yield 0; // titus_sleep()
            foreach ($this->pollEvents() as [$type, $key]) { //Check all events
                if ($type === 'down') {
                    if ($key === self::KEY_ESC) {
                        return self::TITUS_ERROR_QUIT;
                    } elseif ($key === self::KEY_MUSIC) {
                        $this->musicKey($key);
                    } elseif ($key === self::KEY_P) {
                        return 0;
                    }
                }
            }
        } while (true);
    }

    public function BRK_COLLISION(Level $level): \Generator
    { //Collision detection between player and tiles/objects/elevators
        //Point the foot on the block!
        $player = $level->player;

        $tileX = self::u8($player->sprite->x >> 4);
        $tileY = ($player->sprite->y >> 4) - 1;
        $initY = $tileY;

        //if too low then die!
        if (($player->sprite->y > (($level->height + 1) << 4)) && !$this->NOCLIP) {
            $this->CASE_DEAD_IM($level);
        }

        //Test under the feet of the hero and on his head! (In y)
        $this->YFALL = 0;
        //Find the left tile
        //colltest can be 0 to 15 +- 8 (-1 to -8 will change into 255 to 248)
        $colltest = $player->sprite->x & 0x0F;
        if ($colltest < self::TEST_ZONE) {
            $colltest += 256;
            $tileX = self::u8($tileX - 1);
        }
        $colltest -= self::TEST_ZONE;

        $left_tileX = $tileX;
        yield from $this->TAKE_BLK_AND_YTEST($level, $tileY, $tileX); //Test the tile for vertical blocking
        if ($this->YFALL === 1) { //Have the fall stopped?
            //No! Is it necessary to test the right tile?
            $colltest += self::TEST_ZONE * 2; //4 * 2
//          if (colltest > 255) {
//              colltest -= 256;
//              tileX++;
//          } elseif
            if ($colltest > 15) {
                $tileX = self::u8($tileX + 1);
            }
            if ($tileX !== $left_tileX) {
                yield from $this->TAKE_BLK_AND_YTEST($level, $tileY, $tileX); //Also test the left tile
            }
            if ($this->YFALL === 1) {
                if (($this->CROSS_FLAG === 0) && ($this->CHOC_FLAG === 0)) {
                    $this->COLLISION_TRP($level); //Player versus elevators
                    if ($this->YFALL === 1) {
                        $this->COLLISION_OBJET($level); //Player versus objects
                        if ($this->YFALL === 1) {
                            $this->ARAB_TOMBE($level); //No wall/elevator/object under the player; fall down!
                        } else {
                            $player->GLISSE = 0;
                        }
                    }
                } else {
                    $this->ARAB_TOMBE($level); //Fall down!
                }
            }
        }

        //How will the player move in X?
        $changeX = self::TEST_ZONE + self::MAX_X; //4 + 4 ??? + max_speed_x
        if ($player->sprite->speedX < 0) {
            $changeX = 0 - $changeX;
        } elseif ($player->sprite->speedX === 0) {
            $changeX = 0;
        }

        //Test the hero (in x)
        $height = $player->sprite->spritedata->collheight;
        if (($player->sprite->y > self::MAP_LIMIT_Y + 1) && ($initY >= 0) && ($initY < $level->height)) {
            $tileX = self::u8(($player->sprite->x + $changeX) >> 4);
            $tileY = $initY;
            $first = true;
            do {
                $hflag = $this->get_horizflag($level, $tileY, $tileX);
                if ($first) {
                    yield from $this->BLOCK_XXPRG($level, $hflag, self::u8($tileY), $tileX);
                    $first = false;
                } elseif (($hflag === self::HFLAG_CODE) || ($hflag === self::HFLAG_BONUS)) { //level code or HP
                    yield from $this->BLOCK_XXPRG($level, $hflag, self::u8($tileY), $tileX);
                }
                if ($tileY === 0) {
                    return;
                }
                $tileY--;
                $height -= 16;
            } while ($height > 0);
        }
    }

    private function TAKE_BLK_AND_YTEST(Level $level, int $tileY, int $tileX): \Generator
    {
        //Test the current tile for vertical blocking

        $player = $level->player;
        $this->POCKET_FLAG = false;
        $this->PRIER_FLAG = false;
        $this->LADDER_FLAG = false;
        if (($player->sprite->y <= self::MAP_LIMIT_Y) || ($tileY < -1)) { //if player is too high (<= -1), skip test
            $this->ARAB_TOMBE($level);
            $this->YFALL = 0xFF;
            return;
        }
        if ($tileY + 1 >= $level->height) { //if player is too low, skip test
            $this->ARAB_TOMBE($level);
            $this->YFALL = 0xFF;
            return;
        }
        if ($tileY === -1) { //In order to fall down in the right chamber if jumping above level 8
            $tileY = 0;
        }
        $floor = $this->get_floorflag($level, $tileY + 1, $tileX);
        $floor_above = $this->get_floorflag($level, $tileY, $tileX);

        if (($this->LAST_ORDER & 0x0F) !== 2) { //2=SAUTER
            yield from $this->BLOCK_YYPRG($level, $floor, $floor_above, self::u8($tileY + 1), $tileX); //Player versus floor
        }
        //Test the tile on his head
        if (($tileY < 1) || ($player->sprite->speedY > 0)) {
            return;
        }
        $horiz = $this->get_horizflag($level, $tileY, $tileX);
        $ceil = $this->get_ceilflag($level, $tileY - 1, $tileX);
        $this->BLOCK_YYPRGD($level, $ceil, self::u8($tileY - 1), $tileX); //Player versus ceiling

        if ((($horiz === self::HFLAG_WALL) || ($horiz === self::HFLAG_DEADLY) || ($horiz === self::HFLAG_PADLOCK)) && //Step on a hard tile?
          ($player->sprite->y > self::MAP_LIMIT_Y + 1)) {
            if ($player->sprite->speedX > 0) {
                $change = -1;
            } else {
                $change = 1;
            }
            $tileX = self::u8($tileX + $change);
            $horiz = $this->get_horizflag($level, $tileY, $tileX);
            if ($horiz === 0) { //No wall
                $player->sprite->x += $change << 1;
            } else {
                $change = 0 - $change;
                $tileX = self::u8($tileX + $change + $change);
                $horiz = $this->get_horizflag($level, $tileY, $tileX);
                if ($horiz === 0) {
                    $player->sprite->x += $change << 1;
                }
            }
        }
    }

    private function BLOCK_YYPRGD(Level $level, int $ceil, int $tileY, int $tileX): void
    {
        $player = $level->player;
        //Action on different ceiling flags
        switch ($ceil) {

            case 0: //No ceiling
                break;

            case 1: //Ceiling
            case 4: //Deadly
                if (($ceil === 4) && !$this->GODMODE) {
                    $this->CASE_DEAD_IM($level);
                } elseif ($player->sprite->speedY !== 0) {
                    //Stop movement
                    $player->sprite->speedY = 0;
                    $player->sprite->y = self::s16(($player->sprite->y & 0xFFF0) + 16);
                    $this->SAUT_COUNT = 0xFF;
                } elseif (($player->sprite->number !== 10) && //10 = Free fall
                  ($player->sprite->number !== 21) && //21 = Free fall (c)
                  ($this->SAUT_FLAG !== 6)) { //
                    $this->PRIER_FLAG = true;
                    if ($this->CARRY_FLAG) {
                        $object = $this->FORCE_POSE($level);
                        if ($object !== null) {
                            $tileX = self::u8($object->sprite->x >> 4);
                            $tileY = self::u8($object->sprite->y >> 4);
                            $hflag = $this->get_horizflag($level, $tileY, $tileX);
                            if (($hflag === self::HFLAG_WALL) || ($hflag === self::HFLAG_DEADLY) || ($hflag === self::HFLAG_PADLOCK)) {
                                $tileX = self::u8($tileX - 1);
                                $hflag = $this->get_horizflag($level, $tileY, $tileX);
                                if (($hflag === self::HFLAG_WALL) || ($hflag === self::HFLAG_DEADLY) || ($hflag === self::HFLAG_PADLOCK)) {
                                    $object->sprite->x += 16;
                                } else {
                                    $object->sprite->x -= 16;
                                }
                            }
                        }
                    }
                }
                break;

            case 2: //Ladder
                if (($player->sprite->speedY < 0) && ($player->sprite->speedX === 0)) {
                    $this->SAUT_COUNT = 10;
                    $this->LADDER_FLAG = true;
                }
                break;

            case 3: //Padlock
                $this->CASE_SECU($level, $tileY, $tileX);
                break;

            //case 4: on case 1
        }
    }

    private function BLOCK_XXPRG(Level $level, int $horiz, int $tileY, int $tileX): \Generator
    {
        //Action on different horizontal flags
        switch ($horiz) {
            case 0: //No wall
                break;

            case 1: //Wall
                $this->ARAB_BLOCKX($level);
                break;

            case 2: //HP
                $this->CASE_BONUS($level, $tileY, $tileX);
                break;

            case 3: //Unknown, looks deadly
                if (!$this->GODMODE) {
                    $this->CASE_DEAD_IM($level);
                } else {
                    $this->ARAB_BLOCKX($level); //Godmode: wall
                }
                break;

            case 4: //Level code
                yield from $this->CASE_PASS($level, $level->levelnumber, $tileY, $tileX);
                break;

            case 5: //Padlock
                $this->CASE_SECU($level, $tileY, $tileX);
                break;

            case 6: //Level 14 code
                yield from $this->CASE_PASS($level, 14 - 1, $tileY, $tileX);
                break;
        }
    }

    public function ARAB_BLOCKX(Level $level): void
    {
        $player = $level->player;
        //Horizontal hit (wall), stop the player
        $player->sprite->x -= $player->sprite->speedX >> 4;
        $player->sprite->speedX = 0;
        if (($this->KICK_FLAG !== 0) && ($this->SAUT_FLAG !== 6)) {
            $this->CHOC_FLAG = 20;
            $this->KICK_FLAG = 0;
        }
    }

    public function FORCE_POSE(Level $level): ?TitusObject
    {
        //Move object from sprite2 to TMPFP
        $sprite2 = $level->player->sprite2;
        if (($sprite2->enabled) && ($this->CARRY_FLAG)) {
            //Drop the carried object
            $i = 0;
            do {
                // C: (i > level->objectcount); at i == objectcount the C code reads past the array
                if ($i >= $level->objectcount) {
                    // printf("ERROR: FORCE_POSE returned NULL\n");
                    return null;
                }
                if (!($level->object[$i]->sprite->enabled)) {
                    break;
                }
                $i++;
            } while (true);
            $object = $level->object[$i];
            $this->updateobjectsprite($level, $object, $sprite2->number, true);
            $sprite2->enabled = false;
            $object->sprite->killing = false;
            if ($object->sprite->number < self::FIRST_NMI) {
                $object->sprite->droptobottom = false;
            } else {
                $object->sprite->droptobottom = true;
            }
            $object->sprite->x = $sprite2->x;
            $object->sprite->y = $sprite2->y;
            $object->mass = 0;
            $object->sprite->speedY = 0;
            $object->sprite->speedX = 0;
            $object->sprite->UNDER = 0;
            $object->sprite->ONTOP = null;
            $this->POSEREADY_FLAG = true;
            $this->GRAVITY_FLAG = 4;
            $this->CARRY_FLAG = false;
            return $object;
        }
        return null;
    }

    public function ARAB_TOMBE(Level $level): void
    {
        //No wall under the player; fall down!
        $player = $level->player;
        $this->SAUT_FLAG = 6;
        if ($this->KICK_FLAG !== 0) {
            return;
        }
        $this->XACCELERATION($player, self::MAX_X * 16);
        $this->YACCELERATION($player, self::MAX_Y * 16);
        if ($this->CHOC_FLAG !== 0) {
            $this->updatesprite($level, $player->sprite, 15, true); //sprite when hit
        } elseif (!$this->CARRY_FLAG) {
            $this->updatesprite($level, $player->sprite, 10, true); //position while falling  (jump sprite?)
        } else {
            $this->updatesprite($level, $player->sprite, 21, true); //position falling and carry  (jump and carry sprite?)
        }
        $player->sprite->flipped = ($this->SENSX < 0);
    }

    private function XACCELERATION(Player $player, int $maxspeed): void
    {
        //Sideway acceleration
        if ($this->X_FLAG) {
            $changeX = ($this->SENSX << 4) >> $player->GLISSE;
        } else {
            $changeX = 0;
        }

        if ($player->sprite->speedX + $changeX >= $maxspeed) {
            $player->sprite->speedX = $maxspeed;
        } elseif ($player->sprite->speedX + $changeX <= 0 - $maxspeed) {
            $player->sprite->speedX = 0 - $maxspeed;
        } else {
            $player->sprite->speedX += $changeX;
        }
    }

    private function YACCELERATION(Player $player, int $maxspeed): void
    {
        //Accelerate downwards
        if (($player->sprite->speedY + intdiv(32, 2)) < $maxspeed) {
            $player->sprite->speedY = $player->sprite->speedY + intdiv(32, 2);
        } else {
            $player->sprite->speedY = $maxspeed;
        }
    }

    private function BLOCK_YYPRG(Level $level, int $floor, int $floor_above, int $tileY, int $tileX): \Generator
    {
        //Action on different floor flags
        $player = $level->player;
        switch ($floor) {

            case 0: //No floor
                $this->ARAB_TOMBE_F();
                break;

            case 1: //Floor
                $this->ARAB_BLOCK_YU($player);
                break;

            case 2: //Slightly slippery floor
                $this->ARAB_BLOCK_YU($player);
                $player->GLISSE = 1;
                break;

            case 3: //Slippery floor
                $this->ARAB_BLOCK_YU($player);
                $player->GLISSE = 2;
                break;

            case 4: //Very slippery floor
                $this->ARAB_BLOCK_YU($player);
                $player->GLISSE = 3;
                break;

            case 5: //Drop-through if kneestanding
                $player->GLISSE = 0;
                if ($this->CROSS_FLAG === 0) {
                    $this->ARAB_BLOCK_YU($player);
                } else {
                    $this->ARAB_TOMBE_F(); //Drop through!
                }
                break;

            case 6: //Ladder
                //Fall if hit
                //Skip if walking/crawling
                if ($this->CHOC_FLAG !== 0) {
                    $this->ARAB_TOMBE_F(); //Free fall
                    return;
                }
                $order = $this->LAST_ORDER & 0x0F;
                if (($order === 1) ||
                  ($order === 3) ||
                  ($order === 7) ||
                  ($order === 8)) {
                    $this->ARAB_BLOCK_YU($player); //Stop fall
                    return;
                }
                if ($order === 5) { // action baisse
                    $this->ARAB_TOMBE_F(); //Free fall
                    $this->updatesprite($level, $player->sprite, 14, true); //sprite: start climbing down
                    $player->sprite->y += 8;
                }
                if ($floor_above !== 6) { //ladder
                    if ($order === 0) { //action repos
                        $this->ARAB_BLOCK_YU($player); //Stop fall
                        return;
                    }
                    if (($this->keystate(self::KEY_UP) || $this->keystate(self::KEY_JUMP)) && ($order === 6)) { //action UP + climb ladder
                        $this->ARAB_BLOCK_YU($player); //Stop fall
                        return;
                    }
                }

                $this->subto0($this->SAUT_FLAG);
                $this->SAUT_COUNT = 0;
                $this->YFALL = 2;

                $this->LADDER_FLAG = true;
                break;

            case 7:
                $this->CASE_BONUS($level, $tileY, $tileX);
                break;

            case 8:
            case 9:
            case 10:
                if (!$this->GODMODE) {
                    $this->CASE_DEAD_IM($level);
                } else {
                    $this->ARAB_BLOCK_YU($player); //If godmode; ordinary floor
                }
                break;

            case 11:
                yield from $this->CASE_PASS($level, $level->levelnumber, $tileY, $tileX);
                break;

            case 12:
                $this->CASE_SECU($level, $tileY, $tileX);
                break;

            case 13:
                yield from $this->CASE_PASS($level, 14 - 1, $tileY, $tileX);
                break;
        }
    }

    public function ARAB_TOMBE_F(): void
    {
        //Player free fall (doesn't touch floor)
        $this->YFALL = $this->YFALL | 0x01;
    }

    public function ARAB_BLOCK_YU(Player $player): void
    {
        //Floor; the player will not fall through
        $this->POCKET_FLAG = true;
        $player->GLISSE = 0;
        if ($player->sprite->speedY < 0) {
            $this->YFALL = $this->YFALL | 0x01;
            return;
        }
        $player->sprite->y = self::s16($player->sprite->y & 0xFFF0);
        $player->sprite->speedY = 0;
        $this->subto0($this->SAUT_FLAG);
        $this->SAUT_COUNT = 0;
        $this->YFALL = 2;
    }

    private function CASE_BONUS(Level $level, int $tileY, int $tileX): bool
    {
        //Handle bonuses. Increase energy if HP, and change the bonus tile to normal tile
        $i = 0;
        do { //Is the bonus in the list?
            if ($i >= self::MAXIMUM_BONUS) {
                return false; //Bonus not found
            }
            $i++;
        } while (($level->bonus[$i - 1]->x !== $tileX) || ($level->bonus[$i - 1]->y !== $tileY));
        $i--;
        //If the bonus is 253-255, it's HP. Increase life!
        if ($level->bonus[$i]->bonustile >= 255 - 2) {
            //Increase HP if tile number is >= 253
            $this->SELECT_MUSIC(6);
            $this->INC_ENERGY($level);
        }
        //Return the original tile underneath the bonus
        $level->tilemap[$tileY][$tileX] = $level->bonus[$i]->replacetile;

        $this->DISPLAY_CHAR($level, $level->bonus[$i]->replacetile, $tileY % self::screen_height, $tileX % self::screen_width);
        $this->GRAVITY_FLAG = 4;
        $this->PERMUT_FLAG = true;
        return true; //No problems, bonus handling done correctly!
    }

    private function CASE_PASS(Level $level, int $level_index, int $tileY, int $tileX): \Generator
    {
        //Codelamp
        $this->SELECT_MUSIC(7);
        if ($this->CASE_BONUS($level, $tileY, $tileX)) { //if the bonus is found in the bonus list
            yield from $this->view_password($level, $level_index);
        }
    }

    private function CASE_SECU(Level $level, int $tileY, int $tileX): void
    {
        $player = $level->player;
        //Padlock, store X/Y coordinates
        $this->SELECT_MUSIC(5);
        if ($this->CASE_BONUS($level, $tileY, $tileX)) { //if the bonus is found in the bonus list
            $player->initX = $player->sprite->x;
            $player->initY = $player->sprite->y;
            if (($player->sprite2->number === self::FIRST_OBJET + 26) || ($player->sprite2->number === self::FIRST_OBJET + 27)) {
                //If carrying the cage, save the position
                $player->cageX = $player->sprite->x;
                $player->cageY = $player->sprite->y;
            }
        }
    }

    public function INC_ENERGY(Level $level): void
    {
        $player = $level->player;
        $this->BAR_FLAG = 50;
        $player->hp++;
        if ($player->hp > self::MAXIMUM_ENERGY) {
            $player->hp = self::MAXIMUM_ENERGY;
            $level->extrabonus++;
        }
    }

    public function DEC_ENERGY(Level $level): void
    {
        $player = $level->player;
        $this->BAR_FLAG = 50;
        if ($this->RESETLEVEL_FLAG === 0) {
            $player->hp--;
            if ($player->hp < 0) {
                $player->hp = 0;
                $this->DEC_LIFE($level);
            }
        }
    }

    private function ACTION_PRG(Level $level, int $action): void
    {
        //Action dependent code
        $player = $level->player;

        switch ($action) {
            case 0:
            case 9:
            case 16:
                //Rest. Handle deacceleration and slide
                $this->LAST_ORDER = $action;
                $this->DECELERATION($player);
                if ((abs($player->sprite->speedX) >= 1 * 16) &&
                   ($player->sprite->flipped === ($player->sprite->speedX < 0))) {
                    $player->sprite->setAnimation($this->anim_player[4 + $this->add_carry()]);
                } else {
                    $player->sprite->setAnimation($this->anim_player[$action]);
                }
                $this->updatesprite($level, $player->sprite, $player->sprite->animValue(), true);
                $player->sprite->flipped = ($this->SENSX < 0);
                break;

            case 1:
            case 17:
            case 19:
                //Handle walking
                $this->XACCELERATION($player, self::MAX_X * 16);
                $this->NEW_FORM($player, $action); //Update last order and action (animation)
                $this->GET_IMAGE($level); //Update player sprite
                break;

            case 2:
            case 18:
                //Handle a jump
                if ($this->SAUT_COUNT >= 3) {
                    $this->SAUT_FLAG = 6; //Stop jump animation and acceleration
                } else {
                    $this->SAUT_COUNT++;
                    $this->YACCELERATION_NEG($player, intdiv(self::MAX_Y * 16, 4));
                    $this->XACCELERATION($player, self::MAX_X * 16);
                    $this->NEW_FORM($player, $action);
                    $this->GET_IMAGE($level);
                }
                break;

            case 3:
                //Handle crawling
                $this->NEW_FORM($player, $action);
                $this->GET_IMAGE($level);
                $this->XACCELERATION($player, intdiv(self::MAX_X * 16, 2));
                if (abs($player->sprite->speedX) < (2 * 16)) {
                    $this->updatesprite($level, $player->sprite, 6, true); //Crawling but not moving
                    $player->sprite->flipped = ($this->SENSX < 0);
                }
                break;

            case 4:
            case 14:
            case 15:
            case 20:
            case 25:
            case 26:
                //No action?
                break;

            case 5:
                //Kneestand
                $this->NEW_FORM($player, $action);
                $this->GET_IMAGE($level);
                $this->DECELERATION($player);
                if ($this->ACTION_TIMER === 15) {
                    $this->CROSS_FLAG = 6;
                    $player->sprite->speedY = 0;
                }
                break;

            case 6:
            case 22:
                //Climb a ladder
                if ($this->X_FLAG) {
                    $this->XACCELERATION($player, self::MAX_X * 16);
                } else {
                    $this->DECELERATION($player);
                }
                if ($this->ACTION_TIMER <= 1) {
                    if (!$this->CARRY_FLAG) {
                        $this->updatesprite($level, $player->sprite, 12, true); //Last climb sprite
                    } else {
                        $this->updatesprite($level, $player->sprite, 23, true); //First climb sprite (c)
                    }
                }
                if ($this->Y_FLAG) {
                    $this->NEW_FORM($player, 6 + $this->add_carry()); //Action: Climb
                    $this->GET_IMAGE($level);
                    //Place the player on the ladder
                    $player->sprite->x = self::s16(($player->sprite->x & 0xFFF0) + 8);
                    $tileX = self::u8(($player->sprite->x) >> 4);
                    $tileY = self::u8(($player->sprite->y & 0xFFF0) >> 4);
                    if ($this->get_floorflag($level, $tileY, $tileX) !== self::FFLAG_LADDER) {

                        if ($this->get_floorflag($level, $tileY, $tileX - 1) === self::FFLAG_LADDER) {
                            $player->sprite->x -= 16;
                        } elseif ($this->get_floorflag($level, $tileY, $tileX + 1) === self::FFLAG_LADDER) {
                            $player->sprite->x += 16;
                        }
                    }
                    if (!$this->keystate(self::KEY_UP) && !$this->keystate(self::KEY_JUMP)) {
                        $player->sprite->speedY = 4 * 16;
                    } else {
                        $player->sprite->speedY = 0 - (4 * 16);
                    }
                } else {
                    $player->sprite->speedY = 0;
                }
                break;

            case 7:
            case 23:
                //Take a box
                $this->NEW_FORM($player, $action);
                $this->GET_IMAGE($level);
                $this->DECELERATION($player);
                if (!$this->POSEREADY_FLAG) {
                    if (($this->ACTION_TIMER === 1) && ($this->CARRY_FLAG)) {
                        //If the object is placed in a block, fix a speedX
                        $object = $this->FORCE_POSE($level);
                        if ($object !== null) {
                            $tileX = self::u8($object->sprite->x >> 4);
                            $tileY = self::u8($object->sprite->y >> 4);
                            $fflag = $this->get_floorflag($level, $tileY, $tileX);
                            if (($fflag !== self::FFLAG_NOFLOOR) && ($fflag !== self::FFLAG_WATER)) { //this test is really floor flag & 0x07
                                $tileX = self::u8($tileX + 1);
                                $fflag = $this->get_floorflag($level, $tileY, $tileX);
                                if (($fflag !== self::FFLAG_NOFLOOR) && ($fflag !== self::FFLAG_WATER)) {
                                    $object->sprite->speedX = 16 * 3;
                                } else {
                                    $object->sprite->speedX = 0 - (16 * 3);
                                }
                            }
                        }
                    } else {
                        if (!$this->CARRY_FLAG) {
                            $tmpY = $player->sprite->y;
                            $tmpX = $player->sprite->x;
                            for ($i = 0; $i < $level->objectcount; $i++) {
                                //First do a quick test
                                if (!($level->object[$i]->sprite->enabled) ||
                                  (abs($player->sprite->y - $level->object[$i]->sprite->y) >= 20)) {
                                    continue;
                                }
                                $diffX = $player->sprite->x - $level->object[$i]->sprite->x;
                                if (!$player->sprite->flipped) {
                                    $diffX = 0 - $diffX;
                                }
                                //Moktar
                                if ($diffX >= 25) {
                                    continue;
                                }
                                //The ordinary test
                                //X
/*
                                if (player->sprite.x > player->sprite.x) { //Bug: the first expression should have been level->object[i]
                                    if (player->sprite.x + 32 < player->sprite.x) { //This will never execute
                                        continue;
                                    }
                                } else {
                                    if (player->sprite.x + level->object[i].sprite.spritedata->collwidth < player->sprite.x) {
                                        continue; //This should not execute
                                    }
                                }
*/

                                //What it probably should have been
                                if ($level->object[$i]->sprite->x > $player->sprite->x) { //The object is right
                                    if ($level->object[$i]->sprite->x > $player->sprite->x + 32) {
                                        continue; //The object is too far right
                                    }
                                } else { //The object is left
                                    if ($level->object[$i]->sprite->x + $level->object[$i]->sprite->spritedata->collwidth < $player->sprite->x) {
                                        continue; //The object is too far left
                                    }
                                }

                                //Y
                                if ($level->object[$i]->sprite->y < $player->sprite->y) { //The object is above
                                    if ($level->object[$i]->sprite->y <= $player->sprite->y - 10) {
                                        continue;
                                    }
                                } else { //The object is below
                                    if ($level->object[$i]->sprite->y - $level->object[$i]->sprite->spritedata->collheight + 1 >= $player->sprite->y) {
                                        continue;
                                    }
                                }

                                //Take the object
                                $this->FX_START(9); //Sound effect
                                $this->FUME_FLAG = 0;
                                $level->object[$i]->sprite->speedY = 0;
                                $level->object[$i]->sprite->speedX = 0;
                                $this->GRAVITY_FLAG = 4;
                                $this->copysprite($level, $player->sprite2, $level->object[$i]->sprite);
                                $level->object[$i]->sprite->enabled = false;
                                $this->CARRY_FLAG = true;
                                $this->SEECHOC_FLAG = 0;
                                if ($player->sprite2->number === self::FIRST_OBJET + 19) { //flying carpet
                                    $this->TAPISWAIT_FLAG = 0;
                                }
                                $player->sprite2->y = $player->sprite->y - 4;
                                if ($player->sprite->flipped) {
                                    $player->sprite2->x = $player->sprite->x - 10;
                                } else {
                                    $player->sprite2->x = $player->sprite->x + 12;
                                }

                                break;
                            }
                            if (!$this->CARRY_FLAG) { //No objects taken, check if he picks up an enemy!
                                for ($i = 0; $i < $level->enemycount; $i++) {
                                    //First do a quick test
                                    if ((!$level->enemy[$i]->sprite->enabled) ||
                                      (abs($player->sprite->y - $level->enemy[$i]->sprite->y) >= 20)) {
                                        continue;
                                    }
                                    $diffX = $player->sprite->x - $level->enemy[$i]->sprite->x;
                                    if (!$player->sprite->flipped) {
                                        $diffX = 0 - $diffX;
                                    }
                                    //Moktar
                                    if ($diffX >= 25) {
                                        continue;
                                    }
                                    //The ordinary test
                                    //X
                                    if ($level->enemy[$i]->carry_sprite === -1) { //Test if the enemy can be picked up from behind
                                        continue;
                                    }

                                 //   if (player->x > player->x) { //Bug: the first expression should have been enemy[i]
                                 //       if (player->x + 32 < player->x) { //This will never execute
                                 //           continue;
                                 //       }
                                 //   } else {
                                 //       if (player->x + enemy[i].sprite.spritedata->collwidth < player->x) {
                                 //           continue; //This should not execute
                                 //       }
                                 //   }

                                    //What it probably should have been
                                    if ($level->enemy[$i]->sprite->x > $player->sprite->x) { //The enemy is right
                                        if ($level->enemy[$i]->sprite->x > $player->sprite->x + 32) {
                                            continue; //The enemy is too far right
                                        }
                                    } else { //The object is left
                                        if ($level->enemy[$i]->sprite->x + $level->enemy[$i]->sprite->spritedata->collwidth < $player->sprite->x) {
                                            continue; //The enemy is too far left
                                        }
                                    }

                                    //Y
                                    if ($level->enemy[$i]->sprite->y < $player->sprite->y) { //The enemy is above
                                        if ($level->enemy[$i]->sprite->y <= $player->sprite->y - 10) {
                                            continue;
                                        }
                                    } else { //The enemy is below
                                        if ($level->enemy[$i]->sprite->y - $level->enemy[$i]->sprite->spritedata->collheight - 1 >= $player->sprite->y) {
                                            continue;
                                        }
                                    }

                                    if ($level->enemy[$i]->sprite->number >= self::FIRST_NMI) {
                                        $diffX = $player->sprite->x - $level->enemy[$i]->sprite->x;
                                        if ($level->enemy[$i]->sprite->flipped) {
                                            $diffX = 0 - $diffX;
                                        }
                                        if ($diffX < 0) {
                                            continue;
                                        }
                                    }

                                    $this->FX_START(9); //Sound effect
                                    $this->FUME_FLAG = 0;
                                    $level->enemy[$i]->sprite->speedY = 0;
                                    $level->enemy[$i]->sprite->speedX = 0;
                                    $this->GRAVITY_FLAG = 4;
                                    $player->sprite2->flipped = $level->enemy[$i]->sprite->flipped;
                                    $player->sprite2->flash = $level->enemy[$i]->sprite->flash;
                                    $player->sprite2->visible = $level->enemy[$i]->sprite->visible;
                                    $this->updatesprite($level, $player->sprite2, $level->enemy[$i]->carry_sprite, false);
                                    $level->enemy[$i]->sprite->enabled = false;
                                    $this->CARRY_FLAG = true;
                                    $this->SEECHOC_FLAG = 0;
                                    $player->sprite2->y = $player->sprite->y - 4;
                                    if ($player->sprite->flipped) {
                                        $player->sprite2->x = $player->sprite->x - 10;
                                    } else {
                                        $player->sprite2->x = $player->sprite->x + 12;
                                    }

                                    break;
                                } //for loop, enemy
                            } //condition (!CARRY_FLAG), check for enemy pickup
                        } //condition (!CARRY_FLAG), check for object/enemy pickup
                    } //condition ((ACTION_TIMER == 1) && (CARRY_FLAG)),
                } //condition (POSEREADY_FLAG == 0)
                $this->POSEREADY_FLAG = true;
                break;

            case 8:
            case 24:
                //Throw
                $this->NEW_FORM($player, $action);
                $this->GET_IMAGE($level);
                $this->DECELERATION($player);
                if ($this->CARRY_FLAG) {
                    if (!$this->keystate(self::KEY_UP) && !$this->keystate(self::KEY_JUMP)) { //Ordinary throw
                        $speedX = 0x0E * 16;
                        $speedY = 0;
                        if ($player->sprite->flipped) {
                            $speedX = 0 - $speedX;
                        }
                        $player->sprite2->y = $player->sprite->y - 16;
                    } else { //Throw up
                        $speedX = 0;
                        $speedY = 0 - 0x0A * 16;
                    }
                    if ($speedY !== 0) {
                        //Throw up
                        $object = $this->FORCE_POSE($level);
                        if ($object !== null) {
                            $object->sprite->speedY = $speedY;
                            $object->sprite->speedX = $speedX - ($speedX >> 2);
                        }
                    } else {
                        if ($player->sprite2->number < self::FIRST_NMI) { //level->objectdata can only be tested against an object sprite
                            if ($level->objectdata[$player->sprite2->number - self::FIRST_OBJET]->gravity) {
                                //Gravity throw
                                $object = $this->FORCE_POSE($level);
                                if ($object !== null) {
                                    $object->sprite->speedY = $speedY;
                                    $object->sprite->speedX = $speedX - ($speedX >> 2);
                                }
                            } else { //Ordinary throw
                                $this->DROP_FLAG = true;
                                $player->sprite2->speedX = $speedX;
                                $player->sprite2->speedY = $speedY;
                                $this->FX_START(3); //Sound effect
                            }
                        } else { //Ordinary throw
                            $this->DROP_FLAG = true;
                            $player->sprite2->speedX = $speedX;
                            $player->sprite2->speedY = $speedY;
                            $this->FX_START(3); //Sound effect
                        }
                    }
                    $this->updatesprite($level, $player->sprite, 10, true); //The same as in free fall
                    $player->sprite->flipped = ($this->SENSX < 0);
                    $this->CARRY_FLAG = false;
                }
                break;

            //case 9: same as case 0

            case 10:
                //Silent walk?
                $this->XACCELERATION($player, (self::MAX_X - 1) * 16);
                $this->NEW_FORM($player, $action);
                $this->GET_IMAGE($level);
                break;

            case 11:
                //Headache
                $player->sprite->speedX = 0;
                $this->NEW_FORM($player, $action);
                $this->GET_IMAGE($level);
                break;

            case 12:
            case 13:
            case 28:
            case 29:
                //Hit
                $this->YACCELERATION($player, self::MAX_Y * 16);
                $this->NEW_FORM($player, $action);
                $this->GET_IMAGE($level);
                break;

            //case 14, 15: //No action

            //case 16: same as case 0

            //case 17: same as case 1

            //case 18: same as case 2

            //case 19: same as case 1

            //case 20: //No action

            case 21:
                //Kneestand with box
                $this->NEW_FORM($player, $action);
                $this->GET_IMAGE($level);
                $this->DECELERATION($player);
                break;

            //case 22: same as case 6

            //case 23: same as case 7

            //case 24: same as case 8

            //case 25, 26: //No action

            case 27:
                //Headache (c)
                $this->FORCE_POSE($level);
                $player->sprite->speedX = 0;
                $this->NEW_FORM($player, $action);
                $this->GET_IMAGE($level);
                break;

            //case 28, 29: same sa case 12
        }
    }

    public function DECELERATION(Player $player): void
    {
        //Stop acceleration
        $friction = self::u8((3 * 4) >> $player->GLISSE);
        if ($player->sprite->speedX < 0) {
            $speed = $player->sprite->speedX + $friction;
            if ($speed > 0) {
                $speed = 0;
            }
        } else {
            $speed = $player->sprite->speedX - $friction;
            if ($speed < 0) {
                $speed = 0;
            }
        }
        $player->sprite->speedX = $speed;
    }

    private function NEW_FORM(Player $player, int $action): void
    {
        //if the order is changed, change player animation
        if (($this->LAST_ORDER !== $action) || ($player->sprite->animTable === null)) {
            $this->LAST_ORDER = $action;
            $player->sprite->setAnimation($this->anim_player[$action]);
        }
    }

    private function GET_IMAGE(Level $level): void
    {
        $player = $level->player;
        //animate the player sprite
        $frame = $player->sprite->animValue();
        if ($frame < 0) { //frame is a negative number, telling how many bytes to jump back
            $player->sprite->anim += intdiv($frame, 2); //jump back to first frame of animation
            $frame = $player->sprite->animValue();
        }
        $this->updatesprite($level, $player->sprite, $frame, true);
        $player->sprite->flipped = ($this->SENSX < 0);
        $player->sprite->anim++; //Advance in the animation
    }

    private function YACCELERATION_NEG(Player $player, int $maxspeed): void
    {
        //Accelerate upwards
        $maxspeed = 0 - $maxspeed; //maxspeed is negative
        $speed = $player->sprite->speedY - 32;
        if ($speed >= $maxspeed) {
            $speed = $maxspeed;
        }
        $player->sprite->speedY = $speed;
    }

    public function add_carry(): int
    {
        if ($this->CARRY_FLAG) {
            return 16;
        } else {
            return 0;
        }
    }

    public function COLLISION_TRP(Level $level): void
    {
        //Player versus elevators
        //Change player's location according to the elevator
        $player = $level->player;
        $elevator = $level->elevator;
        if (($player->sprite->speedY >= 0) && ($this->CROSS_FLAG === 0)) {
            for ($i = 0; $i < $level->elevatorcount; $i++) {
                //Quick test
                if (!($elevator[$i]->enabled) ||
                  !($elevator[$i]->sprite->visible) ||
                  (abs($elevator[$i]->sprite->x - $player->sprite->x) >= 64) ||
                  (abs($elevator[$i]->sprite->y - $player->sprite->y) >= 16)) {
                    continue;
                }

                //Real test
                if ($player->sprite->x - $level->spritedata[0]->refwidth < $elevator[$i]->sprite->x) { //The elevator is right
                    if ($player->sprite->x - $level->spritedata[0]->refwidth + $level->spritedata[0]->collwidth <= $elevator[$i]->sprite->x) { //player->sprite must be 0
                        continue; //The elevator is too far right
                    }
                } else { //The elevator is left
                    if ($player->sprite->x - $level->spritedata[0]->refwidth >= $elevator[$i]->sprite->x + $elevator[$i]->sprite->spritedata->collwidth) {
                        continue; //The elevator is too far left
                    }
                }

                if ($player->sprite->y - 6 < $elevator[$i]->sprite->y) { //The elevator is below
                    if ($player->sprite->y - 6 + 8 <= $elevator[$i]->sprite->y) {
                        continue; //The elevator is too far below
                    }
                } else { //The elevator is above
                    if ($player->sprite->y - 6 >= $elevator[$i]->sprite->y + $elevator[$i]->sprite->spritedata->collheight) {
                        continue; //The elevator is too far above
                    }
                }

                //Skip fall-through-tile action (ACTION_TIMER == 15)
                if ($this->ACTION_TIMER === 14) {
                    $this->ACTION_TIMER = 16;
                }

                $this->YFALL = 0;
                $player->sprite->y = $elevator[$i]->sprite->y;

                $player->sprite->speedY = 0;
                $this->subto0($this->SAUT_FLAG);
                $this->SAUT_COUNT = 0;
                $this->YFALL = 2;

                $player->sprite->x += $elevator[$i]->sprite->speedX;
                if ($elevator[$i]->sprite->speedY > 0) { //Going down
                    $player->sprite->y += $elevator[$i]->sprite->speedY;
                }
                return;
            }
        }
    }

    public function COLLISION_OBJET(Level $level): void
    {
        //Player versus objects
        //Collision, spring state, speed up carpet/scooter/skateboard, bounce bouncy objects
        $player = $level->player;
        $off_object = null;
        if ($player->sprite->speedY < 0) {
            return;
        }
        //Collision with a sprite
        if (!($this->SPRITES_VS_SPRITES($level, $player->sprite, $level->spritedata[0], $off_object))) { //check if player stands on an object, use sprite[0] (rest) as collision size (first player tile)
            return;
        }
        $player->sprite->y = $off_object->sprite->y - $off_object->sprite->spritedata->collheight;
        //If the foot is placed on a spring, it must be soft!
        if (($off_object->sprite->number === self::FIRST_OBJET + 24) || ($off_object->sprite->number === self::FIRST_OBJET + 25)) {
            $off_object->sprite->UNDER = $off_object->sprite->UNDER | 0x02;
            $off_object->sprite->ONTOP = $player->sprite;
        }
        //If we jump on the flying carpet, let it fly
        if (($off_object->sprite->number === self::FIRST_OBJET + 21) || ($off_object->sprite->number === self::FIRST_OBJET + 22)) { //Flying carpet
            if (!($player->sprite->flipped)) {
                $off_object->sprite->speedX = 6 * 16;
            } else {
                $off_object->sprite->speedX = 0 - 6 * 16;
            }
            $off_object->sprite->flipped = $player->sprite->flipped;
            $this->GRAVITY_FLAG = 4;
            $this->TAPISWAIT_FLAG = 0;
        } elseif (($this->ACTION_TIMER > 10) && //delay
          (($this->LAST_ORDER & 0x0F) === 0) && //Player rests
          ($player->sprite->speedY === 0) && //stable Y
          (($off_object->sprite->number === 83) || //scooter
          ($off_object->sprite->number === 94))) { //skateboard

            //If you put your foot on a scooter or a skateboard
            if (!($player->sprite->flipped)) {
                $off_object->sprite->speedX = 16 * 3;
            } else {
                $off_object->sprite->speedX = 0 - 16 * 3;
            }
            $off_object->sprite->flipped = $player->sprite->flipped;
            $this->GRAVITY_FLAG = 4;
        }

        if ($off_object->sprite->speedX < 0) {
            $player->sprite->speedX = $off_object->sprite->speedX;
        } elseif ($off_object->sprite->speedX > 0) {
            $player->sprite->speedX = $off_object->sprite->speedX + 16;
        }

        //If we want to CROSS (cross) it does not bounce
        if (($this->CROSS_FLAG === 0) && //No long kneestand
          ($player->sprite->speedY > (16 * 3)) &&
          ($off_object->objectdata->bounce)) {
            //Bounce on a ball if no long kneestand (down key)
            if ($this->keystate(self::KEY_DOWN)) {
                $player->sprite->speedY = 0;
            } else {
                if ($this->keystate(self::KEY_UP) || $this->keystate(self::KEY_JUMP)) {
                    $player->sprite->speedY += 16 * 3; //increase speed
                } else {
                    $player->sprite->speedY -= 16; //reduce speed
                }
                $player->sprite->speedY = 0 - $player->sprite->speedY;
                if ($player->sprite->speedY > 0) {
                    $player->sprite->speedY = 0;
                }
            }
            $this->ACTION_TIMER = 0;

            //If the ball lies on the ground
            if ($off_object->sprite->speedY === 0) {
                $this->FX_START(12); //Sound effect
                $off_object->sprite->speedY = 0 - $player->sprite->speedY;
                $off_object->sprite->y -= $off_object->sprite->speedY >> 4;
                $this->GRAVITY_FLAG = 4;
            }
        } else {
            if ($off_object->sprite->speedY !== 0) {
                $player->sprite->speedY = $off_object->sprite->speedY;
            } else {
                $player->sprite->speedY = 0;
            }
            $this->subto0($this->SAUT_FLAG);
            $this->SAUT_COUNT = 0;
            $this->YFALL = 2;
        }
    }
}
