#!/usr/bin/env bash
set -e

if [ -f .env.docker ]; then
    cp .env.docker .env
fi

if [ "$DB_CONNECTION" = "mysql" ] && [ -n "$DB_HOST" ]; then
    echo "Waiting for MySQL at $DB_HOST..."
    until php -r "try { new PDO('mysql:host='.\$_SERVER['DB_HOST'].';port='.(\$_SERVER['DB_PORT']??3306), \$_SERVER['DB_USERNAME'], \$_SERVER['DB_PASSWORD']); echo 'ok'; } catch(Exception \$e) { exit(1); }" 2>/dev/null; do
        sleep 1
    done
    echo "MySQL is ready."

    if [ -n "$MYSQL_ROOT_PASSWORD" ]; then
        mysql -h "$DB_HOST" -u root -p"$MYSQL_ROOT_PASSWORD" -e \
            "CREATE DATABASE IF NOT EXISTS \`$DB_DATABASE\`;
             GRANT ALL PRIVILEGES ON \`$DB_DATABASE\`.* TO '$DB_USERNAME'@'%';
             FLUSH PRIVILEGES;" 2>/dev/null || true
    fi
fi

SKIP_MIGRATE=false
for arg in "$@"; do
    case "$arg" in
        queue:work|queue:listen|horizon) SKIP_MIGRATE=true ;;
    esac
done

if [ "$SKIP_MIGRATE" = false ]; then
    php artisan migrate --force
fi

if [ "$1" = "test" ]; then
    shift
    exec php artisan test "$@"
fi

exec "$@"
