<?php

namespace App\Titus;

/**
 * Decompresses the original "SQZ" files (OpenTitus sqz.c): a 4-byte header (output size on 20 bits,
 * compression type) followed by LZW (type 0x10) or Huffman + run-length data.
 *
 * Integers stay below 2^24 so the decoder also works with the 32-bit integers of the browser.
 */
final class Sqz
{
    private const LZW_CLEAR_CODE = 0x100;
    private const LZW_END_CODE = 0x101;
    private const LZW_FIRST = 0x102;
    private const LZW_MAX_TABLE = 4096;

    /** @return string the decompressed bytes */
    public static function load(string $file): string
    {
        $data = @file_get_contents($file);
        if ($data === false) {
            throw new \RuntimeException("Can't open input file: $file");
        }
        return self::decode($data, $file);
    }

    public static function decode(string $data, string $name = 'data'): string
    {
        if (strlen($data) < 4) {
            throw new \RuntimeException("File too short: $name");
        }
        $outLen = ((ord($data[0]) & 0x0F) << 16) | (ord($data[3]) << 8) | ord($data[2]);
        if ($outLen === 0) {
            throw new \RuntimeException("Invalid file: $name");
        }
        // byte strings, not arrays: big PHP arrays make the browser runtime reallocate huge blocks
        $input = substr($data, 4);
        $out = ord($data[1]) === 0x10 ? self::lzw($input, $outLen) : self::huffman($input, $outLen);
        return str_pad(substr($out, 0, $outLen), $outLen, "\0");
    }

    private static function lzw(string $input, int $outLen): string
    {
        $inLen = strlen($input);
        $nbit = 9;
        $bitadd = 0;
        $kPos = 0;
        $w = 0;
        $addToDict = false;
        $prefix = [];
        $char = [];
        $dictLength = 0;
        $stack = [];
        $out = '';
        $outPos = 0;

        while ($kPos < $inLen && $outPos < $outLen) {
            // the code is at most 12 bits starting at bit $bitadd (< 8): 3 bytes are enough
            $k = 0;
            for ($i = 0; $i < 3; $i++) {
                $k = ($k << 8) | ($kPos + $i < $inLen ? ord($input[$kPos + $i]) : 0);
            }
            $k = ($k >> (24 - $bitadd - $nbit)) & ((1 << $nbit) - 1);
            $bitadd += $nbit;
            while ($bitadd > 8) {
                $bitadd -= 8;
                $kPos++;
            }

            if ($k === self::LZW_CLEAR_CODE) {
                $nbit = 9;
                $dictLength = 0;
                $addToDict = false;
            } elseif ($k !== self::LZW_END_CODE) {
                if ($k > 255 && $k < self::LZW_FIRST + $dictLength) {
                    $i = 0;
                    $tmp = $k;
                    while ($tmp >= self::LZW_FIRST) {
                        $stack[$i] = $char[$tmp - self::LZW_FIRST];
                        $tmp = $prefix[$tmp - self::LZW_FIRST];
                        if (++$i > self::LZW_MAX_TABLE) {
                            throw new \RuntimeException('SQZ: dictionary overflow');
                        }
                    }
                    $stack[$i++] = $tmp;
                    $last = $i - 1;
                    while (--$i > 0) {
                        $out .= chr($stack[$i]);
                        $outPos++;
                    }
                    $out .= chr($stack[0]);
                    $outPos++;
                    $stack[0] = $stack[$last];
                } elseif ($k > 255) {
                    $i = 1;
                    $tmp = $w;
                    while ($tmp >= self::LZW_FIRST) {
                        $stack[$i] = $char[$tmp - self::LZW_FIRST];
                        $tmp = $prefix[$tmp - self::LZW_FIRST];
                        if (++$i > self::LZW_MAX_TABLE) {
                            throw new \RuntimeException('SQZ: dictionary overflow');
                        }
                    }
                    $stack[$i++] = $tmp;
                    $stack[0] = $dictLength > 0 ? $char[$dictLength - 1] : $w;
                    $last = $i - 1;
                    while (--$i > 0) {
                        $out .= chr($stack[$i]);
                        $outPos++;
                    }
                    $out .= chr($stack[0]);
                    $outPos++;
                    $stack[0] = $stack[$last];
                } else {
                    $out .= chr($k);
                    $outPos++;
                    $stack[0] = $k;
                }
                if ($addToDict && self::LZW_FIRST + $dictLength < self::LZW_MAX_TABLE) {
                    $char[$dictLength] = $stack[0];
                    $prefix[$dictLength] = $w;
                    $dictLength++;
                }
                $w = $k;
                $addToDict = true;
            }
            if (self::LZW_FIRST + $dictLength === (1 << $nbit) && $nbit < 12) {
                $nbit++;
            }
        }
        return $out;
    }

    private static function huffman(string $input, int $outLen): string
    {
        $inLen = strlen($input);
        $treeSize = ord($input[0]) | (ord($input[1]) << 8);
        $tree = array_values(unpack('v*', substr($input, 2, $treeSize) . "\0\0") ?: []);
        $node = 0;
        $state = 0;
        $count = 0;
        $last = 0;
        $out = '';
        for ($i = 2 + $treeSize; $i < $inLen && strlen($out) < $outLen; $i++) {
            for ($bit = 128; $bit >= 1; $bit >>= 1) {
                if (ord($input[$i]) & $bit) {
                    $node++;
                }
                $bintree = $tree[$node] ?? 0;
                if ($bintree <= 0x7FFF) {
                    $node = $bintree >> 1;
                    continue;
                }
                $node = $bintree & 0x7FFF;
                $nodeL = $node & 0xFF;
                if ($state === 0) {
                    if ($node < 0x100) {
                        $last = chr($nodeL);
                        $out .= $last;
                    } elseif ($nodeL === 0) {
                        $state = 1;
                    } elseif ($nodeL === 1) {
                        $state = 2;
                    } else {
                        $out .= str_repeat((string)$last, $nodeL);
                    }
                } elseif ($state === 1) {
                    $out .= str_repeat((string)$last, $node);
                    $state = 0;
                } elseif ($state === 2) {
                    $count = 256 * $nodeL;
                    $state = 3;
                } else {
                    $out .= str_repeat((string)$last, $count + $nodeL);
                    $state = 0;
                }
                $node = 0;
            }
        }
        return $out;
    }
}
