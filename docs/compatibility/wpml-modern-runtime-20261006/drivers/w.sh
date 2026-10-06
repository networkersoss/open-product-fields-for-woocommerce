#!/usr/bin/env bash
# wp-cli inside the disposable clone only (container opf-wpml-stack510-web).
exec sudo docker exec opf-wpml-stack510-web php /tools/wp-cli.phar --path=/var/www/html --allow-root "$@"
