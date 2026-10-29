#!/bin/bash
# Draait PHP: lokale php als die bestaat, anders PHP 8 in WebAssembly via npx (@php-wasm/cli, alleen in npm-cache).
if command -v php >/dev/null 2>&1; then exec php "$@"; fi
exec npx -y @php-wasm/cli@latest "$@" 2> >(grep -v 'npm warn' >&2)
