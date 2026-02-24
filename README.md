# WP Performance Diagnostics

Three complementary, **read-only** tools for diagnosing WordPress performance issues.
Zero configuration. Zero destructive operations. Safe to run on live production sites.

---

## Tools

| Script | Where it runs | What it checks |
|---|---|---|
| `wp-perf-diag.php` | **Server-side** (WP-CLI or web) | DB, object cache, plugins, hooks, queries, cron, assets, wp-config constants |
| `wp-frontend-diag.sh` | **Your Mac** (Bash) | DNS, SSL, TTFB, cache headers, Core Web Vitals, asset audit, security headers |
| `wp-profile-diag.sh` | **Server** (WP-CLI, SSH/SFTP) | WordPress load stage timing, hook-level profiling, spotlight on slow hooks |

Use **all three together** for a complete picture — server internals, external frontend behaviour, and granular hook-level timing.

---

## `wp-perf-diag.php` — Server-Side PHP

### Usage

**Recommended: WP-CLI** (no credentials needed, most data)
```bash
wp eval-file wp-perf-diag.php
```

**Via temporary web access**
Upload to WordPress root, visit with secret param, delete immediately after:
```
https://example.com/wp-perf-diag.php?secret=wp-diag-2025
```
Change the default secret by setting the env var `WP_DIAG_SECRET`.

### What it checks

| Section | Details |
|---|---|
| **Environment** | PHP version, OPcache status/hit rate, WP/PHP memory limits, debug flags, active theme |
| **Database** | MySQL version, table sizes, autoloaded options size (with breakdown if large), transient counts, expired transients, post revisions, trash, spam |
| **Object Cache** | External cache detection (Redis/Memcached/Batcache), cache type, WP_CACHE constant, live set/get round-trip test |
| **Plugins** | Count, all active plugins with versions, flags known heavy/cache/debug plugins |
| **WP Queries & Hooks** | Query count and total DB time (if SAVEQUERIES on), slowest 5 queries, registered hooks and total callback count |
| **WP-Cron** | DISABLE_WP_CRON status, scheduled event count, overdue timestamps |
| **Assets** | Registered/enqueued script and style counts |
| **HTTP Self-Check** | Makes two HTTP requests to the homepage — reports TTFB, all cache headers (Batcache, Cloudflare, x-cache, age, etc.), and cache speedup between cold/warm |
| **wp-config constants** | Audits key performance/debug constants with expected values |
| **Filesystem** | Writability, disk free space, upload folder structure |
| **Outbound HTTP** | Tests connectivity to api.wordpress.org, loopback health |
| **Summary** | Consolidated issues and recommendations |

### Enabling detailed query logging

Add to `wp-config.php` temporarily (remove after diagnosis):
```php
define('SAVEQUERIES', true);
```

---

## `wp-frontend-diag.sh` — External Bash (Mac)

Runs entirely from your local machine — no server access needed. Checks everything visible from the outside.

### Requirements

- `curl` (always present on Mac)
- `dig` — `brew install bind`
- `openssl` (present on Mac)
- `python3` (present on Mac) — used for stats and PageSpeed parsing

### PageSpeed API key (optional)

The script uses the Google PageSpeed Insights API. Without a key the free tier applies (limited daily requests). To use your own key, create a file called `pagespeed-api-key.txt` in the same directory as the script containing just the key:

```
AIza...yourkey...
```

The file is excluded from Git via `.gitignore`.

### Usage

```bash
# Make executable first
chmod +x wp-frontend-diag.sh

# Basic run
./wp-frontend-diag.sh https://example.com

# More TTFB samples (default: 3)
./wp-frontend-diag.sh https://example.com --repeat 5

# Show individual sample values
./wp-frontend-diag.sh https://example.com --verbose
```

The URL scheme is normalised automatically — `http://` is upgraded to `https://`, and bare domains like `example.com` have `https://` prepended.

A plain-text report is saved automatically to the script's directory as `wp-frontend-diag-YYYY-MM-DD-HHMMSS-domain.txt`.

### What it checks

| Section | Details |
|---|---|
| **DNS** | A/AAAA records, TTL, Cloudflare IP detection, MX, NS |
| **SSL/TLS** | Certificate issuer, expiry (with days remaining), TLS version, HSTS, HTTP→HTTPS redirect |
| **TTFB** | Multiple cold (cache-bypass) and warm samples, min/max/avg, cache speedup %, rating |
| **Request Waterfall** | curl timing breakdown: DNS → TCP → SSL → TTFB → total, download size/speed |
| **Response Headers** | Side-by-side cold vs warm for all cache-relevant headers: `x-nananana` (Batcache), `x-ac` (edge cache), `cf-cache-status`, `x-cache`, `age`, `cache-control`, `x-varnish`, `x-litespeed-cache`, `x-kinsta-cache`, and more |
| **Security Headers** | X-Frame-Options, X-Content-Type-Options, CSP, HSTS, Referrer-Policy, Permissions-Policy |
| **Core Web Vitals** | PageSpeed Insights API — Performance score, FCP, LCP, TBT, CLS, TTI, top opportunities |
| **Asset Analysis** | Script/stylesheet/image counts, render-blocking scripts in `<head>`, lazy loading, Google Fonts detection, third-party domain inventory |
| **Summary** | Positives and issues/recommendations including asset warnings, TTFB ratings, cache assessment |

---

## `wp-profile-diag.sh` — WP-CLI Hook Profiler (Server)

Installs the `wp-cli/profile-command` package if needed, then runs a full timing breakdown of the WordPress load. Run via SSH or SFTP from inside the site's root directory.

### Requirements

- `php` and `wp` (WP-CLI) available on the server
- Internet access from the server (to download Composer/profile-command on first run)
- A working loopback HTTP connection (required by `wp profile`)

### Usage

```bash
# From inside the site's htdocs / public_html directory:
bash wp-profile-diag.sh

# Or download and run in one step:
wget -q -O wp-profile-diag.sh "https://raw.githubusercontent.com/ItsRobyn/WP-Performance-Scripts/main/wp-profile-diag.sh?$(date +%s)" && bash wp-profile-diag.sh
```

Composer and the profile command are installed to `~/.config` — no system-wide changes. A plain-text report is saved to the current directory.

### What it checks

| Section | Details |
|---|---|
| **Preflight** | Confirms `php` and `wp` (WP-CLI) are available |
| **Environment Setup** | Configures `COMPOSER_HOME` and `WP_CLI_PACKAGES_DIR`, persists to `~/.profile` |
| **Composer** | Installs Composer to `~/.config/composer.phar` if not already present |
| **Profile Command** | Installs `wp-cli/profile-command` via WP-CLI package manager if not already installed |
| **Stage Breakdown** | Bootstrap → main_query → template timing — flags stages over 200ms / 500ms |
| **Hook Breakdown (bootstrap)** | All hooks fired during bootstrap, ordered by time |
| **Spotlight** | Hooks that took ≥1ms — easier to identify real bottlenecks |
| **Hook Breakdown (wp stage)** | Hooks fired during the main query / template stage |
| **Summary** | Positives and issues with timing thresholds and next-step recommendations |

---

## Reading the output

**Status badges in `wp-perf-diag.php`:**
- `[OK]` — within normal/expected range
- `[WARN]` — worth investigating
- `[BAD]` — likely a real problem
- `[INFO]` — informational, no action needed

**Colours in `wp-frontend-diag.sh` and `wp-profile-diag.sh`:**
- Green `✓` — good
- Yellow `⚠` — worth checking
- Red `✗` — problem detected
- Grey `↳` — informational note

---

## Common findings and fixes

| Finding | Fix |
|---|---|
| Autoloaded options >200KB | Use Query Monitor to identify culprits; many plugins cache data here unnecessarily |
| Expired transients not clearing | `wp transient delete --expired` via WP-CLI |
| SAVEQUERIES on in production | Remove from wp-config.php — it adds overhead to every request |
| High revision count | Set `define('WP_POST_REVISIONS', 5)` in wp-config.php |
| WP-Cron on HTTP requests | `define('DISABLE_WP_CRON', true)` + server cron: `*/5 * * * * curl -s https://example.com/wp-cron.php` |
| Render-blocking scripts | Add `async`/`defer` attributes, or use a caching plugin's asset optimisation |
| No page cache (cold=warm TTFB) | Verify Batcache or a page caching plugin is active and WP_CACHE is true |
| CF-Cache-Status: BYPASS | Check Cloudflare Page Rules / Cache Rules; cookies often cause bypass |
| Slow bootstrap stage | Use spotlight output to identify the hook — check for heavy plugin init code |
| Slow template stage | Often a slow DB query or a page builder rendering on every load |

---

## Safety

- **No writes**: All three tools are entirely read-only (except `wp-profile-diag.sh` which installs Composer and profile-command to `~/.config` on first run)
- **No plugin/theme changes**: Nothing is deactivated or modified
- **No setting changes**: wp-config.php is only read, never written
- **Live-site safe**: Uses `wp_remote_get()` for self-checks (goes through normal WP HTTP layer)
- **Temp file reminder**: If using web mode for the PHP script, delete it after use
