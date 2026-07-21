#!/usr/bin/env sh
set -eu

echo "Installing Composer dependencies..."
docker-compose -f compose.custom.yaml run --rm --entrypoint sh xboard -c 'cd /www && composer install --no-dev --optimize-autoloader'

echo "Running Xboard installer..."
docker-compose -f compose.custom.yaml run -it --rm xboard php artisan xboard:install

echo "Starting Xboard..."
docker-compose -f compose.custom.yaml up -d
