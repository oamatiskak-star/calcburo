#!/bin/bash
# End-to-end lokaal: PHP ingebouwde server + curl. Sessiecookie, CSRF, multipart-upload, opslag,
# idempotentie en foutroutes. Mail staat UIT; er wordt niets naar buiten verstuurd.
#   tools/e2e-lokaal.sh
set -u
cd "$(dirname "$0")/.."
command -v php >/dev/null || { echo "Lokale php ontbreekt: e2e overgeslagen (gebruik tests/selftest.php via tools/php.sh)"; exit 2; }
TMP=$(mktemp -d "$PWD/tests/.tmp-e2e-XXXX")
POORT=8765
SLEUTEL=$(php -r 'echo bin2hex(random_bytes(16));')
cat > "$TMP/config.php" <<PHP
<?php return ['modus' => 'test', 'test_sleutel' => '$SLEUTEL', 'data_dir' => '$TMP/data', 'mail' => ['modus' => 'uit'], 'opslag_max_bytes' => 64 * 1024 * 1024];
PHP
CALCBURO_CONFIG="$TMP/config.php" php -d upload_max_filesize=20M -d post_max_size=52M -d max_file_uploads=20 \
  -S 127.0.0.1:$POORT -t httpdocs >"$TMP/server.log" 2>&1 &
PID=$!
trap 'kill $PID 2>/dev/null; wait $PID 2>/dev/null; node -e "require(\"fs\").rmSync(process.argv[1],{recursive:true,force:true})" "$TMP"' EXIT
for _ in $(seq 1 50); do curl -s -o /dev/null "http://127.0.0.1:$POORT/robots.txt" && break; sleep 0.1; done

FOUT=0
check() { if [ "$2" = "$3" ]; then echo "  ok   $1"; else echo "  FOUT $1: verwacht '$2', kreeg '$3'"; FOUT=1; fi; }
B="http://127.0.0.1:$POORT"
JAR="$TMP/cookies"

HTML=$(curl -s -c "$JAR" "$B/aanvraag.php?test=$SLEUTEL")
CSRF=$(printf '%s' "$HTML" | sed -n 's/.*name="csrf" value="\([0-9a-f]*\)".*/\1/p')
ID=$(printf '%s' "$HTML" | sed -n 's/.*name="aanvraag_id" value="\([0-9a-f-]*\)".*/\1/p')
check "formulier met CSRF-token (64 hex)" 64 "${#CSRF}"
check "sessiecookie __Host-cbsessie gezet" 1 "$(grep -c '__Host-cbsessie' "$JAR")"
HDR=$(curl -s -D - -o /dev/null "$B/aanvraag.php?test=$SLEUTEL")
check "CSP-header met form-action self" 1 "$(printf '%s' "$HDR" | grep -c "form-action 'self'")"
check "Referrer-Policy same-origin" 1 "$(printf '%s' "$HDR" | grep -ci 'referrer-policy: same-origin')"

printf '%%PDF-1.4\n%%EOF\n' > "$TMP/tekening.pdf"
printf 'MZ uitvoerbaar' > "$TMP/nep.pdf"
post() { curl -s -b "$JAR" -H 'Accept: application/json' -H "Origin: $1" -o "$TMP/out.json" -w '%{http_code}' "$B/api/aanvraag.php" \
  -F "csrf=$2" -F "aanvraag_id=$3" -F "test_sleutel=$SLEUTEL" -F 'naam=E2E Test' -F 'email=e2e@voorbeeld.nl' \
  -F 'project=Rooktest' -F 'website=' "${@:4}"; }

check "vreemde Origin geweigerd" 403 "$(post https://evil.example "$CSRF" "$ID" -F "documenten[]=@$TMP/tekening.pdf")"
check "fout CSRF-token geweigerd" 403 "$(post https://calcburo.nl "$(printf 'b%.0s' $(seq 64))" "$ID")"
check "nep-PDF geweigerd" 422 "$(post https://calcburo.nl "$CSRF" "$ID" -F "documenten[]=@$TMP/nep.pdf")"
check "geen aanvraag opgeslagen na weigeringen" 0 "$(ls "$TMP/data/aanvragen" 2>/dev/null | wc -l | tr -d ' ')"
check "geldige aanvraag met PDF opgeslagen" 201 "$(post https://calcburo.nl "$CSRF" "$ID" -F "documenten[]=@$TMP/tekening.pdf;type=application/pdf")"
REF=$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["referentie"] ?? "";' "$TMP/out.json")
check "mailstatus eerlijk: uitgeschakeld" uitgeschakeld "$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["mail"]["bevestiging"] ?? "";' "$TMP/out.json")"
check "document onder willekeurige naam buiten httpdocs" 1 "$(ls "$TMP/data/aanvragen/$REF/documenten" | grep -cE '^[0-9a-f]{32}\.pdf$')"
check "identieke herhaling (zelfde document) geeft zelfde referentie" "200 $REF" "$(post https://calcburo.nl "$CSRF" "$ID" -F "documenten[]=@$TMP/tekening.pdf;type=application/pdf") $(php -r 'echo json_decode(file_get_contents($argv[1]), true)["referentie"] ?? "";' "$TMP/out.json")"
check "zelfde formulier met gewijzigd project: 409" 409 "$(curl -s -b "$JAR" -H 'Accept: application/json' -H 'Origin: https://calcburo.nl' -o /dev/null -w '%{http_code}' "$B/api/aanvraag.php" -F "csrf=$CSRF" -F "aanvraag_id=$ID" -F "test_sleutel=$SLEUTEL" -F 'naam=E2E Test' -F 'email=e2e@voorbeeld.nl' -F 'project=Ander project' -F "documenten[]=@$TMP/tekening.pdf;type=application/pdf")"
# Opslag vol: ijl bestand tot vlak onder de limiet (64 MiB in deze testconfig), dan nieuwe aanvraag.
mkdir -p "$TMP/data/aanvragen/CB-ZZZZZZZZ" && php -r '$h=fopen($argv[1],"c"); ftruncate($h, 64*1024*1024 - 1000); fclose($h);' "$TMP/data/aanvragen/CB-ZZZZZZZZ/vol.pdf"
ID2=$(php -r '$b=random_bytes(16);$b[6]=chr((ord($b[6])&0x0f)|0x40);$b[8]=chr((ord($b[8])&0x3f)|0x80);$h=bin2hex($b);echo substr($h,0,8),"-",substr($h,8,4),"-",substr($h,12,4),"-",substr($h,16,4),"-",substr($h,20);')
check "opslag vol: 503 met eerlijke melding" "503 capaciteit" "$(post https://calcburo.nl "$CSRF" "$ID2") $(php -r 'echo json_decode(file_get_contents($argv[1]), true)["fout"]["code"] ?? "";' "$TMP/out.json")"
check "opslag vol: bestaand bestand onaangeroerd, geen nieuwe map" "67107864 2" "$(php -r 'echo filesize($argv[1]);' "$TMP/data/aanvragen/CB-ZZZZZZZZ/vol.pdf") $(ls "$TMP/data/aanvragen" | wc -l | tr -d ' ')"
check "zonder JavaScript (geen Accept JSON) HTML-resultaat" 1 "$(curl -s -b "$JAR" -H 'Origin: https://calcburo.nl' "$B/api/aanvraag.php" -F "csrf=$CSRF" -F "aanvraag_id=$ID" -F "test_sleutel=$SLEUTEL" -F 'naam=E2E Test' -F 'email=e2e@voorbeeld.nl' -F 'project=Rooktest' -F 'website=' -F "documenten[]=@$TMP/tekening.pdf;type=application/pdf" | grep -c "$REF")"
check "aanvraaggegevens niet via de website op te vragen" 0 "$(curl -s --path-as-is "$B/../data/aanvragen/$REF/aanvraag.json" | grep -c aangemaakt_unix)"
exit $FOUT
