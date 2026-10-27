<?php

namespace App\Titus\Audio;

/**
 * PHP port of the DOSBox OPL emulator (dbopl.cpp r3635, as converted to C by Chocolate Doom and used by
 * OpenTitus), OPL2 (YM3812, mono) part only, with the WAVE_TABLEMUL wave generator OpenTitus is built with.
 * The fixed-point integer arithmetic of dbopl is kept (C 32-bit unsigned wraps are emulated with & 0xFFFFFFFF),
 * so the integer samples match dbopl's Chip::GenerateBlock2 for the same register writes at the same samples.
 *
 * Differences with the C code (none of them changes the produced samples in OPL2 mode):
 *  - OPL3 (registers 0x100-0x1FF, 4-op and stereo synth modes, Chip::GenerateBlock3) is left out;
 *  - the synth handler function pointers are replaced by a mode per channel (AM, FM, percussion);
 *  - the noise generator is advanced N steps at once with precomputed GF(2) jump tables (the LFSR is
 *    linear) instead of N single steps: dbopl advances it about 500 times per sample;
 *  - Chip::Setup's OPL3-mode register clearing pass is replaced by its end result (waveform 0 selected).
 * Offline use only (tools/render-audio.php): it needs 64-bit PHP integers.
 */
final class DbOpl
{
    private const OPLRATE = 14318180.0 / 288.0;
    private const TREMOLO_TABLE = 52;
    private const WAVE_SH = 22;          // 32 - WAVE_BITS (10)
    private const WAVE_MASK = (1 << 22) - 1;
    private const LFO_SH = 12;           // WAVE_SH - 10
    private const LFO_MAX = 256 << 12;
    private const ENV_MAX = 511;         // ENV_BITS = 9, ENV_EXTRA = 0
    private const ENV_LIMIT = 384;       // (12 * 256) >> 3
    private const RATE_SH = 24;
    private const RATE_MASK = (1 << 24) - 1;
    private const MUL_SH = 16;
    private const SHIFT_KSLBASE = 16;
    private const SHIFT_KEYCODE = 24;
    private const MASK_KSR = 0x10;
    private const MASK_SUSTAIN = 0x20;
    private const MASK_VIBRATO = 0x40;

    // operator states
    private const OFF = 0;
    private const RELEASE = 1;
    private const SUSTAIN = 2;
    private const DECAY = 3;
    private const ATTACK = 4;

    // synth modes (sm2AM, sm2FM, sm2Percussion)
    private const SM_AM = 0;
    private const SM_FM = 1;
    private const SM_PERC = 2;

    /** Output scale: the loudest possible OPL2 mix (6 channels x 2 ops + doubled percussion) is < 98304 */
    private const OUT_SCALE = 1.0 / 98304.0;

    private const KSL_CREATE = [64, 32, 24, 19, 16, 12, 11, 10, 8, 6, 5, 4, 3, 2, 1, 0];
    private const FREQ_CREATE = [1, 2, 4, 6, 8, 10, 12, 14, 16, 18, 20, 20, 24, 24, 30, 30];
    private const ATTACK_SAMPLES = [69, 55, 46, 40, 35, 29, 23, 20, 19, 15, 11, 10, 9];
    private const ENVELOPE_INCREASE = [4, 5, 6, 7, 8, 10, 12, 14, 16, 20, 24, 28, 32];
    private const WAVE_BASE = [0x000, 0x200, 0x200, 0x800, 0xa00, 0xc00, 0x100, 0x400];
    private const WAVE_MASK_TABLE = [1023, 1023, 511, 511, 1023, 1023, 512, 1023];
    private const WAVE_START = [512, 0, 0, 0, 0, 512, 512, 256];
    /** Bit8s values: 1, 0, 1, 30, 1 - 0x80, 0 - 0x80, 1 - 0x80, 30 - 0x80 */
    private const VIBRATO_TABLE = [1, 0, 1, 30, -127, -128, -127, -98];
    private const KSL_SHIFT = [31, 1, 2, 0];

    /** @var int[] */
    private static array $waveTable = [];
    /** @var int[] */
    private static array $mulTable = [];
    /** @var int[] */
    private static array $kslTable = [];
    /** @var int[] */
    private static array $tremoloTable = [];
    /** @var int[] register channel index (reg & 0xf) -> channel, -1 = none */
    private static array $chanOffset = [];
    /** @var int[] register operator index (reg & 0x1f) -> operator (channel * 2 + op), -1 = none */
    private static array $opOffset = [];
    /** @var int[] noise LFSR jump tables: [(k * 3 + byte) * 256 + value] = 2^k steps of the bits of that byte */
    private static array $noiseJump = [];
    /** @var array<int, array{int[], int[], int[], int, int, int}> per sample rate: freqMul, linearRates, attackRates, noiseAdd, lfoAdd */
    private static array $rateCache = [];

    // chip
    private int $lfoCounter = 0;
    private int $lfoAdd = 0;
    private int $noiseCounter = 0;
    private int $noiseAdd = 0;
    private int $noiseValue = 1;
    /** @var int[] */
    private array $freqMul;
    /** @var int[] */
    private array $linearRates;
    /** @var int[] */
    private array $attackRates;
    private int $reg08 = 0;
    private int $regBD = 0;
    private int $vibratoIndex = 0;
    private int $tremoloIndex = 0;
    private int $vibratoSign = 0;
    private int $vibratoShift = 0;
    private int $tremoloValue = 0;
    private int $vibratoStrength = 0;
    private int $tremoloStrength = 0;
    private int $waveFormMask = 0;

    // channels (9)
    /** @var int[] */
    private array $cSynth;
    /** @var int[] */
    private array $cChanData;
    /** @var int[] */
    private array $cOld0;
    /** @var int[] */
    private array $cOld1;
    /** @var int[] */
    private array $cFeedback;
    /** @var int[] */
    private array $cRegB0;
    /** @var int[] */
    private array $cRegC0;
    /** @var int[] */
    private array $cFourMask;

    // operators (18, index = channel * 2 + op)
    /** @var int[] */
    private array $oWaveBase;
    /** @var int[] */
    private array $oWaveMask;
    /** @var int[] */
    private array $oWaveStart;
    /** @var int[] */
    private array $oWaveIndex;
    /** @var int[] */
    private array $oWaveAdd;
    /** @var int[] */
    private array $oWaveCurrent;
    /** @var int[] */
    private array $oChanData;
    /** @var int[] */
    private array $oFreqMul;
    /** @var int[] */
    private array $oVibrato;
    /** @var int[] */
    private array $oSustainLevel;
    /** @var int[] */
    private array $oTotalLevel;
    /** @var int[] */
    private array $oCurrentLevel;
    /** @var int[] */
    private array $oVolume;
    /** @var int[] */
    private array $oAttackAdd;
    /** @var int[] */
    private array $oDecayAdd;
    /** @var int[] */
    private array $oReleaseAdd;
    /** @var int[] */
    private array $oRateIndex;
    /** @var int[] */
    private array $oRateZero;
    /** @var int[] */
    private array $oKeyOn;
    /** @var int[] */
    private array $oReg20;
    /** @var int[] */
    private array $oReg40;
    /** @var int[] */
    private array $oReg60;
    /** @var int[] */
    private array $oReg80;
    /** @var int[] */
    private array $oRegE0;
    /** @var int[] */
    private array $oState;
    /** @var int[] */
    private array $oTremoloMask;
    /** @var int[] */
    private array $oVibStrength;
    /** @var int[] */
    private array $oKsr;

    public function __construct(int $sampleRate = 16000)
    {
        self::initTables();
        // Channel::Channel / Operator::Operator
        $this->cSynth = array_fill(0, 9, self::SM_FM);
        $this->cChanData = array_fill(0, 9, 0);
        $this->cOld0 = array_fill(0, 9, 0);
        $this->cOld1 = array_fill(0, 9, 0);
        $this->cFeedback = array_fill(0, 9, 31);
        $this->cRegB0 = array_fill(0, 9, 0);
        $this->cRegC0 = array_fill(0, 9, 0);
        $this->cFourMask = [0x01, 0x81, 0x02, 0x82, 0x04, 0x84, 0x40, 0x40, 0x40];
        $z = array_fill(0, 18, 0);
        // waveform 0 (what Chip::Setup's OPL3 clearing pass leaves selected)
        $this->oWaveBase = array_fill(0, 18, self::WAVE_BASE[0]);
        $this->oWaveMask = array_fill(0, 18, self::WAVE_MASK_TABLE[0]);
        $this->oWaveStart = array_fill(0, 18, self::WAVE_START[0] << self::WAVE_SH);
        $this->oWaveIndex = $z;
        $this->oWaveAdd = $z;
        $this->oWaveCurrent = $z;
        $this->oChanData = $z;
        $this->oFreqMul = $z;
        $this->oVibrato = $z;
        $this->oSustainLevel = array_fill(0, 18, self::ENV_MAX);
        $this->oTotalLevel = array_fill(0, 18, self::ENV_MAX);
        $this->oCurrentLevel = array_fill(0, 18, self::ENV_MAX);
        $this->oVolume = array_fill(0, 18, self::ENV_MAX);
        $this->oAttackAdd = $z;
        $this->oDecayAdd = $z;
        $this->oReleaseAdd = $z;
        $this->oRateIndex = $z;
        $this->oRateZero = array_fill(0, 18, 1 << self::OFF);
        $this->oKeyOn = $z;
        $this->oReg20 = $z;
        $this->oReg40 = $z;
        $this->oReg60 = $z;
        $this->oReg80 = $z;
        $this->oRegE0 = $z;
        $this->oState = array_fill(0, 18, self::OFF);
        $this->oTremoloMask = $z;
        $this->oVibStrength = $z;
        $this->oKsr = $z;

        // Chip::Setup
        if (!isset(self::$rateCache[$sampleRate])) {
            self::$rateCache[$sampleRate] = self::computeRates($sampleRate);
        }
        [$this->freqMul, $this->linearRates, $this->attackRates, $this->noiseAdd, $this->lfoAdd] = self::$rateCache[$sampleRate];
        $this->noiseCounter = 0;
        $this->noiseValue = 1;
        $this->lfoCounter = 0;
        $this->vibratoIndex = 0;
        $this->tremoloIndex = 0;
        // clear everything in opl2 mode
        for ($i = 0; $i < 255; $i++) {
            $this->write($i, 0xff);
            $this->write($i, 0x0);
        }
    }

    private static function s32(int $x): int
    {
        return (($x & 0xFFFFFFFF) ^ 0x80000000) - 0x80000000;
    }

    /** @return array{int, int} index, shift */
    private static function envelopeSelect(int $val): array
    {
        if ($val < 13 * 4) {
            return [$val & 3, 12 - ($val >> 2)];
        }
        if ($val < 15 * 4) {
            return [$val - 12 * 4, 0];
        }
        return [12, 0];
    }

    /** The rate dependent part of Chip::Setup */
    private static function computeRates(int $rate): array
    {
        $scale = self::OPLRATE / (float)$rate;
        $noiseAdd = (int)(0.5 + $scale * (1 << self::LFO_SH)) & 0xFFFFFFFF;
        $lfoAdd = $noiseAdd;
        $freqScale = (int)(0.5 + $scale * (1 << (self::WAVE_SH - 1 - 10))) & 0xFFFFFFFF;
        $freqMul = [];
        for ($i = 0; $i < 16; $i++) {
            $freqMul[$i] = ($freqScale * self::FREQ_CREATE[$i]) & 0xFFFFFFFF;
        }
        $linearRates = [];
        for ($i = 0; $i < 76; $i++) {
            [$index, $shift] = self::envelopeSelect($i);
            $linearRates[$i] = (int)($scale * (self::ENVELOPE_INCREASE[$index] << (self::RATE_SH - $shift - 3))) & 0xFFFFFFFF;
        }
        $attackRates = [];
        for ($i = 0; $i < 62; $i++) {
            [$index, $shift] = self::envelopeSelect($i);
            $original = self::s32((int)((self::ATTACK_SAMPLES[$index] << $shift) / $scale));
            $guessAdd = self::s32((int)($scale * (self::ENVELOPE_INCREASE[$index] << (self::RATE_SH - $shift - 3))));
            $bestAdd = $guessAdd;
            $bestDiff = 1 << 30;
            $limit = self::s32($original * 2);
            for ($passes = 0; $passes < 16; $passes++) {
                $volume = self::ENV_MAX;
                $samples = 0;
                $count = 0;
                $add = $guessAdd & 0xFFFFFFFF;
                while ($volume > 0 && $samples < $limit) {
                    $count = ($count + $add) & 0xFFFFFFFF;
                    $change = $count >> self::RATE_SH;
                    $count &= self::RATE_MASK;
                    if ($change !== 0) {
                        $volume += ((~$volume) * $change) >> 3;
                    }
                    $samples++;
                }
                $diff = self::s32($original - $samples);
                $lDiff = abs($diff);
                if ($lDiff < $bestDiff) {
                    $bestDiff = $lDiff;
                    $bestAdd = $guessAdd;
                    if ($bestDiff === 0) {
                        break;
                    }
                }
                if ($diff < 0) {
                    $mul = intdiv(self::s32(($original - $diff) << 12), $original);
                    $guessAdd = self::s32(self::s32($guessAdd * $mul) >> 12);
                    $guessAdd = self::s32($guessAdd + 1);
                } elseif ($diff > 0) {
                    $mul = intdiv(self::s32(($original - $diff) << 12), $original);
                    $guessAdd = self::s32(self::s32($guessAdd * $mul) >> 12);
                    $guessAdd = self::s32($guessAdd - 1);
                }
            }
            $attackRates[$i] = $bestAdd & 0xFFFFFFFF;
        }
        for ($i = 62; $i < 76; $i++) {
            $attackRates[$i] = 8 << self::RATE_SH;
        }
        return [$freqMul, $linearRates, $attackRates, $noiseAdd, $lfoAdd];
    }

    /** DBOPL_InitTables (WAVE_TABLEMUL) */
    private static function initTables(): void
    {
        if (self::$waveTable !== []) {
            return;
        }
        $mul = [];
        for ($i = 0; $i < 384; $i++) {
            $s = $i * 8;
            $val = 0.5 + (2.0 ** (-1.0 + (255 - $s) * (1.0 / 256))) * (1 << self::MUL_SH);
            $mul[$i] = ((int)$val) & 0xFFFF;
        }
        $wt = array_fill(0, 8 * 512, 0);
        for ($i = 0; $i < 512; $i++) {
            $wt[0x0200 + $i] = (int)(sin(($i + 0.5) * (M_PI / 512.0)) * 4084);
            $wt[0x0000 + $i] = -$wt[0x200 + $i];
        }
        for ($i = 0; $i < 256; $i++) {
            $wt[0x700 + $i] = (int)(0.5 + (2.0 ** (-1.0 + (255 - $i * 8) * (1.0 / 256))) * 4085);
            $wt[0x6ff - $i] = -$wt[0x700 + $i];
        }
        for ($i = 0; $i < 256; $i++) {
            $wt[0x400 + $i] = $wt[0];
            $wt[0x500 + $i] = $wt[0];
            $wt[0x900 + $i] = $wt[0];
            $wt[0xc00 + $i] = $wt[0];
            $wt[0xd00 + $i] = $wt[0];
            $wt[0x800 + $i] = $wt[0x200 + $i];
            $wt[0xa00 + $i] = $wt[0x200 + $i * 2];
            $wt[0xb00 + $i] = $wt[0x000 + $i * 2];
            $wt[0xe00 + $i] = $wt[0x200 + $i * 2];
            $wt[0xf00 + $i] = $wt[0x200 + $i * 2];
        }
        $ksl = [];
        for ($oct = 0; $oct < 8; $oct++) {
            $base = $oct * 8;
            for ($i = 0; $i < 16; $i++) {
                $val = $base - self::KSL_CREATE[$i];
                if ($val < 0) {
                    $val = 0;
                }
                $ksl[$oct * 16 + $i] = $val * 4;
            }
        }
        $trem = array_fill(0, self::TREMOLO_TABLE, 0);
        for ($i = 0; $i < self::TREMOLO_TABLE / 2; $i++) {
            $trem[$i] = $i;
            $trem[self::TREMOLO_TABLE - 1 - $i] = $i;
        }
        // channel / operator register maps (ChanOffsetTable / OpOffsetTable, first register bank)
        $chan = [];
        for ($i = 0; $i < 16; $i++) {
            $index = $i;
            if ($index >= 9) {
                $chan[$i] = -1;
                continue;
            }
            if ($index < 6) {
                $index = ($index % 3) * 2 + intdiv($index, 3);
            }
            $chan[$i] = $index;
        }
        $op = [];
        for ($i = 0; $i < 32; $i++) {
            if ($i % 8 >= 6 || (intdiv($i, 8) % 4) === 3) {
                $op[$i] = -1;
                continue;
            }
            $chNum = intdiv($i, 8) * 3 + ($i % 8) % 3;
            $opNum = intdiv($i % 8, 3);
            $op[$i] = $chan[$chNum] * 2 + $opNum;
        }
        // noise LFSR (value ^= 0x800302 & -(value & 1); value >>= 1) is linear over GF(2):
        // jump tables for 2^k steps, one per byte of the 23-bit state
        $jump = [];
        for ($k = 0; $k < 12; $k++) {
            $img = [];
            for ($bit = 0; $bit < 24; $bit++) {
                $v = 1 << $bit;
                for ($n = 1 << $k; $n > 0; $n--) {
                    $v ^= 0x800302 & (0 - ($v & 1));
                    $v >>= 1;
                }
                $img[$bit] = $v;
            }
            for ($byte = 0; $byte < 3; $byte++) {
                $base = ($k * 3 + $byte) * 256;
                for ($val = 0; $val < 256; $val++) {
                    $r = 0;
                    for ($b = 0; $b < 8; $b++) {
                        if (($val >> $b) & 1) {
                            $r ^= $img[$byte * 8 + $b];
                        }
                    }
                    $jump[$base + $val] = $r;
                }
            }
        }
        self::$mulTable = $mul;
        self::$kslTable = $ksl;
        self::$tremoloTable = $trem;
        self::$chanOffset = $chan;
        self::$opOffset = $op;
        self::$noiseJump = $jump;
        self::$waveTable = $wt;
    }

    // ---------------------------------------------------------------- operator

    private function setState(int $o, int $s): void
    {
        $this->oState[$o] = $s;
    }

    private function updateAttack(int $o): void
    {
        $rate = $this->oReg60[$o] >> 4;
        if ($rate !== 0) {
            $this->oAttackAdd[$o] = $this->attackRates[($rate << 2) + $this->oKsr[$o]];
            $this->oRateZero[$o] &= ~(1 << self::ATTACK);
        } else {
            $this->oAttackAdd[$o] = 0;
            $this->oRateZero[$o] |= (1 << self::ATTACK);
        }
    }

    private function updateDecay(int $o): void
    {
        $rate = $this->oReg60[$o] & 0xf;
        if ($rate !== 0) {
            $this->oDecayAdd[$o] = $this->linearRates[($rate << 2) + $this->oKsr[$o]];
            $this->oRateZero[$o] &= ~(1 << self::DECAY);
        } else {
            $this->oDecayAdd[$o] = 0;
            $this->oRateZero[$o] |= (1 << self::DECAY);
        }
    }

    private function updateRelease(int $o): void
    {
        $rate = $this->oReg80[$o] & 0xf;
        if ($rate !== 0) {
            $this->oReleaseAdd[$o] = $this->linearRates[($rate << 2) + $this->oKsr[$o]];
            $this->oRateZero[$o] &= ~(1 << self::RELEASE);
            if (!($this->oReg20[$o] & self::MASK_SUSTAIN)) {
                $this->oRateZero[$o] &= ~(1 << self::SUSTAIN);
            }
        } else {
            $this->oRateZero[$o] |= (1 << self::RELEASE);
            $this->oReleaseAdd[$o] = 0;
            if (!($this->oReg20[$o] & self::MASK_SUSTAIN)) {
                $this->oRateZero[$o] |= (1 << self::SUSTAIN);
            }
        }
    }

    private function updateAttenuation(int $o): void
    {
        $kslBase = ($this->oChanData[$o] >> self::SHIFT_KSLBASE) & 0xff;
        $tl = $this->oReg40[$o] & 0x3f;
        $kslShift = self::KSL_SHIFT[$this->oReg40[$o] >> 6];
        $this->oTotalLevel[$o] = ($tl << 2) + ($kslBase >> $kslShift);
    }

    private function updateFrequency(int $o): void
    {
        $chanData = $this->oChanData[$o];
        $freq = $chanData & ((1 << 10) - 1);
        $block = ($chanData >> 10) & 0xff;
        $this->oWaveAdd[$o] = (($freq << $block) * $this->oFreqMul[$o]) & 0xFFFFFFFF;
        if ($this->oReg20[$o] & self::MASK_VIBRATO) {
            $vs = ($freq >> 7) & 0xff;
            $this->oVibStrength[$o] = $vs;
            $this->oVibrato[$o] = (($vs << $block) * $this->oFreqMul[$o]) & 0xFFFFFFFF;
        } else {
            $this->oVibStrength[$o] = 0;
            $this->oVibrato[$o] = 0;
        }
    }

    private function updateRates(int $o): void
    {
        $newKsr = ($this->oChanData[$o] >> self::SHIFT_KEYCODE) & 0xff;
        if (!($this->oReg20[$o] & self::MASK_KSR)) {
            $newKsr >>= 2;
        }
        if ($this->oKsr[$o] === $newKsr) {
            return;
        }
        $this->oKsr[$o] = $newKsr;
        $this->updateAttack($o);
        $this->updateDecay($o);
        $this->updateRelease($o);
    }

    private function write20(int $o, int $val): void
    {
        $change = $this->oReg20[$o] ^ $val;
        if (!$change) {
            return;
        }
        $this->oReg20[$o] = $val;
        $this->oTremoloMask[$o] = ($val & 0x80) ? 0xff : 0;
        if ($change & self::MASK_KSR) {
            $this->updateRates($o);
        }
        if (($val & self::MASK_SUSTAIN) || !$this->oReleaseAdd[$o]) {
            $this->oRateZero[$o] |= (1 << self::SUSTAIN);
        } else {
            $this->oRateZero[$o] &= ~(1 << self::SUSTAIN);
        }
        if ($change & (0xf | self::MASK_VIBRATO)) {
            $this->oFreqMul[$o] = $this->freqMul[$val & 0xf];
            $this->updateFrequency($o);
        }
    }

    private function write40(int $o, int $val): void
    {
        if (!($this->oReg40[$o] ^ $val)) {
            return;
        }
        $this->oReg40[$o] = $val;
        $this->updateAttenuation($o);
    }

    private function write60(int $o, int $val): void
    {
        $change = $this->oReg60[$o] ^ $val;
        $this->oReg60[$o] = $val;
        if ($change & 0x0f) {
            $this->updateDecay($o);
        }
        if ($change & 0xf0) {
            $this->updateAttack($o);
        }
    }

    private function write80(int $o, int $val): void
    {
        $change = $this->oReg80[$o] ^ $val;
        if (!$change) {
            return;
        }
        $this->oReg80[$o] = $val;
        $sustain = $val >> 4;
        $sustain |= ($sustain + 1) & 0x10;
        $this->oSustainLevel[$o] = $sustain << 4;
        if ($change & 0x0f) {
            $this->updateRelease($o);
        }
    }

    private function writeE0(int $o, int $val): void
    {
        if (!($this->oRegE0[$o] ^ $val)) {
            return;
        }
        $waveForm = $val & (0x3 & $this->waveFormMask);
        $this->oRegE0[$o] = $val;
        $this->oWaveBase[$o] = self::WAVE_BASE[$waveForm];
        $this->oWaveStart[$o] = self::WAVE_START[$waveForm] << self::WAVE_SH;
        $this->oWaveMask[$o] = self::WAVE_MASK_TABLE[$waveForm];
    }

    private function silent(int $o): bool
    {
        if ($this->oTotalLevel[$o] + $this->oVolume[$o] < self::ENV_LIMIT) {
            return false;
        }
        return ($this->oRateZero[$o] & (1 << $this->oState[$o])) !== 0;
    }

    private function prepare(int $o): void
    {
        $this->oCurrentLevel[$o] = $this->oTotalLevel[$o] + ($this->tremoloValue & $this->oTremoloMask[$o]);
        $wc = $this->oWaveAdd[$o];
        if ($this->oVibStrength[$o] >> $this->vibratoShift) {
            $add = self::s32($this->oVibrato[$o] >> $this->vibratoShift);
            $neg = $this->vibratoSign;
            $add = ($add ^ $neg) - $neg;
            $wc = ($wc + $add) & 0xFFFFFFFF;
        }
        $this->oWaveCurrent[$o] = $wc;
    }

    private function keyOn(int $o, int $mask): void
    {
        if (!$this->oKeyOn[$o]) {
            $this->oWaveIndex[$o] = $this->oWaveStart[$o];
            $this->oRateIndex[$o] = 0;
            $this->setState($o, self::ATTACK);
        }
        $this->oKeyOn[$o] |= $mask;
    }

    private function keyOff(int $o, int $mask): void
    {
        $this->oKeyOn[$o] &= ~$mask;
        if (!$this->oKeyOn[$o]) {
            if ($this->oState[$o] !== self::OFF) {
                $this->setState($o, self::RELEASE);
            }
        }
    }

    /** Operator::TemplateVolume for the current state */
    private function envelope(int $o): int
    {
        $vol = $this->oVolume[$o];
        switch ($this->oState[$o]) {
            case self::OFF:
                return self::ENV_MAX;
            case self::ATTACK:
                $ri = ($this->oRateIndex[$o] + $this->oAttackAdd[$o]) & 0xFFFFFFFF;
                $change = $ri >> self::RATE_SH;
                $this->oRateIndex[$o] = $ri & self::RATE_MASK;
                if (!$change) {
                    return $vol;
                }
                $vol += ((~$vol) * $change) >> 3;
                if ($vol < 0) {
                    $this->oVolume[$o] = 0;
                    $this->oRateIndex[$o] = 0;
                    $this->oState[$o] = self::DECAY;
                    return 0;
                }
                break;
            case self::DECAY:
                $ri = $this->oRateIndex[$o] + $this->oDecayAdd[$o];
                $vol += $ri >> self::RATE_SH;
                $this->oRateIndex[$o] = $ri & self::RATE_MASK;
                if ($vol >= $this->oSustainLevel[$o]) {
                    if ($vol >= self::ENV_MAX) {
                        $this->oVolume[$o] = self::ENV_MAX;
                        $this->oState[$o] = self::OFF;
                        return self::ENV_MAX;
                    }
                    $this->oRateIndex[$o] = 0;
                    $this->oState[$o] = self::SUSTAIN;
                }
                break;
            case self::SUSTAIN:
                if ($this->oReg20[$o] & self::MASK_SUSTAIN) {
                    return $vol;
                }
                // no break: in sustain phase but not sustaining, do a regular release
            case self::RELEASE:
                $ri = $this->oRateIndex[$o] + $this->oReleaseAdd[$o];
                $vol += $ri >> self::RATE_SH;
                $this->oRateIndex[$o] = $ri & self::RATE_MASK;
                if ($vol >= self::ENV_MAX) {
                    $this->oVolume[$o] = self::ENV_MAX;
                    $this->oState[$o] = self::OFF;
                    return self::ENV_MAX;
                }
                break;
        }
        $this->oVolume[$o] = $vol;
        return $vol;
    }

    private function forwardVolume(int $o): int
    {
        return $this->oCurrentLevel[$o] + $this->envelope($o);
    }

    private function forwardWave(int $o): int
    {
        $wi = ($this->oWaveIndex[$o] + $this->oWaveCurrent[$o]) & 0xFFFFFFFF;
        $this->oWaveIndex[$o] = $wi;
        return $wi >> self::WAVE_SH;
    }

    private function getWave(int $o, int $index, int $vol): int
    {
        return (self::$waveTable[$this->oWaveBase[$o] + ($index & $this->oWaveMask[$o])] * self::$mulTable[$vol]) >> self::MUL_SH;
    }

    private function getSample(int $o, int $modulation): int
    {
        $vol = $this->forwardVolume($o);
        if ($vol >= self::ENV_LIMIT) {
            $this->oWaveIndex[$o] = ($this->oWaveIndex[$o] + $this->oWaveCurrent[$o]) & 0xFFFFFFFF;
            return 0;
        }
        $index = $this->forwardWave($o) + $modulation;
        return $this->getWave($o, $index, $vol);
    }

    // ---------------------------------------------------------------- channel

    private function setChanData(int $c, int $data): void
    {
        $change = $this->cChanData[$c] ^ $data;
        $this->cChanData[$c] = $data;
        $o0 = $c * 2;
        $o1 = $o0 + 1;
        $this->oChanData[$o0] = $data;
        $this->oChanData[$o1] = $data;
        $this->updateFrequency($o0);
        $this->updateFrequency($o1);
        if ($change & (0xff << self::SHIFT_KSLBASE)) {
            $this->updateAttenuation($o0);
            $this->updateAttenuation($o1);
        }
        if ($change & (0xff << self::SHIFT_KEYCODE)) {
            $this->updateRates($o0);
            $this->updateRates($o1);
        }
    }

    private function channelUpdateFrequency(int $c): void
    {
        $data = $this->cChanData[$c] & 0xffff;
        $kslBase = self::$kslTable[$data >> 6];
        $keyCode = ($data & 0x1c00) >> 9;
        if ($this->reg08 & 0x40) {
            $keyCode |= ($data & 0x100) >> 8;
        } else {
            $keyCode |= ($data & 0x200) >> 9;
        }
        $data |= ($keyCode << self::SHIFT_KEYCODE) | ($kslBase << self::SHIFT_KSLBASE);
        $this->setChanData($c, $data);
    }

    private function writeA0(int $c, int $val): void
    {
        $change = ($this->cChanData[$c] ^ $val) & 0xff;
        if ($change) {
            $this->cChanData[$c] ^= $change;
            $this->channelUpdateFrequency($c);
        }
    }

    private function writeB0(int $c, int $val): void
    {
        $change = ($this->cChanData[$c] ^ ($val << 8)) & 0x1f00;
        if ($change) {
            $this->cChanData[$c] ^= $change;
            $this->channelUpdateFrequency($c);
        }
        if (!(($val ^ $this->cRegB0[$c]) & 0x20)) {
            return;
        }
        $this->cRegB0[$c] = $val;
        if ($val & 0x20) {
            $this->keyOn($c * 2, 0x1);
            $this->keyOn($c * 2 + 1, 0x1);
        } else {
            $this->keyOff($c * 2, 0x1);
            $this->keyOff($c * 2 + 1, 0x1);
        }
    }

    private function writeC0(int $c, int $val): void
    {
        $change = $val ^ $this->cRegC0[$c];
        if (!$change) {
            return;
        }
        $this->cRegC0[$c] = $val;
        $fb = ($val >> 1) & 7;
        $this->cFeedback[$c] = $fb ? 9 - $fb : 31;
        if (($this->cFourMask[$c] & 0x40) && ($this->regBD & 0x20)) {
            // percussion channels are not updated
        } elseif ($val & 1) {
            $this->cSynth[$c] = self::SM_AM;
        } else {
            $this->cSynth[$c] = self::SM_FM;
        }
    }

    private function resetC0(int $c): void
    {
        $val = $this->cRegC0[$c];
        $this->cRegC0[$c] ^= 0xff;
        $this->writeC0($c, $val);
    }

    // ---------------------------------------------------------------- chip

    private function writeBD(int $val): void
    {
        $change = $this->regBD ^ $val;
        if (!$change) {
            return;
        }
        $this->regBD = $val;
        $this->vibratoStrength = ($val & 0x40) ? 0x00 : 0x01;
        $this->tremoloStrength = ($val & 0x80) ? 0x00 : 0x02;
        if ($val & 0x20) {
            if ($change & 0x20) {
                $this->cSynth[6] = self::SM_PERC;
            }
            // bass drum
            if ($val & 0x10) {
                $this->keyOn(12, 0x2);
                $this->keyOn(13, 0x2);
            } else {
                $this->keyOff(12, 0x2);
                $this->keyOff(13, 0x2);
            }
            // hi-hat
            ($val & 0x1) ? $this->keyOn(14, 0x2) : $this->keyOff(14, 0x2);
            // snare
            ($val & 0x8) ? $this->keyOn(15, 0x2) : $this->keyOff(15, 0x2);
            // tom-tom
            ($val & 0x4) ? $this->keyOn(16, 0x2) : $this->keyOff(16, 0x2);
            // top cymbal
            ($val & 0x2) ? $this->keyOn(17, 0x2) : $this->keyOff(17, 0x2);
        } elseif ($change & 0x20) {
            $this->resetC0(6);
            for ($o = 12; $o < 18; $o++) {
                $this->keyOff($o, 0x2);
            }
        }
    }

    /** Chip::WriteReg (OPL2 registers 0x00-0xFF) */
    public function write(int $reg, int $val): void
    {
        if ($reg < 0 || $reg > 0xff) {
            return; // OPL3 bank: not emulated
        }
        $val &= 0xff;
        switch ($reg >> 4) {
            case 0x0:
                if ($reg === 0x01) {
                    $this->waveFormMask = ($val & 0x20) ? 0x7 : 0x0;
                } elseif ($reg === 0x08) {
                    $this->reg08 = $val;
                }
                break;
            case 0x2:
            case 0x3:
                $o = self::$opOffset[$reg & 0x1f];
                if ($o >= 0) {
                    $this->write20($o, $val);
                }
                break;
            case 0x4:
            case 0x5:
                $o = self::$opOffset[$reg & 0x1f];
                if ($o >= 0) {
                    $this->write40($o, $val);
                }
                break;
            case 0x6:
            case 0x7:
                $o = self::$opOffset[$reg & 0x1f];
                if ($o >= 0) {
                    $this->write60($o, $val);
                }
                break;
            case 0x8:
            case 0x9:
                $o = self::$opOffset[$reg & 0x1f];
                if ($o >= 0) {
                    $this->write80($o, $val);
                }
                break;
            case 0xa:
                $c = self::$chanOffset[$reg & 0xf];
                if ($c >= 0) {
                    $this->writeA0($c, $val);
                }
                break;
            case 0xb:
                if ($reg === 0xbd) {
                    $this->writeBD($val);
                } else {
                    $c = self::$chanOffset[$reg & 0xf];
                    if ($c >= 0) {
                        $this->writeB0($c, $val);
                    }
                }
                break;
            case 0xc:
                $c = self::$chanOffset[$reg & 0xf];
                if ($c >= 0) {
                    $this->writeC0($c, $val);
                }
                break;
            case 0xe:
            case 0xf:
                $o = self::$opOffset[$reg & 0x1f];
                if ($o >= 0) {
                    $this->writeE0($o, $val);
                }
                break;
        }
    }

    /** Chip::ForwardLFO: returns how many samples can be generated with the current LFO values */
    private function forwardLfo(int $samples): int
    {
        $v = self::VIBRATO_TABLE[$this->vibratoIndex >> 2];
        $this->vibratoSign = $v >> 7;
        $this->vibratoShift = ($v & 7) + $this->vibratoStrength;
        $this->tremoloValue = self::$tremoloTable[$this->tremoloIndex] >> $this->tremoloStrength;

        $todo = self::LFO_MAX - $this->lfoCounter;
        $count = intdiv($todo + $this->lfoAdd - 1, $this->lfoAdd);
        if ($count > $samples) {
            $count = $samples;
            $this->lfoCounter += $count * $this->lfoAdd;
        } else {
            $this->lfoCounter += $count * $this->lfoAdd;
            $this->lfoCounter &= (self::LFO_MAX - 1);
            $this->vibratoIndex = ($this->vibratoIndex + 1) & 31;
            if ($this->tremoloIndex + 1 < self::TREMOLO_TABLE) {
                ++$this->tremoloIndex;
            } else {
                $this->tremoloIndex = 0;
            }
        }
        return $count;
    }

    /** Chip::ForwardNoise */
    private function forwardNoise(): int
    {
        $this->noiseCounter += $this->noiseAdd;
        $count = $this->noiseCounter >> self::LFO_SH;
        $this->noiseCounter &= self::WAVE_MASK;
        $v = $this->noiseValue;
        $jump = self::$noiseJump;
        $base = 0;
        while ($count > 0) {
            if ($count & 1) {
                $v = $jump[$base + ($v & 0xff)] ^ $jump[$base + 256 + (($v >> 8) & 0xff)] ^ $jump[$base + 512 + ($v >> 16)];
            }
            $count >>= 1;
            $base += 768;
        }
        $this->noiseValue = $v;
        return $v;
    }

    /**
     * Generates $count mono samples (Chip::GenerateBlock2), as floats in -1..1.
     * @return float[]
     */
    public function generate(int $count): array
    {
        if ($count <= 0) {
            return [];
        }
        $buf = array_fill(0, $count, 0);
        $offset = 0;
        $total = $count;
        while ($total > 0) {
            $samples = $this->forwardLfo($total);
            for ($c = 0; $c < 9;) {
                $mode = $this->cSynth[$c];
                if ($mode === self::SM_PERC) {
                    $this->blockPercussion($c, $buf, $offset, $samples);
                    $c += 3;
                } else {
                    $this->block2Op($c, $mode === self::SM_AM, $buf, $offset, $samples);
                    $c++;
                }
            }
            $total -= $samples;
            $offset += $samples;
        }
        $out = [];
        $scale = self::OUT_SCALE;
        foreach ($buf as $s) {
            $out[] = $s * $scale;
        }
        return $out;
    }

    /** Channel::BlockTemplate for sm2AM / sm2FM, with the operators' state in local variables */
    private function block2Op(int $c, bool $am, array &$buf, int $offset, int $samples): void
    {
        $a = $c * 2;
        $b = $a + 1;
        if ($am) {
            if ($this->silent($a) && $this->silent($b)) {
                $this->cOld0[$c] = $this->cOld1[$c] = 0;
                return;
            }
        } elseif ($this->silent($b)) {
            $this->cOld0[$c] = $this->cOld1[$c] = 0;
            return;
        }
        $this->prepare($a);
        $this->prepare($b);
        $wt = self::$waveTable;
        $mt = self::$mulTable;
        $fb = $this->cFeedback[$c];
        $old0 = $this->cOld0[$c];
        $old1 = $this->cOld1[$c];

        $aVol = $this->oVolume[$a];
        $aState = $this->oState[$a];
        $aRate = $this->oRateIndex[$a];
        $aAtt = $this->oAttackAdd[$a];
        $aDec = $this->oDecayAdd[$a];
        $aRel = $this->oReleaseAdd[$a];
        $aSus = $this->oSustainLevel[$a];
        $aSusOn = ($this->oReg20[$a] & self::MASK_SUSTAIN) !== 0;
        $aLvl = $this->oCurrentLevel[$a];
        $aWi = $this->oWaveIndex[$a];
        $aWc = $this->oWaveCurrent[$a];
        $aWb = $this->oWaveBase[$a];
        $aWm = $this->oWaveMask[$a];

        $bVol = $this->oVolume[$b];
        $bState = $this->oState[$b];
        $bRate = $this->oRateIndex[$b];
        $bAtt = $this->oAttackAdd[$b];
        $bDec = $this->oDecayAdd[$b];
        $bRel = $this->oReleaseAdd[$b];
        $bSus = $this->oSustainLevel[$b];
        $bSusOn = ($this->oReg20[$b] & self::MASK_SUSTAIN) !== 0;
        $bLvl = $this->oCurrentLevel[$b];
        $bWi = $this->oWaveIndex[$b];
        $bWc = $this->oWaveCurrent[$b];
        $bWb = $this->oWaveBase[$b];
        $bWm = $this->oWaveMask[$b];

        $end = $offset + $samples;
        for ($i = $offset; $i < $end; $i++) {
            // unsigned shift: all bits can be shifted out but stay in the 10-bit range otherwise
            $mod = (($old0 + $old1) & 0xFFFFFFFF) >> $fb;
            $old0 = $old1;

            // ---- operator a (modulator): envelope
            if ($aState === 4) {
                $aRate = ($aRate + $aAtt) & 0xFFFFFFFF;
                $chg = $aRate >> 24;
                $aRate &= 0xFFFFFF;
                if ($chg !== 0) {
                    $aVol += ((~$aVol) * $chg) >> 3;
                    if ($aVol < 0) {
                        $aVol = 0;
                        $aRate = 0;
                        $aState = 3;
                    }
                }
                $env = $aVol;
            } elseif ($aState === 3) {
                $aRate += $aDec;
                $aVol += $aRate >> 24;
                $aRate &= 0xFFFFFF;
                if ($aVol >= $aSus) {
                    if ($aVol >= 511) {
                        $aVol = 511;
                        $aState = 0;
                    } else {
                        $aRate = 0;
                        $aState = 2;
                    }
                }
                $env = $aVol;
            } elseif ($aState === 0) {
                $env = 511;
            } elseif ($aState === 2 && $aSusOn) {
                $env = $aVol;
            } else {
                $aRate += $aRel;
                $aVol += $aRate >> 24;
                $aRate &= 0xFFFFFF;
                if ($aVol >= 511) {
                    $aVol = 511;
                    $aState = 0;
                }
                $env = $aVol;
            }
            $tot = $aLvl + $env;
            $aWi = ($aWi + $aWc) & 0xFFFFFFFF;
            $old1 = $tot >= 384 ? 0 : ($wt[$aWb + ((($aWi >> 22) + $mod) & $aWm)] * $mt[$tot]) >> 16;

            // ---- operator b (carrier): envelope
            if ($bState === 4) {
                $bRate = ($bRate + $bAtt) & 0xFFFFFFFF;
                $chg = $bRate >> 24;
                $bRate &= 0xFFFFFF;
                if ($chg !== 0) {
                    $bVol += ((~$bVol) * $chg) >> 3;
                    if ($bVol < 0) {
                        $bVol = 0;
                        $bRate = 0;
                        $bState = 3;
                    }
                }
                $env = $bVol;
            } elseif ($bState === 3) {
                $bRate += $bDec;
                $bVol += $bRate >> 24;
                $bRate &= 0xFFFFFF;
                if ($bVol >= $bSus) {
                    if ($bVol >= 511) {
                        $bVol = 511;
                        $bState = 0;
                    } else {
                        $bRate = 0;
                        $bState = 2;
                    }
                }
                $env = $bVol;
            } elseif ($bState === 0) {
                $env = 511;
            } elseif ($bState === 2 && $bSusOn) {
                $env = $bVol;
            } else {
                $bRate += $bRel;
                $bVol += $bRate >> 24;
                $bRate &= 0xFFFFFF;
                if ($bVol >= 511) {
                    $bVol = 511;
                    $bState = 0;
                }
                $env = $bVol;
            }
            $tot = $bLvl + $env;
            $bWi = ($bWi + $bWc) & 0xFFFFFFFF;
            if ($am) {
                $s = $tot >= 384 ? 0 : ($wt[$bWb + (($bWi >> 22) & $bWm)] * $mt[$tot]) >> 16;
                $buf[$i] += $old0 + $s;
            } elseif ($tot < 384) {
                $buf[$i] += ($wt[$bWb + ((($bWi >> 22) + $old0) & $bWm)] * $mt[$tot]) >> 16;
            }
        }

        $this->cOld0[$c] = $old0;
        $this->cOld1[$c] = $old1;
        $this->oVolume[$a] = $aVol;
        $this->oState[$a] = $aState;
        $this->oRateIndex[$a] = $aRate;
        $this->oWaveIndex[$a] = $aWi;
        $this->oVolume[$b] = $bVol;
        $this->oState[$b] = $bState;
        $this->oRateIndex[$b] = $bRate;
        $this->oWaveIndex[$b] = $bWi;
    }

    /** Channel::BlockTemplate for sm2Percussion (channels 6, 7, 8): Channel::GeneratePercussion */
    private function blockPercussion(int $c, array &$buf, int $offset, int $samples): void
    {
        for ($o = $c * 2; $o < $c * 2 + 6; $o++) {
            $this->prepare($o);
        }
        $end = $offset + $samples;
        for ($i = $offset; $i < $end; $i++) {
            // bass drum
            $mod = (($this->cOld0[$c] + $this->cOld1[$c]) & 0xFFFFFFFF) >> $this->cFeedback[$c];
            $this->cOld0[$c] = $this->cOld1[$c];
            $this->cOld1[$c] = $this->getSample(12, $mod);
            // when the bass drum is in AM mode the first operator is ignored
            $mod = ($this->cRegC0[$c] & 1) ? 0 : $this->cOld0[$c];
            $sample = $this->getSample(13, $mod);

            $noiseBit = $this->forwardNoise() & 0x1;
            $c2 = $this->forwardWave(14);
            $c5 = $this->forwardWave(17);
            $phaseBit = ((($c2 & 0x88) ^ (($c2 << 5) & 0x80)) | (($c5 ^ ($c5 << 2)) & 0x20)) ? 0x02 : 0x00;

            // hi-hat
            $hhVol = $this->forwardVolume(14);
            if ($hhVol < self::ENV_LIMIT) {
                $hhIndex = ($phaseBit << 8) | (0x34 << ($phaseBit ^ ($noiseBit << 1)));
                $sample += $this->getWave(14, $hhIndex, $hhVol);
            }
            // snare drum
            $sdVol = $this->forwardVolume(15);
            if ($sdVol < self::ENV_LIMIT) {
                $sdIndex = (0x100 + ($c2 & 0x100)) ^ ($noiseBit << 8);
                $sample += $this->getWave(15, $sdIndex, $sdVol);
            }
            // tom-tom
            $sample += $this->getSample(16, 0);
            // top cymbal
            $tcVol = $this->forwardVolume(17);
            if ($tcVol < self::ENV_LIMIT) {
                $tcIndex = (1 + $phaseBit) << 8;
                $sample += $this->getWave(17, $tcIndex, $tcVol);
            }
            $buf[$i] += $sample << 1;
        }
    }
}
