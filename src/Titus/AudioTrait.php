<?php

namespace App\Titus;

/**
 * audio.c, game side. The original music is an AdLib (OPL2) driver reading MUSIC_MOK.BIN; tools/render-audio.php
 * runs that driver on DbOpl (the OPL2 emulator of OpenTitus, ported to PHP) once and writes the songs and sound effects as WAV files
 * (assets/music/audio.json lists them). Here SELECT_MUSIC / FX_START play those files.
 */
trait AudioTrait
{
    /** 0: level music, 1: bonus (a song that does not become the "last song") */
    private const SONG_TYPE = [0, 1, 0, 0, 0, 1, 1, 1, 1, 0, 0, 0, 0, 0, 0, 0];

    /** @var array<int, array{file: string, duration: float, loops: bool}> */
    private array $songs = [];
    private int $last_song = 0;
    private int $currentSong = -1;
    private float $songStart = 0.0;

    public function initaudio(): void
    {
        $this->AUDIOMODE = 1;
        $manifest = $this->dataDir . '/../music/audio.json';
        if (!is_file($manifest)) {
            return;
        }
        $data = json_decode((string)file_get_contents($manifest), true);
        if (!is_array($data)) {
            return;
        }
        $dir = dirname($manifest);
        foreach ($data['songs'] ?? [] as $n => $song) {
            $this->songs[(int)$n] = ['file' => $song['file'], 'duration' => (float)$song['duration'], 'loops' => (bool)$song['loops']];
            if ($this->sound !== null && is_file("$dir/{$song['file']}")) {
                $this->sound->loadMusic("moktar-song$n", "$dir/{$song['file']}");
            }
        }
        foreach ($data['sfx'] ?? [] as $n => $file) {
            if ($this->sound !== null && is_file("$dir/$file")) {
                $this->sound->loadSfx("moktar-fx$n", "$dir/$file");
            }
        }
    }

    public function SELECT_MUSIC(int $song_number): void
    {
        if ((self::SONG_TYPE[$song_number] ?? 0) === 0) {
            $this->last_song = $song_number;
        }
        $this->currentSong = $song_number;
        $this->songStart = $this->ms;
        if ($this->AUDIOMODE === 1) {
            $this->startmusic();
        }
    }

    /** The current song played to its end (the looping ones never end) */
    private function songFinished(): bool
    {
        $song = $this->songs[$this->currentSong] ?? null;
        if ($song === null) {
            return true;
        }
        return !$song['loops'] && $this->ms - $this->songStart >= $song['duration'] * 1000;
    }

    public function startmusic(): void
    {
        if ($this->sound === null || !isset($this->songs[$this->currentSong])) {
            return;
        }
        $this->sound->stopMusic();
        $this->sound->playMusic("moktar-song{$this->currentSong}", $this->songs[$this->currentSong]['loops']);
    }

    public function stopmusic(): void
    {
        $this->sound?->stopMusic();
    }

    /** Waits for the end of the current song */
    public function WAIT_SONG(): \Generator
    {
        if ($this->AUDIOMODE === 0) {
            return 0;
        }
        while (!$this->songFinished()) {
            yield 0;
            foreach ($this->pollEvents() as [$type, $key]) {
                if ($type !== 'down') {
                    continue;
                }
                if ($key === self::KEY_ESC) {
                    return self::TITUS_ERROR_QUIT;
                }
                if ($key === self::KEY_MUSIC) {
                    $this->musicKey($key);
                    if ($this->AUDIOMODE === 0) {
                        return 0;
                    }
                }
            }
        }
        return 0;
    }

    public function FX_START(int $fx_number): void
    {
        if ($this->AUDIOMODE === 1) {
            $this->sound?->playSfx("moktar-fx$fx_number");
        }
    }

    /** Restart music if the song is finished */
    public function RETURN_MUSIC(): void
    {
        if ($this->AUDIOMODE === 1 && $this->songFinished() && isset($this->songs[$this->last_song])) {
            $this->SELECT_MUSIC($this->last_song);
        }
    }
}
