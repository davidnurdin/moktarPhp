<?php

namespace App\Titus;

use JumPHP\Renderer\Sdl;

/**
 * Pixels of the original files -> SDL textures.
 *
 * The original graphics are 4 bit planes (EGA): planar() turns them into one palette index per byte.
 * Textures are built as 8-bit palettised BMP files (no per-pixel PHP work), loaded with a colour key
 * on palette colour 0 (transparent for sprites).
 */
final class Gfx
{
    /**
     * 4 consecutive planes of (w * h / 8) bytes -> w * h palette indexes.
     * ($b is a byte string: big PHP arrays would make the browser runtime reallocate huge blocks)
     */
    public static function planar(string $b, int $offset, int $w, int $h): string
    {
        static $planes = null;
        if ($planes === null) {
            // $planes[plane][byte] = the 8 pixels of that byte in that plane (string ORed with the others)
            for ($p = 0; $p < 4; $p++) {
                for ($v = 0; $v < 256; $v++) {
                    $s = '';
                    for ($j = 7; $j >= 0; $j--) {
                        $s .= chr((($v >> $j) & 1) << $p);
                    }
                    $planes[$p][$v] = $s;
                }
            }
        }
        $group = ($w * $h) >> 3;
        $b = str_pad(substr($b, $offset, $group * 4), $group * 4, "\0");
        $out = '';
        for ($i = 0; $i < $group; $i++) {
            $out .= $planes[0][ord($b[$i])] | $planes[1][ord($b[$i + $group])]
                | $planes[2][ord($b[$i + $group * 2])] | $planes[3][ord($b[$i + $group * 3])];
        }
        return $out;
    }

    /**
     * Packs images (palette-index strings) into one texture.
     * @param array<int, array{0:int,1:int,2:int}> $palette
     * @template K of int|string
     * @param array<K, array{w:int, h:int, pixels:string}> $images
     * @return array<K, array{0:int,1:int,2:int,3:int}> source rectangle of each image
     */
    public static function atlas(Sdl $sdl, string $name, array $images, array $palette, bool $transparent, int $width = 1024): array
    {
        $rects = [];
        $x = 0;
        $y = 0;
        $rowH = 0;
        foreach ($images as $key => $img) {
            if ($x + $img['w'] > $width) {
                $x = 0;
                $y += $rowH + 1;
                $rowH = 0;
            }
            $rects[$key] = [$x, $y, $img['w'], $img['h']];
            $x += $img['w'] + 1;
            $rowH = max($rowH, $img['h']);
        }
        $height = max(1, $y + $rowH);
        $rows = array_fill(0, $height, str_repeat("\0", $width));
        foreach ($images as $key => $img) {
            [$rx, $ry, $w, $h] = $rects[$key];
            for ($j = 0; $j < $h; $j++) {
                $rows[$ry + $j] = substr_replace($rows[$ry + $j], substr($img['pixels'], $j * $w, $w), $rx, $w);
            }
        }
        self::texture($sdl, $name, $width, $height, implode('', $rows), $palette, $transparent);
        return $rects;
    }

    /**
     * A texture from palette indexes (one byte per pixel, rows top to bottom).
     * @param array<int, array{0:int,1:int,2:int}> $palette
     */
    public static function texture(Sdl $sdl, string $name, int $w, int $h, string $pixels, array $palette, bool $transparent): void
    {
        $file = sys_get_temp_dir() . '/moktar-' . preg_replace('/[^\w-]/', '_', $name) . '.bmp';
        file_put_contents($file, self::bmp($w, $h, $pixels, $palette));
        $sdl->loadTexture($name, $file, $transparent ? $palette[0] : null);
        @unlink($file);
    }

    /** @param array<int, array{0:int,1:int,2:int}> $palette */
    public static function bmp(int $w, int $h, string $pixels, array $palette): string
    {
        $stride = ($w + 3) & ~3;
        $pad = str_repeat("\0", $stride - $w);
        $data = '';
        for ($y = $h - 1; $y >= 0; $y--) {
            $data .= str_pad(substr($pixels, $y * $w, $w), $w, "\0") . $pad;
        }
        $pal = '';
        for ($i = 0; $i < 256; $i++) {
            $c = $palette[$i] ?? [0, 0, 0];
            $pal .= chr($c[2]) . chr($c[1]) . chr($c[0]) . "\0";
        }
        $offset = 14 + 40 + 1024;
        return 'BM' . pack('VvvV', $offset + strlen($data), 0, 0, $offset)
            . pack('VVVvvVVVVVV', 40, $w, $h, 1, 8, 0, strlen($data), 2835, 2835, 256, 0)
            . $pal . $data;
    }
}
