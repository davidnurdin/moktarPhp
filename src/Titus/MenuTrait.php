<?php

namespace App\Titus;

/**
 * menu.c: the menu picture (start / password) and the password screen.
 */
trait MenuTrait
{
    /** First level chosen by a password (curlevel of viewmenu, kept between two menus like the C static flow) */
    private int $curlevel = 1;

    /** Returns the level to play (1-16), or -1 (Escape) */
    public function viewmenu(string $menufile, int $menuformat): \Generator
    {
        $image = $this->loadimage($menufile, $menuformat);
        $fadeTime = 1000;
        $selection = 0;
        $curlevel = 1;
        // the selection marker (Moktar)
        $sel = [[130, 167, 8, 8], [130, 180, 8, 8]];
        $draw = function (int $alpha, bool $fadingOut = false) use ($image, &$selection, $sel): void {
            $this->screen->clear();
            $this->screen->blit($image, [0, 0, 320, 200], 0, 0);
            // the picture has the marker in front of both lines: erase it where it is not selected
            $this->screen->blit($image, $sel[1], $sel[0][0], $sel[0][1]);
            if ($fadingOut) {
                $this->screen->fillRect($sel[0][0], $sel[0][1], 8, 8, [0, 0, 0]);
            }
            $this->screen->blit($image, $sel[0], $sel[$selection][0], $sel[$selection][1]);
            if ($alpha < 255) {
                $this->screen->fillRect(0, 0, 320, 200, [0, 0, 0, 255 - $alpha]);
            }
        };

        $tickStart = $this->SDL_GetTicks();
        $alpha = 0;
        while ($alpha < 255) { // Fade in
            foreach ($this->pollEvents() as [$type, $key]) {
                if ($type === 'down') {
                    if ($key === self::KEY_ESC) {
                        return -1;
                    }
                    $this->musicKey($key);
                }
            }
            $alpha = min(255, intdiv(($this->SDL_GetTicks() - $tickStart) * 256, $fadeTime));
            $draw($alpha);
            yield from $this->titus_flip();
        }

        while (true) {
            $menuloop = true;
            while ($menuloop) { // View the menu
                foreach ($this->pollEvents() as [$type, $key]) {
                    if ($type !== 'down') {
                        continue;
                    }
                    if ($key === self::KEY_ESC) {
                        return -1;
                    }
                    if ($key === self::KEY_UP) {
                        $selection = 0;
                    } elseif ($key === self::KEY_DOWN) {
                        $selection = 1;
                    } elseif ($key === self::KEY_RETURN || $key === self::KEY_SPACE) {
                        $menuloop = false;
                    }
                    $this->musicKey($key);
                }
                $draw(255);
                yield from $this->titus_flip();
            }
            if ($selection === 0) { // Start
                break;
            }
            // Password
            $retval = yield from $this->enterpassword();
            if ($retval > 0 && $retval <= self::LEVELCOUNT) {
                $curlevel = $retval;
            }
            $selection = 0;
            $this->input->flush();
        }

        $tickStart = $this->SDL_GetTicks();
        $alpha = 0;
        while ($alpha < 255) { // Fade out
            foreach ($this->pollEvents() as [$type, $key]) {
                if ($type === 'down') {
                    $this->musicKey($key);
                }
            }
            $alpha = min(255, intdiv(($this->SDL_GetTicks() - $tickStart) * 256, $fadeTime));
            $draw(255 - $alpha, true);
            yield from $this->titus_flip();
        }
        return $curlevel;
    }

    /** 4 hexadecimal digits; returns the level (1-16), 0 for a wrong code, -1 for Escape */
    private function enterpassword(): \Generator
    {
        $code = '____';
        $this->screen->clear();
        $this->SDL_Print_Text('CODE', 111, 80);
        $this->input->takeTyped();
        $i = 0;
        // on a pad / phone: up / down choose the digit, action validates it (the C _DINGUX input)
        $index = 0;
        $counter = 0;
        while ($i < 4) {
            foreach (str_split($this->input->takeTyped()) as $char) {
                if ($i < 4 && ctype_xdigit($char)) {
                    $code[$i++] = strtoupper($char);
                    $index = 0;
                }
            }
            foreach ($this->pollEvents() as [$type, $key]) {
                if ($type !== 'down') {
                    continue;
                }
                if ($key === self::KEY_ESC) {
                    $this->screen->clear();
                    return -1;
                }
                if ($key === self::KEY_UP) {
                    $index = ($index + 1) % 16;
                } elseif ($key === self::KEY_DOWN) {
                    $index = ($index + 15) % 16;
                } elseif ($key === self::KEY_SPACE && $i < 4) {
                    $code[$i++] = strtoupper(dechex($index));
                    $index = 0;
                }
                $this->musicKey($key);
            }
            if ($i < 4) {
                $counter = ($counter + 1) % 40;
                $code[$i] = $counter >= 20 ? '_' : strtoupper(dechex($index));
            }
            $this->SDL_Print_Text($code, 159, 80);
            yield from $this->titus_flip();
        }
        foreach (self::LEVEL_CODES as $n => $levelCode) {
            if ($code === $levelCode) {
                $this->SDL_Print_Text('LEVEL', 103, 104);
                $tmp = (string)($n + 1);
                $this->SDL_Print_Text($tmp, 199 - 8 * strlen($tmp), 104);
                $this->screen->flip();
                $retval = yield from $this->waitforbutton();
                $this->screen->clear();
                $this->screen->flip();
                return $retval < 0 ? $retval : $n + 1;
            }
        }
        $this->SDL_Print_Text('!  WRONG CODE  !', 87, 104);
        $this->screen->flip();
        $retval = yield from $this->waitforbutton();
        $this->screen->clear();
        $this->screen->flip();
        return $retval;
    }
}
