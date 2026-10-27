<?php

namespace App\Titus;

/**
 * gates.c: level completion, secret entrances (kneestand on a gate) and the screen open / close effects.
 */
trait GatesTrait
{
    /** Check and handle level completion, and if the player does a kneestand on a secret entrance */
    public function CROSSING_GATE(Level $level): \Generator
    {
        yield from $this->check_finish($level);
        yield from $this->check_gates($level);
    }

    private function check_finish(Level $level): \Generator
    {
        $player = $level->player;
        if ($this->boss_alive) { // There is still a boss that needs to be killed!
            return;
        }
        if ($level->levelid === 9) { // The level with a cage
            if ($player->sprite2->number !== self::FIRST_OBJET + 26 && $player->sprite2->number !== self::FIRST_OBJET + 27) {
                return;
            }
        }
        if (($player->sprite->x & 0xFFF0) !== $level->finishX && ($player->sprite->x & 0xFFF0) - 16 !== $level->finishX) {
            return;
        }
        if (($player->sprite->y & 0xFFF0) !== $level->finishY && ($player->sprite->y & 0xFFF0) - 16 !== $level->finishY) {
            return;
        }
        $this->SELECT_MUSIC(4);
        yield from $this->WAIT_SONG();
        yield from $this->CLOSE_SCREEN();
        $this->NEWLEVEL_FLAG = true;
    }

    private function check_gates(Level $level): \Generator
    {
        $player = $level->player;
        if ($this->CROSS_FLAG === 0 || $this->NEWLEVEL_FLAG) { // not kneestanding, or the level is finished
            return;
        }
        for ($i = 0; $i < $level->gatecount; $i++) {
            $gate = $level->gate[$i];
            if ($gate->exists && $gate->entranceX === ($player->sprite->x >> 4) && $gate->entranceY === ($player->sprite->y >> 4)) {
                break;
            }
        }
        if ($i === $level->gatecount) {
            return; // No matching gate
        }
        $gate = $level->gate[$i];
        $player->sprite->speedX = 0;
        $player->sprite->speedY = 0;
        yield from $this->CLOSE_SCREEN();
        $origXlimit = $this->XLIMIT & 0xFF; // uint8
        $this->XLIMIT = $level->width - self::screen_width;
        $player->sprite->x = $gate->exitX << 4;
        $player->sprite->y = $gate->exitY << 4;
        while ($this->BITMAP_Y < $gate->screenY && !$this->D_SCROLL($level)) {
        }
        while ($this->BITMAP_Y > $gate->screenY && !$this->U_SCROLL($level)) {
        }
        while ($this->BITMAP_X < $gate->screenX && !$this->R_SCROLL($level)) {
        }
        while ($this->BITMAP_X > $gate->screenX && !$this->L_SCROLL($level)) {
        }
        $this->XLIMIT = $origXlimit;
        $this->NOSCROLL_FLAG = $gate->noscroll;
        yield from $this->OPEN_SCREEN();
    }

    public function CLOSE_SCREEN(): \Generator
    {
        $stepCount = 10;
        $rwidth = 320;
        $rheight = 192;
        $incX = intdiv($rwidth, $stepCount * 2); // 16
        $incY = intdiv($rheight, $stepCount * 2); // 9
        for ($i = 0; $i < $stepCount; $i++) {
            yield from $this->flip_screen(false);
            $this->screen->fillRect(0, 0, self::screen_width * 16, $i * $incY, [0, 0, 0]); // top
            $this->screen->fillRect(0, 0, $i * $incX, self::screen_height * 16, [0, 0, 0]); // left
            $this->screen->fillRect(0, $rheight - $i * $incY, self::screen_width * 16, $i * $incY, [0, 0, 0]); // bottom
            $this->screen->fillRect($rwidth - $i * $incX, 0, $i * $incX, self::screen_height * 16, [0, 0, 0]); // right
        }
    }

    public function OPEN_SCREEN(): \Generator
    {
        $stepCount = 10;
        $blockX = intdiv(320, $stepCount * 2); // 16
        $blockY = intdiv(192, $stepCount * 2); // 9
        $this->TFR_SCREENM();
        $this->screen->fillRect(0, 0, 320, 192, [0, 0, 0]); // BLACK_SCREEN
        $j = $stepCount;
        for ($i = 2; $i <= $stepCount * 2; $i += 2) {
            $j--;
            yield from $this->flip_screen(false);
            $this->copytiles($j * $blockX, $j * $blockY, ($i * $blockX) - $blockX, $blockY); // Upper tiles
            $this->copytiles(($j * $blockX) + ($i * $blockX) - $blockX, $j * $blockY, $blockX, $i * $blockY); // Right tiles
            $this->copytiles(($j * $blockX) + $blockX, (($j + 1) * $blockY) + ($i * $blockY) - $blockY, ($i * $blockX) - $blockX, $blockY); // Bottom tiles
            $this->copytiles($j * $blockX, ($j * $blockY) + $blockY, $blockX, $i * $blockY); // Left tiles
        }
    }
}
