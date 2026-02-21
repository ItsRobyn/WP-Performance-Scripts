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

# ── Header ────────────────────────────────────────────────────
SITE_URL="$(wp option get siteurl --quiet 2>/dev/null || echo 'unknown')"
SITE_HOST="$(echo "$SITE_URL" | sed -E 's|https?://||' | cut -d/ -f1 | sed 's/:.*//')"
REPORT_FILENAME="wp-profile-diag-$(date -u '+%Y-%m-%d-%H%M%S')-${SITE_HOST}.txt"
REPORT_TMPFILE="$(mktemp)"
set +o pipefail
exec > >(tee -i "$REPORT_TMPFILE") 2>&1
set -o pipefail
echo -e "\n${PRI}"
echo -e "  ┌──────────────────────────────────────────────────────────┐"
echo -e "  │${SEC}           WP-CLI Profile Installer & Runner              ${PRI}│"
echo -e "  │${SEC}                 wp-profile-diag.sh                       ${PRI}│"
echo -e "  │${SEC}                  By Robyn × Claude AI                    ${PRI}│"
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

# Set up paths
COMPOSER_HOME="${HOME}/.config/composer"
WP_CLI_PACKAGES_DIR="${HOME}/.config/wp-cli/packages"

# Ensure the paths are exported for this session regardless of ~/.profile state
export COMPOSER_HOME
export WP_CLI_PACKAGES_DIR

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

# Initialise analysis vars — populated by capture runs after each display run
STAGE_DATA=""; HOOK_ALL_COUNT=0; SPOTLIGHT_DATA=""; HOOK_WP_COUNT=0

# ── Run profile ───────────────────────────────────────────────
section "5. WP PROFILE — STAGE BREAKDOWN"

note "Profiling WordPress load stages (this makes a real request)..."
note "Stages: bootstrap → main_query → template"
echo ""

# Run directly so wp-cli renders its own table formatting to the terminal
if wp --no-color profile stage --all --orderby=time; then
    # Capture separately (quiet) just for analysis — table chars intact
    STAGE_DATA=$(wp --no-color profile stage --all --orderby=time 2>/dev/null) || true
    STAGE_COUNT=$(echo "$STAGE_DATA" | grep -c '^| ' || true)
    echo ""
    note "Total stages shown: ${STAGE_COUNT}"
else
    warn "wp profile stage failed — site may not be reachable via loopback"
    note "Ensure the site URL is correct in wp-config.php / WP settings"
    note "Some managed hosts block loopback requests"
fi

# ── Hook-level breakdown ──────────────────────────────────────
section "6. WP PROFILE — HOOK BREAKDOWN (bootstrap stage)"

note "Profiling all hooks during bootstrap — shows which hooks consume the most time..."
echo ""

if wp --no-color profile hook --all --orderby=time; then
    HOOK_ALL_DATA=$(wp --no-color profile hook --all --orderby=time 2>/dev/null) || true
    HOOK_ALL_COUNT=$(echo "$HOOK_ALL_DATA" | grep -c '^| ' || true)
    echo ""
    note "Total hooks shown: ${HOOK_ALL_COUNT}"
else
    warn "wp profile hook failed"
    note "This requires a working loopback HTTP connection"
fi

# ── Spotlight — slow hooks only ───────────────────────────────
section "7. WP PROFILE — SPOTLIGHT (slowest hooks ≥1ms)"

note "Filtering to hooks that took 1ms or more — easier to spot real bottlenecks..."
echo ""

if wp --no-color profile hook --all --spotlight --orderby=time; then
    SPOTLIGHT_DATA=$(wp --no-color profile hook --all --spotlight --orderby=time 2>/dev/null) || true
    SPOTLIGHT_COUNT=$(echo "$SPOTLIGHT_DATA" | grep -c '^| ' || true)
    echo ""
    note "Total slow hooks shown: ${SPOTLIGHT_COUNT}"
else
    warn "wp profile hook --spotlight failed"
fi

# ── Hook breakdown for wp (main query) stage ─────────────────
section "8. WP PROFILE — HOOK BREAKDOWN (wp stage / main query)"

note "Profiling hooks during the main query stage..."
echo ""

if wp --no-color profile hook wp --orderby=time; then
    HOOK_WP_DATA=$(wp --no-color profile hook wp --orderby=time 2>/dev/null) || true
    HOOK_WP_COUNT=$(echo "$HOOK_WP_DATA" | grep -c '^| ' || true)
    echo ""
    note "Total hooks shown: ${HOOK_WP_COUNT}"
else
    warn "wp profile hook wp failed"
    note "The 'wp' stage runs after query vars are set — usually where template logic fires"
fi

# ── Summary ───────────────────────────────────────────────────
section "SUMMARY & NEXT STEPS"

# ── Analysis ──────────────────────────────────────────────────
WINS=()
ISSUES=()

# Parse stage times — columns: | stage | time | ... |
# Extract time value (2nd pipe-delimited field) for each named stage
parse_stage_time() {
    local stage="$1" output="$2"
    echo "$output" | grep "| $stage " | awk -F'|' '{gsub(/ /,"",$3); print $3}' | head -1
}

if [[ -n "${STAGE_DATA:-}" ]]; then
    BOOTSTRAP_T=$(parse_stage_time "bootstrap"  "$STAGE_DATA")
    MAINQUERY_T=$(parse_stage_time "main_query" "$STAGE_DATA")
    TEMPLATE_T=$(parse_stage_time  "template"   "$STAGE_DATA")

    # Flag stages over 0.5s as slow, over 0.2s as a warning
    check_stage() {
        local name="$1" val="$2"
        if [[ -n "$val" ]]; then
            # Compare using awk since bash can't do float comparisons
            if awk "BEGIN{exit !($val > 0.5)}"; then
                ISSUES+=("$name stage is slow (${val}s) — investigate callbacks in this stage")
            elif awk "BEGIN{exit !($val > 0.2)}"; then
                ISSUES+=("$name stage took ${val}s — worth reviewing")
            else
                WINS+=("$name stage looks healthy (${val}s)")
            fi
        fi
    }
    check_stage "bootstrap"  "$BOOTSTRAP_T"
    check_stage "main_query" "$MAINQUERY_T"
    check_stage "template"   "$TEMPLATE_T"
fi

# Count spotlight hooks (slow hooks ≥1ms) — fewer is better
if [[ -n "${SPOTLIGHT_DATA:-}" ]]; then
    SLOW_COUNT=$(echo "$SPOTLIGHT_DATA" | grep -c '^| ' || true)
    if [[ "$SLOW_COUNT" -eq 0 ]]; then
        WINS+=("No hooks exceeded 1ms — excellent hook performance")
    elif [[ "$SLOW_COUNT" -le 5 ]]; then
        WINS+=("Only ${SLOW_COUNT} hook(s) exceeded 1ms — generally healthy")
    elif [[ "$SLOW_COUNT" -le 15 ]]; then
        ISSUES+=("${SLOW_COUNT} hooks exceeded 1ms — review spotlight output above")
    else
        ISSUES+=("${SLOW_COUNT} hooks exceeded 1ms — significant hook overhead detected")
    fi

    # Surface the single slowest hook by name
    SLOWEST_HOOK=$(echo "$SPOTLIGHT_DATA" | grep '^| ' | head -1 | awk -F'|' '{gsub(/^ +| +$/,"",$2); print $2}')
    SLOWEST_TIME=$(echo "$SPOTLIGHT_DATA" | grep '^| ' | head -1 | awk -F'|' '{gsub(/ /,"",$3); print $3}')
    if [[ -n "$SLOWEST_HOOK" && -n "$SLOWEST_TIME" ]]; then
        ISSUES+=("Slowest hook: '$SLOWEST_HOOK' at ${SLOWEST_TIME}s — investigate callbacks on this hook")
    fi
fi

# Hook count — very high total hook count can indicate bloat
if [[ -n "${HOOK_ALL_COUNT:-}" ]]; then
    if [[ "$HOOK_ALL_COUNT" -gt 200 ]]; then
        ISSUES+=("High hook count (${HOOK_ALL_COUNT}) — may indicate plugin or theme bloat")
    elif [[ "$HOOK_ALL_COUNT" -gt 100 ]]; then
        ISSUES+=("Elevated hook count (${HOOK_ALL_COUNT}) — worth noting")
    else
        WINS+=("Hook count looks reasonable (${HOOK_ALL_COUNT})")
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
echo ""
echo -e "  ${BLD}Useful follow-up commands:${RST}"
echo ""
echo "    # Profile a specific hook in detail:"
echo "    wp --no-color profile hook init --orderby=time"
echo ""
echo "    # Profile with a specific URL (useful for page-specific slowness):"
echo "    wp --no-color profile stage --all --url=https://example.com/slow-page/"
echo ""
echo "    # Profile the template stage specifically:"
echo "    wp --no-color profile hook template_redirect --orderby=time"
echo ""
row "Completed at" "$(date '+%Y-%m-%d %H:%M:%S %Z')"
echo ""

# ── Save report ───────────────────────────────────────────────
sleep 0.2
sed 's/\x1b\[[0-9;]*m//g' "$REPORT_TMPFILE" > "$REPORT_FILENAME"
rm -f "$REPORT_TMPFILE"
printf "\033[3;38;2;136;146;160m  Report saved: %s\033[0m\n" "$REPORT_FILENAME" > /dev/tty
