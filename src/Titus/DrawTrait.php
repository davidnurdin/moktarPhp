<?php

namespace App\Titus;

/**
 * draw.c, fonts.c and viewimage.c: drawing into the screen (Screen display list), the status and
 * password screens, the font of FONTS.SQZ, the fades.
 */
trait DrawTrait
{
    /** @var int[] the tile screen (C tilescreen): 20x12 circular buffer of displayed tile indexes */
    public array $tilescreen = [];
    /** @var array<int, string> character -> glyph key in the font atlas */
    private array $fontChars = [];

    /** Draw tiles on the backbuffer (copy from the tile screen) */
    public function TFR_SCREENM(): void
    {
        // First of all: make the screen black, at least the lower part of the screen
        $this->screen->fillRect(0, self::screen_height * 16, self::screen_width * 16, Screen::HEIGHT - self::screen_height * 16, [0, 0, 0]);
        $this->copytiles(0, 0, self::screen_width * 16, self::screen_height * 16);
    }

    /** gates.c copytiles(): a rectangle of the tile screen to the same place of the output screen */
    public function copytiles(int $destX, int $destY, int $width, int $height): void
    {
        $this->screen->tiles($this->tilescreen, $this->BITMAP_XM, $this->BITMAP_YM, [$destX, $destY, $width, $height]);
    }

    /**
     * Loop through all sprites, and draw the sprites that should be visible on the screen (NOT by using the
     * visible flag, it uses the coordinates). If the flash bit is set, the first 3 planes will be 0, the last
     * plane will be normal (colour & 0x01, odd colors gets white, even colours gets black)
     */
    public function DISPLAY_SPRITES(Level $level): void
    {
        for ($i = $level->elevatorcount - 1; $i >= 0; $i--) {
            $this->display_sprite($level, $level->elevator[$i]->sprite);
        }
        for ($i = $level->trashcount - 1; $i >= 0; $i--) {
            $this->display_sprite($level, $level->trash[$i]);
        }
        for ($i = $level->enemycount - 1; $i >= 0; $i--) {
            $this->display_sprite($level, $level->enemy[$i]->sprite);
        }
        for ($i = $level->objectcount - 1; $i >= 0; $i--) {
            $this->display_sprite($level, $level->object[$i]->sprite);
        }
        $this->display_sprite($level, $level->player->sprite3);
        $this->display_sprite($level, $level->player->sprite2);
        $this->display_sprite($level, $level->player->sprite);
        if ($this->GODMODE) {
            $this->SDL_Print_Text('GODMODE', 30 * 8, 0 * 12);
        }
        if ($this->NOCLIP) {
            $this->SDL_Print_Text('NOCLIP', 30 * 8, 1 * 12);
        }
    }

    public function display_sprite(Level $level, Sprite $spr): void
    {
        if (!$spr->enabled || $spr->invisible) {
            return;
        }
        $spr->visible = false;
        $data = $spr->spritedata ?? $level->spritedata[$spr->number];
        if (!$spr->flipped) {
            $x = $spr->x - $data->refwidth - ($this->BITMAP_X << 4);
        } else {
            $x = $spr->x + $data->refwidth - $data->w - ($this->BITMAP_X << 4);
        }
        $y = $spr->y + $data->refheight - $data->h + 1 - ($this->BITMAP_Y << 4);
        if ($x >= self::screen_width * 16 || $x + $data->w < 0 || $y + $data->h < 0 || $y >= self::screen_height * 16) {
            return;
        }
        $sx = 0;
        $sy = 0;
        $sw = $data->w;
        $sh = $data->h;
        if ($x < 0) {
            $sx = -$x;
            $sw -= $sx;
            $x = 0;
        }
        if ($y < 0) {
            $sy = -$y;
            $sh -= $sy;
            $y = 0;
        }
        if ($x + $sw > self::screen_width * 16) {
            $sw = self::screen_width * 16 - $x;
        }
        if ($y + $sh > self::screen_height * 16) {
            $sh = self::screen_height * 16 - $y;
        }
        if ($sw > 0 && $sh > 0) {
            [$ax, $ay] = $this->screen->spriteRects[$data->index];
            // the clipping is computed on the flipped image: mirror it back into the texture
            $tx = $spr->flipped ? $data->w - $sx - $sw : $sx;
            $this->screen->blit($spr->flash ? 'sprites-flash' : 'sprites', [$ax + $tx, $ay + $sy, $sw, $sh], $x, $y, $spr->flipped);
        }
        $spr->visible = true;
        $spr->flash = false;
    }

    /** Flips the screen and waits (NO_FAST_CPU: 29 ms in game = 35 Hz, 10 ms for the quick flips) */
    public function flip_screen(bool $slow): \Generator
    {
        $this->screen->flip();
        yield $slow ? 29 : 10;
    }

    /** A frame of a waiting loop (titus_sleep + flip) */
    public function titus_flip(): \Generator
    {
        $this->screen->flip();
        yield 0;
    }

    /** View status screen (F4) */
    public function viewstatus(Level $level, bool $countbonus): \Generator
    {
        $this->screen->clear();
        $this->SDL_Print_Text('ETAPE', 13 * 8, 12 * 5);
        $this->SDL_Print_Text('EXTRA BONUS', 10 * 8, 10 * 12);
        $this->SDL_Print_Text('VIE', 10 * 8, 11 * 12);
        $tmp = (string)($level->levelnumber + 1);
        $this->SDL_Print_Text($tmp, 25 * 8 - strlen($tmp) * 8, 12 * 5);
        $this->SDL_Print_Text(self::LEVEL_TITLES[$level->levelnumber], 0, 12 * 5 + 16);
        $tmp = (string)$level->extrabonus;
        $this->SDL_Print_Text($tmp, 28 * 8 - strlen($tmp) * 8, 10 * 12);
        $tmp = (string)$level->lives;
        $this->SDL_Print_Text($tmp, 28 * 8 - strlen($tmp) * 8, 11 * 12);
        $this->screen->flip();
        if ($countbonus && $level->extrabonus >= 10) {
            $retval = yield from $this->waitforbutton();
            if ($retval < 0) {
                return $retval;
            }
            while ($level->extrabonus >= 10) {
                for ($i = 0; $i < 10; $i++) {
                    $level->extrabonus--;
                    $tmp = sprintf('%2d', $level->extrabonus);
                    $this->SDL_Print_Text($tmp, 28 * 8 - strlen($tmp) * 8, 10 * 12);
                    $this->screen->flip();
                    for ($j = 0; $j < 15; $j++) {
                        yield 10;
                    }
                }
                $level->lives++;
                $tmp = (string)$level->lives;
                $this->SDL_Print_Text($tmp, 28 * 8 - strlen($tmp) * 8, 11 * 12);
                $this->screen->flip();
                for ($j = 0; $j < 10; $j++) {
                    yield 10;
                }
            }
        }
        $retval = yield from $this->waitforbutton();
        if ($retval < 0) {
            return $retval;
        }
        $this->screen->clear();
        $this->screen->flip();
        return 0;
    }

    /** Initialize backbuffer */
    public function INIT_SCREENM(Level $level): \Generator
    {
        yield from $this->CLOSE_SCREEN();
        $this->BITMAP_X = 0;
        $this->BITMAP_Y = 0;
        $this->BITMAP_XM = 0;
        $this->BITMAP_YM = 0;
        $this->tilescreen = array_fill(0, 240, 0);
        for ($i = 0; $i < 20; $i++) {
            for ($j = 0; $j < 12; $j++) {
                $this->DISPLAY_CHAR($level, $level->tilemap[$this->BITMAP_Y + $j][$this->BITMAP_X + $i] ?? 0, $j, $i);
            }
        }
        $guard = 0;
        do {
            $this->scroll($level);
        } while (($this->YSCROLL_CENTER || $this->XSCROLL_CENTER) && ++$guard < 2000);
        yield from $this->OPEN_SCREEN();
    }

    /** Draw energy */
    public function DISPLAY_COUNT(Level $level): void
    {
        $this->subto0($this->BAR_FLAG);
        if ($this->BAR_FLAG !== 0) {
            $this->DISPLAY_ENERGY($level);
        }
    }

    public function DISPLAY_ENERGY(Level $level): void
    {
        $offset = 96;
        for ($i = 0; $i < $level->player->hp; $i++) { // Draw big bars (4px*16px, spacing 4px)
            $this->screen->fillRect($offset, 9, 4, 16, [255, 255, 255]);
            $offset += 8;
        }
        for ($i = 0; $i < self::MAXIMUM_ENERGY - $level->player->hp; $i++) { // Draw small bars (4px*4px, spacing 4px)
            $this->screen->fillRect($offset, 15, 4, 4, [255, 255, 255]);
            $offset += 8;
        }
    }

    /** Fade the screen to black */
    public function fadeout(): \Generator
    {
        $fadeTime = 1000;
        $base = $this->screen->snapshot();
        $tickStart = $this->SDL_GetTicks();
        $alpha = 0;
        while ($alpha < 255) {
            foreach ($this->pollEvents() as [$type, $key]) {
                if ($type === 'down') {
                    if ($key === self::KEY_ESC) {
                        $this->screen->restore($base);
                        return -1;
                    }
                    $this->musicKey($key);
                }
            }
            $alpha = min(255, intdiv(($this->SDL_GetTicks() - $tickStart) * 256, $fadeTime));
            $this->screen->restore($base);
            $this->screen->fillRect(0, 0, Screen::WIDTH, Screen::HEIGHT, [0, 0, 0, $alpha]);
            yield from $this->titus_flip();
        }
        $this->screen->clear();
        return 0;
    }

    /** Display the password ! */
    public function view_password(Level $level, int $level_index): \Generator
    {
        yield from $this->CLOSE_SCREEN();
        $this->screen->clear();
        $this->screen->flip();
        $this->SDL_Print_Text('ETAPE', 13 * 8, 13 * 8);
        $tmp = (string)($level_index + 1);
        $this->SDL_Print_Text($tmp, 25 * 8 - strlen($tmp) * 8, 13 * 8);
        $this->SDL_Print_Text('CODE', 14 * 8, 10 * 8);
        $this->SDL_Print_Text(self::LEVEL_CODES[$level_index] ?? '', 20 * 8, 10 * 8);
        $this->screen->flip();
        $retval = yield from $this->waitforbutton();
        if ($retval < 0) {
            return $retval;
        }
        yield from $this->OPEN_SCREEN();
        return 0;
    }

    // --- fonts.c ----------------------------------------------------------------------------------------

    public function loadfonts(): void
    {
        $b = Sqz::load($this->file('FONTS.SQZ'));
        $order = '0123456789!?.$_ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $images = [];
        for ($i = 0; $i < strlen($order); $i++) {
            $images[$order[$i]] = ['w' => 8, 'h' => 12, 'pixels' => Gfx::planar($b, $i * 48, 8, 12)];
        }
        $images[' '] = ['w' => 8, 'h' => 12, 'pixels' => str_repeat("\x01", 96)];
        $this->screen->fontRects = Gfx::atlas($this->sdl, 'font', $images, OriginalData::PALETTE_FONT, false, 512);
    }

    public function SDL_Print_Text(string $text, int $x, int $y): void
    {
        $rects = $this->screen->fontRects;
        for ($i = 0, $n = strlen($text); $i < $n; $i++) {
            $c = strtoupper($text[$i]);
            $rect = $rects[$c] ?? $rects['?'];
            $this->screen->blit('font', $rect, $x, $y);
            $x += 8;
        }
    }

    public function viewintrotext(): \Generator
    {
        $year = (int)date('Y');
        $this->screen->clear();
        $this->SDL_Print_Text('     YEAAA . . .', 0, 5 * 12);
        $this->SDL_Print_Text("YOU ARE STILL PLAYING MOKTAR IN $year !!", 0, 6 * 12);
        $this->SDL_Print_Text(' PROGRAMMED IN 1991 ON AT .286 12MHZ.', 0, 12 * 12);
        $this->SDL_Print_Text('   . . . ENJOY MOKTAR ADVENTURE !!', 0, 13 * 12);
        $this->screen->flip();
        $retval = yield from $this->waitforbutton();
        if ($retval < 0) {
            return $retval;
        }
        $this->SDL_Print_Text('     YEAAA . . .', 0, 5 * 12);
        $this->SDL_Print_Text("YOU ARE STILL PLAYING MOKTAR IN $year !!", 0, 6 * 12);
        $this->SDL_Print_Text('  REPROGRAMMED IN 2026 IN PHP + JUMPHP.', 0, 12 * 12);
        $this->SDL_Print_Text('   . . . ENJOY MOKTAR ADVENTURE !!', 0, 13 * 12);
        $this->screen->flip();
        $retval = yield from $this->waitforbutton();
        if ($retval < 0) {
            return $retval;
        }
        return 0;
    }

    // --- viewimage.c ------------------------------------------------------------------------------------

    /** Loads a 320x200 picture of the original files as a texture; returns its name. */
    public function loadimage(string $file, int $format): string
    {
        $name = 'image-' . $file;
        if ($this->sdl->hasTexture($name)) {
            return $name;
        }
        $data = Sqz::load($this->file($file));
        if ($format === 2) { // 256 colours + palette
            $palette = [];
            for ($i = 0; $i < 256; $i++) {
                $palette[$i] = [(ord($data[$i * 3]) & 0x3F) * 4, (ord($data[$i * 3 + 1]) & 0x3F) * 4, (ord($data[$i * 3 + 2]) & 0x3F) * 4];
            }
            $pixels = substr($data, 256 * 3, 320 * 200);
        } else { // planar 16-colour grayscale
            $palette = [];
            for ($i = 0; $i < 16; $i++) {
                $palette[$i] = [$i * 16, $i * 16, $i * 16];
            }
            $pixels = Gfx::planar($data, 0, 320, 200);
        }
        Gfx::texture($this->sdl, $name, 320, 200, $pixels, $palette, false);
        return $name;
    }

    /**
     * Displays an image (menu, logo, intro). $displayformat 0: fade in, wait $delay ms (or a key), fade
     * out; 1: visible until keypress, then fade out.
     */
    public function viewimage(string $file, int $imageformat, int $displayformat, int $delay): \Generator
    {
        $image = $this->loadimage($file, $imageformat);
        $fadeTime = 1000;
        $alpha = 0;
        $fadeoutskip = 0;
        $activedelay = true;
        $draw = function (int $alpha) use ($image): void {
            $this->screen->clear();
            $this->screen->blit($image, [0, 0, 320, 200], 0, 0);
            if ($alpha < 255) {
                $this->screen->fillRect(0, 0, 320, 200, [0, 0, 0, 255 - $alpha]);
            }
        };
        if ($displayformat === 0) {
            $tickStart = $this->SDL_GetTicks();
            while ($alpha < 255 && $activedelay) { // Fade to visible
                foreach ($this->pollEvents() as [$type, $key]) {
                    if ($type !== 'down') {
                        continue;
                    }
                    if ($key === self::KEY_ESC) {
                        return -1;
                    }
                    if ($key === self::KEY_RETURN || $key === self::KEY_SPACE) {
                        $activedelay = false;
                        $fadeoutskip = 255 - $alpha;
                    }
                    $this->musicKey($key);
                }
                $alpha = min(255, intdiv(($this->SDL_GetTicks() - $tickStart) * 256, $fadeTime));
                $draw($alpha);
                yield from $this->titus_flip();
            }
            while ($activedelay) { // Visible delay
                foreach ($this->pollEvents() as [$type, $key]) {
                    if ($type !== 'down') {
                        continue;
                    }
                    if ($key === self::KEY_ESC) {
                        return -1;
                    }
                    if ($key === self::KEY_RETURN || $key === self::KEY_SPACE) {
                        $activedelay = false;
                    }
                    $this->musicKey($key);
                }
                yield from $this->titus_flip();
                if ($this->SDL_GetTicks() - $tickStart + $fadeTime >= $delay) {
                    $activedelay = false;
                }
            }
            $alpha = 255 - $alpha;
        } else {
            $draw(255);
            $this->screen->flip();
            $retval = yield from $this->waitforbutton();
            if ($retval < 0) {
                return $retval;
            }
            $fadeoutskip = 0;
            $alpha = 0;
        }
        $tickStart = $this->SDL_GetTicks();
        while ($alpha < 255) { // Fade to black
            foreach ($this->pollEvents() as [$type, $key]) {
                if ($type === 'down') {
                    if ($key === self::KEY_ESC) {
                        return -1;
                    }
                    $this->musicKey($key);
                }
            }
            $alpha = min(255, intdiv(($this->SDL_GetTicks() - $tickStart) * 256, $fadeTime) + $fadeoutskip);
            $draw(255 - $alpha);
            yield from $this->titus_flip();
        }
        return 0;
    }
}
