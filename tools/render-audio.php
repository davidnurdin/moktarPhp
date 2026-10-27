<?php

/**
 * Renders the original AdLib music and sound effects (assets/data/MUSIC_MOK.BIN) to WAV files:
 *   bin/jumphp php games/moktar tools/render-audio.php [sample rate, default 16000]
 *
 * The original driver (App\Titus\Audio\AdlibDriver) runs on the DOSBox OPL2 emulator used by OpenTitus (App\Titus\Audio\DbOpl),
 * one timer tick every 13.75 ms like the original. Looping songs are rendered once (until every channel
 * has jumped back to its start); the game loops the file. Output: assets/music/*.wav + audio.json.
 * Run it once after copying the original files (a few minutes).
 */

use App\Titus\Audio\AdlibDriver;
use App\Titus\Audio\DbOpl;

require __DIR__ . '/../vendor/autoload.php';

$rate = (int)($argv[1] ?? 16000);
$root = dirname(__DIR__);
$bin = $root . '/assets/data/MUSIC_MOK.BIN';
$out = $root . '/assets/music';
if (!is_file($bin)) {
    fwrite(STDERR, "missing $bin (the music data of the original game)\n");
    exit(1);
}
@mkdir($out, 0777, true);
$data = (string)file_get_contents($bin);
$manifest = ['rate' => $rate, 'songs' => [], 'sfx' => []];

/** One timer tick of audio: 14, 14, 14 then 13 ms (13.75 ms on average) */
$render = function (DbOpl $opl, AdlibDriver $driver, float &$carry) use ($rate): array {
    $ms = ($driver->ticks % 4 === 3) ? 13 : 14;
    $carry += $ms * $rate / 1000;
    $n = (int)$carry;
    $carry -= $n;
    $driver->tick();
    return $opl->generate($n);
};

$write = function (string $file, array $samples, float $peakTarget) use ($rate): void {
    $peak = 0.0001;
    foreach ($samples as $s) {
        $peak = max($peak, abs($s));
    }
    $gain = $peakTarget / $peak;
    $bytes = '';
    foreach (array_chunk($samples, 8192) as $chunk) {
        $values = [];
        foreach ($chunk as $s) {
            $values[] = max(0, min(255, (int)round(128 + $s * $gain * 127 + (mt_rand() / mt_getrandmax() - 0.5))));
        }
        $bytes .= pack('C*', ...$values);
    }
    $len = strlen($bytes);
    file_put_contents($file, 'RIFF' . pack('V', 36 + $len) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, $rate, $rate, 1, 8)
        . 'data' . pack('V', $len) . $bytes);
};

for ($song = 0; $song < 16; $song++) {
    $t = microtime(true);
    // dry run (no sound): the loop ends when every looping channel has jumped back once
    $dry = new AdlibDriver($data, new DbOpl($rate));
    $dry->SELECT_MUSIC($song);
    while ($dry->ticks < 72 * 200 && $dry->tick() > 0) {
    }
    $loopEnd = $dry->cutsong > 0 ? $dry->loopTick : null;

    $opl = new DbOpl($rate);
    $driver = new AdlibDriver($data, $opl);
    $driver->SELECT_MUSIC($song);
    $samples = [];
    $carry = 0.0;
    $loops = false;
    $tail = 0;
    while ($driver->ticks < 72 * 200) {
        array_push($samples, ...$render($opl, $driver, $carry));
        if ($loopEnd !== null && $driver->ticks >= $loopEnd) {
            $loops = true;
            break;
        }
        if ($driver->cutsong <= 0 && ++$tail > 40) { // finished: keep half a second of release
            break;
        }
    }
    $file = "song-$song.wav";
    $write("$out/$file", $samples, 0.9);
    $duration = count($samples) / $rate;
    $manifest['songs'][$song] = ['file' => $file, 'duration' => round($duration, 3), 'loops' => $loops];
    printf("song %2d: %6.1f s%s (%.1f s)\n", $song, $duration, $loops ? ' loop' : '', microtime(true) - $t);
}

for ($fx = 0; $fx < 14; $fx++) {
    $opl = new DbOpl($rate);
    $driver = new AdlibDriver($data, $opl);
    $driver->silence();
    $driver->FX_START($fx);
    $samples = [];
    $carry = 0.0;
    $after = 0;
    while ($driver->isFxOn() || ++$after < 20) {
        array_push($samples, ...$render($opl, $driver, $carry));
    }
    $file = "fx-$fx.wav";
    $write("$out/$file", $samples, 0.8);
    $manifest['sfx'][$fx] = $file;
}

file_put_contents("$out/audio.json", json_encode($manifest, JSON_PRETTY_PRINT) . "\n");
echo "written: $out/audio.json\n";
