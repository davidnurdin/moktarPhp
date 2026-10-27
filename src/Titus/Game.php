<?php

namespace App\Titus;

use JumPHP\Renderer\Sdl;

/**
 * The port of the OpenTitus engine (Moktar). Every C module is a trait of this class, with the same
 * function names; the C globals (globals.h) are properties of the same name.
 *
 * The C code is blocking (it loops and flips the screen); here the blocking functions are
 * generators: `yield` = one flip of the screen. GameScene runs main() frame by frame. The value
 * yielded is the minimum time of that frame in ms (29 = the 35 Hz game loop, see NO_FAST_CPU).
 */
final class Game
{
    use DrawTrait;
    use ScrollTrait;
    use SpritesTrait;
    use ResetTrait;
    use GatesTrait;
    use ElevatorsTrait;
    use ObjectsTrait;
    use EnemiesTrait;
    use PlayerTrait;
    use AudioTrait;
    use MenuTrait;

    // --- globals.h constants ------------------------------------------------------------------------
    public const TEST_ZONE = 4;
    public const MAX_X = 4;
    public const MAX_Y = 12;
    public const MAP_LIMIT_Y = -1;
    public const S_LINES = 12;
    public const S_COLUMNS = 20;
    public const LIMIT_TIMER = 22;
    public const ALERT_X = 32;
    public const screen_width = 20;
    public const screen_height = 12;
    public const FIRST_OBJET = 30;
    public const FIRST_NMI = 101;
    public const MAXIMUM_BONUS = 100;
    public const MAXIMUM_ENERGY = 16;
    public const MAXIMUM_DTRP = 4;
    public const GESTION_X = 40;
    public const GESTION_Y = 20;
    public const MAX_SPEED_DEAD = 20;

    public const HFLAG_NOWALL = 0;
    public const HFLAG_WALL = 1;
    public const HFLAG_BONUS = 2;
    public const HFLAG_DEADLY = 3;
    public const HFLAG_CODE = 4;
    public const HFLAG_PADLOCK = 5;
    public const HFLAG_LEVEL14 = 6;

    public const FFLAG_NOFLOOR = 0;
    public const FFLAG_FLOOR = 1;
    public const FFLAG_SSFLOOR = 2;
    public const FFLAG_SFLOOR = 3;
    public const FFLAG_VSFLOOR = 4;
    public const FFLAG_DROP = 5;
    public const FFLAG_LADDER = 6;
    public const FFLAG_BONUS = 7;
    public const FFLAG_WATER = 8;
    public const FFLAG_FIRE = 9;
    public const FFLAG_SPIKES = 10;
    public const FFLAG_CODE = 11;
    public const FFLAG_PADLOCK = 12;
    public const FFLAG_LEVEL14 = 13;

    public const CFLAG_NOCEILING = 0;
    public const CFLAG_CEILING = 1;
    public const CFLAG_LADDER = 2;
    public const CFLAG_PADLOCK = 3;
    public const CFLAG_DEADLY = 4;

    public const TITUS_ERROR_QUIT = -1;

    // keys (logical names, see Input)
    public const KEY_F1 = 'f1';
    public const KEY_F2 = 'f2';
    public const KEY_E = 'e';
    public const KEY_F4 = 'f4';
    public const KEY_LEFT = 'left';
    public const KEY_RIGHT = 'right';
    public const KEY_UP = 'up';
    public const KEY_DOWN = 'down';
    public const KEY_JUMP = 'jump';
    public const KEY_SPACE = 'action';
    public const KEY_ENTER = 'confirm';
    public const KEY_RETURN = 'confirm';
    public const KEY_ESC = 'esc';
    public const KEY_P = 'pause';
    public const KEY_NOCLIP = 'n';
    public const KEY_GODMODE = 'g';
    public const KEY_DEBUG = 'd';
    public const KEY_MUSIC = 'music';
    public const KEY_SKIP = 's';

    public const SPRITECOUNT = 356;
    public const LEVELCOUNT = 16;

    /** Level files, in game order ("0J123456789BCEFG") */
    public const LEVEL_FILES = ['LEVEL0', 'LEVELJ', 'LEVEL1', 'LEVEL2', 'LEVEL3', 'LEVEL4', 'LEVEL5', 'LEVEL6',
        'LEVEL7', 'LEVEL8', 'LEVEL9', 'LEVELB', 'LEVELC', 'LEVELE', 'LEVELF', 'LEVELG'];

    public const LEVEL_CODES = ['EFE8', '5165', '67D4', '2BDA', '11E5', '86EE', '4275', 'A0B9', '501C', 'E9ED',
        'D4E6', 'A531', 'CE96', 'B1A4', 'EBEA', '3B9C'];

    public const LEVEL_TITLES = [
        '     A LA RECHERCHE DE LA ZOUBIDA',
        '          LES QUARTIERS CHICS',
        '           ATTENTION TRAVAUX',
        '         LES COULOIRS DU METRO',
        '       LES CATACOMBES INFERNALES',
        '         ARRIVEE DANS LA CITE',
        '       L IMMEUBLE DE LA ZOUBIDA',
        '      SOUS LE CHEMIN DE MARRAKECH',
        '            LA CITE ENFOUIE',
        '             DESERT PRIVE',
        '          LA VILLE DES SABLES',
        '            LE PHARE OUEST',
        '             UN BON TUYAU',
        '           DE RETOUR AU PAYS',
        '           DIRECTION BARBES',
        '              BIG BISOUS',
    ];

    // --- globals.h variables ------------------------------------------------------------------------
    public int $RESETLEVEL_FLAG = 0;
    /** triggers a game over */
    public bool $GAMEOVER_FLAG = false;
    /** timer for health bar */
    public int $BAR_FLAG = 0;
    /** true if left or right key is pressed */
    public bool $X_FLAG = false;
    /** true if up or down key is pressed */
    public bool $Y_FLAG = false;
    /** headache timer */
    public int $CHOC_FLAG = 0;
    /** player sprite array */
    public int $action = 0;
    /** hit/burn timer */
    public int $KICK_FLAG = 0;
    /** If set, player will be "burned" when hit (fireballs) */
    public bool $GRANDBRULE_FLAG = false;
    /** True if in a ladder */
    public bool $LADDER_FLAG = false;
    /** True if player is forced into kneestanding because of low ceiling */
    public bool $PRIER_FLAG = false;
    /** 6 if free fall or in the middle of a jump, decremented if on solid surface. Must be 0 to initiate a jump. */
    public int $SAUT_FLAG = 0;
    /** Last action (kneestand + jump = silent walk) */
    public int $LAST_ORDER = 0;
    /** Silent walk timer */
    public int $FURTIF_FLAG = 0;
    /** True if an object is throwed forward */
    public bool $DROP_FLAG = false;
    public bool $DROPREADY_FLAG = false;
    /** true if carrying something (add 16 to player sprite) */
    public bool $CARRY_FLAG = false;
    public bool $POSEREADY_FLAG = false;
    /** Frames since last action change */
    public int $ACTION_TIMER = 0;
    /** When non-zero, boss is invulnerable */
    public int $INVULNERABLE_FLAG = 0;
    /** When non-zero, the flying carpet is flying */
    public int $TAPISFLY_FLAG = 0;
    /** When non-zero, fall through certain floors (after key down) */
    public int $CROSS_FLAG = 0;
    /** When zero, skip object gravity function */
    public int $GRAVITY_FLAG = 0;
    /** Smoke when object hits the floor */
    public int $FUME_FLAG = 0;
    /** Lives */
    public int $LIFE = 0;
    public int $YFALL = 0;
    public bool $POCKET_FLAG = false;
    /** If false, there are no animated tiles on the screen */
    public bool $PERMUT_FLAG = false;
    /** Increased every loop in game loop (0 to 3) */
    public int $loop_cycle = 0;
    /** Current tile animation (0-1-2), changed every 4th game loop cycle */
    public int $tile_anim = 0;
    /** Screen offset (X) in tiles */
    public int $BITMAP_X = 0;
    /** Point to the left tile in the tile screen (0 to 19) */
    public int $BITMAP_XM = 0;
    /** Screen offset (Y) in tiles */
    public int $BITMAP_Y = 0;
    /** Point to the top tile in the tile screen (0 to 11) */
    public int $BITMAP_YM = 0;
    /** If true, the screen will scroll in X */
    public bool $XSCROLL_CENTER = false;
    /** If scrolling: scroll until player is in this tile (X) */
    public int $XLIMIT_SCROLL = 0;
    /** The engine will not scroll past this tile before the player have crossed the line (X) */
    public int $XLIMIT = 0;
    /** If true, the screen will scroll in Y */
    public bool $YSCROLL_CENTER = false;
    /** If scrolling: scroll until player is in this tile (Y) */
    public int $YLIMIT_SCROLL = 0;
    /** The engine will not scroll below this tile before the player have gone below (Y) (uint8) */
    public int $ALTITUDE_ZERO = 0;
    /** Increased every loop in game loop (0 to 0x0FFF) */
    public int $IMAGE_COUNTER = 0;
    /** 1: walk right, 0: stand still, -1: walk left, triggers the ACTION_TIMER if it changes */
    public int $SENSX = 0;
    /** Incremented from 0 to 3 when accelerating while jumping, stop acceleration upwards if >= 3 */
    public int $SAUT_COUNT = 0;
    public bool $NOSCROLL_FLAG = false;
    /** Finish a level */
    public bool $NEWLEVEL_FLAG = false;
    /** Number of bosses that needs to be killed to finish */
    public int $BIGNMI_NBR = 0;
    /** Used for enemies walking and popping up */
    public int $TAUPE_FLAG = 0;
    /** Flying carpet state */
    public int $TAPISWAIT_FLAG = 0;
    /** Counter when hit */
    public int $SEECHOC_FLAG = 0;
    /** Skip NO_FAST_CPU */
    public bool $NFC_FLAG = false;
    /** Lives of the boss */
    public int $BIGNMI_POWER = 0;
    /** True if the boss is alive */
    public bool $boss_alive = false;
    public int $AUDIOMODE = 1;
    /** If true, the player will not interfere with the enemies */
    public bool $GODMODE = false;
    /** If true, the player will move noclip */
    public bool $NOCLIP = false;
    public bool $DISPLAYLOOPTIME = false;
    /** cheat keys (G, N, S) */
    public int $devmode = 0;

    // --- port state ----------------------------------------------------------------------------------
    public Level $level;
    public Screen $screen;
    /** @var SpriteData[] */
    private array $sprites = [];
    /** @var ObjectData[] */
    private array $objects = [];
    /** @var int[][] the C anim_player table (row 1 = the Moktar walk) */
    public array $anim_player = [];
    /** Game time in ms (SDL_GetTicks) */
    private float $ms = 0.0;
    /** First level of a demo / test run (enter() params) */
    public ?int $startLevel = null;
    /** Called when the player quits from the menu (desktop: closes the game) */
    public ?\Closure $onQuit = null;

    public function __construct(
        public readonly Sdl $sdl,
        public readonly Input $input,
        public readonly string $dataDir,
        public readonly ?\JumPHP\Audio\SoundManager $sound = null,
    ) {
        $this->level = new Level();
        $this->screen = new Screen();
        $this->anim_player = OriginalData::ANIM_PLAYER;
        $this->anim_player[1] = OriginalData::ANIM_PLAYER_MOKTAR;
    }

    /** The data files are there (the game cannot run without them). */
    public static function hasData(string $dataDir): bool
    {
        foreach (['SPRITES.SQZ', 'FONTS.SQZ', 'MENU.SQZ', 'LEVEL0.SQZ'] as $file) {
            if (!is_file($dataDir . '/' . $file)) {
                return false;
            }
        }
        return true;
    }

    public function file(string $name): string
    {
        return $this->dataDir . '/' . $name;
    }

    /** GameScene: time of the frame. */
    public function advanceTime(float $ms): void
    {
        $this->ms += $ms;
    }

    /** SDL_GetTicks() */
    public function SDL_GetTicks(): int
    {
        return (int)$this->ms;
    }

    // --- opentitus.c --------------------------------------------------------------------------------

    /** main(): intro text, logo, intro picture, then menu <-> game forever. */
    public function main(): \Generator
    {
        $this->initoriginal();
        $this->loadfonts();
        $this->initaudio();

        if ($this->startLevel !== null) { // demo / tests: straight into a level
            yield from $this->playtitus($this->startLevel);
        }

        $state = true;
        $retval = yield from $this->viewintrotext();
        if ($retval < 0) {
            $state = false;
        }
        if ($state) {
            $retval = yield from $this->viewimage('TITUS.SQZ', 2, 0, 4000);
        }
        $this->SELECT_MUSIC(15);
        if ($state && $retval >= 0) {
            yield from $this->viewimage('TITRE.SQZ', 2, 0, 6500);
        }
        while (true) {
            $retval = yield from $this->viewmenu('MENU.SQZ', 2);
            if ($retval <= 0) {
                // Escape in the menu: the original quits the program
                if ($this->onQuit !== null) {
                    ($this->onQuit)();
                }
                yield 0;
                continue;
            }
            if ($retval <= self::LEVELCOUNT) {
                yield from $this->playtitus($retval - 1);
                $this->SELECT_MUSIC(15);
            }
        }
    }

    /** original.c initoriginal(): object data, sprites */
    private function initoriginal(): void
    {
        $this->objects = [];
        foreach (OriginalData::OBJECT_FLAG as $i => $flag) {
            $o = new ObjectData();
            $o->maxspeedY = OriginalData::OBJECT_MAXSPEEDY[$i];
            $o->support = ($flag & 0x01) !== 0;
            $o->bounce = ($flag & 0x02) !== 0;
            $o->gravity = ($flag & 0x04) !== 0;
            $o->droptobottom = ($flag & 0x08) !== 0;
            $o->no_damage = ($flag & 0x10) !== 0;
            $this->objects[$i] = $o;
        }
        $this->loadsprites();
    }

    /** sprites.c loadsprites() */
    private function loadsprites(): void
    {
        $b = Sqz::load($this->file('SPRITES.SQZ'));
        $offset = 0;
        $this->sprites = [];
        for ($i = 0; $i < self::SPRITECOUNT; $i++) {
            $w = OriginalData::SPRITE_WIDTH[$i];
            $h = OriginalData::SPRITE_HEIGHT[$i];
            $this->sprites[$i] = new SpriteData($i, $w, $h,
                OriginalData::SPRITE_COLL[$i * 2], OriginalData::SPRITE_COLL[$i * 2 + 1],
                OriginalData::SPRITE_REF[$i * 2], -(OriginalData::SPRITE_REF[$i * 2 + 1] - $h),
                Gfx::planar($b, $offset, $w, $h));
            $offset += ($w * $h) >> 1;
        }
    }

    /** level number (0-15) -> index in the original tables (getlevelid) */
    public function getlevelid(int $levelnumber): int
    {
        foreach (OriginalData::LEVEL_ID as $i => $id) {
            if ($id === $levelnumber + 1) {
                return $i;
            }
        }
        return 0;
    }

    /** engine.c playtitus(): plays from $firstlevel to the end (or game over / quit). */
    private function playtitus(int $firstlevel): \Generator
    {
        $level = $this->level;
        $level->lives = 2;
        $level->extrabonus = 0;
        for ($n = $firstlevel; $n < self::LEVELCOUNT; $n++) {
            $level->load(Sqz::load($this->file(self::LEVEL_FILES[$n] . '.SQZ')), $n, $this->sprites, $this->objects);
            $level->levelid = $this->getlevelid($n);
            $level->levelnumber = $n;
            $this->PERMUT_FLAG = $level->permut;
            $this->ALTITUDE_ZERO = $level->altitudeZero & 0xFF;
            $this->XLIMIT = $level->xLimit;
            $this->screen->loadLevelGraphics($this->sdl, $level);
            $first = true;
            while (true) {
                $this->SELECT_MUSIC(0);
                $this->CLEAR_DATA($level);
                $this->GODMODE = false;
                $this->NOCLIP = false;
                $this->DISPLAYLOOPTIME = false;
                $retval = yield from $this->viewstatus($level, $first);
                $first = false;
                if ($retval < 0) {
                    return $retval;
                }
                $this->SELECT_MUSIC(OriginalData::LEVEL_MUSIC[$level->levelid]);
                yield from $this->INIT_SCREENM($level);
                $this->TFR_SCREENM();
                yield from $this->flip_screen(true);
                $retval = yield from $this->playlevel($level);
                if ($retval < 0) {
                    return $retval;
                }
                if ($this->NEWLEVEL_FLAG) {
                    break;
                }
                if ($this->RESETLEVEL_FLAG === 1) {
                    if ($level->lives === 0) {
                        $this->GAMEOVER_FLAG = true;
                    } else {
                        $level->lives--;
                        yield from $this->death($level);
                    }
                }
                if ($this->GAMEOVER_FLAG) {
                    yield from $this->gameover($level);
                    return 0;
                }
            }
        }
        return 0;
    }

    /** engine.c playlevel(): the game loop */
    private function playlevel(Level $level): \Generator
    {
        $firstrun = true;
        do {
            if (!$firstrun) {
                $this->DISPLAY_COUNT($level);
                $this->RETURN_MUSIC();
                yield from $this->flip_screen(true);
            }
            $firstrun = false;
            $this->IMAGE_COUNTER = ($this->IMAGE_COUNTER + 1) & 0x0FFF;
            $this->MOVE_TRP($level);
            $this->move_objects($level);
            $retval = yield from $this->move_player($level);
            if ($retval === self::TITUS_ERROR_QUIT) {
                return $retval;
            }
            $this->MOVE_NMI($level);
            $this->MOVE_TRASH($level);
            $this->SET_NMI($level);
            yield from $this->CROSSING_GATE($level);
            $this->SPRITES_ANIMATION($level);
            $this->scroll($level);
            $this->BLOC_ANIMATION($level);
            $this->TFR_SCREENM();
            $this->DISPLAY_SPRITES($level);
            $retval = yield from $this->RESET_LEVEL($level);
            if ($retval < 0) {
                return $retval;
            }
        } while ($retval === 0);
        return 0;
    }

    /** engine.c death() */
    private function death(Level $level): \Generator
    {
        $player = $level->player;
        $this->SELECT_MUSIC(1);
        $this->FORCE_POSE($level);
        $this->updatesprite($level, $player->sprite, 13, true); // death
        $player->sprite->speedY = 15;
        for ($i = 0; $i < 60; $i++) {
            $this->TFR_SCREENM();
            $this->DISPLAY_SPRITES($level);
            yield from $this->flip_screen(true);
            $player->sprite->speedY--;
            if ($player->sprite->speedY < -16) {
                $player->sprite->speedY = -16;
            }
            $player->sprite->y -= $player->sprite->speedY;
        }
        yield from $this->WAIT_SONG();
        $this->SELECT_MUSIC(0);
        yield from $this->CLOSE_SCREEN();
    }

    /** engine.c gameover() */
    private function gameover(Level $level): \Generator
    {
        $player = $level->player;
        $this->SELECT_MUSIC(2);
        $this->updatesprite($level, $player->sprite, 13, true); // death
        $this->updatesprite($level, $player->sprite2, 333, true); // game
        $player->sprite2->x = ($this->BITMAP_X << 4) - (120 - 2);
        $player->sprite2->y = ($this->BITMAP_Y << 4) + 100;
        $this->updatesprite($level, $player->sprite3, 334, true); // over
        $player->sprite3->x = ($this->BITMAP_X << 4) + (320 + 120 - 2);
        $player->sprite3->y = ($this->BITMAP_Y << 4) + 100;
        for ($i = 0; $i < 31; $i++) {
            $this->TFR_SCREENM();
            $this->DISPLAY_SPRITES($level);
            yield from $this->flip_screen(true);
            $player->sprite2->x += 8;
            $player->sprite3->x -= 8;
        }
        $retval = yield from $this->waitforbutton();
        if ($retval < 0) {
            return $retval;
        }
        yield from $this->fadeout();
        return 0;
    }

    // --- common.c / keyboard.c ------------------------------------------------------------------------

    /** Decreases a value with 1 until it's zero */
    public function subto0(int &$number): void
    {
        if ($number > 0) {
            $number--;
        }
    }

    /** keystate[key]: is the key held now */
    public function keystate(string $key): bool
    {
        return $this->input->held($key);
    }

    /**
     * SDL_PollEvent: the key events since the last step.
     * @return array<int, array{0:string, 1:string}> ['down'|'up', key]
     */
    public function pollEvents(): array
    {
        return $this->input->takeEvents();
    }

    /** waitforbutton(): 0 = Return / Enter / Space, -1 = Escape */
    public function waitforbutton(): \Generator
    {
        while (true) {
            foreach ($this->pollEvents() as [$type, $key]) {
                if ($type !== 'down') {
                    continue;
                }
                if ($key === self::KEY_RETURN || $key === self::KEY_SPACE) {
                    return 0;
                }
                if ($key === self::KEY_ESC) {
                    return -1;
                }
                $this->musicKey($key);
            }
            yield 0;
        }
    }

    /** KEY_MUSIC handling, common to every waiting loop */
    public function musicKey(string $key): void
    {
        if ($key === self::KEY_MUSIC) {
            $this->AUDIOMODE++;
            if ($this->AUDIOMODE > 1) {
                $this->AUDIOMODE = 0;
            }
            if ($this->AUDIOMODE === 1) {
                $this->startmusic();
            } else {
                $this->stopmusic();
            }
        }
    }

    // --- C integer helpers ---------------------------------------------------------------------------

    /** (uint8) */
    public static function u8(int $v): int
    {
        return $v & 0xFF;
    }

    /** (int8) */
    public static function s8(int $v): int
    {
        $v &= 0xFF;
        return $v >= 0x80 ? $v - 0x100 : $v;
    }

    /** (uint16) */
    public static function u16(int $v): int
    {
        return $v & 0xFFFF;
    }

    /** (int16) */
    public static function s16(int $v): int
    {
        $v &= 0xFFFF;
        return $v >= 0x8000 ? $v - 0x10000 : $v;
    }

    /** C integer division (truncates toward zero) */
    public static function div(int $a, int $b): int
    {
        return intdiv($a, $b);
    }
}
