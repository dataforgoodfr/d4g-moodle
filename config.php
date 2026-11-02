<?php  // Moodle configuration file — Data For Good, image d4g-moodle.
//
// Tout vient de l'environnement du conteneur (d4g-gitops, base/moodle) : la
// base de l'item Vaultwarden `moodle-db`, l'URL publique de la ConfigMap.
// Rien de secret dans l'image.

unset($CFG);
global $CFG;
$CFG = new stdClass();

$CFG->dbtype    = 'pgsql';
$CFG->dblibrary = 'native';
$CFG->dbhost    = getenv('MOODLE_DB_HOST');
$CFG->dbname    = getenv('MOODLE_DB_NAME');
$CFG->dbuser    = getenv('MOODLE_DB_USER');
$CFG->dbpass    = getenv('MOODLE_DB_PASSWORD');
$CFG->prefix    = 'mdl_';
$CFG->dboptions = array(
    'dbpersist' => 0,
    'dbport'    => (int) getenv('MOODLE_DB_PORT'),
    'dbsocket'  => '',
);

// TLS terminé par Traefik : Moodle reçoit du HTTP et doit écrire des liens
// https. `sslproxy`, pas `reverseproxy` (celui-là sert quand le nom d'hôte
// change en route).
$CFG->wwwroot   = getenv('MOODLE_WWWROOT');
$CFG->sslproxy  = true;

$CFG->dataroot  = '/var/www/moodledata';
$CFG->admin     = 'admin';

$CFG->directorypermissions = 02775;

// Le code est dans l'image : on ne met pas Moodle à jour depuis l'admin, ni
// n'y installe de plugin — on reconstruit l'image (README.md).
$CFG->disableupdateautodeploy = true;
$CFG->disableupdatenotifications = true;

// Le cron tourne dans le conteneur `cron` du même pod, jamais depuis le web.
$CFG->cronclionly = true;

require_once(__DIR__ . '/lib/setup.php');

// There is no php closing tag in this file,
// it is intentional because it prevents trailing whitespace problems!
