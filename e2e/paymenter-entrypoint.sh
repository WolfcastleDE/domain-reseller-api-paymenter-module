#!/bin/ash
# Installs the extension from the read-only mount, then hands over to Paymenter's entrypoint.
set -e
rm -rf /app/extensions/Servers/DomainResellerApi
cp -r /e2e/extension /app/extensions/Servers/DomainResellerApi
cd /app
exec /bin/ash .github/docker/entrypoint.sh "$@"
