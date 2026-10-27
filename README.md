# Intranet Mersch

Intranet du personnel de l'Administration communale de Mersch : annuaire, liens vers les outils, pages RH, Communication et Guides informatiques, en luxembourgeois avec traductions allemande et française.

Le cahier des charges (01 v3), les rôles (04) et le journal des décisions (05) sont tenus dans le dossier du projet, hors de ce dépôt. En cas de contradiction, le journal des décisions fait foi.

## Choix techniques

| Sujet | Choix | Décision |
|---|---|---|
| Hébergement | Serveur Linux communal, réseau interne uniquement | D-003 |
| CMS | Drupal 11 (Composer), MariaDB | D-004 |
| Langues | LU par défaut, DE et FR (modules de base Language, Content Translation, Locale) | D-005 |
| Connexion | Comptes Drupal locaux pour rédacteurs et admins ; lecteurs sans connexion ; SSO Entra ID = évolution | D-006, D-028 |
| Annuaire | Liste Excel du personnel importée par un admin (module `mersch_annuaire`) | D-026 (remplace D-009) |
| Charte | Thème `mersch` : couleurs et logo de la charte 2023 | D-002 |
| Navigateurs | Edge et Chrome à jour | D-013 |

## Structure

```
.ddev/                    Environnement de développement local
config/sync/              Configuration Drupal exportée (drush cex)
docs/                     Feuille de route technique
web/themes/custom/mersch/ Thème de l'intranet
web/modules/custom/       Modules propres au projet (mersch_accueil, mersch_annuaire, mersch_rh)
```

## Démarrer en local

Prérequis : [DDEV](https://ddev.readthedocs.io/) et Docker.

```bash
ddev start
ddev composer install
ddev drush site:install minimal --locale=lb --site-name="Intranet Mersch"
ddev drush theme:enable mersch
ddev drush config:set system.theme default mersch -y
ddev drush pm:install mersch_accueil mersch_annuaire mersch_rh -y
ddev drush theme:enable claro
ddev drush config:set system.theme admin claro -y
ddev launch
```

Le premier `composer install` crée `composer.lock` : il faut le committer.

## Page d'accueil

Le module `mersch_accueil` fournit la page d'accueil (`/accueil`, déclarée page d'accueil du site à l'installation), d'après la maquette validée :
- liens rapides DSKNet, GESCOM, Helpdesk et mersch.lu, ouverts dans un nouvel onglet (US-10) ;
- recherche dans l'annuaire, qui ouvre `/annuaire?q=…` avec le champ déjà rempli, et lien vers l'annuaire complet (affichés seulement si `mersch_annuaire` est installé) ;
- accès aux rubriques RH, Communication et Guides IT, aussi présentes dans le menu principal (US-03) ; `/rh`, `/communication` et `/guides-it` affichent une page d'attente tant que les rubriques ne sont pas construites ;
- boutons LU / DE / FR en haut à droite (US-02) : le module crée les trois langues, met le luxembourgeois par défaut (adresses sans préfixe, `/de/…` et `/fr/…` pour les traductions).

Un admin gère les liens rapides depuis **Administration > Configuration > Quick links** (`/admin/config/mersch/liens-rapides`) : libellé et description en LU, DE et FR (une langue vide reprend le LU), URL en `https://` ou `http://` (une autre adresse est refusée), icône, affichage, ordre par glisser-déposer (US-11). Seuls les admins ont la permission « Administer quick links ».

Les textes de l'interface sont en anglais dans le code et traduits dans `web/modules/custom/mersch_accueil/translations/` (`lb.po`, `de.po`, `fr.po`), importés à l'installation du module. Le LU est à relire par Marc (D-005). Après une modification d'un `.po` : `ddev drush locale:check && ddev drush locale:update`.

Le thème place lui-même ses blocs (langues, menu principal, titre, onglets, messages) depuis `web/themes/custom/mersch/config/optional/`.

Tests : `ddev exec SIMPLETEST_DB=sqlite://localhost/:memory: vendor/bin/phpunit -c web/core web/modules/custom/mersch_accueil` (avec `drupal/core-dev` installé).

## Annuaire

Le module `mersch_annuaire` affiche l'annuaire sur `/annuaire` : agents groupés par service, recherche sans accents, liens `tel:` et `mailto:`.

Un admin le met à jour depuis **Administration > Configuration > Staff directory** (`/admin/config/mersch/annuaire`) : il dépose la liste Excel du personnel (.xlsx ou .csv), clique sur « Check » pour lire le compte rendu, puis sur « Import ». La nouvelle liste remplace l'ancienne ; le fichier n'est pas conservé.

Règles de lecture :
- colonnes attendues : Nom/Prénom, Tél., Gsm, Mail, mail service (colonne sans titre après Mail), Service, Bâtiment, Remarques ;
- une ligne est ignorée si elle n'a pas de service, si le nom n'est pas au format « NOM Prénom » ou si elle figure dans la liste d'exclusion (« HELPDESK IT » par défaut) ;
- les sigles en tête du service sont remplacés par leur nom complet (« SR = Service régie », « ST = Service technique », modifiables dans le formulaire, D-029) ;
- les variantes d'écriture d'un même service (accents, majuscules, espaces) sont regroupées, les services presque identiques sont signalés ;
- si aucune ligne n'est valide, l'annuaire actuel est conservé.

En ligne de commande : `ddev drush mersch:annuaire-import ~/personnel.xlsx --verifier`, puis sans `--verifier` pour importer.

Tests : `ddev exec SIMPLETEST_DB=sqlite://localhost/:memory: vendor/bin/phpunit -c web/core web/modules/custom/mersch_annuaire` (avec `drupal/core-dev` installé).

## Page RH

Le module `mersch_rh` remplace la page d'attente `/rh` par la page RH, gérée par les rédacteurs RH (rôle « HR editor », `redacteur_rh`, à attribuer à Sarah Reuter et André Hippertchen d'après 04) :

- **Organigramme** (US-12, D-011) : un rédacteur RH clique sur « Replace the organisation chart » (`/rh/organigramme`) et dépose le PDF fourni par les RH (une image PNG ou JPG est aussi acceptée). Le nouveau fichier remplace l'ancien, qui est supprimé (pas d'archive) ; la date de mise à jour s'affiche. Le PDF est affiché dans la visionneuse d'Edge ou de Chrome, avec liens « plein écran » (pour zoomer et imprimer) et « télécharger ».
- **Nouvelles RH** (US-13, D-023), type de contenu `rh_nouvelle` : catégorie (nouveau visage, départ à la retraite, autre), titre (le nom de la personne), service, texte, photo facultative, date de fin de publication facultative. La case « accord écrit obtenu » est obligatoire pour un nouveau visage, une retraite ou une photo ; plus de 150 mots est refusé, moins de 80 mots donne un simple avertissement. Sur la page, les nouvelles sont groupées sous « Nei Gesiichter » et « Merci! », plus récentes d'abord ; sans photo, les initiales s'affichent.
- **Communications RH** (US-14), type de contenu `rh_communication` : titre, court texte, PDF obligatoire.

Droits du rôle : créer, modifier, traduire (DE/FR) et dépublier les contenus RH (case « Publié »), voir leurs révisions, déposer l'organigramme ; pas de suppression (D-019), pas d'accès aux contenus Communication (D-007).

À régler sur le serveur :
- taille maximale d'envoi PHP (`upload_max_filesize` et `post_max_size`) d'au moins 20 Mo : le PDF des RH d'août 2026 pèse 7,6 Mo ;
- le cron Drupal une fois par jour au moins : il retire de la page les nouvelles dont la date de fin est passée.

Photos et noms des nouvelles, organigramme : données personnelles. Rien de réel n'est dans le dépôt ; les fichiers déposés vont dans `web/sites/default/files/rh/`, ignoré par Git.

Tests : `ddev exec SIMPLETEST_DB=sqlite://localhost/:memory: vendor/bin/phpunit -c web/core web/modules/custom/mersch_rh` (avec `drupal/core-dev` installé).

## Règles du dépôt

- Aucune donnée personnelle dans le dépôt : ni annuaire, ni export de base, ni fichier déposé. Le `.gitignore` bloque déjà `*.sql`, `*.csv`, `*.xlsx` et `web/sites/*/files/`.
- Aucun secret : mots de passe, clé Graph et secret client Entra ID restent dans `settings.local.php` ou des variables d'environnement sur le serveur.
- La configuration Drupal passe par `config/sync/` (`drush cex` puis commit).
