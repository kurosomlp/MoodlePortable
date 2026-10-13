#!/usr/bin/env bash
# Inicia el servidor portable de Moodle (Linux/macOS).
set -e
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
export PHPRC="$DIR/runtime/linux/php"
cd "$DIR"
exec runtime/linux/php/php server/start.php
