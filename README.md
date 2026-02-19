# WP Performance Diagnostics

Two complementary, **read-only** tools for diagnosing WordPress performance issues.  
Zero configuration. Zero destructive operations. Safe to run on live production sites.

---

## Tools

| Script | Where it runs | What it checks |
|---|---|---|
| `wp-perf-diag.php` | **Server-side** (WP-CLI or web) | DB, object cache, plugins, hooks, queries, cron, assets, wp-config constants |
| `wp-perf-check.sh` | **Your Mac** (Bash) | DNS, SSL, TTFB, cache headers, Core Web Vitals, asset audit, security headers |

Use **both together** for a complete picture.

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

## `wp-perf-check.sh` — External Bash (Mac)

### Requirements

- `curl` (always present on Mac)
- `dig` — `brew install bind`
- `openssl` (present on Mac)
- `python3` (present on Mac) — used for stats and PageSpeed parsing
- `jq` — optional, `brew install jq`

### Usage

```bash
# Make executable first
chmod +x wp-perf-check.sh

# Basic run
./wp-perf-check.sh https://example.com

# More TTFB samples (default: 3)
./wp-perf-check.sh https://example.com --repeat 5

# Show individual sample values
./wp-perf-check.sh https://example.com --verbose
```

### What it checks

| Section | Details |
|---|---|
| **DNS** | A/AAAA records, TTL, Cloudflare IP detection, MX, NS |
| **SSL/TLS** | Certificate issuer, expiry (with days remaining), TLS version, HSTS, HTTP→HTTPS redirect |
| **TTFB** | Multiple cold (cache-bypass) and warm samples, min/max/avg, cache speedup %, rating |
| **Request Waterfall** | curl timing breakdown: DNS → TCP → SSL → TTFB → total, download size/speed |
| **Response Headers** | Side-by-side cold vs warm for all cache-relevant headers: `x-batcache`, `cf-cache-status`, `x-cache`, `age`, `cache-control`, `x-varnish`, `x-litespeed-cache`, `x-kinsta-cache`, and more |
| **WordPress Detection** | WP version in meta (security), wp-content confirmed, REST API status, xmlrpc.php, readme.html, login page accessibility |
| **Core Web Vitals** | PageSpeed Insights API (no key required) — Performance score, FCP, LCP, TBT, CLS, TTI, top opportunities |
| **Asset Analysis** | Script/stylesheet/image counts, render-blocking scripts in `<head>`, lazy loading usage, Google Fonts detection, third-party domain inventory |
| **Security Headers** | X-Frame-Options, X-Content-Type-Options, CSP, HSTS, Referrer-Policy, Permissions-Policy |
| **Summary** | Consolidated TTFB ratings, cache assessment, next-step recommendations |

---

## Reading the output

**Status badges in `wp-perf-diag.php`:**
- `[OK]` — within normal/expected range
- `[WARN]` — worth investigating
- `[BAD]` — likely a real problem
- `[INFO]` — informational, no action needed

**Colours in `wp-perf-check.sh`:**
- Green — good
- Yellow — worth checking
- Red — problem detected

---

## Common findings and fixes

| Finding | Fix |
|---|---|
| No external object cache | Install Redis or Memcached with a WP drop-in |
| Autoloaded options >200KB | Use Query Monitor to identify culprits; many plugins cache data here unnecessarily |
| Expired transients not clearing | `wp transient delete --expired` via WP-CLI |
| SAVEQUERIES on in production | Remove from wp-config.php — it adds overhead to every request |
| High revision count | Set `define('WP_POST_REVISIONS', 5)` in wp-config.php |
| WP-Cron on HTTP requests | `define('DISABLE_WP_CRON', true)` + server cron: `*/5 * * * * curl -s https://example.com/wp-cron.php` |
| Render-blocking scripts | Add `async`/`defer` attributes, or use a caching plugin's asset optimisation |
| No page cache (cold=warm TTFB) | Verify Batcache or a page caching plugin is active and WP_CACHE is true |
| CF-Cache-Status: BYPASS | Check Cloudflare Page Rules / Cache Rules; cookies often cause bypass |

---

## Safety

- **No writes**: Both tools are entirely read-only
- **No plugin/theme changes**: Nothing is deactivated or modified
- **No setting changes**: wp-config.php is only read, never written
- **Live-site safe**: Uses `wp_remote_get()` for self-checks (goes through normal WP HTTP layer)
- **Temp file reminder**: If using web mode for the PHP script, delete it after use
