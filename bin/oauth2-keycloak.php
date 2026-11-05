<?php
// Le service OAuth2 de Moodle branché sur Keycloak (d4g-gitops DG-88).
//
// Les réglages OAuth2 vivent dans la base de Moodle, pas dans l'image : ce
// script est la façon de les refaire. Idempotent — l'émetteur est retrouvé par
// son URL, créé s'il manque, son client et son secret remis sinon.
//
// Pas dans l'image : il est copié dans le pod le temps d'un appel. Le secret
// arrive sur l'entrée standard, jamais en argument (il finirait dans `ps`).
//
//   kubectl -n prod cp bin/oauth2-keycloak.php moodle-xxx:/tmp/ -c web
//   <secret du client> | kubectl -n prod exec -i deploy/moodle -c web -- \
//     php /tmp/oauth2-keycloak.php https://auth.dataforgood.fr/realms/dataforgood moodle-prod
//
// `--disable-others` coupe en plus tous les autres émetteurs (Authentik) :
// désactivés, pas supprimés — leurs liaisons de comptes restent, et
// `\core\oauth2\api::enable_issuer(<id>)` les rallume.
//
// Ce qu'il règle, et pourquoi :
// - `sub` → `username` : c'est la clé de la liaison (auth_oauth2_linked_login).
//   Par défaut Moodle prend l'e-mail, qui change ; `preferred_username` a déjà
//   été renommé en masse une fois (d4g-community-manager DCM2-37). `sub` ne
//   bouge jamais.
// - `requireconfirmation` à 0 : au premier passage, un compte Moodle existant
//   qui a le même e-mail est rattaché d'office, sans courriel de confirmation —
//   pas de doublon. Keycloak vérifie les e-mails (verify_email) et les veut
//   uniques, c'est ce qui rend ce rattachement sûr.
// - le reste des correspondances est celui que Moodle crée à la découverte
//   OpenID Connect (given_name, family_name, email…) ; une revendication
//   absente ne vide pas le champ.

define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
require_once($CFG->libdir . '/clilib.php');

use core\oauth2\api;
use core\oauth2\issuer;
use core\oauth2\user_field_mapping;

[$options, $args] = cli_get_params(['disable-others' => false, 'help' => false], ['h' => 'help']);
if ($options['help'] || count($args) !== 2) {
    cli_writeln('Usage : php oauth2-keycloak.php [--disable-others] <URL du realm> <client id>  (secret sur stdin)');
    exit(empty($options['help']) ? 2 : 0);
}
[$baseurl, $clientid] = $args;

$secret = trim((string) fgets(STDIN));
if ($secret === '') {
    cli_error('Pas de secret sur l\'entrée standard.');
}

// api::create_issuer exige moodle/site:config.
\core\session\manager::set_user(get_admin());

$issuer = issuer::get_record(['baseurl' => $baseurl]);
if (!$issuer) {
    $issuer = api::create_issuer((object) [
        'name' => 'Data For Good',
        'image' => '',
        'baseurl' => $baseurl,
        'clientid' => $clientid,
        'clientsecret' => $secret,
        'loginscopes' => 'openid profile email',
        'loginscopesoffline' => 'openid profile email',
        'loginparams' => '',
        'loginparamsoffline' => '',
        'alloweddomains' => '',
        'showonloginpage' => issuer::LOGINONLY,
        'basicauth' => 1,
        'requireconfirmation' => 0,
        'servicetype' => 'custom',
    ]);
    cli_writeln("Émetteur créé : {$issuer->get('id')}");
} else {
    $issuer->set('clientid', $clientid);
    $issuer->set('clientsecret', $secret);
    $issuer->set('requireconfirmation', 0);
    $issuer->update();
    cli_writeln("Émetteur mis à jour : {$issuer->get('id')}");
}
if (!$issuer->get('enabled')) {
    api::enable_issuer($issuer->get('id'));
}
if (!$issuer->get_endpoint_url('authorization')) {
    cli_error('Découverte OpenID Connect sans résultat : ' . $baseurl . '/.well-known/openid-configuration');
}

$username = user_field_mapping::get_record(['issuerid' => $issuer->get('id'), 'internalfield' => 'username']);
if (!$username) {
    (new user_field_mapping(0, (object) [
        'issuerid' => $issuer->get('id'), 'externalfield' => 'sub', 'internalfield' => 'username',
    ]))->create();
} else if ($username->get('externalfield') !== 'sub') {
    $username->set('externalfield', 'sub');
    $username->update();
}

// Premier sur la page de connexion.
while ($issuer->get('sortorder') > 0) {
    api::move_up_issuer($issuer->get('id'));
    $issuer = new issuer($issuer->get('id'));
}

if ($options['disable-others']) {
    foreach (api::get_all_issuers(true) as $other) {
        if ($other->get('id') != $issuer->get('id') && $other->get('enabled')) {
            api::disable_issuer($other->get('id'));
            cli_writeln("Désactivé : {$other->get('id')} {$other->get('name')}");
        }
    }
}

foreach (api::get_all_issuers(true) as $i) {
    cli_writeln(sprintf('%s %-25s enabled=%d loginpage=%d %s',
        $i->get('id'), $i->get('name'), $i->get('enabled'), $i->get('showonloginpage'), $i->get('baseurl')));
}
