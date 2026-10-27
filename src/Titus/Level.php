<?php

namespace App\Titus;

/**
 * TITUS_level, loaded from the decompressed bytes of a LEVELx.SQZ file (OpenTitus level.c).
 *
 * Layout: height * 256 tile indexes, 256 tiles of 128 bytes (16x16, 4 planes), 3 * 256 tile flags,
 * then objects, enemies, bonuses, gates, elevators, start / finish positions and scroll limits.
 */
final class Level
{
    public int $levelnumber = 0;
    public int $levelid = 0;
    public int $height = 0;
    public int $width = 256;
    /** @var array<int, int[]> [y][x] tile index */
    public array $tilemap = [];
    /** @var Tile[] */
    public array $tile = [];
    /** @var SpriteData[] */
    public array $spritedata = [];
    /** @var ObjectData[] */
    public array $objectdata = [];
    public int $finishX = 0;
    public int $finishY = 0;
    public int $lives = 2;
    public int $extrabonus = 0;
    /** @var array<int, array{0:int,1:int,2:int}> palette of the level (16 colours, colour 14 depends on the level) */
    public array $palette = [];
    public Player $player;
    /** @var TitusObject[] */
    public array $object = [];
    public int $objectcount = 0;
    /** @var Enemy[] */
    public array $enemy = [];
    public int $enemycount = 0;
    /** @var Bonus[] */
    public array $bonus = [];
    public int $bonuscount = 0;
    /** @var Gate[] */
    public array $gate = [];
    public int $gatecount = 0;
    /** @var Elevator[] */
    public array $elevator = [];
    public int $elevatorcount = 0;
    /** @var Sprite[] enemy bullets */
    public array $trash = [];
    public int $trashcount = 0;
    /** Scroll limits stored in the level (the engine globals ALTITUDE_ZERO and XLIMIT) */
    public int $altitudeZero = 0;
    public int $xLimit = 0;
    /** True when the level has animated tiles (PERMUT_FLAG at load time) */
    public bool $permut = false;

    public function __construct()
    {
        $this->player = new Player();
    }

    /**
     * @param SpriteData[] $spritedata
     * @param ObjectData[] $objectdata
     */
    public function load(string $data, int $levelnumber, array $spritedata, array $objectdata): void
    {
        $b = $data; // a byte string (see Gfx::planar)
        $this->levelnumber = $levelnumber;
        $this->player = new Player();
        $this->player->inithp = 16;
        $this->width = 256;
        $this->height = intdiv(strlen($b) - 35828, 256);
        $w = $this->width;

        $this->tilemap = [];
        for ($i = 0; $i < $this->height; $i++) {
            $this->tilemap[$i] = array_values(unpack('C*', substr($b, $i * $w, $w)) ?: []);
        }

        $this->palette = OriginalData::PALETTE;
        $this->palette[14] = OriginalData::PALETTE_LEVEL[$levelnumber] ?? OriginalData::PALETTE[14];

        $offset = $this->height * $w;
        $this->tile = [];
        $j = 256; // last tile with the animation flag
        for ($i = 0; $i < 256; $i++) {
            $tile = new Tile();
            $tile->pixels = Gfx::planar($b, $offset + $i * 128, 16, 16);
            $tile->current = $i;
            $tile->horizflag = ord($b[$offset + 32768 + $i]);
            $tile->floorflag = ord($b[$offset + 32768 + 256 + $i]);
            $tile->ceilflag = ord($b[$offset + 32768 + 512 + $i]) & 0x7F;
            $tile->animated = true;
            if ($j === $i - 1) { // second tile after the animation flag
                $tile->animation = [$i, $i + 1, $i - 1];
                $this->permut = true;
            } elseif ($j === $i - 2) { // third tile after the animation flag
                $tile->animation = [$i, $i - 2, $i - 1];
                $this->permut = true;
            } elseif ((ord($b[$offset + 32768 + 512 + $i]) & 0x80) === 0x80) { // animation flag
                $tile->animation = [$i, $i + 1, $i + 2];
                $j = $i;
                $this->permut = true;
            } else {
                $tile->animation = [$i, $i, $i];
                $tile->animated = false;
            }
            $this->tile[$i] = $tile;
        }
        // the animation of the last tiles cannot point outside the table
        foreach ($this->tile as $tile) {
            foreach ($tile->animation as $k => $index) {
                $tile->animation[$k] = $index & 0xFF;
            }
        }

        $this->spritedata = $spritedata;
        $this->objectdata = $objectdata;
        $this->player->initX = self::s16($b, $offset + 33778);
        $this->player->initY = self::s16($b, $offset + 33780);
        $this->finishX = self::s16($b, $offset + 35824);
        $this->finishY = self::s16($b, $offset + 35826);

        // objects
        $this->objectcount = 40;
        $this->object = [];
        $o = $offset + 33536;
        for ($i = 0; $i < $this->objectcount; $i++) {
            $obj = new TitusObject();
            $obj->initsprite = self::u16($b, $o + $i * 6);
            $obj->init_enabled = $obj->initsprite !== 0xFFFF;
            if ($obj->init_enabled) {
                $obj->initX = self::s16($b, $o + $i * 6 + 2);
                $obj->initY = self::s16($b, $o + $i * 6 + 4);
            }
            $this->object[$i] = $obj;
        }

        // enemies
        $this->enemycount = 50;
        $this->enemy = [];
        $o = $offset + 33782;
        for ($i = 0; $i < $this->enemycount; $i++) {
            $e = new Enemy();
            $e->initsprite = self::u16($b, $o + 4);
            $e->init_enabled = $e->initsprite !== 0xFFFF;
            if ($e->init_enabled) {
                $e->initX = self::s16($b, $o + 0);
                $e->initY = self::s16($b, $o + 2);
                $e->type = self::u16($b, $o + 6) & 0x1FFF;
                $e->initspeedX = self::s16($b, $o + 8);
                $e->power = self::s16($b, $o + 12);
                switch ($e->type) {
                    case 0: // noclip walk
                    case 1:
                        $e->centerX = self::s16($b, $o + 15);
                        $e->rangeX = self::u16($b, $o + 17);
                        break;
                    case 2: // shoot
                        $e->delay = ord($b[$o + 16]);
                        $e->rangeX = self::u16($b, $o + 17);
                        $e->direction = ($e->rangeX >> 14) & 0x03;
                        $e->rangeX &= 0x3FFF;
                        break;
                    case 3: // noclip walk, jump to player
                    case 4:
                    case 5: // noclip walk, move to player
                    case 6:
                        $e->centerX = self::s16($b, $o + 15);
                        $e->rangeX = self::u16($b, $o + 17);
                        $e->rangeY = ord($b[$o + 19]);
                        break;
                    case 7: // gravity walk, hit when near
                    case 9: // walk and periodically pop-up
                    case 10: // alert when near, walk when nearer
                    case 11: // walk and shoot
                        $e->walkspeedX = ord($b[$o + 19]);
                        $e->rangeX = self::u16($b, $o + 23);
                        break;
                    case 8: // gravity walk when off-screen
                    case 14: // gravity walk when off-screen (immortal)
                        $e->walkspeedX = ord($b[$o + 19]);
                        break;
                    case 12: // jump (immortal)
                        $e->rangeY = self::u16($b, $o + 15);
                        $e->delay = ord($b[$o + 19]);
                        break;
                    case 13: // bounce
                        $e->delay = ord($b[$o + 20]);
                        $e->rangeX = self::u16($b, $o + 23);
                        break;
                    case 17: // drop (immortal)
                        $e->rangeX = self::u16($b, $o + 15);
                        $e->delay = self::u16($b, $o + 17);
                        $e->rangeY = self::u16($b, $o + 21);
                        break;
                    case 18: // drop (immortal)
                        $e->rangeX = self::u16($b, $o + 15);
                        $e->rangeY = self::u16($b, $o + 17);
                        $e->initspeedY = ord($b[$o + 19]);
                        break;
                }
            }
            $this->enemy[$i] = $e;
            $o += 26;
        }

        // bonuses (they overwrite the tile they hide behind)
        $this->bonuscount = 100;
        $this->bonus = [];
        $o = $offset + 35082;
        for ($i = 0; $i < $this->bonuscount; $i++) {
            $bonus = new Bonus();
            $bonus->x = ord($b[$o + 2]);
            $bonus->y = ord($b[$o + 3]);
            $bonus->exists = $bonus->x !== 0xFF && $bonus->y !== 0xFF;
            if ($bonus->exists) {
                $bonus->bonustile = ord($b[$o]);
                $bonus->replacetile = ord($b[$o + 1]);
                if (isset($this->tilemap[$bonus->y])) {
                    $this->tilemap[$bonus->y][$bonus->x] = ord($b[$o]);
                }
            }
            $this->bonus[$i] = $bonus;
            $o += 4;
        }

        // gates
        $this->gatecount = 20;
        $this->gate = [];
        $o = $offset + 35484;
        for ($i = 0; $i < $this->gatecount; $i++) {
            $gate = new Gate();
            $gate->entranceY = ord($b[$o + 1]);
            $gate->exists = $gate->entranceY !== 0xFF;
            if ($gate->exists) {
                $gate->entranceX = ord($b[$o]);
                $gate->screenX = ord($b[$o + 2]);
                $gate->screenY = ord($b[$o + 3]);
                $gate->exitX = ord($b[$o + 4]);
                $gate->exitY = ord($b[$o + 5]);
                $gate->noscroll = ord($b[$o + 6]) !== 0;
            }
            $this->gate[$i] = $gate;
            $o += 7;
        }

        // elevators
        $this->elevatorcount = 10;
        $this->elevator = [];
        $o = $offset + 35624;
        for ($i = 0; $i < $this->elevatorcount; $i++) {
            $el = new Elevator();
            $el->initsprite = self::u16($b, $o + 4);
            $el->initX = self::s16($b, $o + 12);
            $el->initY = self::s16($b, $o + 14);
            $speed = ord($b[$o + 7]);
            $el->init_enabled = $el->initsprite !== 0xFFFF && $speed < 8 && $el->initX >= -16 && $el->initY >= 0;
            $el->enabled = $el->init_enabled;
            if ($el->enabled) {
                $el->range = self::u16($b, $o + 10);
                $el->init_direction = ord($b[$o + 16]);
                if ($el->init_direction === 0 || $el->init_direction === 3) { // up or left
                    $speed = -$speed;
                }
                if ($el->init_direction === 0 || $el->init_direction === 2) { // up or down
                    $el->initspeedY = $speed;
                } else {
                    $el->initspeedX = $speed;
                }
            }
            $this->elevator[$i] = $el;
            $o += 20;
        }

        $this->altitudeZero = self::s16($b, $offset + 33776);
        $this->xLimit = self::s16($b, $offset + 35482);

        $this->trashcount = 4;
        $this->trash = [];
        for ($i = 0; $i < $this->trashcount; $i++) {
            $this->trash[$i] = new Sprite();
        }
    }

    private static function u16(string $b, int $o): int
    {
        return ord($b[$o]) | (ord($b[$o + 1]) << 8);
    }

    private static function s16(string $b, int $o): int
    {
        $v = ord($b[$o]) | (ord($b[$o + 1]) << 8);
        return $v >= 0x8000 ? $v - 0x10000 : $v;
    }
}
