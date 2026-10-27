<?php

namespace App\Titus;

use JumPHP\Renderer\Renderer;
use JumPHP\Renderer\Sdl;

/**
 * The C "screen" surface (320x200) as a display list: the game draws into it while it runs (like the C
 * code draws into the SDL backbuffer), flip() presents it, render() replays what was presented.
 * Drawing over the whole screen (clear) restarts the list, so it stays short.
 */
final class Screen
{
    public const WIDTH = 320;
    public const HEIGHT = 200;

    /** @var array<int, array> commands being drawn */
    private array $list = [];
    /** @var array<int, array> commands of the last flip */
    private array $presented = [];
    /** @var array<int, array{0:int,1:int,2:int,3:int}> sprite rectangles in the 'sprites' atlas */
    public array $spriteRects = [];
    /** @var array<string, array{0:int,1:int,2:int,3:int}> glyph rectangles in the 'font' atlas, by character */
    public array $fontRects = [];
    /** Palette colour 14 of the textures of sprites (it changes with the level) */
    private ?array $spritePalette = null;

    public function clear(): void
    {
        $this->list = [['fill', 0, 0, self::WIDTH, self::HEIGHT, [0, 0, 0, 255]]];
    }

    /** SDL_FillRect; a full-screen fill restarts the list. */
    public function fillRect(int $x, int $y, int $w, int $h, array $color): void
    {
        if ($x <= 0 && $y <= 0 && $x + $w >= self::WIDTH && $y + $h >= self::HEIGHT && ($color[3] ?? 255) === 255) {
            $this->list = [];
        }
        $this->list[] = ['fill', $x, $y, $w, $h, $color + [3 => 255]];
    }

    /** A texture part: $src [x, y, w, h] at ($x, $y). */
    public function blit(string $texture, array $src, int $x, int $y, bool $flip = false): void
    {
        $this->list[] = ['tex', $texture, $src, $x, $y, $flip];
    }

    /**
     * The tile screen (TFR_SCREENM / copytiles): 20x12 tiles of the circular buffer $cells starting at
     * ($xm, $ym), clipped to the rectangle $clip.
     * @param int[] $cells 240 tile indexes, [y * 20 + x]
     */
    public function tiles(array $cells, int $xm, int $ym, array $clip): void
    {
        if ($clip[0] <= 0 && $clip[1] <= 0 && $clip[2] >= self::WIDTH && $clip[3] >= 192) {
            // covers the whole game area: what was drawn there before is hidden
            $this->list = array_values(array_filter($this->list, fn (array $c) => $c[0] === 'fill' && $c[2] >= 192));
        }
        $this->list[] = ['tiles', $cells, $xm, $ym, $clip];
    }

    /** @return array<int, array> what is drawn now (fadeout() keeps it under a black veil) */
    public function snapshot(): array
    {
        return $this->list;
    }

    /** @param array<int, array> $list */
    public function restore(array $list): void
    {
        $this->list = $list;
    }

    public function flip(): void
    {
        $this->presented = $this->list;
    }

    public function render(Renderer $r, Sdl $sdl): void
    {
        $r->clear([0, 0, 0]);
        foreach ($this->presented as $c) {
            switch ($c[0]) {
                case 'fill':
                    $r->fillRect($c[1], $c[2], $c[3], $c[4], $c[5]);
                    break;
                case 'tex':
                    $tex = $sdl->getTexture($c[1])['texture'];
                    $r->copy($tex, $c[2], $c[3], $c[4], null, null, $c[5]);
                    break;
                case 'tiles':
                    $this->renderTiles($r, $sdl, $c[1], $c[2], $c[3], $c[4]);
                    break;
            }
        }
    }

    private function renderTiles(Renderer $r, Sdl $sdl, array $cells, int $xm, int $ym, array $clip): void
    {
        $tex = $sdl->getTexture('tiles')['texture'];
        [$cx, $cy, $cw, $ch] = $clip;
        if ($cw <= 0 || $ch <= 0) {
            return;
        }
        for ($ty = intdiv(max(0, $cy), 16); $ty < 12 && $ty * 16 < $cy + $ch; $ty++) {
            for ($tx = intdiv(max(0, $cx), 16); $tx < 20 && $tx * 16 < $cx + $cw; $tx++) {
                $tile = $cells[(($ty + $ym) % 12) * 20 + ($tx + $xm) % 20];
                $x0 = max($tx * 16, $cx);
                $y0 = max($ty * 16, $cy);
                $x1 = min($tx * 16 + 16, $cx + $cw);
                $y1 = min($ty * 16 + 16, $cy + $ch);
                if ($x1 <= $x0 || $y1 <= $y0) {
                    continue;
                }
                $r->copy($tex, [($tile & 15) * 16 + $x0 - $tx * 16, ($tile >> 4) * 16 + $y0 - $ty * 16, $x1 - $x0, $y1 - $y0], $x0, $y0);
            }
        }
    }

    /** Tiles (one texture per level) and sprites (rebuilt when the level palette changes). */
    public function loadLevelGraphics(Sdl $sdl, Level $level): void
    {
        $pixels = '';
        $rows = [];
        foreach ($level->tile as $i => $tile) {
            for ($y = 0; $y < 16; $y++) {
                $rows[($i >> 4) * 16 + $y][$i & 15] = substr($tile->pixels, $y * 16, 16);
            }
        }
        for ($y = 0; $y < 256; $y++) {
            $pixels .= implode('', $rows[$y]);
        }
        Gfx::texture($sdl, 'tiles', 256, 256, $pixels, $level->palette, false);

        if ($this->spritePalette !== $level->palette) {
            $this->spritePalette = $level->palette;
            $images = [];
            $flash = [];
            // flash: the first 3 planes are 0 -> odd colours white, even colours black (colour 2)
            $map = [];
            for ($i = 1; $i < 16; $i++) {
                $map[chr($i)] = ($i & 1) ? "\x01" : "\x02";
            }
            foreach ($level->spritedata as $i => $s) {
                $images[$i] = ['w' => $s->w, 'h' => $s->h, 'pixels' => $s->pixels];
                $flash[$i] = ['w' => $s->w, 'h' => $s->h, 'pixels' => strtr($s->pixels, $map)];
            }
            $this->spriteRects = Gfx::atlas($sdl, 'sprites', $images, $level->palette, true);
            Gfx::atlas($sdl, 'sprites-flash', $flash, $level->palette, true);
        }
    }
}
