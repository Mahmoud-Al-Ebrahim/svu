#!/bin/bash
set -e

# Configuration
DEPLOY_DIR="${DEPLOY_PATH:-/var/www/dallal}"

echo "=== Deployment started at $(date) ==="

# Pull latest changes
echo "Pulling latest code..."
cd "$DEPLOY_DIR"
export GIT_SSH_COMMAND="ssh -i ~/.ssh/github_deploy -o IdentitiesOnly=yes -o StrictHostKeyChecking=no"
git restore .
git pull origin main

# Install PHP dependencies.
# Skip --optimize-autoloader: classmap generation OOMs (exit 137) on shared hosting
# without root/swap. PSR-4 autoload is fine for production here.
echo "Installing PHP dependencies..."
export COMPOSER_MEMORY_LIMIT=-1
php -d memory_limit=-1 "$(command -v composer)" install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --no-scripts \
    --no-autoloader

echo "Dumping autoloader (no classmap optimize)..."
php -d memory_limit=-1 "$(command -v composer)" config optimize-autoloader false --no-interaction
php -d memory_limit=-1 "$(command -v composer)" dump-autoload \
    --no-dev \
    --no-interaction
git checkout -- composer.json

# Run package discovery (skipped by --no-scripts above)
php artisan package:discover --ansi

# Run migrations
echo "Running migrations..."
php artisan migrate --force

# Clear and cache configurations
echo "Optimizing application..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# Set permissions
echo "Setting permissions..."
# chown -R www-data:www-data "$DEPLOY_DIR"
chmod -R 755 "$DEPLOY_DIR"
chmod -R 775 "$DEPLOY_DIR/storage"
chmod -R 775 "$DEPLOY_DIR/bootstrap/cache"

WEB_ROOT="${PUBLIC_HTML_PATH:-${HOME}/public_html}"
STORAGE_TARGET="${DEPLOY_DIR}/storage/app/public"
STORAGE_LINK="${WEB_ROOT}/storage"
mkdir -p "$STORAGE_TARGET"

if [ ! -d "$WEB_ROOT" ]; then
    echo "WARNING: web root ${WEB_ROOT} not found; skipped public_html storage link."
else
    PUBLIC_REAL="$(realpath "${DEPLOY_DIR}/public" 2>/dev/null || true)"
    WEB_REAL="$(realpath "$WEB_ROOT" 2>/dev/null || true)"

    if [ -n "$PUBLIC_REAL" ] && [ "$PUBLIC_REAL" = "$WEB_REAL" ]; then
        echo "Web root is Laravel public/; artisan storage:link covers /storage."
    elif [ -e "$STORAGE_LINK" ] && [ ! -L "$STORAGE_LINK" ]; then
        echo "WARNING: ${STORAGE_LINK} exists and is not a symlink; not replacing it."
    else
        ln -sfn "$STORAGE_TARGET" "$STORAGE_LINK"
        echo "Linked ${STORAGE_LINK} -> ${STORAGE_TARGET}"
    fi
fi

# Restart queue workers if supervisor is installed
if command -v supervisorctl &> /dev/null; then
    echo "Restarting queue workers..."
    supervisorctl restart all || echo "Supervisor not configured or failed to restart"
fi

echo "=== Deployment completed successfully! ==="
