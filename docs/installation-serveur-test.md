# Installation sur le serveur de test

Guide pas à pas pour installer l'intranet sur un serveur Linux interne (D-003, D-004). Durée : environ 30 minutes. Toutes les commandes se tapent sur le serveur, en root (`sudo -i`).

Le script `scripts/serveur/installer.sh` fait les étapes 3 et 4 d'un coup ; les sections ci-dessous disent ce qu'il fait, pour pouvoir vérifier ou le faire à la main.

**Secrets** : le script ne demande et n'affiche aucun mot de passe. Celui de la base de données est tiré au hasard et écrit seulement dans `web/sites/default/settings.php` (lisible par root et Apache, jamais dans le dépôt). Chaque rédacteur et admin choisit son mot de passe lui-même avec un lien à usage unique (étape 6).

**Données personnelles** : l'annuaire, l'organigramme, les photos et la liste des comptes ne sont jamais dans le dépôt. Ils sont copiés sur le serveur pour l'import, puis effacés du dossier d'import (étape 5).

## 1. Prérequis

| Élément | Valeur recommandée | Remarque |
|---|---|---|
| Système | Debian 13 ou Ubuntu 24.04 LTS | Drupal 11 demande PHP 8.3 ou plus : Debian 12 (PHP 8.2) ne convient pas |
| Serveur | 2 processeurs, 4 Go de mémoire, 20 Go de disque | ~100 agents, lecture surtout |
| Base de données | MariaDB (paquet de la distribution, 10.11 ou plus) | Installée par le script, écoute seulement en local |
| Serveur web | Apache 2.4 + PHP-FPM | Installés par le script |
| Nom DNS interne | ex. `intranet-test.mersch.local` | Créé par l'informatique, pointe vers le serveur |
| Certificat HTTPS | Certificat de l'autorité interne (fichiers `.crt` et `.key`) | Facultatif pour un premier essai en HTTP |
| Accès Internet sortant | `github.com`, `repo.packagist.org`, `packages.drupal.org`, `ftp.drupal.org` (HTTPS) | Pendant l'installation seulement (Composer). Sans `ftp.drupal.org`, Drupal s'installe en anglais puis passe en LU (voir étape 4) |
| Réseau | Ports 80 et 443 ouverts depuis le réseau interne seulement | Le script refuse aussi les autres adresses dans Apache (403) |
| Fichiers à importer | Dossier du projet : `organigramme/`, `guides-it/intranet/` ; liste Excel du personnel | Hors dépôt (RGPD) |

Testé le 2026-10-28 sur Ubuntu 24.04 (PHP 8.3, MariaDB 10.11, Drupal 11.4) : installation, modules, imports des guides et de l'organigramme, comptes, accès refusé hors réseau interne.

## 2. Récupérer le code et régler le script

```bash
apt-get update && apt-get install -y git
git clone https://github.com/Mariscaner/Intranet.git /var/www/intranet
cp /var/www/intranet/scripts/serveur/intranet.conf.exemple /etc/intranet-mersch.conf
nano /etc/intranet-mersch.conf
```

À renseigner dans `/etc/intranet-mersch.conf` :

| Réglage | Exemple | Rôle |
|---|---|---|
| `NOM_SERVEUR` | `intranet-test.mersch.local` | Adresse tapée dans Edge ou Chrome ; les autres noms sont refusés par Drupal |
| `RESEAUX_INTERNES` | `10.0.0.0/8 192.168.0.0/16` | Plages d'adresses du personnel ; les autres reçoivent une erreur 403 |
| `DOSSIER` | `/var/www/intranet` | Dossier du code |
| `MAIL_SITE` | adresse du service informatique | Expéditeur des e-mails de Drupal et e-mail du compte de secours |
| `CERTIFICAT`, `CLE_CERTIFICAT` | `/etc/ssl/intranet/intranet.crt`, `.key` | Vides = HTTP seulement |

Le reste (nom de la base, branche `main`) peut rester tel quel.

## 3. Lancer l'installation

```bash
bash /var/www/intranet/scripts/serveur/installer.sh
```

Le script s'arrête à la première erreur avec un message en français ; il peut être relancé après correction sans rien casser (Drupal n'est installé qu'une fois).

| Étape du script | Ce qu'elle fait |
|---|---|
| 1. Paquets | Apache, PHP-FPM et extensions (gd, xml, mbstring, curl, zip, intl, mysql, apcu, opcache), MariaDB, Composer ; vérifie PHP 8.3 ou plus |
| 2. Réglages PHP | Envois jusqu'à 20 Mo (PDF des communications RH et des guides), mémoire 256 Mo |
| 3. Code | `composer install --no-dev` dans `/var/www/intranet` |
| 4. Base | Base `intranet` en utf8mb4, utilisateur local avec mot de passe aléatoire, `settings.php` (connexion, `config/sync`, nom de serveur autorisé) |
| 5. Drupal | `drush site:install minimal` en luxembourgeois ; compte n° 1 « admin » = compte de secours, sans mot de passe affiché |
| 6. Modules | Thème `mersch`, thème d'administration Claro, `admin_toolbar` et les 5 modules `mersch_*` ; inscription libre désactivée ; rôle « Administrator » avec tous les droits |
| 7. Apache | Site `intranet-mersch` sur `NOM_SERVEUR`, `Require ip` limité aux réseaux internes, HTTPS si un certificat est donné (HTTP redirigé) |
| 8. Cron | Cron Drupal toutes les heures (retire les nouvelles échues) ; commande `drush-intranet` |

`drush-intranet` lance Drush sous l'utilisateur d'Apache (`www-data`), avec la bonne adresse. C'est la commande à utiliser pour tout le reste.

## 4. Vérifier

```bash
drush-intranet status
drush-intranet core:requirements --severity=2
```

| Vérification | Résultat attendu |
|---|---|
| `status` | « Drupal bootstrap : Successful », thème par défaut `mersch` |
| Depuis un PC du réseau, `http(s)://NOM_SERVEUR/` | Page d'accueil en luxembourgeois (« Startsäit ») |
| `/annuaire`, `/rh`, `/communication`, `/guides-it` | Pages vides mais sans erreur |
| Depuis une adresse hors `RESEAUX_INTERNES` | Erreur 403 |
| `core:requirements` | Pas d'erreur, sauf « Failed to retrieve security advisory data » si le serveur n'a pas d'accès Internet permanent (sans gravité) |

Si `ftp.drupal.org` n'était pas joignable, le script l'a signalé et a installé Drupal en anglais : le luxembourgeois reste la langue par défaut et les textes de l'intranet sont traduits (fichiers `.po` des modules), seuls certains écrans d'administration de Drupal restent en anglais. Pour les traduire plus tard, avec un accès Internet : `drush-intranet locale:check && drush-intranet locale:update`.

## 5. Importer l'annuaire, l'organigramme et les guides

Copier les fichiers depuis un PC (WinSCP, ou `scp`) dans un dossier temporaire du serveur :

| Données | Source | Destination sur le serveur |
|---|---|---|
| Annuaire (D-026) | Liste Excel du personnel (.xlsx) | `/srv/import-intranet/personnel.xlsx` |
| Organigramme (D-030) | Dossier du projet `organigramme/` (`organigramme.json` + `photos/`) | `/srv/import-intranet/organigramme/` |
| Guides IT (D-014) | Dossier du projet `guides-it/intranet/` (`guides.json` + 6 PDF corrigés) | `/srv/import-intranet/guides-it/` |

Puis :

```bash
chown -R www-data:www-data /srv/import-intranet && chmod -R o-rwx /srv/import-intranet

drush-intranet mersch:annuaire-import /srv/import-intranet/personnel.xlsx --verifier   # compte rendu seul
drush-intranet mersch:annuaire-import /srv/import-intranet/personnel.xlsx
drush-intranet mersch:organigramme-import /srv/import-intranet/organigramme
drush-intranet mersch:guides-import /srv/import-intranet/guides-it

rm -rf /srv/import-intranet   # données personnelles : ne pas les laisser traîner
```

Résultat attendu (essai du 2026-10-28) : « Organigramme chargé : 35 services, 155 postes, 148 photos. » et « 6 guides publiés. ». Pour recharger l'organigramme ou les guides, ajouter `--remplacer`. Plus tard, l'annuaire se met à jour depuis l'administration (`/admin/config/mersch/annuaire`), sans ligne de commande.

Avant l'import des guides : valider les PDF corrigés (`https://apps`, noms masqués), point ouvert de la feuille de route.

## 6. Créer les comptes des rédacteurs et des admins

Les 8 comptes de `04_roles-et-droits.md` (2 admins, 2 rédacteurs RH, 4 rédacteurs Communication) sont des comptes Drupal locaux (D-028). Tous les autres agents lisent sans compte (D-006).

```bash
cp /var/www/intranet/scripts/serveur/comptes.exemple /root/comptes.txt
chmod 600 /root/comptes.txt
nano /root/comptes.txt          # une ligne par personne : identifiant;e-mail professionnel;rôle
bash /var/www/intranet/scripts/serveur/creer-comptes.sh /root/comptes.txt
```

| Personne (04) | Rôle à écrire |
|---|---|
| Marc, Dan Bucari | `administrator` |
| Sarah Reuter, André Hippertchen | `redacteur_rh` |
| Aviva Rübel, Christian Mohr, Jill Manternach, John Manternach | `redacteur_communication` |

Pour chaque compte, le script affiche un lien de connexion à usage unique, valable 24 heures. La personne l'ouvre depuis son PC et choisit son mot de passe ; personne d'autre ne le connaît. Remettre le lien en main propre ou par e-mail interne, puis effacer le fichier : `rm /root/comptes.txt`. Un lien expiré se régénère en relançant le script (les comptes existants ne sont pas recréés) ou avec `drush-intranet user:login --name=<identifiant> --no-browser`.

Le compte n° 1 « admin » sert de secours : on s'y connecte avec `drush-intranet user:login --name=admin --no-browser`, sans mot de passe à retenir.

## 7. Après l'installation

| Sujet | À faire |
|---|---|
| `composer.lock` | Créé par le premier `composer install` sur le serveur : l'envoyer (pas de secret dedans) pour qu'il soit ajouté au dépôt, afin que les prochaines installations prennent les mêmes versions |
| Cron | Le lendemain : « Dernière exécution » dans `/admin/config/system/cron` |
| Sauvegarde du serveur de test | Facultative ; pour la production : base (`drush-intranet sql:dump --gzip`) et dossier `web/sites/default/files/` chaque jour (étape 9 de la feuille de route) |
| Relecture | Textes LU des 4 rubriques et des 6 guides par Marc (D-005) |

## 8. Mettre à jour le code

Après la fusion d'une nouvelle PR :

```bash
cd /var/www/intranet
git pull
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --no-interaction --optimize-autoloader
chown -R root:www-data . && chmod -R g+rX,g-w,o-rwx . && chown -R www-data:www-data web/sites/default/files
drush-intranet updatedb --yes
drush-intranet locale:check && drush-intranet locale:update
drush-intranet cache:rebuild
```

Un nouveau module `mersch_*` s'active avec `drush-intranet pm:install <module> --yes`.

## 9. En cas de problème

| Symptôme | Cause probable | Solution |
|---|---|---|
| « PHP 8.x trop ancien » | Debian 12 ou Ubuntu 22.04 | Utiliser Debian 13 ou Ubuntu 24.04 |
| `composer install` échoue (connexion) | Pas d'accès à `packages.drupal.org` ou `repo.packagist.org` | Ouvrir ces adresses le temps de l'installation, ou passer par le proxy de la commune (`export https_proxy=…`) |
| « The provided host name is not valid for this server » | Adresse tapée différente de `NOM_SERVEUR` | Utiliser le nom DNS prévu, ou corriger `trusted_host_patterns` dans `settings.php` |
| Erreur 403 depuis un PC du personnel | Plage d'adresses absente de `RESEAUX_INTERNES` | Corriger `/etc/intranet-mersch.conf` et relancer le script |
| Envoi d'un PDF refusé | Fichier de plus de 20 Mo | Réduire le PDF, ou augmenter les limites dans `/etc/php/*/fpm/conf.d/90-intranet-mersch.ini` puis redémarrer `php*-fpm` |
| Page blanche ou erreur 500 | Voir le journal | `tail /var/log/apache2/intranet-mersch-error.log` et `drush-intranet watchdog:show` |
