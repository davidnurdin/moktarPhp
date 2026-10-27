<?php

namespace App\Scene;

use App\Titus\Game;
use App\Titus\Input;
use JumPHP\Kernel\Platform;
use JumPHP\Renderer\Renderer;
use JumPHP\Scene\AbstractScene;

/**
 * The whole game: runs the OpenTitus port (App\Titus\Game::main(), a generator) frame by frame and
 * draws its screen. Each step of the generator ends with a flip of the original screen; the value it
 * yields is the minimum duration of that frame in ms (29 ms = the 35 Hz of the original game loop).
 *
 * Params: level (0-15) starts that level directly; demo: true = a bot plays (smoke test).
 */
final class GameScene extends AbstractScene
{
    /** JumPHP action => key name of the C code (App\Titus\Game::KEY_*) */
    private const KEYS = [
        'left' => Game::KEY_LEFT,
        'right' => Game::KEY_RIGHT,
        'up' => Game::KEY_UP,
        'down' => Game::KEY_DOWN,
        'fire' => Game::KEY_SPACE,
        'start' => Game::KEY_RETURN,
        'quit' => Game::KEY_ESC,
        'pause' => Game::KEY_P,
        'music' => Game::KEY_MUSIC,
        'energy' => Game::KEY_E,
        'status' => Game::KEY_F4,
        'suicide' => Game::KEY_F1,
        'cheat_god' => Game::KEY_GODMODE,
        'cheat_noclip' => Game::KEY_NOCLIP,
        'cheat_skip' => Game::KEY_SKIP,
    ];

    private ?Game $game = null;
    private ?\Generator $main = null;
    private Input $input;
    private float $acc = 0.0;
    private int $wait = 0;
    private bool $demo = false;
    private int $frame = 0;
    private ?string $error = null;

    public function enter(array $params = []): void
    {
        $this->input = new Input();
        $this->error = null;
        $this->acc = 0.0;
        $this->wait = 0;
        $this->frame = 0;
        $this->demo = (bool)($params['demo'] ?? false);
        $dataDir = $this->engine->config->path('assets/data');
        if (!Game::hasData($dataDir)) {
            $this->error = "THE ORIGINAL GAME FILES ARE MISSING.\n\nCOPY THE MOKTAR FILES (*.SQZ)\nINTO assets/data/";
            $this->game = null;
            $this->main = null;
            return;
        }
        $this->game = new Game($this->sdl(), $this->input, $dataDir, $this->sound());
        $this->game->devmode = (int)Platform::setting('MOKTAR_CHEATS', 0);
        if (isset($params['level'])) {
            $this->game->startLevel = (int)$params['level'];
        }
        $this->game->onQuit = function (): void {
            if (Platform::isDesktop()) {
                $this->engine->quit();
            }
        };
        $this->main = $this->game->main();
        $this->wait = (int)$this->main->current();
    }

    public function update(float $dt): void
    {
        if ($this->main === null || $this->game === null) {
            return;
        }
        $this->frame++;
        $this->readInput();

        $ms = $dt * 1000;
        $this->game->advanceTime($ms);
        $this->acc += $ms;
        if ($this->wait <= 0) {
            $this->step();
            $this->acc = 0.0;
            return;
        }
        // the 35 Hz game loop: as many steps as the time allows (at most 3 per frame)
        for ($n = 0; $n < 3 && $this->acc >= $this->wait; $n++) {
            $this->acc -= $this->wait;
            $this->step();
            if ($this->wait <= 0) {
                $this->acc = 0.0;
                break;
            }
        }
        if ($this->acc > 100) {
            $this->acc = 0.0;
        }
    }

    private function step(): void
    {
        if ($this->main === null || !$this->main->valid()) {
            return;
        }
        $this->main->next();
        $this->wait = (int)$this->main->current();
    }

    private function readInput(): void
    {
        if ($this->demo) {
            $this->demoInput();
            return;
        }
        $actions = $this->actions();
        foreach (self::KEYS as $action => $key) {
            $this->input->setHeld($key, $actions->isHeld($action) || $actions->isPressed($action));
        }
        // KEY_JUMP is the up key of the original game
        $this->input->setHeld(Game::KEY_JUMP, $actions->isHeld('up'));
        $typed = '';
        foreach ($this->engine->input->getKeyboard()->getTyped() as $code) {
            if (($code >= 48 && $code <= 57) || ($code >= 97 && $code <= 102)) {
                $typed .= chr($code);
            }
        }
        if ($typed !== '') {
            $this->input->type($typed);
        }
    }

    /** A bot: walks right, jumps from time to time, validates the screens. */
    private function demoInput(): void
    {
        $f = $this->frame;
        $this->input->setHeld(Game::KEY_RIGHT, $f % 300 < 240);
        $this->input->setHeld(Game::KEY_LEFT, $f % 300 >= 250);
        $jump = $f % 50 < 12;
        $this->input->setHeld(Game::KEY_UP, $jump);
        $this->input->setHeld(Game::KEY_JUMP, $jump);
        $this->input->setHeld(Game::KEY_SPACE, $f % 90 > 80);
        if ($f % 40 === 0) {
            $this->input->press(Game::KEY_RETURN);
        }
    }

    public function render(Renderer $r): void
    {
        if ($this->error !== null || $this->game === null) {
            $r->clear([0, 0, 0]);
            $this->font()->draw($r, (string)$this->error, $this->width() / 2, 70, 1, [255, 220, 120], 'center');
            return;
        }
        $this->game->screen->render($r, $this->sdl());
    }
}
