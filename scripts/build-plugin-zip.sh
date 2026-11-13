#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
rm -f fusion-plugins.zip
zip -qr fusion-plugins.zip fusion-plugins -x '*/.DS_Store'
unzip -tq fusion-plugins.zip
echo 'Built fusion-plugins.zip'
