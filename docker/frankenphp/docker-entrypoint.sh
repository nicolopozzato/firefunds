#!/bin/sh
# Prepares the Symfony app, then runs the container's command (FrankenPHP, or
# the scheduler's messenger:consume).
#
#   RUN_MIGRATIONS=1   apply pending Doctrine migrations before starting
#                      (only the web container sets it, so they run once)
#   APP_USER=app       drop from root to this user first (dev: the host
#                      user's twin; prod: the image's unprivileged user)
set -e

cd /app/backend

if [ -n "$APP_USER" ] && [ "$(id -u)" = 0 ]; then
	# Prod: the volumes may come from an older image that ran as root.
	if [ "$APP_ENV" = 'prod' ]; then
		mkdir -p config/jwt var/share var/backups
		chown -R "$APP_USER:$APP_USER" config/jwt var/share var/backups
	fi
	exec setpriv --reuid="$APP_USER" --regid="$APP_USER" --init-groups \
		env HOME="$(getent passwd "$APP_USER" | cut -d: -f6)" "$0" "$@"
fi

# Only for the app's own commands, not for `docker compose exec ... sh`.
if [ "$1" = 'frankenphp' ] || [ "$1" = 'php' ] || [ "$1" = 'bin/console' ]; then

	# Dev: the code is bind-mounted, vendor/ may not be there yet.
	if [ "$APP_ENV" != 'prod' ] && [ ! -f vendor/autoload_runtime.php ]; then
		composer install --prefer-dist --no-progress --no-interaction
	fi

	# Prod: never with the placeholders from backend/.env.
	if [ "$APP_ENV" = 'prod' ]; then
		if [ -z "$APP_SECRET" ] || [ "$JWT_PASSPHRASE" = 'dev-only-passphrase' ] || [ -z "$JWT_PASSPHRASE" ]; then
			echo 'APP_SECRET and JWT_PASSPHRASE must be set (see the README): refusing to start.'
			exit 1
		fi
	fi

	# JWT keypair: kept on a volume (prod) or in config/jwt (dev, git-ignored).
	# Only the web container makes it: the scheduler starting at the same
	# moment could otherwise write half of a second pair.
	if [ "$1" = 'frankenphp' ] && [ ! -f config/jwt/private.pem ]; then
		mkdir -p config/jwt
		php bin/console lexik:jwt:generate-keypair --skip-if-exists --no-interaction
	fi

	if grep -q ^DATABASE_URL= .env 2>/dev/null || [ -n "$DATABASE_URL" ]; then
		echo 'Waiting for the database...'
		ATTEMPTS=60
		until [ $ATTEMPTS -eq 0 ] || DATABASE_ERROR=$(php bin/console dbal:run-sql -q 'SELECT 1' 2>&1); do
			ATTEMPTS=$((ATTEMPTS - 1))
			sleep 1
		done
		if [ $ATTEMPTS -eq 0 ]; then
			echo 'The database is not reachable:'
			echo "$DATABASE_ERROR"
			exit 1
		fi

		if [ "$RUN_MIGRATIONS" = '1' ]; then
			php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --all-or-nothing
		fi
	fi
fi

exec docker-php-entrypoint "$@"
