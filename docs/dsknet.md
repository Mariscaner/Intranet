# Données DSKNet de l'agent (lot 4)

Mis à jour : 2026-11-17 · Décisions : D-038, D-042 · Version DSK-API : 1.6.138.43 (doc OpenAPI : lots/lot-4-dsknet/dsk-api-1.6-openapi.json)

L'agent connecté ouvre **Mäin DSKNet** (menu principal, adresse `/dsknet`) et voit ses propres données, en lecture seule :

| Bloc | Appel DSK-API 1.6 (après `http://APPS/API/dsknet`) | Ce qui est affiché |
|---|---|---|
| Dernier pointage | `GET /pers/{noPers}/dayBookings?theDate=<aujourd'hui>` | Entrée, Sortie ou Déplacement, et l'heure |
| Solde d'heures | `GET /pers/{noPers}/solde?previousMonth=<aujourd'hui>` | Solde du mois en cours |
| Congé, CET… | `GET /pers/{noPers}/counters?refYear=<année>` | Valeur restante des compteurs que DSKNet montre sur son propre tableau de bord (le CET n'apparaît que si l'agent en a un) |
| Absences accordées | `GET /pers/{noPers}/absences` (aujourd'hui → 12 mois, liste `abs`) et `GET /account/{n}` (libellé) | Périodes et type d'absence |
| Correspondance | `GET /listAll/id` puis `GET /pers/{n}/details` (champ `login`) (une fois par jour, et à la première visite d'un nouvel agent, au plus une fois par heure) | Relie chaque compte SSO à son n° DSK par le champ `login` de DSK. Seule la paire identifiant AD → n° DSK est gardée, le reste de la liste est jeté aussitôt |

Pas de demande d'absence depuis l'intranet : un lien renvoie vers DSKNet.

**RGPD** : données lues en direct à chaque affichage, visibles par l'agent seul (aucun n° d'agent dans l'adresse, aucune page pour voir un collègue, pas même pour un admin). Rien n'est enregistré dans l'intranet, sauf le n° DSK de chaque agent (relié automatiquement ou saisi par un admin). Seuls les libellés des types d'absence, communs à tous, sont gardés un jour en cache. Le journal ne contient ni jeton, ni donnée, ni n° d'agent.

**Session** : le jeton DSK est une session qui expire. Le cron la prolonge chaque jour (`GET /revalidateToken`) et le rapport d'état affiche sa date de fin. S'il a expiré (erreur « jeton refusé ou expiré »), en créer un nouveau dans DSK et remplacer la ligne dans le fichier.

**Jeton** : il reste dans `/etc/intranet/dsknet.env` (ligne `DSKNET_TOKEN=…`, droits `640 root:www-data`). L'intranet le relit à chaque appel et ne l'affiche jamais. Ne jamais le copier dans GitHub, un e-mail ou une conversation avec Claude.

## 1. Installer le module

Depuis PowerShell :

```powershell
ssh BEMA@intranet
```

Puis sur le serveur, une commande à la fois :

```bash
sudo -i
cd /var/www/intranet
git pull
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --no-interaction --optimize-autoloader
chown -R root:www-data . && chmod -R g+rX,g-w,o-rwx . && chown -R www-data:www-data web/sites/default/files
drush-intranet pm:install mersch_dsknet --yes
drush-intranet locale:check && drush-intranet locale:update
drush-intranet cache:rebuild
```

## 2. Vérifier l'accès à DSKNet

Toujours en root sur le serveur, avec **votre propre** identifiant Windows (exemple : `bema`) :

```bash
drush-intranet mersch:dsknet-verifier bema
```

| Ligne affichée | Signification | Que faire |
|---|---|---|
| `OK /version` et `OK /test` | Adresse et jeton bons | Rien |
| `ERREUR /test : jeton illisible` | `www-data` ne lit pas le fichier | `ls -l /etc/intranet/dsknet.env` doit montrer `-rw-r----- root www-data` |
| `ERREUR … injoignable` | `APPS` non résolu ou serveur DSK arrêté | `getent hosts APPS` doit répondre (ligne dans `/etc/hosts` en attendant le DNS) |
| `NON /listAll/details … absent` | Votre login DSK n'est pas votre identifiant Windows (ou appel absent de la 1.6) | Relancer avec `--nopers=<votre n° DSK>`, puis voir § 3 |
| `ERREUR dayBookings : appel absent de DSK-API` | Appel absent de la version installée (1.6) | Le bloc affiche « non disponible » ; le signaler à DSK |

Les lignes sous `OK dayBookings`, `OK counters`, etc. montrent les codes bruts : **copiez-les à Claude en masquant les valeurs si vous le souhaitez** (types de pointage, n° et libellés des compteurs, `inDay`, `visibility`). Elles servent à régler l'affichage (§ 4).

## 3. Relier les agents à DSK

DSKNet authentifie ses agents par LDAP sur `domain.local` (clé `samAccountName`) : le **login DSK** de chaque agent est donc son identifiant Windows, celui que le SSO rattache à son compte intranet. L'intranet relie les deux chaque nuit (cron) et à la première visite d'un nouvel agent. Pour relier tout de suite :

```bash
drush-intranet mersch:dsknet-relier
```

La commande donne le nombre de comptes reliés et la liste des identifiants sans login DSK correspondant. Pour l'un d'eux : ouvrir son compte dans l'intranet (Administration › Personnes › Modifier) et saisir **N° DSK de l'agent (noPers)**. Ce champ n'est visible que des admins et l'emporte sur la liaison automatique.

Tant que le SSO n'est pas actif, aucun compte n'a d'identifiant Windows : seul le n° saisi par un admin fonctionne (pratique pour tester avec votre compte).

### Présence dans l'annuaire (D-044)

Les agents connectés voient dans l'annuaire, à côté de chaque nom, **Présent**, **Absent** ou **Déplacement** (sortie de service). Jamais de motif, de date de retour ni d'heure ; les lecteurs non connectés ne voient rien. L'état est lu dans DSKNet (pointages du jour de tous les agents, `/bookings`, un seul appel) au plus une fois par minute, et la page se met à jour seule chaque minute.

Chaque fiche de l'annuaire est reliée à son n° DSK chaque nuit : par l'e-mail du compte intranet relié (ci-dessus), sinon par nom et prénom s'ils sont uniques dans DSK. Pour relier tout de suite et vérifier :

```bash
drush-intranet mersch:dsknet-presence
```

La commande donne le nombre de fiches reliées, la liste des fiches sans n° DSK (pas d'état affiché), la répartition des états du moment, et les codes `type` / `subType` des pointages du jour avec leur nombre (sans n° ni nom). **Copiez-les à Claude** : ils servent à confirmer `types_pointage` (code de la sortie de service).

Le compte du jeton doit avoir le droit DSK `read:bookings`. (`/presAbs` existe dans DSK-API 1.6 mais n'y donne pas le dernier pointage.)

## 4. Réglages (facultatif)

Dans `web/sites/default/settings.php`, à la fin, seulement si une valeur par défaut ne convient pas :

```php
$settings['mersch_dsknet'] = [
  'base' => 'http://APPS/API/dsknet',          // nom d'hôte, jamais l'adresse IP
  'fichier_jeton' => '/etc/intranet/dsknet.env',
  'correspondance' => 'login',                 // ou 'codePers', ou 'manuel' (n° saisi par un admin)
  'absences_mois' => 12,
  // Codes de pointage DSK → état, à confirmer avec le § 2 :
  'types_pointage' => ['E' => 'entree', 'S' => 'sortie', 'D' => 'deplacement'],
  // langID DSK des libellés, à confirmer avec le § 2 :
  'langues' => ['fr' => 1, 'de' => 2, 'lb' => 2],
  // Si DSK 1.6 ne dit pas quels compteurs vont sur son tableau de bord :
  'compteurs' => [1, 2],                       // n° noCounter (congé, CET)
];
```

Puis `drush-intranet cache:rebuild`.

## 5. Dépannage

| Symptôme | Commande |
|---|---|
| Rapport d'état | Administration › Rapports › Rapport d'état, ligne « DSKNet » |
| Journal | `drush-intranet watchdog:show --type=mersch_dsknet` |
| « Votre compte n'est pas relié » | § 3 |
