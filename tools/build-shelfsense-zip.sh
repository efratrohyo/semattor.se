#!/usr/bin/env bash
# Builds dist/Semattor_ShelfSense_v<version>_<date>.zip (version from composer.json).
# The zip is unpacked in the Magento root: it contains app/code/Semattor/ShelfSense.
set -euo pipefail
cd "$(dirname "$0")/.."
MOD=magento/app/code/Semattor/ShelfSense
VERSION=$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["version"];' "$MOD/composer.json")
NAME="Semattor_ShelfSense_v${VERSION}_$(date +%F).zip"
STAGE=$(mktemp -d)
mkdir -p dist
cp -r magento/app "$STAGE/"
rm -f "dist/$NAME"
(cd "$STAGE" && zip -qr - app) > "dist/$NAME"
rm -rf "$STAGE"
echo "dist/$NAME"
