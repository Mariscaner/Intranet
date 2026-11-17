# Connexion SSO (Windows / AD)

Mis à jour : 2026-11-12 · Décision : D-035

Guide pas à pas pour activer la connexion des agents par leur session Windows (Kerberos, Active Directory local). Durée : environ 1 heure, une fois l'intégrateur passé. Les commandes se tapent depuis PowerShell, sur le serveur INTRANET :

```powershell
ssh -t BEMA@intranet "commande"
```

Ou en ouvrant une session (`ssh BEMA@intranet`), puis les commandes une à une. `drush-intranet` existe déjà sur le serveur.

## 1. Ce qui change

| Avant | Après |
|---|---|
| Adresse `http://intranet` (nom court) | `intranet.domain.local` (nom complet) : le nom court et l'adresse IP sont redirigés vers lui par Apache |
| Comptes locaux avec mot de passe | Connexion par la session Windows, sans saisie |
| Compte n° 1 « admin » | Reste le compte de secours (`/user/login`) |

Pourquoi le nom complet : le nom de service Kerberos (SPN) `HTTP/intranet.domain.local` ne couvre que lui.

Seul le lien de connexion de l'en-tête (`/sso/connexion`) demande Kerberos. Toutes les autres pages restent lisibles sans connexion, comme aujourd'hui (US-30).

**Secrets** : le keytab `/etc/intranet-mersch/http.keytab` et le fichier `/etc/intranet-mersch/ldap.secret` (liaison « motdepasse », solution de repli) sont déposés sur le serveur par l'intégrateur. Jamais par e-mail, jamais dans GitHub, jamais dans une conversation avec Claude. Le certificat racine `/etc/intranet-mersch/ca-ad.pem` n'est pas secret.

**RGPD (D-035)** : seuls l'identifiant AD, le nom affiché, l'e-mail et la date de dernière connexion sont gardés.

## 2. Vérifier les prérequis

La liste des demandes à l'intégrateur est dans `lots/lot-1-sso/prerequis-integrateur.md` (compte de service, SPN, keytab, DNS, heure, flux 88 et 636, certificat, stratégie des navigateurs). Ne pas continuer tant qu'elle n'est pas livrée. Contrôles sur le serveur :

```powershell
ssh -t BEMA@intranet "host -t SRV _kerberos._tcp.domain.local"
ssh -t BEMA@intranet "timedatectl"
ssh -t BEMA@intranet "sudo klist -kte /etc/intranet-mersch/http.keytab"
```

| Contrôle | Résultat attendu |
|---|---|
| `host` | Liste des contrôleurs de domaine |
| `timedatectl` | « System clock synchronized: yes » (écart maximal de Kerberos : 5 minutes) |
| `klist` | Une ligne `HTTP/intranet.domain.local` en aes256 |

## 3. Compléter les réglages

```bash
sudo nano /etc/intranet-mersch.conf
```

| Réglage | Valeur | Remarque |
|---|---|---|
| `SSO_ACTIF` | `"oui"` | `"non"` pour revenir en arrière |
| `NOM_SERVEUR` | `"intranet.domain.local"` | Nom complet, couvert par le SPN |
| `SSO_REALM` | `"DOMAIN.LOCAL"` | Domaine Kerberos, en majuscules |
| `SSO_LDAP_URI` | `"ldaps://dc1.domain.local"` | Contrôleurs de domaine, séparés par des espaces |
| `SSO_LDAP_BASE` | `"OU=…,DC=domain,DC=local"` | Base de recherche des comptes d'agents (donnée par l'intégrateur) |
| `SSO_LDAP_CA` | `"/etc/intranet-mersch/ca-ad.pem"` | Certificat racine |
| `SSO_LDAP_LIAISON` | `"gssapi"` | `"motdepasse"` en repli (voir section 8) |
| `SSO_LDAP_COMPTE` | `""` | Par exemple `svc-intranet@domain.local`, seulement pour « motdepasse » |
| `SSO_INACTIVITE_MOIS` | `"24"` | Décidé par Marc le 2026-11-12 (US-33) |

Aucun mot de passe dans ce fichier.

## 4. Lancer l'installation

```bash
cd /var/www/intranet
git pull
sudo bash scripts/serveur/installer.sh
```

Le script est relançable sans risque. Il refuse de continuer, avec un message en français, si le keytab ou le certificat manque. Ce qu'il ajoute :

| Élément | Détail |
|---|---|
| Paquets | `libapache2-mod-auth-gssapi`, `krb5-user`, `php-ldap` |
| `settings.sso.php` | Réglages SSO sans secret, lus par Drupal |
| `/etc/krb5.conf` | Écrit seulement s'il n'existe pas ou n'a pas de `default_realm` |
| Vhost Apache | `<Location /sso/connexion>` seulement, plus la redirection du nom court et de l'IP vers le nom complet |

## 5. Contrôles

### 5.1 Lecture LDAP depuis le serveur

Paquet nécessaire : `ldap-utils`.

```bash
sudo apt-get install -y ldap-utils
sudo -u www-data env KRB5_CLIENT_KTNAME=/etc/intranet-mersch/http.keytab KRB5CCNAME=MEMORY: LDAPTLS_CACERT=/etc/intranet-mersch/ca-ad.pem \
  ldapsearch -Y GSSAPI -O maxssf=0 -H ldaps://dc1.domain.local \
  -b "DC=domain,DC=local" "(sAMAccountName=xxxx)" sAMAccountName displayName mail
```

Remplacer `xxxx` par l'identifiant d'un agent et la base par `SSO_LDAP_BASE`. Résultat attendu : une entrée avec les trois champs.

### 5.2 Rapport d'état Drupal

Ouvrir `/admin/reports/status` : la ligne « Windows login (SSO) » doit afficher « Active (realm DOMAIN.LOCAL) ». Une ligne rouge dit ce qui manque (extension LDAP, keytab illisible).

### 5.3 Depuis un PC du domaine

1. Ouvrir Edge.
2. Aller sur `http://intranet.domain.local` (ou `https://` si le certificat est en place).
3. Cliquer « Aloggen » dans l'en-tête.
4. Résultat attendu : connexion sans saisie, le nom de l'agent s'affiche en haut.

Test fait en développement : un Kerberos local avec Apache et mod_auth_gssapi a validé la connexion de bout en bout (l'identifiant arrive bien à PHP-FPM). La lecture LDAP réelle et le navigateur Windows restent à tester sur la VM : ce sont les points 5.1 et 5.3.

## 6. Comptes existants

Les comptes existants sont rattachés automatiquement à la première connexion, par e-mail identique à celui de l'AD. Rôles et services sont conservés.

**Avant la première connexion**, vérifier que l'e-mail des 8 comptes actuels (2 admins, 2 rédacteurs RH, 4 rédacteurs Communication) est identique à celui de l'AD. Sinon, un doublon est créé.

```bash
ssh -t BEMA@intranet "drush-intranet user:information --mail=prenom.nom@mersch.lu"
```

Après le rattachement, l'identifiant et le mot de passe sont refusés, sauf pour le compte n° 1 (« admin »), compte de secours :

| Besoin | Moyen |
|---|---|
| Formulaire | `/user/login` |
| Lien à usage unique | `drush-intranet user:login --name=admin --no-browser` |

## 7. Comptes inactifs

Le cron bloque un compte sans connexion après `SSO_INACTIVITE_MOIS` mois (24, décidé par Marc le 2026-11-12). Exception : les comptes qui ont un rôle (rédacteur, admin). Ils ne sont pas bloqués ; ils sont listés dans le rapport d'état (« Inactive editor or administrator accounts »). Marc les bloque ou retire leur rôle à la main.

## 8. Dépannage

| Symptôme | Cause probable | Vérification |
|---|---|---|
| « Session Windows non reconnue » | Navigateur non configuré : `intranet.domain.local` absent de la stratégie AuthServerAllowlist ou de la zone Intranet local | Demander à l'intégrateur la stratégie de groupe (demande n° 9) |
| Idem | PC hors domaine, ou session ouverte avec un compte local | Tester sur un PC du domaine, avec son compte |
| Idem | Nom court ou IP tapés | Taper `intranet.domain.local` ; la redirection doit se faire seule |
| Idem | SPN en double | L'intégrateur lance `setspn -Q HTTP/intranet.domain.local` : un seul résultat |
| Idem | Heure décalée de plus de 5 minutes | `timedatectl` sur le serveur, et l'heure du PC |
| Idem, après un changement du mot de passe du compte de service | Keytab ancien (kvno périmé) | `sudo klist -kte /etc/intranet-mersch/http.keytab` ; redemander un keytab |
| Fenêtre de mot de passe du navigateur | Stratégie navigateur absente | Même stratégie que ci-dessus |
| « L'annuaire ne répond pas » | LDAPS 636 bloqué entre la VM et le contrôleur | `timeout 5 bash -c '</dev/tcp/dc1.domain.local/636' && echo ouvert` |
| Idem | Certificat racine absent ou faux | `openssl s_client -connect dc1.domain.local:636 -CAfile /etc/intranet-mersch/ca-ad.pem </dev/null` : « Verify return code: 0 » |
| Idem | Liaison GSSAPI refusée | Test 5.1 ; sinon essayer `SSO_LDAP_LIAISON="motdepasse"` avec `SSO_LDAP_COMPTE` et `ldap.secret` déposé par l'intégrateur, puis relancer `installer.sh` |
| « Compte bloqué » | Compte bloqué à la main ou par inactivité | `/admin/people` : réactiver le compte |
| « Votre compte Windows ne peut pas être utilisé » | Realm différent de `SSO_REALM`, ou compte hors de `SSO_LDAP_BASE` | Comparer `SSO_REALM` et `SSO_LDAP_BASE` avec les données de l'intégrateur |

Journaux :

```bash
sudo tail /var/log/apache2/intranet-mersch-error.log
ssh -t BEMA@intranet "drush-intranet watchdog:show --type=mersch_sso"
```

## 9. Sécurité : limites connues

| Point | Ce qu'il faut savoir | Que faire |
|---|---|---|
| Liaison LDAP par keytab (GSSAPI) | Elle ne marche que si l'UPN du compte de service est le SPN `HTTP/intranet.domain.local`. C'est le comportement par défaut de `ktpass -mapuser`. **Non testé sur un AD réel.** | Si le test 5.1 échoue, passer en `SSO_LDAP_LIAISON="motdepasse"`, avec `ldap.secret` déposé par l'intégrateur. |
| Keytab lisible par PHP | Apache et PHP tournent sous `www-data`, qui doit lire le keytab. Une exécution de code dans Drupal permettrait de forger des tickets pour l'intranet (risque accepté). | Mettre Drupal à jour régulièrement et passer en HTTPS avant la mise en service. |
| Rattachement par e-mail | Il fait confiance à l'attribut `mail` de l'AD. Une personne qui peut modifier cet attribut dans l'AD peut reprendre un compte local pas encore rattaché, y compris un compte admin. | Le jour de la mise en service, faire connecter tout de suite les 8 comptes existants. Vérifier ensuite le journal : `drush-intranet watchdog:show --type=mersch_sso`, messages « rattaché ». |
| Comptes AD désactivés | Ils sont refusés à la connexion, même avec un ticket Kerberos encore valable. | Rien à faire. |
| Adresses LDAP | Seules les adresses `ldaps://` sont acceptées. `installer.sh` et le module refusent `ldap://`. | Rien à faire. |

## 10. Revenir en arrière

```bash
sudo nano /etc/intranet-mersch.conf     # SSO_ACTIF="non"
cd /var/www/intranet && sudo bash scripts/serveur/installer.sh
```

Le vhost redevient sans Kerberos et sans redirection. Les comptes rattachés restent ; pour se connecter par mot de passe, utiliser un lien à usage unique (`drush-intranet user:login --name=<identifiant> --no-browser`). `NOM_SERVEUR` peut rester le nom complet.
