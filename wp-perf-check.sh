#!/usr/bin/env bash
# =============================================================
# wp-perf-check.sh — External WordPress Performance Checker
# =============================================================
# Usage: ./wp-perf-check.sh https://example.com
#        ./wp-perf-check.sh https://example.com --repeat 5
#        ./wp-perf-check.sh https://example.com --verbose
#
# Requirements (auto-detected): curl, dig, openssl, jq (optional)
# All reads. Zero writes. Safe for production.
# =============================================================

set -euo pipefail

# ── Colours ──────────────────────────────────────────────────
RED='\033[0;31m'; YLW='\033[0;33m'; GRN='\033[0;32m'
PRI='\033[1;38;2;182;29;111m'   # #b61d6f — primary (bars)
SEC='\033[1;38;2;255;255;255m'  # #ffffff — secondary (titles)
BLD='\033[1m';    RST='\033[0m'
BAR="$(printf '─%.0s' {1..64})"

# ── Args ──────────────────────────────────────────────────────
TARGET_URL="${1:-}"
REPEAT=3       # default TTFB samples
VERBOSE=false

if [[ -z "$TARGET_URL" ]]; then
    echo "Usage: $0 <url> [--repeat N] [--verbose]"
    echo "  Example: $0 https://example.com"
    exit 1
fi

shift || true
while [[ $# -gt 0 ]]; do
    case "$1" in
        --repeat)  REPEAT="$2"; shift 2 ;;
        --verbose) VERBOSE=true; shift ;;
        *) shift ;;
    esac
done

# Normalise URL
TARGET_URL="${TARGET_URL%/}"
DOMAIN=$(echo "$TARGET_URL" | sed -E 's|https?://||' | cut -d/ -f1 | sed 's/:.*//')

# ── Helpers ───────────────────────────────────────────────────
section() { echo -e "\n${PRI}${BAR}${RST}\n${SEC}  $1${RST}\n${PRI}${BAR}${RST}"; }
row()     { printf "  ${BLD}%-38s${RST} %s\n" "$1" "$2"; }
good()    { echo -e "  ${GRN}✓ $1${RST}"; }
warn()    { echo -e "  ${YLW}⚠ $1${RST}"; }
bad()     { echo -e "  ${RED}✗ $1${RST}"; }
note()    { echo -e "  ${SEC}↳ $1${RST}"; }
require() {
    if ! command -v "$1" &>/dev/null; then
        warn "$1 not found — skipping related checks. Install with: brew install $1"
        return 1
    fi
    return 0
}

# ── File output setup ────────────────────────────────────────
REPORT_FILENAME="wp-perf-check-$(date -u '+%Y-%m-%d-%H%M%S')-${DOMAIN}.txt"
REPORT_TMPFILE="$(mktemp)"
exec > >(tee "$REPORT_TMPFILE") 2>&1

# ── Header ────────────────────────────────────────────────────
echo -e "\n${PRI}"
echo -e "  ┌──────────────────────────────────────────────────────────┐"
echo -e "  │${SEC}          WP External Performance Diagnostics             ${PRI}│"
echo -e "  │${SEC}                  wp-perf-check.sh                        ${PRI}│"
echo -e "  └──────────────────────────────────────────────────────────┘${RST}"
echo -e "  ${SEC}By Robyn × Claude AI${RST}"
echo ""
row "Generated"   "$(date -u '+%Y-%m-%d %H:%M:%S UTC')"
row "Site"        "$TARGET_URL"

# ─────────────────────────────────────────────────────────────
# 1. DNS RESOLUTION
# ─────────────────────────────────────────────────────────────
section "1. DNS"

if require dig; then
    DNS_START=$(date +%s%N 2>/dev/null || gdate +%s%N 2>/dev/null || echo 0)
    A_RECORDS=$(dig +short A "$DOMAIN" 2>/dev/null | head -10)
    AAAA_RECORDS=$(dig +short AAAA "$DOMAIN" 2>/dev/null | head -5)
    DNS_END=$(date +%s%N 2>/dev/null || gdate +%s%N 2>/dev/null || echo 0)

    if [[ -n "$A_RECORDS" ]]; then
        echo "  A records:"
        while IFS= read -r ip; do
            echo "    $ip"
        done <<< "$A_RECORDS"
    else
        bad "No A records found for $DOMAIN"
    fi

    if [[ -n "$AAAA_RECORDS" ]]; then
        echo "  AAAA (IPv6):"
        while IFS= read -r ip; do
            echo "    $ip"
        done <<< "$AAAA_RECORDS"
    fi

    # Check for Cloudflare IPs
    CF_CHECK=$(echo "$A_RECORDS" | head -1)
    if [[ "$CF_CHECK" =~ ^(172\.64\.|172\.65\.|172\.66\.|172\.67\.|104\.1[67]\.|104\.2[0-6]\.|108\.162\.|141\.101\.|162\.158\.|188\.114\.|190\.93\.|197\.234\.|198\.41\.) ]]; then
        good "Cloudflare IP range detected — CDN/proxy is active"
    fi

    MX=$(dig +short MX "$DOMAIN" 2>/dev/null | head -3)
    if [[ -n "$MX" ]]; then
        echo "  MX records:"
        echo "$MX" | while IFS= read -r mx; do echo "    $mx"; done
    fi

    NS=$(dig +short NS "$DOMAIN" 2>/dev/null | head -3)
    if [[ -n "$NS" ]]; then
        echo "  NS records:"
        echo "$NS" | while IFS= read -r ns; do echo "    $ns"; done
    fi
fi

# TTL check
TTL=$(dig +nocmd +noall +answer A "$DOMAIN" 2>/dev/null | awk '{print $2}' | head -1)
if [[ -n "$TTL" ]]; then
    row "DNS TTL (A record)" "${TTL}s"
    if (( TTL < 300 )); then warn "Low TTL (${TTL}s) — may indicate active DNS changes"; fi
fi

# ─────────────────────────────────────────────────────────────
# 2. SSL / TLS
# ─────────────────────────────────────────────────────────────
section "2. SSL / TLS"

if [[ "$TARGET_URL" == https://* ]]; then
    # Fetch the raw certificate in two steps so failures are detectable
    RAW_CERT=$(echo | openssl s_client \
        -connect "${DOMAIN}:443" \
        -servername "$DOMAIN" \
        2>/dev/null || true)

    CERT_PEM=$(echo "$RAW_CERT" | openssl x509 2>/dev/null || true)

    if [[ -n "$CERT_PEM" ]]; then
        SSL_INFO=$(echo "$CERT_PEM" | openssl x509 -noout -subject -issuer -dates 2>/dev/null || true)

        ISSUER=$(echo "$SSL_INFO"    | grep 'issuer='    | sed 's/issuer=//'    | sed 's/^ //' || true)
        NOT_AFTER=$(echo "$SSL_INFO" | grep 'notAfter='  | sed 's/notAfter=//'  | sed 's/^ //' || true)
        NOT_BEFORE=$(echo "$SSL_INFO" | grep 'notBefore=' | sed 's/notBefore=//' | sed 's/^ //' || true)

        row "Certificate issuer" "$ISSUER"
        row "Valid from"         "$NOT_BEFORE"
        row "Expires"            "$NOT_AFTER"

        # Days until expiry
        if command -v python3 &>/dev/null && [[ -n "$NOT_AFTER" ]]; then
            DAYS_LEFT=$(python3 -c "
from datetime import datetime
try:
    for fmt in ('%b %d %H:%M:%S %Y %Z', '%b  %d %H:%M:%S %Y %Z'):
        try:
            exp = datetime.strptime('$NOT_AFTER', fmt)
            print((exp - datetime.utcnow()).days)
            break
        except ValueError:
            continue
except Exception as e:
    print('?')
" 2>/dev/null || echo "?")
            if [[ "$DAYS_LEFT" != "?" ]]; then
                row "Days until expiry" "${DAYS_LEFT}d"
                if (( DAYS_LEFT < 14 )); then   bad  "Certificate expires in ${DAYS_LEFT} days!"
                elif (( DAYS_LEFT < 30 )); then  warn "Certificate expires in ${DAYS_LEFT} days"
                else                             good "Certificate valid for ${DAYS_LEFT} more days"
                fi
            fi
        fi

        # TLS version and cipher
        TLS_VER=$(echo "$RAW_CERT"    | grep -i "Protocol" | grep -v "#" | awk '{print $NF}' | head -1 || true)
        TLS_CIPHER=$(echo "$RAW_CERT" | grep -i "^Cipher"  | awk '{print $NF}' | head -1 || true)
        [[ -n "$TLS_VER" ]]    && row "TLS version"   "$TLS_VER"
        [[ -n "$TLS_CIPHER" ]] && row "Cipher suite"  "$TLS_CIPHER"

        # SAN / subject
        SUBJECT=$(echo "$SSL_INFO" | grep 'subject=' | sed 's/subject=//' | sed 's/^ //' || true)
        [[ -n "$SUBJECT" ]] && row "Certificate subject" "$SUBJECT"

        # HSTS
        HSTS=$(curl -sI --max-time 5 "https://$DOMAIN" 2>/dev/null | grep -i 'strict-transport-security' | tr -d '\r' || true)
        if [[ -n "$HSTS" ]]; then
            good "HSTS present: $(echo "$HSTS" | sed 's/strict-transport-security: //i')"
        else
            warn "No HSTS header found"
        fi
    else
        HANDSHAKE_ERR=$(echo "$RAW_CERT" | grep -i "error\|unable\|verify\|failed" | head -2 || true)
        if [[ -n "$HANDSHAKE_ERR" ]]; then
            bad "SSL handshake failed: $HANDSHAKE_ERR"
        else
            bad "Could not retrieve SSL certificate — check domain resolves and port 443 is reachable"
        fi
    fi

    # HTTP -> HTTPS redirect
    HTTP_STATUS=$(curl -sI --max-time 5 -o /dev/null -w "%{http_code}" "http://$DOMAIN" 2>/dev/null || echo "fail")
    HTTP_REDIR=$(curl -sI --max-time 5 -o /dev/null -w "%{redirect_url}" "http://$DOMAIN" 2>/dev/null || echo "")
    row "HTTP redirect to HTTPS" "$HTTP_STATUS $HTTP_REDIR"
    if [[ "$HTTP_STATUS" =~ ^30 ]]; then good "HTTP correctly redirects to HTTPS"; else warn "HTTP may not redirect to HTTPS ($HTTP_STATUS)"; fi
else
    warn "Target uses HTTP (not HTTPS)"
fi

# ─────────────────────────────────────────────────────────────
# 3. TTFB (multiple samples)
# ─────────────────────────────────────────────────────────────
section "3. TIME TO FIRST BYTE (${REPEAT} samples)"

note "Sampling TTFB with cache-busting params, then clean requests..."

declare -a TTFBS=()
declare -a TTFBS_WARM=()

# Cold (cache-bypass) requests
for i in $(seq 1 "$REPEAT"); do
    CB_PARAM="nocache=$(date +%s%N)_${i}"
    TTFB_VAL=$(curl -sI --max-time 15 \
        -H "Cache-Control: no-cache, no-store" \
        -H "Pragma: no-cache" \
        -w "%{time_starttransfer}" \
        -o /dev/null \
        "${TARGET_URL}?${CB_PARAM}" 2>/dev/null || echo "0")
    TTFB_MS=$(awk "BEGIN {printf \"%.0f\", $TTFB_VAL * 1000}")
    TTFBS+=("$TTFB_MS")
    $VERBOSE && row "  Cold sample $i" "${TTFB_MS}ms"
done

# Warm requests (allow caching)
for i in $(seq 1 "$REPEAT"); do
    TTFB_VAL=$(curl -sI --max-time 15 \
        -w "%{time_starttransfer}" \
        -o /dev/null \
        "$TARGET_URL" 2>/dev/null || echo "0")
    TTFB_MS=$(awk "BEGIN {printf \"%.0f\", $TTFB_VAL * 1000}")
    TTFBS_WARM+=("$TTFB_MS")
    $VERBOSE && row "  Warm sample $i" "${TTFB_MS}ms"
done

# Calculate stats
calc_stats() {
    local arr=("$@")
    local n=${#arr[@]}
    local sum=0 min=${arr[0]} max=${arr[0]}
    for v in "${arr[@]}"; do
        sum=$((sum + v))
        (( v < min )) && min=$v
        (( v > max )) && max=$v
    done
    local avg=$((sum / n))
    echo "$avg $min $max"
}

read AVG_COLD MIN_COLD MAX_COLD <<< "$(calc_stats "${TTFBS[@]}")"
read AVG_WARM MIN_WARM MAX_WARM <<< "$(calc_stats "${TTFBS_WARM[@]}")"

row "Cold TTFB (cache-bypass) avg"  "${AVG_COLD}ms  [min: ${MIN_COLD}ms / max: ${MAX_COLD}ms]"
row "Warm TTFB (cached) avg"        "${AVG_WARM}ms  [min: ${MIN_WARM}ms / max: ${MAX_WARM}ms]"

# TTFB ratings
ttfb_status() {
    local ms=$1
    if (( ms < 200 )); then echo -e "${GRN}Excellent${RST}"
    elif (( ms < 400 )); then echo -e "${GRN}Good${RST}"
    elif (( ms < 800 )); then echo -e "${YLW}OK${RST}"
    elif (( ms < 1500 )); then echo -e "${YLW}Slow${RST}"
    else echo -e "${RED}Very Slow${RST}"; fi
}

echo -e "  Cold TTFB rating:  $(ttfb_status $AVG_COLD)"
echo -e "  Warm TTFB rating:  $(ttfb_status $AVG_WARM)"

CACHE_SPEEDUP=0
if (( AVG_COLD > 0 )); then
    CACHE_SPEEDUP=$(awk "BEGIN {printf \"%.0f\", (($AVG_COLD - $AVG_WARM) / $AVG_COLD) * 100}")
fi
row "Cache speedup" "${CACHE_SPEEDUP}%"
if (( CACHE_SPEEDUP > 40 )); then good "Significant cache speedup detected";
elif (( CACHE_SPEEDUP > 10 )); then note "Some caching benefit visible";
else warn "Minimal difference between cold and warm — cache may not be working"; fi

# ─────────────────────────────────────────────────────────────
# 4. FULL CURL TIMING WATERFALL
# ─────────────────────────────────────────────────────────────
section "4. REQUEST TIMING WATERFALL"

CURL_OUT=$(curl -s --max-time 15 \
    -w "namelookup:%{time_namelookup}\nconnect:%{time_connect}\nappconnect:%{time_appconnect}\npretransfer:%{time_pretransfer}\nredirect:%{time_redirect}\nstarttransfer:%{time_starttransfer}\ntotal:%{time_total}\nsize_download:%{size_download}\nspeed_download:%{speed_download}\nhttp_code:%{http_code}\ncontent_type:%{content_type}\n" \
    -o /dev/null \
    "$TARGET_URL" 2>/dev/null || true)

parse_curl() { echo "$CURL_OUT" | grep "^$1:" | cut -d: -f2; }
to_ms()      { awk "BEGIN {printf \"%.1f\", $1 * 1000}"; }

DNS_T=$(parse_curl namelookup)
CONNECT_T=$(parse_curl connect)
APPCON_T=$(parse_curl appconnect)
PRE_T=$(parse_curl pretransfer)
REDIR_T=$(parse_curl redirect)
TTFB_T=$(parse_curl starttransfer)
TOTAL_T=$(parse_curl total)
DL_SIZE=$(parse_curl size_download)
DL_SPEED=$(parse_curl speed_download)
HTTP_CODE=$(parse_curl http_code)
CTYPE=$(parse_curl content_type)

[[ -n "$DNS_T" ]]     && row "DNS lookup"      "$(to_ms $DNS_T)ms"
[[ -n "$CONNECT_T" ]] && row "TCP connect"     "$(to_ms $CONNECT_T)ms  (cumulative)"
[[ -n "$APPCON_T" && "$APPCON_T" != "0.000000" ]] && row "SSL handshake" "$(to_ms $APPCON_T)ms  (cumulative)"
[[ -n "$PRE_T" ]]     && row "Pre-transfer"    "$(to_ms $PRE_T)ms  (cumulative)"
[[ -n "$REDIR_T" && "$REDIR_T" != "0.000000" ]] && row "Redirects"   "$(to_ms $REDIR_T)ms"
[[ -n "$TTFB_T" ]]    && row "TTFB"            "$(to_ms $TTFB_T)ms  (cumulative)"
[[ -n "$TOTAL_T" ]]   && row "Total"           "$(to_ms $TOTAL_T)ms"
if [[ -n "$DL_SIZE" && "$DL_SIZE" != "0" ]]; then
    DL_SIZE_KB=$(awk "BEGIN {printf \"%.1f\", $DL_SIZE / 1024}")
    DL_SPEED_KB=$(awk "BEGIN {printf \"%.1f\", $DL_SPEED / 1024}")
    row "Response size"   "${DL_SIZE_KB} KB"
    row "Download speed"  "${DL_SPEED_KB} KB/s"
fi
[[ -n "$HTTP_CODE" ]] && row "HTTP status"     "$HTTP_CODE"
[[ -n "$CTYPE" ]]     && row "Content-Type"    "$CTYPE"

# ─────────────────────────────────────────────────────────────
# 5. HTTP HEADERS ANALYSIS
# ─────────────────────────────────────────────────────────────
section "5. RESPONSE HEADERS ANALYSIS"

note "Fetching all response headers..."

HEADERS_COLD=$(curl -sI --max-time 15 \
    -H "Cache-Control: no-cache" \
    -H "Pragma: no-cache" \
    -H "User-Agent: WPPerfDiag/1.0" \
    "${TARGET_URL}?nocache=$(date +%s)" 2>/dev/null || true)

HEADERS_WARM=$(curl -sI --max-time 15 \
    -H "User-Agent: WPPerfDiag/1.0" \
    "$TARGET_URL" 2>/dev/null || true)

get_header() {
    echo "$1" | grep -i "^$2:" | head -1 | sed "s/^$2: //i" | tr -d '\r'
}

# Cache/CDN headers to check (both cold and warm)
CACHE_HEADERS=(
    "x-nananana"
    "x-ac"
    "cache-control"
    "x-cache"
    "x-cache-status"
    "x-batcache"
    "cf-cache-status"
    "x-varnish"
    "age"
    "pragma"
    "expires"
    "x-powered-by"
    "server"
    "x-litespeed-cache"
    "x-kinsta-cache"
    "x-wpe-request-id"
    "x-wp-cf-super-cache"
    "x-cacheable"
    "surrogate-control"
    "x-proxy-cache"
    "x-served-by"
    "x-cache-hits"
    "x-fastly-request-id"
    "vary"
    "etag"
    "last-modified"
    "set-cookie"
)

echo "  Header               [Cold/Bypass]                 [Warm/Cached]"
echo "  $(printf '─%.0s' {1..62})"

for h in "${CACHE_HEADERS[@]}"; do
    COLD_VAL=$(get_header "$HEADERS_COLD" "$h")
    WARM_VAL=$(get_header "$HEADERS_WARM" "$h")
    if [[ -n "$COLD_VAL" || -n "$WARM_VAL" ]]; then
        printf "  %-20s %-30s %s\n" "$h:" "${COLD_VAL:-(not present)}" "${WARM_VAL:-(not present)}"
    fi
done

# Detailed interpretations
echo ""

# set-cookie — grab ALL values (multiple headers possible), check both cold and warm
COOKIES_WARM=$(echo "$HEADERS_WARM" | grep -i "^set-cookie:" | sed 's/^[Ss]et-[Cc]ookie: //' | tr -d '\r')
COOKIES_COLD=$(echo "$HEADERS_COLD" | grep -i "^set-cookie:" | sed 's/^[Ss]et-[Cc]ookie: //' | tr -d '\r')
COOKIE_COUNT_WARM=$(echo "$COOKIES_WARM" | grep -c '.' || echo 0)
COOKIE_COUNT_COLD=$(echo "$COOKIES_COLD" | grep -c '.' || echo 0)

echo ""
if [[ -n "$COOKIES_WARM" || -n "$COOKIES_COLD" ]]; then
    warn "set-cookie headers present — these will typically cause edge/page cache to BYPASS"
    echo "  Cookies set on warm request ($COOKIE_COUNT_WARM):"
    if [[ -n "$COOKIES_WARM" ]]; then
        echo "$COOKIES_WARM" | while IFS= read -r cookie; do
            # Extract just the name for readability
            CNAME=$(echo "$cookie" | cut -d= -f1)
            echo "    • $CNAME"
        done
    else
        echo "    (none)"
    fi
    echo "  Cookies set on cold/bypass request ($COOKIE_COUNT_COLD):"
    if [[ -n "$COOKIES_COLD" ]]; then
        echo "$COOKIES_COLD" | while IFS= read -r cookie; do
            CNAME=$(echo "$cookie" | cut -d= -f1)
            echo "    • $CNAME"
        done
    else
        echo "    (none)"
    fi
    if [[ -n "$COOKIES_WARM" ]]; then
        bad "Cookies are being set on normal (warm) requests — likely breaking caching"
        note "Common culprits: analytics plugins, consent banners, session handlers, WooCommerce cart"

        # Flag wp_ and woocommerce_ cookies specifically
        WP_COOKIES=$(echo "$COOKIES_WARM" | grep -i "^wp_\|^woocommerce_" || true)
        if [[ -n "$WP_COOKIES" ]]; then
            echo ""
            bad "WordPress/WooCommerce cookies being set on every request:"
            echo "$WP_COOKIES" | while IFS= read -r cookie; do
                CNAME=$(echo "$cookie" | cut -d= -f1)
                echo "    • $CNAME"
            done
            note "wp_ cookies on non-logged-in requests often indicate a plugin misusing sessions"
            note "woocommerce_ cookies suggest cart/session activity on pages that should be cacheable"
        fi
    fi
else
    good "No set-cookie headers on either request — cache-safe"
fi

# Batcache (x-nananana)
X_NANANANA=$(get_header "$HEADERS_WARM" "x-nananana")
if [[ -n "$X_NANANANA" ]]; then
    if echo "$X_NANANANA" | grep -qi "Batcache-Hit"; then
        good "Batcache HIT (x-nananana: $X_NANANANA)"
    elif echo "$X_NANANANA" | grep -qi "Batcache-Set"; then
        warn "Batcache SET (x-nananana: $X_NANANANA) — cached now, will HIT on next request"
    else
        warn "x-nananana present but unexpected value: $X_NANANANA"
    fi
else
    bad "x-nananana header absent — Batcache may not be running or is bypassed"
fi

# Edge cache (x-ac)
X_AC=$(get_header "$HEADERS_WARM" "x-ac")
if [[ -n "$X_AC" ]]; then
    if echo "$X_AC" | grep -qi "HIT"; then
        good "Edge cache HIT (x-ac: $X_AC)"
    elif echo "$X_AC" | grep -qi "MISS"; then
        warn "Edge cache MISS (x-ac: $X_AC) — not yet in edge cache, try a second request"
    elif echo "$X_AC" | grep -qi "BYPASS"; then
        bad "Edge cache BYPASS (x-ac: $X_AC) — caching is being skipped"
    else
        warn "x-ac present but unexpected value: $X_AC"
    fi
else
    bad "x-ac header absent — edge cache may not be active for this URL"
fi

CF_STATUS=$(get_header "$HEADERS_WARM" "cf-cache-status")
if [[ -n "$CF_STATUS" ]]; then
    case "${CF_STATUS^^}" in
        HIT|REVALIDATED|UPDATING) good "Cloudflare cache: $CF_STATUS" ;;
        MISS)   warn "Cloudflare MISS — page not yet cached or cache bypassed" ;;
        BYPASS) warn "Cloudflare BYPASS — caching may be disabled for this URL" ;;
        DYNAMIC) note "Cloudflare DYNAMIC — not cached at edge (may be intentional)" ;;
        *) note "Cloudflare cache status: $CF_STATUS" ;;
    esac
fi

X_CACHE=$(get_header "$HEADERS_WARM" "x-cache")
if [[ -n "$X_CACHE" ]]; then
    if echo "$X_CACHE" | grep -qi "HIT"; then good "x-cache HIT: $X_CACHE"
    else warn "x-cache MISS: $X_CACHE"; fi
fi

X_BATCACHE=$(get_header "$HEADERS_WARM" "x-batcache")
if [[ -n "$X_BATCACHE" ]]; then
    good "Batcache header found: $X_BATCACHE"
else
    note "No x-batcache header (Batcache may not be active, or not exposing headers)"
fi

CACHE_CTRL=$(get_header "$HEADERS_WARM" "cache-control")
if echo "$CACHE_CTRL" | grep -qi "no-store\|no-cache"; then
    warn "Cache-Control prevents caching: $CACHE_CTRL"
elif echo "$CACHE_CTRL" | grep -qi "max-age"; then
    good "Cache-Control allows caching: $CACHE_CTRL"
fi

AGE=$(get_header "$HEADERS_WARM" "age")
if [[ -n "$AGE" && "$AGE" != "0" ]]; then
    good "Age header present ($AGE s) — response served from cache"
elif [[ "$AGE" == "0" ]]; then
    warn "Age: 0 — likely a fresh cache miss"
fi

# Server detection
SERVER=$(get_header "$HEADERS_WARM" "server")
XPOWERED=$(get_header "$HEADERS_WARM" "x-powered-by")
[[ -n "$SERVER" ]]  && row "Server software" "$SERVER"
[[ -n "$XPOWERED" ]] && row "X-Powered-By" "$XPOWERED"

# Gzip/Brotli
note "Checking compression..."
ENCODING=$(curl -sI --max-time 10 \
    -H "Accept-Encoding: br, gzip, deflate" \
    -H "User-Agent: WPPerfDiag/1.0" \
    "$TARGET_URL" 2>/dev/null | grep -i "content-encoding" | head -1 | tr -d '\r')
if [[ -n "$ENCODING" ]]; then
    good "Compression active: $ENCODING"
else
    warn "No Content-Encoding header — compression may not be enabled"
fi

# ─────────────────────────────────────────────────────────────
# 6. WORDPRESS-SPECIFIC CHECKS
# ─────────────────────────────────────────────────────────────
section "6. WORDPRESS DETECTION"

note "Probing for WordPress signals..."

BODY=$(curl -s --max-time 15 "$TARGET_URL" 2>/dev/null || true)

# WP generator tag
WP_VER=$(echo "$BODY" | grep -o 'content="WordPress [0-9.]*"' | grep -o '[0-9.]*' | head -1)
if [[ -n "$WP_VER" ]]; then
    warn "WordPress version exposed in meta generator: $WP_VER (consider removing)"
else
    good "WordPress version not exposed in meta generator"
fi

# wp-content path detection
if echo "$BODY" | grep -q "wp-content"; then
    good "wp-content found in source (WordPress confirmed)"
fi

# REST API
REST_URL="${TARGET_URL}/wp-json/wp/v2"
REST_CODE=$(curl -sI --max-time 10 -o /dev/null -w "%{http_code}" "$REST_URL" 2>/dev/null || echo "fail")
row "WP REST API (/wp-json/wp/v2)" "$REST_CODE"
if [[ "$REST_CODE" == "200" ]]; then
    note "REST API is publicly accessible — ensure sensitive endpoints are protected"
fi

# WP login page
LOGIN_CODE=$(curl -sI --max-time 10 -o /dev/null -w "%{http_code}" "${TARGET_URL}/wp-login.php" 2>/dev/null || echo "fail")
row "wp-login.php accessible" "$LOGIN_CODE"
if [[ "$LOGIN_CODE" == "200" ]]; then
    note "Login page is publicly accessible (standard; consider rate limiting)"
elif [[ "$LOGIN_CODE" == "403" || "$LOGIN_CODE" == "404" ]]; then
    good "Login page returns $LOGIN_CODE (may be protected/relocated)"
fi

# xmlrpc
XMLRPC_CODE=$(curl -sI --max-time 10 -o /dev/null -w "%{http_code}" "${TARGET_URL}/xmlrpc.php" 2>/dev/null || echo "fail")
row "xmlrpc.php" "$XMLRPC_CODE"
if [[ "$XMLRPC_CODE" == "200" || "$XMLRPC_CODE" == "405" ]]; then
    warn "xmlrpc.php is accessible ($XMLRPC_CODE) — disable if not needed"
fi

# Readme
README_CODE=$(curl -sI --max-time 5 -o /dev/null -w "%{http_code}" "${TARGET_URL}/readme.html" 2>/dev/null || echo "fail")
if [[ "$README_CODE" == "200" ]]; then
    warn "readme.html accessible — may expose WordPress version"
fi

# Feed
FEED_URL="${TARGET_URL}/feed/"
FEED_CODE=$(curl -sI --max-time 5 -o /dev/null -w "%{http_code}" "$FEED_URL" 2>/dev/null || echo "fail")
row "RSS Feed (/feed/)" "$FEED_CODE"

# Check if WP Query Monitor or Debug Bar signals in headers
QM_HEADER=$(get_header "$HEADERS_WARM" "x-query-monitor")
if [[ -n "$QM_HEADER" ]]; then
    note "Query Monitor headers detected (debug plugin active)"
fi

# ─────────────────────────────────────────────────────────────
# 7. CORE WEB VITALS VIA CRUX API
# ─────────────────────────────────────────────────────────────
section "7. CORE WEB VITALS (PageSpeed Insights)"

PAGESPEED_URL="https://www.googleapis.com/pagespeedonline/v5/runPagespeed?url=$(python3 -c "import urllib.parse; print(urllib.parse.quote('$TARGET_URL'))" 2>/dev/null || echo "$TARGET_URL")&strategy=mobile"

note "Querying PageSpeed Insights API (no API key — limited data)..."
PSI_DATA=$(curl -s --max-time 20 "$PAGESPEED_URL" 2>/dev/null || true)

if [[ -n "$PSI_DATA" ]]; then
    if command -v python3 &>/dev/null; then
        python3 - <<PYEOF
import json, sys

try:
    data = json.loads('''$PSI_DATA''')
except:
    print("  Could not parse PageSpeed response")
    sys.exit(0)

cats = data.get('lighthouseResult', {}).get('categories', {})
audits = data.get('lighthouseResult', {}).get('audits', {})

perf_score = cats.get('performance', {}).get('score')
if perf_score is not None:
    score = int(perf_score * 100)
    color = '\033[32m' if score >= 90 else '\033[33m' if score >= 50 else '\033[31m'
    print(f"  {color}Performance Score: {score}/100\033[0m")

# Key metrics
metric_keys = {
    'first-contentful-paint': 'FCP',
    'largest-contentful-paint': 'LCP',
    'total-blocking-time': 'TBT',
    'cumulative-layout-shift': 'CLS',
    'speed-index': 'Speed Index',
    'interactive': 'TTI',
    'server-response-time': 'Server Response Time',
}

for key, label in metric_keys.items():
    audit = audits.get(key, {})
    display = audit.get('displayValue', '')
    score = audit.get('score')
    if display:
        color = '\033[32m' if score == 1 else '\033[33m' if score and score >= 0.5 else '\033[31m'
        print(f"  {color}{label:<30}{display}\033[0m")

# Opportunities
opps = {k: v for k, v in audits.items() 
        if v.get('details', {}).get('type') == 'opportunity' 
        and v.get('score') is not None and v.get('score') < 1
        and v.get('details', {}).get('overallSavingsMs', 0) > 100}

if opps:
    print("")
    print("  Top opportunities:")
    sorted_opps = sorted(opps.items(), key=lambda x: x[1].get('details', {}).get('overallSavingsMs', 0), reverse=True)
    for k, v in sorted_opps[:5]:
        savings = v.get('details', {}).get('overallSavingsMs', 0)
        title = v.get('title', k)
        print(f"    ⚠ {title[:55]:<55} ~{savings:.0f}ms savings")

PYEOF
    else
        note "python3 not found — install to parse PageSpeed results"
        echo "$PSI_DATA" | grep -o '"score":[0-9.]*' | head -5 || true
    fi
else
    warn "Could not reach PageSpeed Insights API"
fi

# ─────────────────────────────────────────────────────────────
# 8. ASSET SCAN (HTML source)
# ─────────────────────────────────────────────────────────────
section "8. ASSET ANALYSIS (from HTML source)"

note "Analysing linked assets from homepage source..."

FULL_BODY=$(curl -sL --max-time 20 \
    -H "User-Agent: Mozilla/5.0 (compatible; WPPerfDiag)" \
    "$TARGET_URL" 2>/dev/null || true)

if [[ -n "$FULL_BODY" ]]; then
    # Count scripts
    SCRIPT_COUNT=$(echo "$FULL_BODY" | grep -oi '<script[^>]*src=' | wc -l | tr -d ' ')
    STYLE_COUNT=$(echo  "$FULL_BODY" | grep -oi '<link[^>]*stylesheet' | wc -l | tr -d ' ')
    IMG_COUNT=$(echo    "$FULL_BODY" | grep -oi '<img[^>]*>' | wc -l | tr -d ' ')
    IFRAME_COUNT=$(echo "$FULL_BODY" | grep -oi '<iframe' | wc -l | tr -d ' ')

    row "External script tags" "$SCRIPT_COUNT" 
    (( SCRIPT_COUNT > 20 )) && warn "High number of scripts ($SCRIPT_COUNT) — check for bloat"
    row "Stylesheet links" "$STYLE_COUNT"
    (( STYLE_COUNT > 12 )) && warn "High number of stylesheets ($STYLE_COUNT)"
    row "Images" "$IMG_COUNT"
    row "iFrames" "$IFRAME_COUNT"
    (( IFRAME_COUNT > 2 )) && warn "Multiple iframes can affect performance"

    # HTML size
    HTML_SIZE=${#FULL_BODY}
    row "HTML body size" "$(awk "BEGIN {printf \"%.1f\", $HTML_SIZE/1024}")KB"
    (( HTML_SIZE > 200000 )) && warn "Large HTML document (>200KB)"

    # Render-blocking scripts in <head>
    HEAD=$(echo "$FULL_BODY" | python3 -c "
import sys
content = sys.stdin.read()
head_start = content.lower().find('<head')
head_end = content.lower().find('</head>')
if head_start >= 0 and head_end >= 0:
    print(content[head_start:head_end])
" 2>/dev/null || true)
    
    BLOCKING_SCRIPTS=$(echo "$HEAD" | grep -oi '<script[^>]*src=[^>]*>' | grep -v 'async\|defer' | wc -l | tr -d ' ')
    row "Render-blocking scripts in <head>" "$BLOCKING_SCRIPTS"
    (( BLOCKING_SCRIPTS > 2 )) && warn "$BLOCKING_SCRIPTS render-blocking scripts in <head> — add async/defer"

    # Lazy loading images
    LAZY_IMGS=$(echo "$FULL_BODY" | grep -oi 'loading="lazy"' | wc -l | tr -d ' ')
    TOTAL_IMGS=$IMG_COUNT
    row "Images with lazy loading" "$LAZY_IMGS / $TOTAL_IMGS"
    if (( TOTAL_IMGS > 5 && LAZY_IMGS == 0 )); then
        warn "No lazy-loaded images detected — consider adding loading=\"lazy\""
    fi

    # Google Fonts check
    if echo "$FULL_BODY" | grep -qi "fonts.googleapis.com\|fonts.gstatic.com"; then
        warn "Google Fonts loaded externally — consider self-hosting for performance"
    fi

    # Third-party domains
    note "Third-party domains referenced:"
    echo "$FULL_BODY" | grep -oP '(https?:)?//[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}' 2>/dev/null | \
        sed -E 's|https?://||' | cut -d/ -f1 | \
        grep -v "^${DOMAIN}$" | grep -v "^$" | \
        sort | uniq -c | sort -rn | head -15 | \
        while read count domain; do
            echo "    $count refs — $domain"
        done
fi

# ─────────────────────────────────────────────────────────────
# 9. SECURITY HEADERS
# ─────────────────────────────────────────────────────────────
section "9. SECURITY HEADERS"

declare -A SEC_HEADERS=(
    ["x-frame-options"]="Prevents clickjacking"
    ["x-content-type-options"]="Prevents MIME sniffing"
    ["x-xss-protection"]="XSS filter (legacy)"
    ["content-security-policy"]="CSP — important"
    ["referrer-policy"]="Controls referrer info"
    ["permissions-policy"]="Controls browser features"
    ["strict-transport-security"]="HSTS"
)

for h in "${!SEC_HEADERS[@]}"; do
    val=$(get_header "$HEADERS_WARM" "$h")
    desc="${SEC_HEADERS[$h]}"
    if [[ -n "$val" ]]; then
        good "$h: $val"
    else
        warn "Missing: $h — $desc"
    fi
done

# ─────────────────────────────────────────────────────────────
# 10. SUMMARY
# ─────────────────────────────────────────────────────────────
section "SUMMARY"

echo ""

# Quick wins
echo -e "  ${BLD}Key metrics:${RST}"
row "  Cold TTFB avg"   "${AVG_COLD}ms"
row "  Warm TTFB avg"   "${AVG_WARM}ms"
row "  Cache speedup"   "${CACHE_SPEEDUP}%"
[[ -n "$WP_VER" ]] && row "  WP version exposed" "$WP_VER"

echo ""
echo -e "  ${BLD}Recommendations:${RST}"

# TTFB
if (( AVG_COLD > 1500 )); then
    bad  "Very slow cold TTFB (${AVG_COLD}ms) — investigate server/PHP/DB performance"
elif (( AVG_COLD > 600 )); then
    warn "Slow cold TTFB (${AVG_COLD}ms) — check for slow queries, no OPcache, or heavy plugins"
else
    good "Cold TTFB acceptable (${AVG_COLD}ms)"
fi

if (( CACHE_SPEEDUP < 10 )); then
    warn "Minimal cache speedup (${CACHE_SPEEDUP}%) — page caching may not be effective"
elif (( CACHE_SPEEDUP > 50 )); then
    good "Strong cache speedup (${CACHE_SPEEDUP}%) — page caching is working well"
fi

CF_WARM=$(get_header "$HEADERS_WARM" "cf-cache-status")
[[ -z "$CF_WARM" ]] && note "No Cloudflare headers — not on Cloudflare (or CF not proxying)"

# x-nananana summary
X_NAN_WARM=$(get_header "$HEADERS_WARM" "x-nananana")
if [[ -z "$X_NAN_WARM" ]]; then
    warn "x-nananana absent — Batcache not confirmed"
elif echo "$X_NAN_WARM" | grep -qi "Batcache-Hit"; then
    good "Batcache confirmed HIT"
elif echo "$X_NAN_WARM" | grep -qi "Batcache-Set"; then
    warn "Batcache SET — will HIT on next request"
fi

# x-ac summary
X_AC_WARM=$(get_header "$HEADERS_WARM" "x-ac")
if [[ -z "$X_AC_WARM" ]]; then
    warn "x-ac absent — edge cache not confirmed"
elif echo "$X_AC_WARM" | grep -qi "HIT"; then
    good "Edge cache confirmed HIT"
elif echo "$X_AC_WARM" | grep -qi "MISS"; then
    warn "Edge cache MISS — not yet cached at edge"
elif echo "$X_AC_WARM" | grep -qi "BYPASS"; then
    bad "Edge cache BYPASS — investigate why caching is skipped"
fi

echo ""
echo -e "  ${SEC}Next steps:${RST}"
echo "    • Run wp-perf-diag.php server-side for DB/plugin/object cache detail"
echo "    • Use Query Monitor plugin for per-request query analysis"
echo "    • Check GTmetrix / WebPageTest for waterfall breakdown"
echo "    • Review PageSpeed Insights for full Core Web Vitals data"
echo ""
row "Completed at" "$(date '+%Y-%m-%d %H:%M:%S %Z')"
echo ""

# ── Save report ───────────────────────────────────────────────
# Give the tee process a moment to flush, then strip ANSI and write the clean file
sleep 0.2
sed 's/\x1b\[[0-9;]*m//g' "$REPORT_TMPFILE" > "$REPORT_FILENAME"
rm -f "$REPORT_TMPFILE"
printf "\033[3;38;2;136;146;160m  Report saved: %s\033[0m\n" "$REPORT_FILENAME" > /dev/tty
