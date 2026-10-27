<?php

namespace App\Titus\Audio;

/**
 * The music / sound effect driver of the original game (OpenTitus audio.c), playing MUSIC_MOK.BIN on an
 * OPL2. tick() is the timer callback (every 13.75 ms on average: 14, 14, 14, 13 ms).
 */
final class AdlibDriver
{
    private const DATA_COUNT = 10;
    private const INSTRUMENT_COUNT = 19;
    private const SFX_COUNT = 14;
    private const MUS_OFFSET = 0;
    private const INS_OFFSET = 352;
    private const SFX_OFFSET = 1950;
    /** Moktar (Titus the Fox: 1301) */
    private const SEG_REDUCTION = 1345;

    private const OPERA = [0, 0, 1, 2, 3, 4, 5, 8, 9, 0xA, 0xB, 0xC, 0xD, 0x10, 0x11, 0x12, 0x13, 0x14, 0x15];
    private const VOXP = [1, 2, 3, 7, 8, 9, 13, 17, 15, 18, 14];
    private const GAMME = [343, 363, 385, 408, 432, 458, 485, 514, 544, 577, 611, 647, 0];

    /** @var int[] */
    private array $data;
    private array $duration = [];
    private array $volume = [];
    private array $tempo = [];
    private array $triple_duration = [];
    private array $lie = [];
    private array $vox = [];
    /** @var array<int, ?int> index in $instrument_data */
    private array $instrument = [];
    private array $delay_counter = [];
    private array $freq = [];
    private array $octave = [];
    private array $return_point = [];
    private array $loop_counter = [];
    /** @var array<int, ?int> offset in $data */
    private array $pointer = [];
    private array $lie_late = [];
    private int $perc_stat = 0;
    private int $skip_delay = 0;
    private int $skip_delay_counter = 0;
    /** Number of active music channels */
    public int $cutsong = 0;
    /** @var array<int, array{op: int[][], fb_alg: int, vox: int}> */
    private array $instrument_data = [];
    /** @var array<int, array{op: int[][], fb_alg: int}> */
    private array $sfx = [];
    private bool $FX_ON = false;
    private int $FX_TIME = 0;
    /** @var array<int, int> tick of the first jump back of each channel */
    private array $jumpTicks = [];
    /** Tick at which every looping channel has jumped back once: the length of the song loop */
    public ?int $loopTick = null;
    public int $ticks = 0;

    public function __construct(string $musicBin, private DbOpl $opl)
    {
        $this->data = array_values(unpack('C*', $musicBin) ?: []);
        $this->initsfx();
    }

    private function updatechip(int $reg, int $val): void
    {
        $this->opl->write($reg, $val);
    }

    private function byte(int $offset): int
    {
        return $this->data[$offset] ?? 0;
    }

    private function word(int $offset): int
    {
        return $this->byte($offset) | ($this->byte($offset + 1) << 8);
    }

    /** One tick of the timer: returns the number of active channels */
    public function tick(): int
    {
        $this->ticks++;
        $this->FX_DRIVER();
        $this->skip_delay_counter = ($this->skip_delay_counter - 1) & 0xFF;
        if ($this->skip_delay_counter === 0) {
            $this->skip_delay_counter = $this->skip_delay;
            return $this->cutsong; // Skip (for modifying tempo)
        }
        for ($i = 0; $i < self::DATA_COUNT; $i++) {
            if (($this->pointer[$i] ?? null) === null) {
                continue;
            }
            if ($this->delay_counter[$i] > 1) {
                $this->delay_counter[$i]--;
                continue;
            }
            do {
                $byte = $this->byte($this->pointer[$i]);
                $this->pointer[$i]++;
                $oct = ($byte >> 4) & 0x07;
                $freq = $byte & 0x0F;
                if ($byte & 0x80) { // Escape
                    switch ($oct) {
                        case 0: // Change duration
                            $this->duration[$i] = $freq;
                            break;
                        case 1: // Change volume
                            $this->volume[$i] = $freq;
                            $this->outputVolume($i, $freq);
                            break;
                        case 2: // Change tempo
                            $this->tempo[$i] = $freq;
                            break;
                        case 3: // Change triple_duration
                            $this->triple_duration[$i] = $freq;
                            break;
                        case 4: // Change lie
                            $this->lie[$i] = $freq;
                            break;
                        case 5: // Change vox (channel)
                            $this->vox[$i] = $freq;
                            break;
                        case 6: // Change instrument
                            if ($freq === 1) { // Not melodic
                                $this->instrument[$i] = $this->octave[$i] + 15; // (1st perc instrument is the 16th instrument)
                                $this->vox[$i] = $this->instrument_data[$this->instrument[$i]]['vox'] ?? 0;
                                $this->perc_stat |= $this->shift(0x10, -($this->vox[$i] - 6));
                            } else {
                                if ($freq > 1) {
                                    $freq--;
                                }
                                $this->instrument[$i] = $freq;
                                if ($this->vox[$i] - 6 >= 0) {
                                    $tmp1 = $this->shift(0x10, $this->vox[$i] - 6) & 0xFF;
                                    $this->perc_stat |= $tmp1; // clear a bit from perc_stat
                                    $this->perc_stat &= ~$tmp1 & 0xFF;
                                    $this->updatechip(0xBD, $this->perc_stat);
                                }
                            }
                            $this->loadInstrument($i);
                            break;
                        case 7: // Extra functions
                            switch ($freq) {
                                case 0: // Call a sub
                                    $this->return_point[$i] = $this->pointer[$i] + 2;
                                    $this->pointer[$i] = $this->word($this->pointer[$i]) - self::SEG_REDUCTION;
                                    break;
                                case 1: // Update loop counter
                                    $this->loop_counter[$i] = $this->byte($this->pointer[$i]);
                                    $this->pointer[$i]++;
                                    break;
                                case 2: // Loop
                                    if ($this->loop_counter[$i] > 1) {
                                        $this->loop_counter[$i]--;
                                        $this->pointer[$i] = $this->word($this->pointer[$i]) - self::SEG_REDUCTION;
                                    } else {
                                        $this->pointer[$i] += 2;
                                    }
                                    break;
                                case 3: // Return from sub
                                    $this->pointer[$i] = $this->return_point[$i];
                                    break;
                                case 4: // Jump
                                    $this->pointer[$i] = $this->word($this->pointer[$i]) - self::SEG_REDUCTION;
                                    if (!isset($this->jumpTicks[$i])) {
                                        $this->jumpTicks[$i] = $this->ticks;
                                        $this->loopTick = max($this->jumpTicks);
                                    }
                                    break;
                                case 15: // Finish
                                    $this->pointer[$i] = null;
                                    $this->cutsong--;
                                    break;
                            }
                            break;
                    }
                }
            } while (($byte & 0x80) && $this->pointer[$i] !== null);
            if ($this->pointer[$i] === null) {
                continue;
            }
            $this->octave[$i] = $oct;
            $this->freq[$i] = $freq;
            // Play note
            $ins = $this->instrument[$i] ?? null;
            if (self::GAMME[$freq] !== 0 && $ins !== null) {
                if (($this->instrument_data[$ins]['vox'] ?? 0xFF) === 0xFE) { // Play a frequence
                    $vox = $this->vox[$i];
                    $this->updatechip(0xA0 + $vox, self::GAMME[$freq] & 0xFF); // lower 8 bits of frequence
                    if ($this->lie_late[$i] !== 1) {
                        $this->updatechip(0xB0 + $vox, 0); // Silence the channel
                    }
                    $tmp1 = ($oct + 2) & 0x07; // Octave (3 bits)
                    $tmp2 = (self::GAMME[$freq] >> 8) & 0x03; // Frequency (higher 2 bits)
                    $this->updatechip(0xB0 + $vox, 0x20 + ($tmp1 << 2) + $tmp2); // Voices the channel
                    $this->lie_late[$i] = $this->lie[$i];
                } else { // Play a perc instrument
                    if ($ins !== $oct + 15) { // New instrument
                        $this->instrument[$i] = $oct + 15;
                        $this->vox[$i] = $this->instrument_data[$oct + 15]['vox'] ?? 0;
                        $this->perc_stat |= $this->shift(0x10, -($this->vox[$i] - 6));
                        $this->loadInstrument($i);
                        $this->outputVolume($i, $this->volume[$i]);
                    }
                    $tmpC = $this->shift(0x10, -($this->vox[$i] - 6));
                    $this->updatechip(0xBD, $this->perc_stat & ~$tmpC & 0xFF); // perc_stat with one bit removed
                    if ($this->vox[$i] === 6) {
                        $this->updatechip(0xA6, 0x57);
                        $this->updatechip(0xB6, 0);
                        $this->updatechip(0xB6, 5); // Output the perc sound
                    }
                    $this->updatechip(0xBD, $this->perc_stat);
                }
            } else {
                $this->lie_late[$i] = $this->lie[$i];
            }
            if ($this->duration[$i] === 7) {
                $this->delay_counter[$i] = 0x40 >> $this->triple_duration[$i];
            } else {
                $this->delay_counter[$i] = 0x60 >> $this->duration[$i];
            }
        }
        return $this->cutsong;
    }

    /** C: 0x10 >> n (n may be negative: then a left shift), on 8 bits */
    private function shift(int $value, int $n): int
    {
        return ($n >= 0 ? $value >> $n : $value << -$n) & 0xFF;
    }

    private function outputVolume(int $i, int $volume): void
    {
        $ins = $this->instrument_data[$this->instrument[$i] ?? -1] ?? null;
        if ($ins === null) {
            return;
        }
        $tmpC = (($ins['op'][0][2] & 0x3F) - 63) & 0xFF;
        $tmp1 = (((256 - $tmpC) << 4) & 0x0FF0) * ($volume + 1);
        $tmp1 = (63 - (($tmp1 >> 8) & 0xFF)) & 0xFF;
        $tmp2 = self::VOXP[$this->vox[$i]] ?? 0;
        if ($tmp2 <= 13) {
            $tmp2 += 3;
        }
        $this->updatechip(0x40 + (self::OPERA[$tmp2] ?? 0), $tmp1);
    }

    private function loadInstrument(int $i): void
    {
        $ins = $this->instrument_data[$this->instrument[$i] ?? -1] ?? null;
        if ($ins === null) {
            return;
        }
        $tmp2 = self::VOXP[$this->vox[$i]] ?? 0;
        if ($this->vox[$i] <= 6) {
            $tmp2 += 3;
        }
        $channel = self::OPERA[$tmp2] ?? 0; // Adlib channel
        $this->insmaker($ins['op'][0], $channel);
        if ($this->vox[$i] < 7) {
            $this->insmaker($ins['op'][1], $channel - 3);
            $this->updatechip(0xC0 + $this->vox[$i], $ins['fb_alg']);
        }
    }

    /** @param int[] $insdata */
    private function insmaker(array $insdata, int $channel): void
    {
        $this->updatechip(0x60 + $channel, $insdata[0]); // Attack Rate / Decay Rate
        $this->updatechip(0x80 + $channel, $insdata[1]); // Sustain Level / Release Rate
        $this->updatechip(0x40 + $channel, $insdata[2]); // Key scaling level / Operator output level
        $this->updatechip(0x20 + $channel, $insdata[3]); // Amp Mod / Vibrato / EG type / Key Scaling / Multiple
        $this->updatechip(0xE0 + $channel, $insdata[4]); // Wave type
    }

    public function SELECT_MUSIC(int $song_number): void
    {
        $this->perc_stat = 0x20;
        $this->loopTick = null;
        $this->jumpTicks = [];
        $this->ticks = 0;
        // Load instruments
        $j = self::INS_OFFSET;
        for ($i = 0; $i < $song_number; $i++) {
            do {
                $j += 2;
            } while ($this->word($j) !== 0xFFFF);
            $j += 2;
        }
        $this->all_vox_zero();
        $tmp2 = $this->word($j);
        $tmp1 = 0;
        $this->instrument_data = [];
        for ($i = 0; $i < self::INSTRUMENT_COUNT + 1 && $j + 2 < count($this->data); $i++) {
            $tmp1 = $tmp2;
            $tmp2 = $this->word($j + 2);
            $j += 2;
            if ($tmp2 === 0xFFFF) {
                break;
            }
            if ($tmp1 === 0) { // Instrument not in use
                continue;
            }
            $ins = ['op' => [[], []], 'fb_alg' => 0, 'vox' => 0xFE];
            if ($i > 14) { // Perc instrument (15-18) have an extra byte, melodic (0-14) have not
                $ins['vox'] = $this->byte(($tmp1++) - self::SEG_REDUCTION);
            }
            for ($k = 0; $k < 5; $k++) {
                $ins['op'][0][$k] = $this->byte(($tmp1++) - self::SEG_REDUCTION);
            }
            for ($k = 0; $k < 5; $k++) {
                $ins['op'][1][$k] = $this->byte(($tmp1++) - self::SEG_REDUCTION);
            }
            $ins['fb_alg'] = $this->byte($tmp1 - self::SEG_REDUCTION);
            $this->instrument_data[$i] = $ins;
        }
        // Set skip delay (uint8)
        $this->skip_delay = $tmp1 & 0xFF;
        $this->skip_delay_counter = $tmp1 & 0xFF;
        // Load music
        $j = self::MUS_OFFSET;
        for ($i = 0; $i < $song_number; $i++) {
            do {
                $j += 2;
            } while ($this->word($j) !== 0xFFFF);
            $j += 2;
        }
        $this->cutsong = -1;
        $this->pointer = [];
        for ($i = 0; $i < self::DATA_COUNT + 1 && $j < count($this->data); $i++) {
            $tmp1 = $this->word($j);
            $this->cutsong++;
            if ($tmp1 === 0xFFFF) {
                break;
            }
            $this->duration[$i] = 0;
            $this->volume[$i] = 0;
            $this->tempo[$i] = 0;
            $this->triple_duration[$i] = 0;
            $this->lie[$i] = 0;
            $this->vox[$i] = $i;
            $this->instrument[$i] = null;
            $this->delay_counter[$i] = 0;
            $this->freq[$i] = 0;
            $this->octave[$i] = 0;
            $this->return_point[$i] = null;
            $this->loop_counter[$i] = 0;
            $this->pointer[$i] = $tmp1 - self::SEG_REDUCTION;
            $this->lie_late[$i] = 0;
            $j += 2;
        }
    }

    private function all_vox_zero(): void
    {
        for ($i = 0xB0; $i < 0xB9; $i++) {
            $this->updatechip($i, 0); // Clear voice, octave and upper bits of frequence
        }
        for ($i = 0xA0; $i < 0xB9; $i++) {
            $this->updatechip($i, 0); // Clear lower byte of frequence
        }
        $this->updatechip(0x08, 0x00);
        $this->updatechip(0xBD, 0x00);
        foreach ([0x40, 0x41, 0x42, 0x43, 0x44, 0x45, 0x48, 0x49, 0x4A, 0x4B, 0x4C, 0x4D, 0x50, 0x51, 0x52, 0x53, 0x54, 0x55] as $reg) {
            $this->updatechip($reg, 0x3F);
        }
    }

    private function initsfx(): void
    {
        $tmp1 = self::SFX_OFFSET;
        for ($i = 0; $i < self::SFX_COUNT; $i++) {
            $sfx = ['op' => [[], []], 'fb_alg' => 0];
            for ($k = 0; $k < 5; $k++) {
                $sfx['op'][0][$k] = $this->byte($tmp1++);
            }
            for ($k = 0; $k < 5; $k++) {
                $sfx['op'][1][$k] = $this->byte($tmp1++);
            }
            $sfx['fb_alg'] = $this->byte($tmp1++);
            $this->sfx[$i] = $sfx;
        }
    }

    /** Silences the music (a sound effect rendered alone) */
    public function silence(): void
    {
        $this->pointer = [];
        $this->cutsong = 0;
        $this->all_vox_zero();
        $this->perc_stat = 0x20;
    }

    public function FX_START(int $fx_number): void
    {
        $this->FX_TIME = 15;
        $this->FX_ON = true;
        $sfx = $this->sfx[$fx_number];
        $this->insmaker($sfx['op'][0], 0x13); // Channel 6 operator 1
        $this->insmaker($sfx['op'][1], 0x10); // Channel 6 operator 2
        $this->updatechip(0xC6, $sfx['fb_alg']); // Channel 6 (Feedback/Algorithm)
    }

    public function isFxOn(): bool
    {
        return $this->FX_ON;
    }

    private function FX_DRIVER(): void
    {
        if (!$this->FX_ON) {
            return;
        }
        $this->updatechip(0xBD, 0xEF & $this->perc_stat);
        $this->updatechip(0xA6, 0x57);
        $this->updatechip(0xB6, 1);
        $this->updatechip(0xB6, 5);
        $this->updatechip(0xBD, 0x10 | $this->perc_stat);
        $this->FX_TIME--;
        if ($this->FX_TIME === 0) {
            $this->FX_STOP();
        }
    }

    private function FX_STOP(): void
    {
        $this->updatechip(0xB6, 32);
        $this->insmaker([0xF5, 0x7F, 0x00, 0x11, 0x00], 0x13); // Channel 6 operator 1
        $this->insmaker([0xF8, 0xFF, 0x04, 0x30, 0x00], 0x10); // Channel 6 operator 2
        $this->updatechip(0xC6, 0x08); // Channel 6 (Feedback/Algorithm)
        $this->FX_ON = false;
    }
}
