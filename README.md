# MOKTAR (JumPHP)

Portage en PHP d'[OpenTitus](https://github.com/stople/OpenTitus), le moteur libre du jeu de plateforme
*Lagaf' : Les Aventures de Moktar* (Titus, 1991), sur JumPHP : il tourne sur le bureau et dans le navigateur.

Comme OpenTitus, il lit **les fichiers originaux du jeu** (format `.SQZ`) : ils ne sont pas fournis.

0. Ce dépôt est un jeu [JumPHP](https://github.com/davidnurdin/jumPhp) : il se place dans `games/moktar` du framework.
   ```bash
   git clone https://github.com/davidnurdin/jumPhp
   git clone https://github.com/davidnurdin/moktarPhp jumPhp/games/moktar
   cd jumPhp
   ```
1. Copier les fichiers du jeu (`*.SQZ`) et `MUSIC_MOK.BIN` (dossier `bin/` d'OpenTitus) dans `assets/data/`.
2. `bin/jumphp install moktar`
3. Musique (une fois, quelques minutes) :
   `bin/jumphp php games/moktar -d opcache.enable_cli=1 -d opcache.jit_buffer_size=128M tools/render-audio.php`
4. `bin/jumphp run moktar` (bureau) ou `bin/jumphp build moktar && bin/jumphp serve moktar` (navigateur).

Sur téléphone : joystick flottant (moitié gauche de l'écran, les diagonales font marcher + sauter),
SAUT, PRENDRE, START et PAUSE.

Touches : flèches (haut = sauter, bas = s'accroupir / passage secret), Espace = prendre / lancer, Entrée,
P = pause, M = musique, E = énergie, F4 = statut, Échap = menu.

Code sous GPL v3 (comme OpenTitus). Les fichiers du jeu original restent la propriété de leurs auteurs.
