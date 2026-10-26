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
web/modules/custom/       Modules propres au projet (mersch_annuaire)
```

## Démarrer en local

Prérequis : [DDEV](https://ddev.readthedocs.io/) et Docker.

```bash
ddev start
ddev composer install
ddev drush site:install minimal --locale=lb --site-name="Intranet Mersch"
ddev drush theme:enable mersch
ddev drush config:set system.theme default mersch -y
ddev launch
```

Le premier `composer install` crée `composer.lock` : il faut le committer.

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

## Règles du dépôt

- Aucune donnée personnelle dans le dépôt : ni annuaire, ni export de base, ni fichier déposé. Le `.gitignore` bloque déjà `*.sql`, `*.csv`, `*.xlsx` et `web/sites/*/files/`.
- Aucun secret : mots de passe, clé Graph et secret client Entra ID restent dans `settings.local.php` ou des variables d'environnement sur le serveur.
- La configuration Drupal passe par `config/sync/` (`drush cex` puis commit).
