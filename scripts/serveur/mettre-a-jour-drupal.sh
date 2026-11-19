#!/usr/bin/env bash
#
# Mise à jour de Drupal (cœur) sur le serveur de l'intranet Mersch, par exemple
# après l'alerte « There is a security update available ».
#
# Usage, en root : bash scripts/serveur/mettre-a-jour-drupal.sh [/etc/intranet-mersch.conf]
#
# Étapes : sauvegarde de la base et des fichiers déposés, mode maintenance,
# composer update drupal/core-*, mises à jour de la base, droits, fin de la
# maintenance. composer.lock n'est pas suivi par Git : il est mis à jour sur
# le serveur seulement.
# Guide : docs/installation-serveur-test.md, étape 8.

set -euo pipefail

CONF="${1:-/etc/intranet-mersch.conf}"

etape() { printf '\n\033[1m== %s\033[0m\n' "$*"; }
erreur() { printf '\033[31mERREUR : %s\033[0m\n' "$*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || erreur "lancer le script en root (sudo bash $0)."
[[ -r $CONF ]] || erreur "réglages introuvables : $CONF."
# shellcheck source=intranet.conf.exemple
source "$CONF"
WEB="$DOSSIER/web"
DRUSH=/usr/local/bin/drush-intranet
[[ -x $DRUSH ]] || erreur "$DRUSH absent : lancer d'abord scripts/serveur/installer.sh."
SAUVEGARDES=/var/backups/intranet
HORODATAGE="$(date +%Y%m%d-%H%M)"

# En cas d'échec après la sauvegarde : le site reste en maintenance et le
# message dit comment revenir en arrière.
echec() {
  printf '\n\033[31mLa mise à jour a échoué. Le site est resté en maintenance.\033[0m\n' >&2
  printf 'Sauvegardes : %s/*-%s*\n' "$SAUVEGARDES" "$HORODATAGE" >&2
  printf 'Retour arrière : docs/installation-serveur-test.md, étape 8.\n' >&2
}

etape "1/6 Version actuelle"
"$DRUSH" status --fields=drupal-version,php-version
cd "$DOSSIER"

etape "2/6 Sauvegarde de la base, des fichiers déposés et de composer.lock"
install -d -o www-data -g root -m 700 "$SAUVEGARDES"
"$DRUSH" sql:dump --gzip --result-file="$SAUVEGARDES/base-$HORODATAGE.sql"
tar -czf "$SAUVEGARDES/fichiers-$HORODATAGE.tar.gz" -C "$WEB/sites/default" files
cp composer.lock "$SAUVEGARDES/composer-$HORODATAGE.lock"
chmod 600 "$SAUVEGARDES"/*-"$HORODATAGE"*
ls -lh "$SAUVEGARDES"/*-"$HORODATAGE"*
trap echec ERR

etape "3/6 Mode maintenance"
"$DRUSH" state:set system.maintenance_mode 1 --input-format=integer
"$DRUSH" cache:rebuild

etape "4/6 Mise à jour du cœur de Drupal (Composer)"
COMPOSER_ALLOW_SUPERUSER=1 composer update "drupal/core-*" --with-all-dependencies \
  --no-dev --no-interaction --optimize-autoloader
chown -R root:www-data "$DOSSIER"
chmod -R g+rX,g-w,o-rwx "$DOSSIER"
chown -R www-data:www-data "$WEB/sites/default/files"

etape "5/6 Mises à jour de la base et des traductions"
"$DRUSH" updatedb --yes
"$DRUSH" locale:check && "$DRUSH" locale:update \
  || echo "Traductions de Drupal non téléchargées : à refaire plus tard."
"$DRUSH" cache:rebuild

etape "6/6 Fin de la maintenance"
"$DRUSH" state:set system.maintenance_mode 0 --input-format=integer
"$DRUSH" cache:rebuild
trap - ERR
"$DRUSH" status --fields=drupal-version,db-status,bootstrap
printf '\n\033[32mDrupal à jour. Vérifiez Administration > Rapports > Mises à jour disponibles.\033[0m\n'
