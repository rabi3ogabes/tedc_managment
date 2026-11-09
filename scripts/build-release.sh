#!/usr/bin/env bash
# Builds the folder the Athar Qatar server runs (docs/DEPLOY-ATHAR.md): the Laravel app at the root, with the built
# React web app inside public/ (index.html becomes app.html: Laravel answers every page route with it, see
# backend/routes/web.php). The server's panel runs `composer install` in this folder, so vendor/ is not included.
#
#   bash scripts/build-release.sh [output-folder]      (default: release-out)
set -euo pipefail

OUT="${1:-release-out}"
cd "$(git rev-parse --show-toplevel)"
rm -rf "$OUT"
mkdir -p "$OUT"

# 1. The backend as committed (so nothing git ignores, such as .env or logs, can slip in), without its tests.
git archive HEAD:backend | tar -x -C "$OUT"
rm -rf "$OUT/tests" "$OUT/phpunit.xml"

# 2. The web app, served from the same address as the API.
# (SKIP_NPM_CI=1 reuses an existing web/node_modules, e.g. while a dev server holds it open.)
[ -n "${SKIP_NPM_CI:-}" ] || (cd web && npm ci --no-audit --no-fund)
(cd web && VITE_API_URL=/api/v1 npm run build)
cp -R web/dist/. "$OUT/public/"
mv "$OUT/public/index.html" "$OUT/public/app.html"

# 3. Which commit this release is.
git rev-parse HEAD > "$OUT/RELEASE"
echo "Release of $(git rev-parse --short HEAD) built in $OUT ($(du -sh "$OUT" | cut -f1))"
