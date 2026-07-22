#!/usr/bin/env sh
set -eu

XBOARD_REPO="https://github.com/code6379/Xboard.git"
XBOARD_BRANCH="master"
COMPOSE_FILE="compose.custom.yaml"

configure_compose() {
    if docker compose version >/dev/null 2>&1; then
        compose() { docker compose -f compose.yaml "$@"; }
    elif command -v docker-compose >/dev/null 2>&1; then
        compose() { docker-compose -f compose.yaml "$@"; }
    else
        echo "Docker Compose is not installed." >&2
        exit 1
    fi
}

prepare_source() {
    if [ -d www/.git ]; then
        echo "Using existing source directory: ./www"
    elif [ -e www ]; then
        echo "./www exists but is not an Xboard Git repository." >&2
        exit 1
    else
        echo "Cloning Xboard source..."
        git clone --depth 1 --branch "$XBOARD_BRANCH" --recurse-submodules "$XBOARD_REPO" www
    fi
}

prepare_compose_file() {
    echo "Using Compose template: www/$COMPOSE_FILE"
    cp "www/$COMPOSE_FILE" compose.yaml
}

prepare_data_directories() {
    mkdir -p data/redis data/database data/logs data/theme data/plugins data/tinyrdm
}

install_dependencies() {
    echo "Installing Composer dependencies..."
    compose run --rm --entrypoint sh xboard -c 'cd /www && composer install --no-dev --optimize-autoloader'
}

run_installer() {
    echo "Running Xboard installer..."
    compose run -it --rm xboard php artisan xboard:install
}

main() {
    configure_compose
    prepare_source
    prepare_compose_file
    prepare_data_directories
    install_dependencies
    run_installer
}

main "$@"
