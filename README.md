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
docs/                     Feuille de route technique, guide d'installation sur le serveur de test
scripts/serveur/          Scripts d'installation du serveur Linux et de création des comptes
web/themes/custom/mersch/ Thème de l'intranet
web/modules/custom/       Modules propres au projet (mersch_accueil, mersch_annuaire, mersch_rh, mersch_communication, mersch_guides)
```

## Installer sur le serveur de test

Guide pas à pas : [docs/installation-serveur-test.md](docs/installation-serveur-test.md). Sur Debian 13 ou Ubuntu 24.04, en root :

```bash
git clone https://github.com/Mariscaner/Intranet.git /var/www/intranet
cp /var/www/intranet/scripts/serveur/intranet.conf.exemple /etc/intranet-mersch.conf   # à adapter
bash /var/www/intranet/scripts/serveur/installer.sh
```

Le script installe Apache, PHP-FPM, MariaDB et Drupal, active le thème et les modules `mersch_*`, limite l'accès au réseau interne et règle le cron. Les imports (annuaire, organigramme, guides) et les 8 comptes (`scripts/serveur/creer-comptes.sh`) suivent, avec des fichiers qui restent hors du dépôt.

## Démarrer en local

Prérequis : [DDEV](https://ddev.readthedocs.io/) et Docker.

```bash
ddev start
ddev composer install
ddev drush site:install minimal --locale=lb --site-name="Intranet Mersch"
ddev drush theme:enable mersch
ddev drush config:set system.theme default mersch -y
ddev drush pm:install mersch_accueil mersch_annuaire mersch_rh mersch_communication mersch_guides -y
ddev drush theme:enable claro
ddev drush config:set system.theme admin claro -y
ddev launch
```

Le premier `composer install` crée `composer.lock` : il faut le committer.

## Page d'accueil

Le module `mersch_accueil` fournit la page d'accueil (`/accueil`, déclarée page d'accueil du site à l'installation), d'après la maquette validée :
- liens rapides DSKNet, GESCOM, Helpdesk et mersch.lu, ouverts dans un nouvel onglet (US-10) ;
- recherche dans l'annuaire, qui ouvre `/annuaire?q=…` avec le champ déjà rempli, et lien vers l'annuaire complet (affichés seulement si `mersch_annuaire` est installé) ;
- « Dernières nouvelles » : les 3 nouvelles les plus récentes des rubriques RH et Communication, avec leur date et leur rubrique (chaque module de rubrique les fournit par `hook_mersch_accueil_nouvelles()`, voir `mersch_accueil.api.php`) ;
- accès aux rubriques RH, Communication et Guides IT, aussi présentes dans le menu principal (US-03) ; `/rh`, `/communication` et `/guides-it` affichent une page d'attente tant que le module de la rubrique n'est pas installé ;
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

- **Organigramme** (US-12), page `/rh/organigramme` : les services sont les termes du vocabulaire « Organisation chart departments » (`rh_service` : nom, service parent, ordre), les personnes et postes vacants sont des contenus « Organisation chart entry » (`rh_poste` : nom, fonction, service, ordre, photo). Ni grade, ni ancienneté, ni temps de travail. Les rédacteurs RH les tiennent à jour depuis l'administration (boutons « Add a person » et « Manage departments » sur la page). Mise en page : une section par bandeau or (Collège, Service administration, Service technique, Autres services), la direction en tête, les services en cartes, les équipes imbriquées ; recherche par nom, fonction ou service sans accents ; lien de chaque nom vers l'annuaire ; impression (une section par page). Sans photo, les initiales s'affichent.
- **Premier chargement de l'organigramme** : `ddev drush mersch:organigramme-import <dossier>` lit `organigramme.json` et `photos/` extraits du PDF des RH (`--remplacer` pour tout recharger). Ce dossier contient des données personnelles : il reste hors du dépôt (dossier du projet `organigramme/`).
- **Nouvelles RH** (US-13, D-023), type de contenu `rh_nouvelle` : catégorie (nouveau visage, départ à la retraite, autre), titre (le nom de la personne), service, texte, photo facultative, date de fin de publication facultative. La case « accord écrit obtenu » est obligatoire pour un nouveau visage, une retraite ou une photo ; plus de 150 mots est refusé, sans minimum. Sur la page, les nouvelles sont groupées sous « Nei Gesiichter » et « Merci! », plus récentes d'abord ; sans photo, les initiales s'affichent.
- **Communications RH** (US-14), type de contenu `rh_communication` : titre, court texte, PDF obligatoire.

Droits du rôle : créer, modifier, traduire (DE/FR) et dépublier les contenus RH (case « Publié »), voir leurs révisions ; créer et modifier les services de l'organigramme ; supprimer une personne de l'organigramme (départ : ses données ne sont pas gardées) ; pas d'autre suppression (D-019), pas d'accès aux contenus Communication (D-007).

À régler sur le serveur :
- taille maximale d'envoi PHP (`upload_max_filesize` et `post_max_size`) d'au moins 20 Mo pour les PDF des communications RH ;
- le cron Drupal une fois par jour au moins : il retire de la page les nouvelles dont la date de fin est passée.

Photos et noms des nouvelles et de l'organigramme : données personnelles (photos avec l'accord signé des personnes). Rien de réel n'est dans le dépôt ; les fichiers déposés vont dans `web/sites/default/files/rh/`, ignoré par Git.

Tests : `ddev exec SIMPLETEST_DB=sqlite://localhost/:memory: vendor/bin/phpunit -c web/core web/modules/custom/mersch_rh` (avec `drupal/core-dev` installé).

## Page Communication

Le module `mersch_communication` remplace la page d'attente `/communication` par la page Communication, d'après la maquette validée :

- **Trois tuiles vers mersch.lu** (US-15 à US-17, D-012), ouvertes dans un nouvel onglet : « Nik » (rubrique Nik de la médiathèque), « Prochains événements » (agenda) et « Albums photos » (médiathèque). Rien n'est copié dans l'intranet. Un admin peut corriger ces adresses depuis **Administration > Configuration > Communication page links** (`/admin/config/mersch/communication`) ; une adresse sans `https://` ou `http://` est refusée.
- **Agenda** : mersch.lu ne publie ni flux RSS ni iCal (vérifié le 2026-10-28 sur la page agenda et une fiche événement) ; la tuile renvoie donc vers l'agenda, l'affichage automatique des 5 prochains événements reste une évolution (US-16).
- **Nouvelles** (US-18, D-031), type de contenu `comm_nouvelle` : titre, texte de 150 mots au plus (sans minimum), photo facultative, case « accord écrit obtenu », date de fin de publication facultative. Une photo sans la case cochée est refusée ; pour une nouvelle qui nomme une personne sans photo, le rédacteur coche la case lui-même (aide du champ). Plus récentes d'abord, les échues disparaissent le lendemain de leur date de fin.

Le rôle « Communication editor » (`redacteur_communication`) est à attribuer à Aviva Rübel, Christian Mohr, Jill Manternach et John Manternach d'après 04. Droits : créer, modifier, traduire (DE/FR) et dépublier les nouvelles Communication, voir leurs révisions ; pas de suppression (D-019), pas d'accès aux contenus RH ni aux liens de la page (D-007).

Le format de texte « Simple text » (`mersch_simple`), commun aux nouvelles RH et Communication, est désormais fourni par `mersch_accueil` (rien ne change sur un site où il existe déjà).

À régler sur le serveur : le cron Drupal une fois par jour au moins (retire les nouvelles échues).

Photos des nouvelles : données personnelles (accord écrit des personnes). Les fichiers déposés vont dans `web/sites/default/files/communication/`, ignoré par Git.

Tests : `ddev exec SIMPLETEST_DB=sqlite://localhost/:memory: vendor/bin/phpunit -c web/core web/modules/custom/mersch_communication` (avec `drupal/core-dev` installé).

## Page Guides IT

Le module `mersch_guides` remplace la page d'attente `/guides-it` par la page Guides IT, d'après la maquette validée :

- **Recherche** (US-19) : boutons de catégorie (Helpdesk, Applications, Messagerie et agenda, Sécurité et comptes, Matériel et logiciels ; seules les catégories qui contiennent un guide s'affichent) et champ de recherche dès 2 caractères, sans accents ni majuscules. La recherche porte sur le titre, le résumé et les mots-clés **dans les trois langues** et sur le nom de la catégorie : « passwuert » trouve le guide même en français. Lien direct possible : `/guides-it?q=dsknet`.
- **Cartes** : catégorie, titre, résumé, lien vers le PDF (ou vers le guide s'il n'a qu'un texte), lien Helpdesk, date de dernière mise à jour ; bandeau « Pas de guide adapté ? Ouvrez un ticket au Helpdesk » en bas de page.
- **Page d'un guide** : catégorie, résumé, texte, captures d'écran, bouton « Ouvrir le guide (PDF) », date de dernière mise à jour (US-21), lien Helpdesk, retour à la liste.
- **Adresse du helpdesk** : celle du lien rapide « Helpdesk » de l'accueil (`/admin/config/mersch/liens-rapides`), pour ne pas la saisir deux fois ; `https://helpdesk/` si ce lien est supprimé.

Type de contenu « IT guide » (`guide_it`) : titre, catégorie, résumé (200 caractères au plus), texte, captures d'écran, PDF, mots-clés. Un guide sans texte ni PDF est refusé (US-21). Titre, résumé, texte et mots-clés se traduisent en DE et FR ; le PDF et les captures sont communs aux trois langues.

Droits : **administrateurs seulement** (D-007) ; aucun rôle de rédacteur n'a de droit sur les guides. Le bouton « Add a guide » et les liens « Edit » n'apparaissent qu'aux admins.

**Reprise des 6 guides existants** (US-20, D-014) : `ddev drush mersch:guides-import <dossier>` lit `guides.json` (catégorie, titre, résumé et mots-clés en LU, DE et FR, nom du PDF) et publie les PDF (`--remplacer` pour tout recharger). Le dossier prêt à l'emploi est dans le dossier du projet, `guides-it/intranet/`, hors du dépôt. Ses PDF sont des copies corrigées des originaux : `https://apps` au lieu de `http://apps` dans le guide DSKnet, et noms des collègues masqués dans les captures (helpdesk : utilisateur connecté ; réservation : participants de l'Assistant Planification).

À régler sur le serveur : taille maximale d'envoi PHP d'au moins 20 Mo (PDF des guides, comme pour la page RH).

Tests : `ddev exec SIMPLETEST_DB=sqlite://localhost/:memory: vendor/bin/phpunit -c web/core web/modules/custom/mersch_guides` (avec `drupal/core-dev` installé).

## Règles du dépôt

- Aucune donnée personnelle dans le dépôt : ni annuaire, ni export de base, ni fichier déposé. Le `.gitignore` bloque déjà `*.sql`, `*.csv`, `*.xlsx` et `web/sites/*/files/`.
- Aucun secret : mots de passe, clé Graph et secret client Entra ID restent dans `settings.local.php` ou des variables d'environnement sur le serveur.
- La configuration Drupal passe par `config/sync/` (`drush cex` puis commit).
