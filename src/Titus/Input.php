<?php

namespace App\Titus;

/**
 * What the C code reads from SDL: keys held now (keystate) and the key events since the last step
 * of the game (SDL_PollEvent). GameScene fills it every frame from the JumPHP actions.
 */
final class Input
{
    /** @var array<string, bool> */
    private array $held = [];
    /** @var array<int, array{0:string, 1:string}> */
    private array $events = [];
    /** Hexadecimal characters typed (password screen) */
    private string $typed = '';

    public function setHeld(string $key, bool $held): void
    {
        $was = $this->held[$key] ?? false;
        if ($held !== $was) {
            $this->events[] = [$held ? 'down' : 'up', $key];
        }
        $this->held[$key] = $held;
    }

    /** A key press that is not held (touch, scripted demo). */
    public function press(string $key): void
    {
        $this->events[] = ['down', $key];
        $this->events[] = ['up', $key];
    }

    public function held(string $key): bool
    {
        return $this->held[$key] ?? false;
    }

    /** @return array<int, array{0:string, 1:string}> */
    public function takeEvents(): array
    {
        $events = $this->events;
        $this->events = [];
        return $events;
    }

    public function type(string $chars): void
    {
        $this->typed .= $chars;
    }

    public function takeTyped(): string
    {
        $typed = $this->typed;
        $this->typed = '';
        return $typed;
    }

    /** Forget everything pending (new screen). */
    public function flush(): void
    {
        $this->events = [];
        $this->typed = '';
    }
}
