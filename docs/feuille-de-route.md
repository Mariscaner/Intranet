# Feuille de route technique

Correspondance entre les user stories du MVP (cahier des charges 01 v3) et le travail dans Drupal.

| Étape | User stories | Travail |
|---|---|---|
| 1. Socle | US-01, US-03 | Installation Drupal 11, thème `mersch`, menu Accueil / RH / Communication / Guides IT, HTTPS sur le serveur interne |
| 2. Langues | US-02, US-05 | Langues LU (défaut), DE, FR et sélecteur en haut à droite (fait avec `mersch_accueil`) ; repli sur le LU avec la mention « traduction non disponible » |
| 3. Rôles et connexion | US-01, US-04 | Rôles Lecteur (anonyme), Rédacteur RH, Rédacteur Communication, Administrateur ; 8 comptes Drupal locaux (D-028, SSO Entra ID = évolution) ; un rédacteur dépublie mais ne supprime pas (D-019) |
| 4. Annuaire | US-06 à US-09 | Module `mersch_annuaire` : import de la liste Excel du personnel par un admin (D-026), vérification avant import, liste d'exclusion ; GSM affiché ; recherche insensible aux accents ; liens `tel:` et `mailto:` |
| 5. Accueil | US-10, US-11 | Module `mersch_accueil` : liens rapides administrables (libellé LU/DE/FR, URL, icône, ordre), recherche vers l'annuaire, accès aux rubriques, menu principal |
| 6. RH | US-12 à US-14 | Module `mersch_rh` : organigramme structuré (services + personnes avec photo, sans grade ni ancienneté) tenu par les rédacteurs RH, recherche et impression ; nouvelles (150 mots au plus, case « accord écrit », « Nei Gesiichter » / « Merci! ») ; communications RH avec PDF ; rôle Rédacteur RH |
| 7. Communication | US-15 à US-18 | Liens Nik, agenda et albums sur mersch.lu ; nouvelles |
| 8. Guides IT | US-19 à US-21 | Type de contenu Guide, catégories, recherche ; reprise des 6 guides existants |
| 9. Exploitation | — | Historique des révisions 12 mois (D-021), sauvegarde quotidienne, journaux limités |

## Points ouverts

- Inscrire dans 05 les choix de Marc du 2026-10-28 : organigramme saisi dans l'intranet (remplace le dépôt de fichier de D-011), photos avec fiche signée, pas de minimum de mots (D-023).
- Relecture par Marc des textes LU de la page RH (`mersch_rh/translations/lb.po`).
- Flux RSS ou iCal de l'agenda mersch.lu.
- Licence webfont de Larken et Fabrikat Kompakt (substitution Georgia / Arial en attendant).
- Confirmer les noms complets SR et ST (D-029).
- Traduction LU/DE/FR des textes de l'interface de l'annuaire (étape 2) : à ajouter dans un `.po` comme pour `mersch_accueil`.
- Relecture par Marc des textes LU de l'accueil (`mersch_accueil/translations/lb.po`).
