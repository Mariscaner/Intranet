#!/usr/bin/env bash
#
# Installation de l'intranet Mersch sur le serveur de test interne
# (Debian 13 ou Ubuntu 24.04, Apache, PHP-FPM, MariaDB).
#
# Usage, en root : bash scripts/serveur/installer.sh [/etc/intranet-mersch.conf]
#
# Le script peut être relancé : chaque étape vérifie ce qui existe déjà et
# Drupal n'est installé qu'une fois. Il ne demande et n'affiche aucun mot de
# passe : celui de la base est tiré au hasard et écrit seulement dans
# settings.php, lisible par root et Apache.
# Guide complet : docs/installation-serveur-test.md

set -euo pipefail

CONF="${1:-/etc/intranet-mersch.conf}"

etape() { printf '\n\033[1m== %s\033[0m\n' "$*"; }
erreur() { printf '\033[31mERREUR : %s\033[0m\n' "$*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || erreur "lancer le script en root (sudo bash $0)."
[[ -r $CONF ]] || erreur "réglages introuvables : $CONF (copier scripts/serveur/intranet.conf.exemple)."
# shellcheck source=intranet.conf.exemple
source "$CONF"
for variable in NOM_SERVEUR RESEAUX_INTERNES DOSSIER BASE UTILISATEUR_BASE COMPTE_SECOURS MAIL_SITE; do
  [[ -n ${!variable:-} ]] || erreur "$variable est vide dans $CONF."
done
command -v apt-get >/dev/null || erreur "script prévu pour Debian ou Ubuntu (apt-get)."
if [[ -n ${CERTIFICAT:-} ]]; then
  [[ -r $CERTIFICAT && -r ${CLE_CERTIFICAT:-} ]] || erreur "certificat ou clé illisible : $CERTIFICAT / ${CLE_CERTIFICAT:-}."
  URL="https://$NOM_SERVEUR"
else
  URL="http://$NOM_SERVEUR"
fi
WEB="$DOSSIER/web"
SETTINGS="$WEB/sites/default/settings.php"
SETTINGS_SSO="$WEB/sites/default/settings.sso.php"

# SSO Kerberos (D-035, docs/sso-ad.md) : désactivé par défaut.
SSO_ACTIF="${SSO_ACTIF:-non}"
KEYTAB="/etc/intranet-mersch/http.keytab"
if [[ $SSO_ACTIF == oui ]]; then
  for variable in SSO_REALM SSO_LDAP_URI SSO_LDAP_BASE; do
    [[ -n ${!variable:-} ]] || erreur "$variable est vide dans $CONF (SSO_ACTIF=oui)."
  done
  [[ $NOM_SERVEUR == *.* ]] || erreur "SSO : NOM_SERVEUR doit être le nom complet couvert par le SPN (ex. intranet.domain.local)."
  for uri in $SSO_LDAP_URI; do
    [[ $uri == ldaps://* ]] || erreur "SSO : $uri doit commencer par ldaps:// (sinon le mot de passe LDAP passerait en clair)."
  done
  [[ -s $KEYTAB ]] || erreur "SSO : keytab absent ($KEYTAB). Laisser SSO_ACTIF=non tant que l'intégrateur ne l'a pas livré."
  [[ -z ${SSO_LDAP_CA:-} || -r $SSO_LDAP_CA ]] || erreur "SSO : certificat de l'autorité interne illisible : $SSO_LDAP_CA."
  if [[ ${SSO_LDAP_LIAISON:-gssapi} == motdepasse ]]; then
    [[ -n ${SSO_LDAP_COMPTE:-} && -s /etc/intranet-mersch/ldap.secret ]] \
      || erreur "SSO : liaison motdepasse sans SSO_LDAP_COMPTE ou sans /etc/intranet-mersch/ldap.secret."
  fi
elif [[ $SSO_ACTIF != non ]]; then
  erreur "SSO_ACTIF doit valoir oui ou non."
fi

# Drush tourne sous l'utilisateur d'Apache, pour que les fichiers déposés
# (photos, PDF) lui appartiennent.
drush() { sudo -u www-data "$DOSSIER/vendor/bin/drush" --root="$WEB" --uri="$URL" "$@"; }

etape "1/8 Paquets Apache, PHP, MariaDB, Composer"
export DEBIAN_FRONTEND=noninteractive
# Ubuntu : needrestart redémarre les services sans poser de question (sinon
# sa fenêtre bloque le script, invisible derrière un tee).
export NEEDRESTART_MODE=a NEEDRESTART_SUSPEND=1
apt-get update -q
apt-get install -y -q --no-install-recommends \
  apache2 mariadb-server \
  php-fpm php-cli php-mysql php-gd php-xml php-mbstring php-curl php-zip php-intl php-apcu \
  composer git unzip curl openssl ca-certificates sudo php-ldap
if [[ $SSO_ACTIF == oui ]]; then
  # Kerberos dans Apache (mod_auth_gssapi), liaison LDAP par GSSAPI.
  apt-get install -y -q --no-install-recommends \
    libapache2-mod-auth-gssapi libsasl2-modules-gssapi-mit krb5-user
fi
php -r 'exit(version_compare(PHP_VERSION, "8.3", ">=") ? 0 : 1);' \
  || erreur "PHP $(php -r 'echo PHP_VERSION;') trop ancien : Drupal 11 demande PHP 8.3 ou plus (Debian 13 ou Ubuntu 24.04)."
PHP_VERSION="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
# OPcache est intégré à PHP depuis 8.5 ; paquet séparé avant.
if ! php -m | grep -q "Zend OPcache"; then
  apt-get install -y -q --no-install-recommends "php$PHP_VERSION-opcache"
fi
echo "PHP $PHP_VERSION, $(mariadb --version)"

etape "2/8 Réglages PHP (envois de 20 Mo pour les PDF RH et guides)"
cat > "/etc/php/$PHP_VERSION/fpm/conf.d/90-intranet-mersch.ini" <<'INI'
memory_limit = 256M
upload_max_filesize = 20M
post_max_size = 25M
max_execution_time = 120
expose_php = Off
opcache.memory_consumption = 128
INI
cat > "/etc/php/$PHP_VERSION/cli/conf.d/90-intranet-mersch.ini" <<'INI'
upload_max_filesize = 20M
post_max_size = 25M
INI
systemctl enable --now "php$PHP_VERSION-fpm" mariadb
systemctl restart "php$PHP_VERSION-fpm"

etape "3/8 Code de l'intranet dans $DOSSIER"
if [[ ! -d $DOSSIER ]]; then
  git clone --branch "$BRANCHE" "$DEPOT" "$DOSSIER"
fi
[[ -f $DOSSIER/composer.json ]] || erreur "$DOSSIER ne contient pas le dépôt de l'intranet (composer.json absent)."
cd "$DOSSIER"
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --no-interaction --optimize-autoloader

etape "4/8 Base de données et settings.php"
if [[ -f $SETTINGS ]]; then
  echo "settings.php existe déjà : base et réglages conservés."
else
  MDP_BASE="$(openssl rand -hex 24)"
  # Mot de passe passé par l'entrée standard, jamais sur la ligne de commande.
  mariadb <<SQL
CREATE DATABASE IF NOT EXISTS \`$BASE\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
CREATE USER IF NOT EXISTS '$UTILISATEUR_BASE'@'localhost' IDENTIFIED BY '$MDP_BASE';
ALTER USER '$UTILISATEUR_BASE'@'localhost' IDENTIFIED BY '$MDP_BASE';
GRANT ALL PRIVILEGES ON \`$BASE\`.* TO '$UTILISATEUR_BASE'@'localhost';
SQL
  HOTE_REGEX="^$(printf '%s' "$NOM_SERVEUR" | sed 's/[.-]/\\&/g')\$"
  umask 077
  cp "$WEB/sites/default/default.settings.php" "$SETTINGS"
  cat >> "$SETTINGS" <<PHP

// Intranet Mersch : réglages du serveur (écrits par scripts/serveur/installer.sh).
\$databases['default']['default'] = [
  'driver' => 'mysql',
  'database' => '$BASE',
  'username' => '$UTILISATEUR_BASE',
  'password' => '$MDP_BASE',
  'host' => 'localhost',
  'port' => '3306',
  'prefix' => '',
  'collation' => 'utf8mb4_general_ci',
];
\$settings['config_sync_directory'] = '../config/sync';
\$settings['trusted_host_patterns'] = ['$HOTE_REGEX', '^localhost\$'];
// Validation HTML5 des formulaires gardée (Drupal 12 la désactivera par défaut).
\$settings['enable_html5_validation'] = TRUE;
PHP
  umask 022
  unset MDP_BASE
fi
install -d -o www-data -g www-data -m 2770 "$WEB/sites/default/files"

# Réglages du SSO (sans secret), réécrits à chaque passage depuis $CONF.
if [[ $SSO_ACTIF == oui ]]; then PHP_ACTIF=TRUE; else PHP_ACTIF=FALSE; fi
if [[ -n ${CERTIFICAT:-} ]]; then PHP_HTTPS=TRUE; else PHP_HTTPS=FALSE; fi
php_texte() { printf "'%s'" "$(printf '%s' "$1" | sed "s/[\\']/\\&/g")"; }
cat > "$SETTINGS_SSO" <<PHP
<?php

// Écrit par scripts/serveur/installer.sh depuis $CONF : ne pas modifier ici.
// Connexion SSO Kerberos (module mersch_sso, D-035, docs/sso-ad.md).
\$settings['mersch_sso'] = [
  'actif' => $PHP_ACTIF,
  'serveur' => $(php_texte "$NOM_SERVEUR"),
  'https' => $PHP_HTTPS,
  'realm' => $(php_texte "${SSO_REALM:-}"),
  'ldap_uri' => $(php_texte "${SSO_LDAP_URI:-}"),
  'ldap_base' => $(php_texte "${SSO_LDAP_BASE:-}"),
  'ldap_liaison' => $(php_texte "${SSO_LDAP_LIAISON:-gssapi}"),
  'ldap_compte' => $(php_texte "${SSO_LDAP_COMPTE:-}"),
  'ldap_fichier_mdp' => '/etc/intranet-mersch/ldap.secret',
  'ldap_ca' => $(php_texte "${SSO_LDAP_CA:-}"),
  'keytab' => '$KEYTAB',
  'inactivite_mois' => $(( ${SSO_INACTIVITE_MOIS:-12} )),
];
PHP
chown root:www-data "$SETTINGS_SSO"
chmod 440 "$SETTINGS_SSO"
if ! grep -q 'settings.sso.php' "$SETTINGS"; then
  chmod u+w "$SETTINGS"
  cat >> "$SETTINGS" <<'PHP'
// Réglages SSO écrits par installer.sh (module mersch_sso).
if (file_exists($app_root . '/' . $site_path . '/settings.sso.php')) {
  include $app_root . '/' . $site_path . '/settings.sso.php';
}
PHP
fi
if [[ $SSO_ACTIF == oui ]]; then
  # Le keytab est lu par Apache (mod_auth_gssapi) et par PHP (liaison LDAP).
  chown root:www-data /etc/intranet-mersch /etc/intranet-mersch/http.keytab
  chmod 750 /etc/intranet-mersch
  chmod 640 /etc/intranet-mersch/http.keytab
  [[ -f /etc/intranet-mersch/ldap.secret ]] && chown root:www-data /etc/intranet-mersch/ldap.secret && chmod 640 /etc/intranet-mersch/ldap.secret
  # Client Kerberos : contrôleurs trouvés par le DNS, pas de nom inversé.
  # Le paquet krb5-user peut poser un krb5.conf d'exemple (ATHENA.MIT.EDU) :
  # remplacé, avec une sauvegarde, s'il ne nomme pas le realm de l'AD.
  if ! grep -Eq "^[[:space:]]*default_realm[[:space:]]*=[[:space:]]*$SSO_REALM[[:space:]]*\$" /etc/krb5.conf 2>/dev/null; then
    [[ -f /etc/krb5.conf ]] && cp -p /etc/krb5.conf "/etc/krb5.conf.avant-intranet.$(date +%Y%m%d%H%M%S)"
    cat > /etc/krb5.conf <<KRB5
# Écrit par scripts/serveur/installer.sh (SSO de l'intranet).
[libdefaults]
  default_realm = $SSO_REALM
  dns_lookup_kdc = true
  dns_lookup_realm = false
  rdns = false
KRB5
  fi
fi

etape "5/8 Installation de Drupal"
if [[ "$(drush status --field=bootstrap 2>/dev/null || true)" == *Successful* ]]; then
  echo "Drupal est déjà installé."
else
  # Installation en anglais : localize.drupal.org n'a pas toujours de fichier
  # luxembourgeois pour la version de Drupal installée, et site:install
  # s'arrête alors. Le module mersch_accueil met ensuite le luxembourgeois par
  # défaut, et les traductions de Drupal sont importées à l'étape 6.
  # Root écrit hash_salt dans settings.php ; le mot de passe tiré au hasard
  # pour le compte de secours n'est pas affiché (connexion par drush user:login).
  "$DOSSIER/vendor/bin/drush" --root="$WEB" --uri="$URL" site:install minimal \
    --locale=en --site-name="Intranet Mersch" --site-mail="$MAIL_SITE" \
    --account-name="$COMPTE_SECOURS" --account-mail="$MAIL_SITE" --yes 2>&1 \
    | sed '/[Pp]assword/d'
fi
chown -R root:www-data "$DOSSIER"
chmod -R g+rX,g-w,o-rwx "$DOSSIER"
chown -R www-data:www-data "$WEB/sites/default/files"
chmod 440 "$SETTINGS"
chmod 550 "$WEB/sites/default"

etape "6/8 Thème, modules mersch_* et rôle Administrateur"
drush theme:enable mersch claro
drush config:set system.theme default mersch --yes
drush config:set system.theme admin claro --yes
drush pm:install admin_toolbar mersch_accueil mersch_annuaire mersch_rh mersch_communication mersch_guides mersch_services mersch_sso --yes
# Personne ne crée son compte : les admins créent les 8 comptes (04, D-028).
drush config:set user.settings register admin_only --yes
# shellcheck disable=SC2016
drush php:eval '
  $role = \Drupal\user\Entity\Role::load("administrator")
    ?? \Drupal\user\Entity\Role::create(["id" => "administrator", "label" => "Administrator", "weight" => 2]);
  $role->setIsAdmin(TRUE)->save();
'
# Traductions de Drupal (LU, DE, FR) quand elles existent : sans Internet ou
# sans fichier pour cette version, l'intranet garde les siennes (.po des modules).
drush locale:check && drush locale:update \
  || echo "Traductions de Drupal non téléchargées : à refaire plus tard avec drush-intranet locale:update."
drush cache:rebuild

etape "7/8 Apache : $URL, réseau interne seulement"
a2enmod -q rewrite headers proxy_fcgi setenvif >/dev/null
a2enconf -q "php$PHP_VERSION-fpm" >/dev/null
cat > /etc/apache2/conf-available/intranet-mersch-securite.conf <<'APACHE'
ServerTokens Prod
ServerSignature Off
APACHE
a2enconf -q intranet-mersch-securite >/dev/null
SSO_APACHE=""
if [[ $SSO_ACTIF == oui ]]; then
  # Seule /sso/connexion négocie Kerberos : les autres pages restent lisibles
  # sans connexion (US-30). RequireAll garde la restriction aux réseaux
  # internes, que Require valid-user remplacerait sinon.
  SSO_APACHE="
  # SSO Kerberos (module mersch_sso, docs/sso-ad.md).
  <LocationMatch \"^/([a-z]{2}/)?sso/connexion\$\">
    AuthType GSSAPI
    AuthName \"Intranet Mersch\"
    GssapiCredStore keytab:$KEYTAB
    GssapiAllowedMech krb5
    GssapiBasicAuth Off
    GssapiLocalName Off
    GssapiNegotiateOnce On
    $([[ -n ${CERTIFICAT:-} ]] && echo 'GssapiSSLonly On' || true)
    <RequireAll>
      Require ip 127.0.0.1 ::1 $RESEAUX_INTERNES
      Require valid-user
    </RequireAll>
    ErrorDocument 401 /sso/echec
  </LocationMatch>
  # Le ticket Kerberos ne vaut que pour le nom complet : nom court et adresse
  # IP sont renvoyés vers lui (la session y est la même pour toutes les pages).
  RewriteEngine On
  RewriteCond %{HTTP_HOST} !^$(printf '%s' "$NOM_SERVEUR" | sed 's/[.-]/\\&/g')(:[0-9]+)?\$ [NC]
  RewriteRule ^ $URL%{REQUEST_URI} [R=302,L]"
fi
REPERTOIRE="  DocumentRoot $WEB
  <Directory $WEB>
    AllowOverride All
    Options -Indexes +FollowSymLinks
    # D-003 : réseau interne uniquement.
    Require ip 127.0.0.1 ::1 $RESEAUX_INTERNES
  </Directory>
  ErrorLog \${APACHE_LOG_DIR}/intranet-mersch-error.log
  CustomLog \${APACHE_LOG_DIR}/intranet-mersch-access.log combined$SSO_APACHE"
{
  echo "# Écrit par scripts/serveur/installer.sh"
  echo "<VirtualHost *:80>"
  echo "  ServerName $NOM_SERVEUR"
  if [[ -n ${CERTIFICAT:-} ]]; then
    echo "  Redirect permanent / https://$NOM_SERVEUR/"
  else
    echo "$REPERTOIRE"
  fi
  echo "</VirtualHost>"
  if [[ -n ${CERTIFICAT:-} ]]; then
    echo "<VirtualHost *:443>"
    echo "  ServerName $NOM_SERVEUR"
    echo "  SSLEngine on"
    echo "  SSLCertificateFile $CERTIFICAT"
    echo "  SSLCertificateKeyFile $CLE_CERTIFICAT"
    echo "$REPERTOIRE"
    echo "</VirtualHost>"
  fi
} > /etc/apache2/sites-available/intranet-mersch.conf
[[ -n ${CERTIFICAT:-} ]] && a2enmod -q ssl >/dev/null
if [[ $SSO_ACTIF == oui ]]; then a2enmod -q auth_gssapi >/dev/null; fi
a2dissite -q 000-default >/dev/null 2>&1 || true
a2ensite -q intranet-mersch >/dev/null
apache2ctl configtest
systemctl reload apache2

etape "8/8 Cron Drupal (retire les nouvelles échues) et commande drush-intranet"
cat > /etc/cron.d/intranet-mersch <<CRON
# Cron Drupal de l'intranet Mersch, toutes les heures (écrit par installer.sh).
17 * * * * www-data $DOSSIER/vendor/bin/drush --root=$WEB --uri=$URL cron --quiet
CRON
cat > /usr/local/bin/drush-intranet <<SH
#!/bin/sh
# Drush de l'intranet Mersch, sous l'utilisateur d'Apache.
exec sudo -u www-data $DOSSIER/vendor/bin/drush --root=$WEB --uri=$URL "\$@"
SH
chmod 755 /usr/local/bin/drush-intranet

drush status --fields=drupal-version,db-status,bootstrap,php-version,theme
drush core:requirements --severity=2 || true
printf '\n\033[32mIntranet installé : %s\033[0m\n' "$URL"
echo "Suite : imports et comptes, voir docs/installation-serveur-test.md (étapes 5 et 6)."
