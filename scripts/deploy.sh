#!/usr/bin/env bash
#
# Mise à jour de l'API sur le serveur (à lancer en SSH, depuis n'importe où) :
#
#   ./scripts/deploy.sh                 mise à jour complète (git pull + composer + migrations + contrôle)
#   ./scripts/deploy.sh --no-pull       sans git pull (code déjà déposé autrement)
#   ./scripts/deploy.sh --no-composer   sans composer install (vendor/ envoyé tel quel)
#   ./scripts/deploy.sh --skip-backup   sans sauvegarde préalable (déconseillé)
#
# Variables utiles : PHP_BIN (ex. /opt/alt/php83/usr/bin/php), COMPOSER_CMD (ex. "php composer.phar").
#
# Principe : le site passe en maintenance, on sauvegarde, on met à jour, on migre, on contrôle, puis on rouvre.
# En cas d'échec le site RESTE en maintenance : mieux vaut un site fermé qu'un site ouvert à moitié migré.

set -euo pipefail

cd "$(dirname "$0")/.."

PHP="${PHP_BIN:-php}"
COMPOSER="${COMPOSER_CMD:-composer}"
PULL=1; INSTALL=1; BACKUP=1

for arg in "$@"; do
  case "$arg" in
    --no-pull)       PULL=0 ;;
    --no-composer)   INSTALL=0 ;;
    --skip-backup)   BACKUP=0 ;;
    -h|--help)       sed -n '2,13p' "$0"; exit 0 ;;
    *) echo "Option inconnue : $arg" >&2; exit 2 ;;
  esac
done

step() { printf '\n==> %s\n' "$1"; }

PREVIOUS="inconnu"
if command -v git >/dev/null 2>&1 && [ -d .git ]; then
  PREVIOUS="$(git rev-parse --short HEAD)"
fi

on_error() {
  echo
  echo "!! ÉCHEC pendant le déploiement : le site reste en MAINTENANCE." >&2
  echo "   Version avant mise à jour : ${PREVIOUS}" >&2
  echo "   Corrigez le problème puis relancez ce script, ou revenez en arrière (voir DEPLOIEMENT.md, « Retour arrière »)." >&2
  echo "   Pour rouvrir le site sans rien changer : ${PHP} artisan up" >&2
}
trap on_error ERR

step "Maintenance"
"$PHP" artisan down --retry=60 || true

if [ "$BACKUP" -eq 1 ]; then
  step "Sauvegarde avant mise à jour"
  "$PHP" artisan depot:backup
fi

if [ "$PULL" -eq 1 ]; then
  step "Récupération du code (git pull --ff-only)"
  git pull --ff-only
fi

if [ "$INSTALL" -eq 1 ]; then
  step "Dépendances (composer install --no-dev)"
  $COMPOSER install --no-dev --optimize-autoloader --no-interaction
fi

step "Migrations : base centrale puis bases des dépôts"
"$PHP" artisan migrate --force
"$PHP" artisan tenants:migrate --force

step "Caches (config, routes, vues, événements)"
"$PHP" artisan optimize:clear
"$PHP" artisan optimize

step "Contrôle de l'installation"
"$PHP" artisan depot:check --tenants

step "Réouverture du site"
"$PHP" artisan up

NOW="inconnu"
if command -v git >/dev/null 2>&1 && [ -d .git ]; then
  NOW="$(git rev-parse --short HEAD)"
fi
echo
echo "Déploiement terminé : ${PREVIOUS} -> ${NOW}"
