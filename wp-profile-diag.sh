#!/usr/bin/env bash
# =============================================================
# wp-profile-diag.sh — WP-CLI Profile Command Installer & Runner
# =============================================================
# Installs Composer and wp-cli/profile-command if not already
# present, then runs a full profile breakdown for the current
# WordPress site.
#
# Usage (run from the site's htdocs/public_html directory):
#   bash wp-profile-diag.sh
#
# Or download and run in one step:
#   wget -q -O wp-profile-diag.sh "https://raw.githubusercontent.com/ItsRobyn/WP-Performance-Scripts/main/wp-profile-diag.sh?$(date +%s)" && bash wp-profile-diag.sh
#
# Requirements: php, wp (WP-CLI), internet access
# Safe: installs only to ~/.config — no system-wide changes.
# =============================================================

set -euo pipefail

# ── Colours ───────────────────────────────────────────────────
RED='\033[0;31m'; YLW='\033[0;33m'; GRN='\033[0;32m'
PRI='\033[1;38;2;182;29;111m'   # #b61d6f — primary (bars)
SEC='\033[1;38;2;255;255;255m'  # #ffffff — secondary (titles)
BLD='\033[1m';    RST='\033[0m'
BAR="$(printf '─%.0s' {1..64})"

# ── Helpers ───────────────────────────────────────────────────
section() { echo -e "\n${PRI}${BAR}${RST}\n${SEC}  $1${RST}\n${PRI}${BAR}${RST}\n"; }
good()    { echo -e "  ${GRN}✓ $1${RST}"; }
warn()    { echo -e "  ${YLW}⚠ $1${RST}"; }
bad()     { echo -e "  ${RED}✗ $1${RST}"; }
note()    { echo -e "  ${SEC}↳ $1${RST}"; }
row()     { printf "  ${BLD}%-38s${RST} %s\n" "$1" "$2"; }

step() {
    echo -e "\n  ${BLD}${SEC}▶ $1${RST}"
}

die() {
    bad "$1"
    exit 1
}

# ── Environment vars needed before first wp call ──────────────
COMPOSER_HOME="${HOME}/.config/composer"
WP_CLI_PACKAGES_DIR="${HOME}/.config/wp-cli/packages"
export COMPOSER_HOME
export WP_CLI_PACKAGES_DIR

# ── Header ────────────────────────────────────────────────────
SITE_URL="$(wp --no-color option get siteurl 2>/dev/null)" || SITE_URL=""
# Keep only the first line and strip any leading/trailing whitespace
SITE_URL="$(echo "$SITE_URL" | head -1 | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')"
[[ -z "$SITE_URL" || "$SITE_URL" != http* ]] && SITE_URL="unknown"
SITE_HOST="$(echo "$SITE_URL" | sed -E 's|https?://||' | cut -d/ -f1 | sed 's/:.*//')"
REPORT_FILENAME="wp-profile-diag-$(date -u '+%Y-%m-%d-%H%M%S')-${SITE_HOST}.txt"
REPORT_TMPFILE="$(mktemp)"
# Duplicate original stdout to fd 3 (terminal), then redirect stdout to tmpfile.
# All output goes to the file; we tee to the terminal by writing to fd 3 via a
# background tail. Simpler and more portable than process substitution (>(tee ...))
# which requires /dev/fd support not present on all Linux hosts.
exec 3>&1 1>"$REPORT_TMPFILE" 2>&1
# Stream the file to the terminal in real time via tail -f on fd 3
tail -f "$REPORT_TMPFILE" >&3 &
TAIL_PID=$!
# Detect Python — used in report post-processing
_PYTHON=""
command -v python3 &>/dev/null && _PYTHON=python3
[[ -z "$_PYTHON" ]] && command -v python &>/dev/null && _PYTHON=python
# format_as_table() reads CSV from stdin and renders a bordered ASCII table.
# Uses --format=csv (reliable regardless of WP-CLI version or TTY context)
# rather than --format=table, which many profile-command versions ignore in
# non-TTY contexts, falling back to plain space-separated output.
# Appends a computed "Total" row summing numeric columns (ratio/rate/pct
# columns are excluded as summing them is meaningless).
format_as_table() {
    awk '
    function rpad(s, n,    r, i) {
        r = s
        for (i = length(s); i < n; i++) r = r " "
        return r
    }
    function hline(    s, i, j, d) {
        s = "+"
        for (i = 1; i <= maxcols; i++) {
            d = ""
            for (j = 0; j < w[i]+2; j++) d = d "-"
            s = s d "+"
        }
        return s
    }
    # Format numeric values for display (4 decimal places).
    # Scientific notation  → time string with ms or s suffix.
    # Regular decimals >4dp → truncated to 4dp (ms suffix if sub-millisecond).
    function fmt_cell(v,    f) {
        if (v ~ /[eE][-+][0-9]+$/) {
            f = v + 0
            if (f < 0.01)  return sprintf("%.4fms", f * 1000)
            else           return sprintf("%.4fs",   f)
        }
        if (v ~ /^[0-9]*\.[0-9][0-9][0-9][0-9][0-9][0-9]*$/) {
            f = v + 0
            if (f < 0.001) return sprintf("%.4fms", f * 1000)
            else           return sprintf("%.4f",    f)
        }
        return v
    }
    BEGIN { FS=","; maxcols=0; nr=0 }
    {
        nr++
        for (i = 1; i <= NF; i++) {
            raw = $i
            if (substr(raw,1,1) == "\"" && substr(raw,length(raw),1) == "\"")
                raw = substr(raw, 2, length(raw)-2)
            v = fmt_cell(raw)
            rows[nr, i] = v
            if (length(v) > w[i]) w[i] = length(v)
            # Accumulate raw numeric values for totals (skip header row and first column)
            if (nr > 1 && i > 1 && raw ~ /^[0-9]*\.?[0-9]+([eE][-+][0-9]+)?$/) {
                sums[i] += raw + 0
                has_sum[i] = 1
            }
        }
        if (NF > maxcols) maxcols = NF
    }
    END {
        if (nr == 0) exit
        # Build totals row; only widen columns when Total row will actually be printed
        totals[1] = "Total"
        if (nr > 1 && length("Total") > w[1]) w[1] = length("Total")
        for (i = 2; i <= maxcols; i++) {
            hdr = tolower(rows[1, i])
            if (has_sum[i] && hdr !~ /ratio|rate|pct/) {
                totals[i] = fmt_cell(sprintf("%.10g", sums[i]))
            } else {
                totals[i] = "-"
            }
            if (nr > 1 && length(totals[i]) > w[i]) w[i] = length(totals[i])
        }
        print hline()
        for (r = 1; r <= nr; r++) {
            line = "|"
            for (i = 1; i <= maxcols; i++) line = line " " rpad(rows[r, i], w[i]) " |"
            print line
            if (r == 1) print hline()
        }
        if (nr > 1) {
            print hline()
            line = "|"
            for (i = 1; i <= maxcols; i++) line = line " " rpad(totals[i], w[i]) " |"
            print line
        }
        print hline()
    }
    '
}
# wp_profile() runs a wp profile subcommand and renders output as a bordered
# ASCII table. Three-tier fallback strategy:
#   1. --format=csv  → format_as_table  (fastest; field-name validation can exit 0
#      with empty output on some profile-command versions, so we guard with -s)
#   2. --format=json → PHP → CSV → format_as_table  (PHP is always present on WP
#      hosts; JSON output bypasses the CSV formatter's field-name validation entirely)
#   3. --format=table (raw)  (last resort — shows data even without formatting)
wp_profile() {
    local tmpout tmpfmt
    tmpout=$(mktemp)
    tmpfmt=$(mktemp)

    # Tier 1: CSV path — guard with [[ -s ]] because some profile-command versions
    # exit 0 with empty output when a field-name mismatch is detected.
    if wp --no-color "$@" --format=csv > "$tmpout" 2>/tmp/wp_profile_err \
            && [[ -s "$tmpout" ]]; then
        format_as_table < "$tmpout" > "$tmpfmt"
        if [[ -s "$tmpfmt" ]]; then
            cat "$tmpfmt"
            WP_PROFILE_LAST=$(cat "$tmpfmt")
            rm -f "$tmpout" "$tmpfmt"
            return 0
        fi
    fi

    # Tier 2: JSON → PHP → CSV. PHP is always available on WordPress hosts and
    # --format=json has no field-name validation, so it works across all versions.
    local _json _phpscript _csv
    _json=$(mktemp)
    _phpscript=$(mktemp)
    _csv=$(mktemp)
    cat > "$_phpscript" <<'PHPEOF'
<?php
$d = json_decode(stream_get_contents(STDIN), true);
if (!empty($d)) {
    echo implode(',', array_keys($d[0])) . "\n";
    foreach ($d as $row) {
        $cells = array();
        foreach (array_values($row) as $v) {
            $cells[] = is_numeric($v) ? $v
                : '"' . str_replace('"', '""', (string)$v) . '"';
        }
        echo implode(',', $cells) . "\n";
    }
}
PHPEOF
    if wp --no-color "$@" --format=json > "$_json" 2>/dev/null \
            && [[ -s "$_json" ]] \
            && php "$_phpscript" < "$_json" > "$_csv" 2>/dev/null \
            && [[ -s "$_csv" ]]; then
        format_as_table < "$_csv" > "$tmpfmt"
        if [[ -s "$tmpfmt" ]]; then
            cat "$tmpfmt"
            WP_PROFILE_LAST=$(cat "$tmpfmt")
            rm -f "$tmpout" "$tmpfmt" "$_json" "$_phpscript" "$_csv"
            return 0
        fi
    fi
    rm -f "$_json" "$_phpscript" "$_csv"

    # Tier 3: raw --format=table — at minimum the user sees the data.
    local err
    err=$(cat /tmp/wp_profile_err 2>/dev/null)
    if wp --no-color "$@" --format=table > "$tmpfmt" 2>/dev/null && [[ -s "$tmpfmt" ]]; then
        cat "$tmpfmt"
        WP_PROFILE_LAST=$(cat "$tmpfmt")
        rm -f "$tmpout" "$tmpfmt"
        return 0
    fi
    [[ -n "$err" ]] && warn "wp profile error: $err"
    rm -f "$tmpout" "$tmpfmt"
    return 1
}
echo -e "\n${PRI}"
echo -e "  ┌──────────────────────────────────────────────────────────┐"
echo -e "  │${SEC}           WP-CLI Profile Installer & Runner              ${PRI}│"
echo -e "  │${SEC}                 wp-profile-diag.sh                       ${PRI}│"
echo -e "  │${SEC}                   By Robyn × Claude AI                   ${PRI}│"
echo -e "  └──────────────────────────────────────────────────────────┘${RST}"
echo ""
printf "  ${BLD}%-20s${RST} %s\n" "Generated" "$(date -u '+%Y-%m-%d %H:%M:%S UTC')"
printf "  ${BLD}%-20s${RST} %s\n" "Site" "$SITE_URL"

# ── Preflight checks ──────────────────────────────────────────
section "1. PREFLIGHT CHECKS"

# PHP
if ! command -v php &>/dev/null; then
    die "php not found — cannot continue"
fi
PHP_VER=$(php -r 'echo PHP_VERSION;' 2>/dev/null || echo 'unknown')
good "PHP found: $PHP_VER"

# WP-CLI
if ! command -v wp &>/dev/null; then
    die "wp (WP-CLI) not found — install it first: https://wp-cli.org/#installing"
fi
WP_VER=$(wp --version 2>/dev/null | head -1 || echo 'unknown')
good "WP-CLI found: $WP_VER"


# ── Environment setup ─────────────────────────────────────────
section "2. ENVIRONMENT SETUP"

# COMPOSER_HOME and WP_CLI_PACKAGES_DIR already exported at top of script
row "COMPOSER_HOME"        "$COMPOSER_HOME"
row "WP_CLI_PACKAGES_DIR"  "$WP_CLI_PACKAGES_DIR"

# Create ~/.config if needed
if [[ ! -d "${HOME}/.config" ]]; then
    step "Creating ~/.config directory"
    mkdir -p "${HOME}/.config"
    good "Created ~/.config"
else
    good "~/.config already exists"
fi

# Persist env vars to ~/.profile if not already there
if ! grep -q 'COMPOSER_HOME' "${HOME}/.profile" 2>/dev/null; then
    step "Adding COMPOSER_HOME and WP_CLI_PACKAGES_DIR to ~/.profile"
    {
        echo ''
        echo '# Composer'
        echo 'export COMPOSER_HOME="$HOME/.config/composer"'
        echo ''
        echo '# WP-CLI packages'
        echo 'export WP_CLI_PACKAGES_DIR="$HOME/.config/wp-cli/packages"'
    } >> "${HOME}/.profile"
    good "Environment variables added to ~/.profile"
else
    good "Environment variables already in ~/.profile"
fi

# ── Composer ──────────────────────────────────────────────────
section "3. COMPOSER"

COMPOSER_BIN="${HOME}/.config/composer.phar"

if [[ -f "$COMPOSER_BIN" ]]; then
    COMPOSER_VER=$(php "$COMPOSER_BIN" --version 2>/dev/null | head -1 || echo 'unknown')
    good "Composer already installed: $COMPOSER_VER"
else
    step "Downloading Composer installer..."
    cd "${HOME}/.config"

    if ! php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" 2>/dev/null; then
        die "Failed to download Composer installer — check internet access"
    fi
    good "Installer downloaded"

    step "Running Composer installer..."
    if ! php composer-setup.php --quiet 2>/dev/null; then
        die "Composer installer failed"
    fi
    rm -f composer-setup.php

    # composer.phar lands in the current dir (~/.config)
    COMPOSER_BIN="${HOME}/.config/composer.phar"
    COMPOSER_VER=$(php "$COMPOSER_BIN" --version 2>/dev/null | head -1 || echo 'unknown')
    good "Composer installed: $COMPOSER_VER"

    # Return to original directory
    cd - >/dev/null
fi

# Convenience alias for this session
composer() { php "$COMPOSER_BIN" "$@"; }

# ── wp-cli/profile-command ────────────────────────────────────
section "4. WP-CLI PROFILE COMMAND"

# Check if already installed
if wp --no-color package list 2>/dev/null | grep -q 'wp-cli/profile-command'; then
    PROFILE_VER=$(wp --no-color package list 2>/dev/null | grep 'profile-command' | awk '{print $2}' || echo 'installed')
    good "wp-cli/profile-command already installed ($PROFILE_VER)"
else
    step "Installing wp-cli/profile-command..."
    note "This may take a minute..."
    if ! wp package install wp-cli/profile-command 2>&1; then
        bad "Failed to install wp-cli/profile-command"
        note "Try manually: wp package install wp-cli/profile-command"
        note "Or check: wp package list"
        exit 1
    fi
    good "wp-cli/profile-command installed successfully"
fi

# Verify the profile command is available
if ! wp --no-color help profile &>/dev/null 2>&1; then
    die "wp profile command not available after install — try opening a new shell session and re-running"
fi
good "wp profile command is available"

# Initialise analysis vars — populated by wp_profile() calls below
STAGE_DATA=""; STAGE_COUNT=0; TOTAL_CALLBACKS=0
SPOTLIGHT_DATA=""; SPOTLIGHT_COUNT=0
WP_PROFILE_LAST=""

# ── Run profile ───────────────────────────────────────────────
section "5. WP PROFILE — STAGE BREAKDOWN"

note "Profiling WordPress load stages (this makes a real request)..."
note "Stages: bootstrap → main_query → template"
echo ""

if wp_profile profile stage --orderby=time; then
    STAGE_DATA="$WP_PROFILE_LAST"
    STAGE_COUNT=$(( $(echo "$STAGE_DATA" | grep -c '^| ' || true) - 2 ))
    # Sum the callback invocations (runs/callback_count column) across all stages
    TOTAL_CALLBACKS=$(echo "$STAGE_DATA" | awk -F'|' '
        /^\| / && !hdr {
            hdr = 1
            for (i = 2; i <= NF-1; i++) {
                v = $i; gsub(/^ +| +$/, "", v)
                if (v == "runs" || v == "callback_count") cb_col = i
            }
            next
        }
        cb_col && /^\| / {
            v = $2; gsub(/^ +| +$/, "", v)
            if (v ~ /^(bootstrap|main_query|template)$/) {
                n = $cb_col; gsub(/ /, "", n)
                if (n ~ /^[0-9]+$/) total += n
            }
        }
        END { print total+0 }
    ' || echo 0)
    echo ""
    note "Total stages shown: ${STAGE_COUNT}"
    [[ "${TOTAL_CALLBACKS:-0}" -gt 0 ]] && note "Total callback invocations across stages: ${TOTAL_CALLBACKS}"
else
    warn "wp profile stage failed — site may not be reachable via loopback"
    note "Ensure the site URL is correct in wp-config.php / WP settings"
    note "Some managed hosts block loopback requests"
fi

# ── Per-stage drill-downs ─────────────────────────────────────
section "6. WP PROFILE — STAGE DRILL-DOWN: BOOTSTRAP"

note "All hooks within the bootstrap stage — plugin init, autoloaders, CPTs, taxonomies..."
echo ""

if wp_profile profile stage bootstrap --orderby=time; then
    echo ""
    note "Total hooks shown: $(( $(echo "$WP_PROFILE_LAST" | grep -c '^| ' || true) - 2 ))"
else
    warn "wp profile stage bootstrap failed"
fi

section "7. WP PROFILE — STAGE DRILL-DOWN: MAIN QUERY"

note "All hooks within the main_query stage — query vars, post retrieval, template selection..."
echo ""

if wp_profile profile stage main_query --orderby=time; then
    echo ""
    note "Total hooks shown: $(( $(echo "$WP_PROFILE_LAST" | grep -c '^| ' || true) - 2 ))"
else
    warn "wp profile stage main_query failed"
fi

section "8. WP PROFILE — STAGE DRILL-DOWN: TEMPLATE"

note "All hooks within the template stage — rendering, shortcodes, widgets, footer..."
echo ""

if wp_profile profile stage template --orderby=time; then
    echo ""
    note "Total hooks shown: $(( $(echo "$WP_PROFILE_LAST" | grep -c '^| ' || true) - 2 ))"
else
    warn "wp profile stage template failed"
fi

# ── Spotlight sections ────────────────────────────────────────
section "9. WP PROFILE — SPOTLIGHT: BOOTSTRAP HOOKS (≥1ms)"

note "Slow hooks (≥1ms) within the bootstrap stage only..."
echo ""

if wp_profile profile hook --spotlight --orderby=time; then
    echo ""
    note "Slow hooks shown: $(( $(echo "$WP_PROFILE_LAST" | grep -c '^| ' || true) - 2 ))"
else
    warn "wp profile hook --spotlight failed"
fi

section "10. WP PROFILE — SPOTLIGHT: ALL STAGES (≥1ms)"

note "Slow hooks (≥1ms) across all WordPress load stages — the most actionable view..."
echo ""

if wp_profile profile hook --all --spotlight --orderby=time; then
    SPOTLIGHT_DATA="$WP_PROFILE_LAST"
    SPOTLIGHT_COUNT=$(echo "$SPOTLIGHT_DATA" | grep -c '^| ' || true)
    echo ""
    note "Slow hooks shown: $(( ${SPOTLIGHT_COUNT} - 2 ))"
else
    warn "wp profile hook --all --spotlight failed"
fi

# ── Specific hook spotlight sections ──────────────────────────
# profile_hook_spotlight <section_num> <hook_name> <description>
profile_hook_spotlight() {
    local secnum="$1" hookname="$2" hooknote="$3"
    section "${secnum}. WP PROFILE — HOOK: ${hookname}"
    note "$hooknote"
    echo ""
    if wp_profile profile hook "$hookname" --spotlight --orderby=time; then
        local hcount
        hcount=$(echo "$WP_PROFILE_LAST" | grep -c '^| ' || true)
        echo ""
        if [[ "${hcount:-0}" -le 2 ]]; then
            note "No callbacks on '${hookname}' exceeded 1ms"
        else
            note "Slow callbacks shown: $(( hcount - 2 ))"
        fi
    else
        warn "wp profile hook ${hookname} --spotlight failed"
    fi
}

profile_hook_spotlight 11 "init"             "Fires on every request — plugins register CPTs, taxonomies, REST routes, shortcodes here"
profile_hook_spotlight 12 "plugins_loaded"   "Plugin bootstrap — runs immediately after all plugins are loaded"
profile_hook_spotlight 13 "rest_api_init"    "REST route registration — can be slow even on frontend requests"
profile_hook_spotlight 14 "wp_enqueue_scripts" "Script and style registration — slow here indicates unnecessary asset loading"
profile_hook_spotlight 15 "wp_head"          "Head output — meta tags, inline scripts, dequeued assets added by plugins"
profile_hook_spotlight 16 "wp_footer"        "Footer output — deferred scripts, analytics, chat widgets, etc."
profile_hook_spotlight 17 "pre_get_posts"    "Query modification — common source of N+1 queries and slow custom queries"

# ── Summary ───────────────────────────────────────────────────
section "SUMMARY & RECOMMENDATIONS"

# ── Analysis ──────────────────────────────────────────────────
WINS=()
ISSUES=()

# Parse stage times — columns: | stage | time | ... |
# Extract time value (2nd pipe-delimited field) for each named stage
parse_stage_time() {
    local stage="$1" output="$2"
    # Find the 'time' column by name from the header row, then extract for the named stage.
    echo "$output" | awk -F'|' -v stg="$stage" '
        /^\| / && !hdr {
            hdr = 1
            for (i = 2; i <= NF-1; i++) {
                v = $i; gsub(/^ +| +$/, "", v)
                if (v == "time") time_col = i
            }
            next
        }
        time_col && /^\| / {
            v = $2; gsub(/^ +| +$/, "", v)
            if (v == stg) { val = $time_col; gsub(/ /, "", val); print val; exit }
        }
    '
}

if [[ -n "${STAGE_DATA:-}" ]]; then
    BOOTSTRAP_T=$(parse_stage_time "bootstrap"  "$STAGE_DATA")
    MAINQUERY_T=$(parse_stage_time "main_query" "$STAGE_DATA")
    TEMPLATE_T=$(parse_stage_time  "template"   "$STAGE_DATA")

    # Flag stages over 0.5s as slow, over 0.2s as a warning
    check_stage() {
        local name="$1" val="$2"
        if [[ -n "$val" ]]; then
            # fmt_cell may have added a units suffix ("ms" or "s"); strip it for numeric comparison.
            # ms values are converted back to seconds so thresholds remain consistent.
            local num="${val%ms}"; num="${num%s}"
            [[ "$val" == *ms ]] && num=$(awk "BEGIN{printf \"%.6f\", $num/1000}")
            local display="$val"
            [[ "$val" != *ms && "$val" != *s ]] && display="${val}s"
            if awk "BEGIN{exit !($num > 0.5)}"; then
                ISSUES+=("$name stage is slow (${display}) — investigate callbacks in this stage")
            elif awk "BEGIN{exit !($num > 0.2)}"; then
                ISSUES+=("$name stage took ${display} — worth reviewing")
            else
                WINS+=("$name stage looks healthy (${display})")
            fi
        fi
    }
    check_stage "bootstrap"  "$BOOTSTRAP_T"
    check_stage "main_query" "$MAINQUERY_T"
    check_stage "template"   "$TEMPLATE_T"
fi

# Count spotlight hooks (slow hooks ≥1ms) — subtract 2 for the header and Total rows
if [[ -n "${SPOTLIGHT_DATA:-}" ]]; then
    SLOW_COUNT=$(( $(echo "$SPOTLIGHT_DATA" | grep -c '^| ' || true) - 2 ))
    if [[ "$SLOW_COUNT" -le 0 ]]; then
        WINS+=("No hooks exceeded 1ms — excellent hook performance")
    elif [[ "$SLOW_COUNT" -le 5 ]]; then
        WINS+=("Only ${SLOW_COUNT} hook(s) exceeded 1ms — generally healthy")
    elif [[ "$SLOW_COUNT" -le 15 ]]; then
        ISSUES+=("${SLOW_COUNT} hooks exceeded 1ms — review spotlight output above")
    else
        ISSUES+=("${SLOW_COUNT} hooks exceeded 1ms — significant hook overhead detected")
    fi

    # Surface the single slowest hook by name from the first data row (skip header)
    SLOWEST_HOOK=$(echo "$SPOTLIGHT_DATA" | awk -F'|' '
        /^\| / && !hdr { hdr=1; next }
        /^\| / { v=$2; gsub(/^ +| +$/, "", v); if (v != "") { print v; exit } }
    ')
    SLOWEST_TIME=$(echo "$SPOTLIGHT_DATA" | awk -F'|' '
        /^\| / && !hdr {
            hdr=1
            for (i=2; i<=NF-1; i++) { v=$i; gsub(/^ +| +$/,"",v); if (v=="time") col=i }
            next
        }
        col && /^\| / { v=$col; gsub(/ /,"",v); if (v!="") { print v; exit } }
    ')
    if [[ -n "$SLOWEST_HOOK" && -n "$SLOWEST_TIME" ]]; then
        ISSUES+=("Slowest hook: '$SLOWEST_HOOK' at ${SLOWEST_TIME} — investigate callbacks on this hook")
    fi
fi

# Total callback invocations across all stages (from stage breakdown)
if [[ "${TOTAL_CALLBACKS:-0}" -gt 0 ]]; then
    if [[ "$TOTAL_CALLBACKS" -gt 150000 ]]; then
        ISSUES+=("High total callback invocations (${TOTAL_CALLBACKS}) — may indicate plugin or theme bloat")
    elif [[ "$TOTAL_CALLBACKS" -gt 75000 ]]; then
        ISSUES+=("Elevated callback count (${TOTAL_CALLBACKS} total invocations) — worth reviewing")
    else
        WINS+=("Callback count looks reasonable (${TOTAL_CALLBACKS} total invocations)")
    fi
fi

# Output positives
if [[ ${#WINS[@]} -gt 0 ]]; then
    echo -e "  ${GRN}${BLD}✓ Positives:${RST}"
    for w in "${WINS[@]}"; do
        echo -e "  ${GRN}✓ $w${RST}"
    done
    echo ""
fi

# Output issues
if [[ ${#ISSUES[@]} -gt 0 ]]; then
    echo -e "  ${YLW}${BLD}⚠ Issues/Recommendations:${RST}"
    for i in "${ISSUES[@]}"; do
        echo -e "  ${YLW}⚠ $i${RST}"
    done
    echo ""
fi

echo -e "  ${BLD}What to look for in the output above:${RST}"
echo ""
echo "  Stage breakdown (Section 5):"
echo "    • High 'bootstrap' time → slow plugin loading, no OPcache"
echo "    • High 'main_query' time → slow DB queries, unoptimised WP_Query"
echo "    • High 'template' time → heavy theme, slow shortcodes/blocks"
echo ""
echo "  Hook breakdown (Sections 6–8):"
echo "    • Hooks with high 'time' and many 'cb' (callbacks) are worth investigating"
echo "    • Note the source — plugin name usually appears in the callback or hook name"
echo "    • 'wp_head' and 'wp_footer' are expected to be busy; look for surprises"
echo ""
echo "  Spotlight (Section 7):"
echo "    • These are your actionable items — hooks taking ≥1ms of real time"
echo "    • Cross-reference with Section 4 of wp-perf-diag.php (plugins list)"
# ── Save report ───────────────────────────────────────────────
sleep 1; kill "$TAIL_PID" 2>/dev/null || true; wait "$TAIL_PID" 2>/dev/null || true
# Restore both stdout and stderr to the terminal so python errors are visible
exec 1>&3 2>&3 3>&-

# Write the processing script to its own temp file — avoids stdin/heredoc
# ambiguity that exists when stdout/stderr have been redirected mid-script
_PY=$(mktemp)
cat > "$_PY" <<'PYEOF'
import sys, re

with open(sys.argv[1], 'r', encoding='utf-8', errors='replace') as f:
    content = f.read()

# Strip ANSI escape codes
content = re.sub(r'\033\[[0-9;]*m', '', content)

# Replace box-drawing and symbol characters with ASCII equivalents
replacements = {
    '┌': '+', '─': '-', '┐': '+',
    '└': '+', '┘': '+', '│': '|',
    '—': '--', '–': '-',
    '→': '->',
    '…': '...',
    '×': 'x',
    '↳': '>',
    '⚠': '!',
    '✓': '+',
    '✗': 'x',
    '•': '*',
}
for char, replacement in replacements.items():
    content = content.replace(char, replacement)

with open(sys.argv[2], 'w', encoding='utf-8') as f:
    f.write(content)
PYEOF

if [[ -n "$_PYTHON" ]] && "$_PYTHON" "$_PY" "$REPORT_TMPFILE" "$REPORT_FILENAME"; then
    rm -f "$REPORT_TMPFILE" "$_PY"
    printf "\033[3;38;2;136;146;160m  Report saved: %s\033[0m\n" "$REPORT_FILENAME"
else
    rm -f "$_PY"
    # Fallback: sed-based ANSI stripping (Unicode symbols not translated)
    sed 's/\x1b\[[0-9;]*m//g' "$REPORT_TMPFILE" > "$REPORT_FILENAME" 2>/dev/null || true
    rm -f "$REPORT_TMPFILE"
    if [[ -s "$REPORT_FILENAME" ]]; then
        printf "\033[3;38;2;136;146;160m  Report saved (basic): %s\033[0m\n" "$REPORT_FILENAME"
    else
        printf "\033[33m  Could not write report to: %s\033[0m\n" "$REPORT_FILENAME"
    fi
fi
