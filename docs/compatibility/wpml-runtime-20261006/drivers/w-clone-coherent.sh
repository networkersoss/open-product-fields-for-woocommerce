#!/usr/bin/env bash
# wp-cli inside the disposable clone only (container opf-wpml-coherent-web).
exec sudo docker exec opf-wpml-coherent-web php /tools/wp-cli.phar --path=/var/www/html --allow-root "$@"
