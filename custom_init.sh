#!/usr/bin/env sh
set -eu

XBOARD_REPO="https://github.com/code6379/Xboard.git"
XBOARD_BRANCH="master"
COMPOSE_FILE="compose.custom.yaml"


echo "Cloning Xboard source..."
git clone --depth 1 --branch "$XBOARD_BRANCH" --recurse-submodules "$XBOARD_REPO" www

echo "Using Compose template: www/$COMPOSE_FILE"
cp "www/$COMPOSE_FILE" "$COMPOSE_FILE"

echo "Installing Composer dependencies..."
docker compose run --rm --entrypoint sh xboard -c 'cd /www && composer install --no-dev --optimize-autoloader -vvv'

echo "Running Xboard installer..."
docker compose run -it --rm xboard php artisan xboard:install