# moktar — notes for agents

This game is built with JumPHP. **Read `../../AGENTS.md` first** (rules, commands, API).

## What it is

A PHP port of **OpenTitus** (https://github.com/stople/OpenTitus, GPL v3), the open-source engine of the 1991
platformer *Lagaf': Les Aventures de Moktar*. Like OpenTitus, it **plays the original game files**: nothing of
the game is redrawn, everything is read from the `.SQZ` files at run time.

- `assets/data/` (git-ignored, proprietary): `LEVEL*.SQZ`, `SPRITES.SQZ`, `FONTS.SQZ`, `TITUS.SQZ`, `TITRE.SQZ`,
  `MENU.SQZ` from the original game, and `MUSIC_MOK.BIN` (the AdLib music, as extracted by OpenTitus `bin/`).
  Without them `GameScene` shows a "files missing" screen.
- `assets/music/` (generated, git-ignored): `bin/jumphp php games/moktar -d opcache.enable_cli=1
  -d opcache.jit_buffer_size=128M tools/render-audio.php` renders the music and sound effects to WAV (+ `audio.json`)
  once. Without them the game is silent.

## Code

- `src/Titus/` = the port. **One trait per C file, same function names, same globals** (properties of
  `Game` named like `globals.h`): `PlayerTrait` (player.c), `EnemiesTrait` (enemies.c), `ObjectsTrait`
  (objects.c), `GatesTrait` (gates.c), `ElevatorsTrait`, `ScrollTrait` (scroll.c, tile_animation.c, level.c
  flags), `SpritesTrait`, `ResetTrait` (reset.c, the final sequence), `DrawTrait` (draw.c, fonts.c, viewimage.c),
  `MenuTrait` (menu.c), `AudioTrait`. `Game.php` = opentitus.c + engine.c (main flow, game loop, death, game over).
  Keep the port faithful: fix bugs by comparing with the C code, not by redesigning.
- `Sqz` = the SQZ decompressor (LZW / Huffman), `Level` = level.c loader, `OriginalData` = tables of
  original.c (generated: do not edit), `Gfx` = 4-plane EGA graphics -> 8-bit BMP textures (atlases).
- **Blocking C code = generators**: `yield` = one flip of the screen, the value = minimum frame time in ms
  (29 = the 35 Hz game loop, 10 = quick flips, 0 = one display frame). Call blocking functions with `yield from`.
- `Screen` = the C 320x200 backbuffer as a display list (`flip()` presents, `render()` replays).
  The tile screen is the C circular buffer (`tilescreen`, `BITMAP_XM/YM`).
- `Input` = keystate + SDL events for the C code, filled by `GameScene` from the actions of `config/input.yaml`.
- `src/Titus/Audio/`: `AdlibDriver` (the original music driver of audio.c) + `DbOpl` (PHP port of the DOSBox OPL2 emulator `dbopl.c` that OpenTitus uses, bit-exact), used
  only by `tools/render-audio.php`.
- `src/Scene/GameScene.php`: the only scene; runs `Game::main()` at the original speed.

## Controls

Arrows: walk, Up = jump, Down = kneel (on a secret gate: enter it), Space: take / throw an object,
Enter: validate, P: pause, M: music on/off, E: energy, F4: status, Escape: back to the menu (in the menu: quit
on desktop). Cheats with `MOKTAR_CHEATS=1`: G godmode, N noclip, S skip level. Password codes: menu, 2nd line.

## Tests

- `bin/jumphp test moktar`: smoke = `GameScene` `{demo: true, level: 0}` (a bot plays level 1).
- Any level: `JUMPHP_SCENE='App\Scene\GameScene' JUMPHP_SCENE_PARAMS='{"demo":true,"level":5}' JUMPHP_MAX_FRAMES=3000 bin/jumphp test moktar`.
- Screens: `JUMPHP_SCENE='App\Scene\GameScene' JUMPHP_SCENE_PARAMS='{"level":0}' JUMPHP_INPUT='[[10,"start"]]' bin/jumphp shot moktar 300`.
