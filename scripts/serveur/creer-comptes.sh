#!/usr/bin/env bash
#
# Crée les comptes Drupal locaux des rédacteurs et des admins (04, D-028) et
# affiche pour chacun un lien de connexion à usage unique, valable 24 heures,
# avec lequel la personne choisit elle-même son mot de passe.
#
# Usage, en root : bash scripts/serveur/creer-comptes.sh /root/comptes.txt
#
# Une ligne par compte : identifiant;e-mail professionnel;rôle
# Rôles : administrator, redacteur_rh, redacteur_communication.
# Modèle : scripts/serveur/comptes.exemple. Le fichier rempli contient des
# données personnelles : il reste sur le serveur, jamais dans le dépôt.
# Relancer le script ne recrée pas un compte existant : il remet seulement
# son rôle et affiche un nouveau lien.

set -euo pipefail

FICHIER="${1:-}"
DRUSH=/usr/local/bin/drush-intranet

erreur() { printf '\033[31mERREUR : %s\033[0m\n' "$*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || erreur "lancer le script en root (sudo bash $0 <fichier>)."
[[ -n $FICHIER && -r $FICHIER ]] || erreur "fichier des comptes introuvable : ${FICHIER:-(aucun)}."
[[ -x $DRUSH ]] || erreur "$DRUSH absent : lancer d'abord scripts/serveur/installer.sh."

numero=0
while IFS=';' read -r identifiant mail role || [[ -n ${identifiant:-} ]]; do
  numero=$((numero + 1))
  identifiant="$(printf '%s' "$identifiant" | xargs)"
  mail="$(printf '%s' "${mail:-}" | xargs)"
  role="$(printf '%s' "${role:-}" | tr -d '\r' | xargs)"
  [[ -z $identifiant || $identifiant == \#* ]] && continue
  case "$role" in
    administrator|redacteur_rh|redacteur_communication) ;;
    *) erreur "ligne $numero : rôle inconnu « $role »." ;;
  esac
  [[ $mail == *@*.* ]] || erreur "ligne $numero : e-mail invalide « $mail »."

  echo "• $identifiant ($role)"
  if "$DRUSH" user:information "$identifiant" >/dev/null 2>&1; then
    echo "  existe déjà"
  else
    # Sans mot de passe : le compte ne sert qu'avec le lien ci-dessous.
    "$DRUSH" user:create "$identifiant" --mail="$mail" >/dev/null 2>&1
    echo "  créé"
  fi
  "$DRUSH" user:role:add "$role" "$identifiant" >/dev/null 2>&1
  echo "  lien à remettre à la personne : $("$DRUSH" user:login --name="$identifiant" --no-browser)"
done < "$FICHIER"
