# d4g-moodle

L'image Moodle de Data For Good : `ghcr.io/dataforgoodfr/d4g-moodle`,
déployée par `d4g-gitops` (`base/moodle`) sur le cluster `tools`.

- Base : `moodlehq/moodle-php-apache:8.3-bookworm`, épinglée par digest.
- Moodle : le tag `MOODLE_REF` du `Dockerfile` (branche 4.5 LTS).
- Plugins hors distribution, épinglés : `theme_boost_magnific` (le thème du
  site) et `logstore_lanalytics`. La liste réelle du site :
  `php admin/cli/uninstall_plugins.php --show-contrib`.
- `config.php` lit tout dans l'environnement (`MOODLE_DB_*`,
  `MOODLE_WWWROOT`) ; aucun secret dans l'image.
- Apache écoute sur 8080 et tourne en `www-data` (uid 33).

## Monter Moodle ou un plugin

1. Changer l'épingle dans le `Dockerfile`, pousser sur `main` : la CI publie
   `sha-<court>`.
2. `d4gctl deploy moodle sha-<court>` en staging, puis `d4gctl promote moodle`.
3. L'initContainer `upgrade` du Deployment lance `admin/cli/upgrade.php` avant
   qu'Apache ne démarre : la base migre au premier pod de la nouvelle image.
   Un dump de la base avant, toujours.

On n'installe ni ne met à jour rien depuis l'admin de Moodle : le code n'est
pas inscriptible, et ce qui y serait écrit disparaîtrait au pod suivant.
