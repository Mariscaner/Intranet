# Feuille de route technique

Correspondance entre les user stories du MVP (cahier des charges 01 v3) et le travail dans Drupal.

| Étape | User stories | Travail |
|---|---|---|
| 1. Socle | US-01, US-03 | Installation Drupal 11, thème `mersch`, menu Accueil / RH / Communication / Guides IT, HTTPS sur le serveur interne |
| 2. Langues | US-02, US-05 | Langues LU (défaut), DE, FR ; sélecteur en haut à droite ; repli sur le LU avec la mention « traduction non disponible » |
| 3. Rôles et SSO | US-01, US-04 | Rôles Lecteur (anonyme), Rédacteur RH, Rédacteur Communication, Administrateur ; OpenID Connect vers Entra ID ; un rédacteur dépublie mais ne supprime pas (D-019) |
| 4. Annuaire | US-06 à US-09 | Module `mersch_annuaire` : synchro Graph hebdomadaire (User.Read.All, lecture seule), comptes actifs avec prénom, nom et service, liste d'exclusion ; recherche insensible aux accents ; liens `tel:` et `mailto:` |
| 5. Accueil | US-10, US-11 | Liens rapides administrables (libellé LU/DE/FR, URL, icône, ordre) |
| 6. RH | US-12 à US-14 | Organigramme remplacé chaque semaine ; nouvelles (gabarit D-023, case « accord écrit ») ; communications RH avec PDF |
| 7. Communication | US-15 à US-18 | Liens Nik, agenda et albums sur mersch.lu ; nouvelles |
| 8. Guides IT | US-19 à US-21 | Type de contenu Guide, catégories, recherche ; reprise des 6 guides existants |
| 9. Exploitation | — | Historique des révisions 12 mois (D-021), sauvegarde quotidienne, journaux limités |

## Points ouverts

- Format du fichier organigramme (PDF ou image).
- Flux RSS ou iCal de l'agenda mersch.lu.
- Licence webfont de Larken et Fabrikat Kompakt (substitution Georgia / Arial en attendant).
- Champ « GSM » de l'annuaire : non prévu par D-009.
