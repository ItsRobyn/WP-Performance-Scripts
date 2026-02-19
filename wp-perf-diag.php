<?php
/**
 * WP Performance Diagnostics
 * ============================================================
 * Drop-in, read-only WordPress performance diagnostic script.
 * Run via WP-CLI: wp eval-file wp-perf-diag.php
 * Or as a temporary web-accessible file (remove after use).
 *
 * SAFE: Makes zero changes to the site. No plugin/theme
 * deactivations, no option writes, no DB mutations.
 * ============================================================
 */

// ── Bootstrap ────────────────────────────────────────────────
define('WP_PERF_DIAG_START', microtime(true));

// WP_CLI may not be defined yet when using `wp eval-file`; check SAPI first
$is_cli = (php_sapi_name() === 'cli');
// Also check WP_CLI in case it gets defined after bootstrap
if (!$is_cli && defined('WP_CLI') && WP_CLI) {
    $is_cli = true;
}

if (!$is_cli) {
    // Basic security: only allow if accessed with a secret param
    // Set ?secret=YOUR_SECRET in the URL, or remove this block if
    // you trust your network.
    $expected_secret = getenv('WP_DIAG_SECRET') ?: 'wp-diag-2025';
    if (($_GET['secret'] ?? '') !== $expected_secret) {
        http_response_code(403);
        die('Forbidden. Add ?secret=wp-diag-2025 to the URL (or set WP_DIAG_SECRET env var).');
    }
}

// Load WordPress if running standalone (not via WP-CLI)
if (!defined('ABSPATH')) {
    $wp_load = __DIR__ . '/wp-load.php';
    if (!file_exists($wp_load)) {
        // Try walking up directories to find wp-load.php
        $dir = __DIR__;
        for ($i = 0; $i < 5; $i++) {
            $dir = dirname($dir);
            if (file_exists($dir . '/wp-load.php')) {
                $wp_load = $dir . '/wp-load.php';
                break;
            }
        }
    }
    if (!file_exists($wp_load)) {
        die("Could not find wp-load.php. Place this script in your WordPress root.\n");
    }
    require_once $wp_load;
}

// ── Output helpers ────────────────────────────────────────────
$out = [];

function section(string $title): void {
    global $out, $is_cli;
    $bar = str_repeat('─', 60);
    $out[] = '';
    $out[] = $is_cli ? "\033[1;36m$bar\033[0m" : $bar;
    $out[] = $is_cli ? "\033[1;33m  $title\033[0m" : "  $title";
    $out[] = $is_cli ? "\033[1;36m$bar\033[0m" : $bar;
}

function row(string $label, $value, string $status = ''): void {
    global $out, $is_cli;
    $statusColors = ['OK' => '32', 'WARN' => '33', 'BAD' => '31', 'INFO' => '36', '' => '0'];
    $color = $statusColors[$status] ?? '0';
    $badge = $status ? "[$status]" : '';
    if ($is_cli && $status) {
        $badge = "\033[{$color}m$badge\033[0m";
    }
    $line = sprintf("  %-35s %s %s", $label, $value, $badge);
    $out[] = $line;
}

function note(string $msg): void {
    global $out, $is_cli;
    $out[] = $is_cli ? "  \033[2m↳ $msg\033[0m" : "    ↳ $msg";
}

function warn(string $msg): void {
    global $out, $is_cli;
    $out[] = $is_cli ? "  \033[33m⚠ $msg\033[0m" : "  ⚠ $msg";
}

function good(string $msg): void {
    global $out, $is_cli;
    $out[] = $is_cli ? "  \033[32m✓ $msg\033[0m" : "  ✓ $msg";
}

function bad(string $msg): void {
    global $out, $is_cli;
    $out[] = $is_cli ? "  \033[31m✗ $msg\033[0m" : "  ✗ $msg";
}

function bytes(int $b): string {
    if ($b >= 1073741824) return round($b / 1073741824, 2) . ' GB';
    if ($b >= 1048576)    return round($b / 1048576, 2)    . ' MB';
    if ($b >= 1024)       return round($b / 1024, 2)       . ' KB';
    return $b . ' B';
}

function ms(float $seconds): string {
    return round($seconds * 1000, 2) . 'ms';
}

// ─────────────────────────────────────────────────────────────
// 1. ENVIRONMENT
// ─────────────────────────────────────────────────────────────
section('1. ENVIRONMENT');

row('PHP Version',        PHP_VERSION,           version_compare(PHP_VERSION, '8.0', '>=') ? 'OK' : 'WARN');
row('PHP SAPI',           php_sapi_name());
row('WordPress Version',  get_bloginfo('version'));
row('WP Multisite',       is_multisite() ? 'Yes' : 'No');
row('WP Debug',           defined('WP_DEBUG') && WP_DEBUG ? 'ON' : 'off', defined('WP_DEBUG') && WP_DEBUG ? 'WARN' : 'OK');
row('WP Debug Log',       defined('WP_DEBUG_LOG') && WP_DEBUG_LOG ? 'ON' : 'off');
row('Script Debug',       defined('SCRIPT_DEBUG') && SCRIPT_DEBUG ? 'ON' : 'off');
row('WP Memory Limit',    WP_MEMORY_LIMIT);
row('WP Max Memory Limit', defined('WP_MAX_MEMORY_LIMIT') ? WP_MAX_MEMORY_LIMIT : 'not set');
row('PHP memory_limit',   ini_get('memory_limit'));
row('PHP max_execution_time', ini_get('max_execution_time') . 's');
row('PHP upload_max_filesize', ini_get('upload_max_filesize'));
row('PHP post_max_size',  ini_get('post_max_size'));
row('OPcache enabled',    function_exists('opcache_get_status') && @opcache_get_status() !== false ? 'Yes' : 'No',
    function_exists('opcache_get_status') && @opcache_get_status() !== false ? 'OK' : 'WARN');

if (function_exists('opcache_get_status')) {
    $op = @opcache_get_status(false);
    if ($op) {
        $used  = $op['memory_usage']['used_memory'] ?? 0;
        $free  = $op['memory_usage']['free_memory'] ?? 0;
        $total = $used + $free;
        $pct   = $total ? round($used / $total * 100) : 0;
        row('OPcache memory usage', bytes($used) . ' / ' . bytes($total) . " ($pct%)", $pct > 90 ? 'WARN' : 'OK');
        row('OPcache cached scripts', $op['opcache_statistics']['num_cached_scripts'] ?? 'n/a');
        row('OPcache hit rate',
            isset($op['opcache_statistics']['opcache_hit_rate'])
                ? round($op['opcache_statistics']['opcache_hit_rate'], 2) . '%'
                : 'n/a',
            ($op['opcache_statistics']['opcache_hit_rate'] ?? 100) < 80 ? 'WARN' : 'OK');
    }
}

row('Server Software', $_SERVER['SERVER_SOFTWARE'] ?? 'unknown');
row('Site URL',        get_site_url());
row('Home URL',        get_home_url());
row('Active Theme',    wp_get_theme()->get('Name') . ' v' . wp_get_theme()->get('Version'));

// ─────────────────────────────────────────────────────────────
// 2. DATABASE
// ─────────────────────────────────────────────────────────────
section('2. DATABASE');

global $wpdb;
row('DB Host',     DB_HOST);
row('DB Name',     DB_NAME);
row('DB Charset',  DB_CHARSET);
row('DB Collate',  DB_COLLATE ?: 'default');

// MySQL version
$db_ver = $wpdb->get_var('SELECT VERSION()');
row('MySQL/MariaDB Version', $db_ver ?? 'unknown');

// Table prefix
row('Table Prefix', $wpdb->prefix);

// Check for slow query log
$slow_log_row = $wpdb->get_row("SHOW VARIABLES LIKE 'slow_query_log'");
$slow_qs_row  = $wpdb->get_row("SHOW VARIABLES LIKE 'long_query_time'");
row('Slow Query Log',  $slow_log_row->Value ?? 'unknown');
row('Long Query Time', $slow_qs_row->Value  ?? 'unknown');

// DB table sizes — use SHOW TABLE STATUS (works on managed hosts; information_schema often restricted)
$tables = $wpdb->get_results("SHOW TABLE STATUS");
if ($tables) {
    usort($tables, fn($a, $b) => (($b->Data_length + $b->Index_length) <=> ($a->Data_length + $a->Index_length)));
    $out[] = '';
    $out[] = '  Top tables by size:';
    foreach (array_slice($tables, 0, 15) as $t) {
        $size = (int)$t->Data_length + (int)$t->Index_length;
        $rows = number_format((int)$t->Rows);
        row('  ' . $t->Name, bytes($size) . " (~{$rows} rows)");
    }
} else {
    $out[] = '  Top tables by size: (unavailable — insufficient permissions)';
}

// Check autoload options size
$autoload_size = $wpdb->get_var("
    SELECT SUM(LENGTH(option_value))
    FROM {$wpdb->options}
    WHERE autoload = 'yes'
");
$autoload_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE autoload = 'yes'");
$autoload_kb = round($autoload_size / 1024, 1);
row('Autoloaded options', "$autoload_count rows / {$autoload_kb} KB",
    $autoload_kb > 800 ? 'BAD' : ($autoload_kb > 200 ? 'WARN' : 'OK'));

if ($autoload_kb > 200) {
    // Show biggest autoloaded options
    $big_autoloads = $wpdb->get_results("
        SELECT option_name, LENGTH(option_value) AS size
        FROM {$wpdb->options}
        WHERE autoload = 'yes'
        ORDER BY size DESC
        LIMIT 10
    ");
    $out[] = '  Largest autoloaded options:';
    foreach ($big_autoloads as $opt) {
        row('    ' . substr($opt->option_name, 0, 40), bytes((int)$opt->size));
    }
}

// Transients count
$transient_count = $wpdb->get_var("
    SELECT COUNT(*) FROM {$wpdb->options}
    WHERE option_name LIKE '_transient_%'
    AND option_name NOT LIKE '_transient_timeout_%'
");
$expired_transients = $wpdb->get_var("
    SELECT COUNT(*) FROM {$wpdb->options} o
    INNER JOIN {$wpdb->options} ot ON ot.option_name = CONCAT('_transient_timeout_', SUBSTRING(o.option_name, 12))
    WHERE o.option_name LIKE '_transient_%'
    AND o.option_name NOT LIKE '_transient_timeout_%'
    AND ot.option_value < UNIX_TIMESTAMP()
");
row('Transients (DB)',      $transient_count,    (int)$transient_count > 1000 ? 'WARN' : 'OK');
row('Expired transients',  $expired_transients, (int)$expired_transients > 100 ? 'WARN' : 'OK');

// Post revisions
$revision_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'");
row('Post revisions',      $revision_count,     (int)$revision_count > 500 ? 'WARN' : 'OK');
row('WP_POST_REVISIONS',   defined('WP_POST_REVISIONS') ? WP_POST_REVISIONS : 'default (unlimited)');

// Trash
$trash_posts = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'");
row('Trashed posts',       $trash_posts);

// Spam comments
$spam_comments = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'");
row('Spam comments',       $spam_comments, (int)$spam_comments > 500 ? 'WARN' : 'OK');

// ─────────────────────────────────────────────────────────────
// 3. OBJECT CACHE
// ─────────────────────────────────────────────────────────────
section('3. OBJECT CACHE');

$using_external_cache = wp_using_ext_object_cache();
row('External object cache', $using_external_cache ? 'YES' : 'No (using DB)',
    $using_external_cache ? 'OK' : 'WARN');

// Detect cache type
$cache_type = 'None';
if (file_exists(WP_CONTENT_DIR . '/object-cache.php')) {
    $cache_drop_in = file_get_contents(WP_CONTENT_DIR . '/object-cache.php');
    if (stripos($cache_drop_in, 'redis') !== false) $cache_type = 'Redis';
    elseif (stripos($cache_drop_in, 'memcach') !== false) $cache_type = 'Memcached';
    elseif (stripos($cache_drop_in, 'batcache') !== false) $cache_type = 'Batcache (object-cache.php)';
    else $cache_type = 'Custom drop-in';
}
row('Detected cache backend', $cache_type);

// Batcache check
$batcache_active = file_exists(WP_CONTENT_DIR . '/advanced-cache.php');
$batcache_content = $batcache_active ? file_get_contents(WP_CONTENT_DIR . '/advanced-cache.php') : '';
$is_batcache = $batcache_active && stripos($batcache_content, 'batcache') !== false;
row('Batcache (advanced-cache.php)', $is_batcache ? 'Present' : ($batcache_active ? 'Other drop-in present' : 'Not found'),
    $is_batcache ? 'OK' : 'INFO');
row('WP_CACHE constant', defined('WP_CACHE') && WP_CACHE ? 'true' : 'false',
    defined('WP_CACHE') && WP_CACHE ? 'OK' : 'WARN');

// Test a cache round-trip
$test_key = 'wp_perf_diag_test_' . time();
$test_val = 'diag_ok';
wp_cache_set($test_key, $test_val, 'perf_diag_test', 30);
$retrieved = wp_cache_get($test_key, 'perf_diag_test');
$cache_works = ($retrieved === $test_val);
wp_cache_delete($test_key, 'perf_diag_test');
row('Cache set/get round-trip', $cache_works ? 'Working' : 'FAILED', $cache_works ? 'OK' : 'BAD');

// Cache stats if available
if (isset($wp_object_cache) && method_exists($wp_object_cache, 'stats')) {
    ob_start();
    $wp_object_cache->stats();
    $stats_output = ob_get_clean();
    if ($stats_output) {
        $out[] = '  Cache stats output captured (raw):';
        $out[] = '  ' . strip_tags(str_replace('<br />', "\n  ", $stats_output));
    }
}

// ─────────────────────────────────────────────────────────────
// 4. PLUGINS
// ─────────────────────────────────────────────────────────────
section('4. PLUGINS');

$active_plugins = get_option('active_plugins', []);
if (is_multisite()) {
    $network_plugins = array_keys(get_site_option('active_sitewide_plugins', []));
    $active_plugins  = array_merge($active_plugins, $network_plugins);
}
row('Active plugin count', count($active_plugins), count($active_plugins) > 30 ? 'WARN' : 'OK');

if (!function_exists('get_plugins')) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
$all_plugins = get_plugins();

// Flag known performance-impactful plugins
$perf_plugins = [
    // Good/caching
    'w3-total-cache/w3-total-cache.php'           => ['W3 Total Cache', 'cache'],
    'wp-super-cache/wp-cache.php'                 => ['WP Super Cache', 'cache'],
    'litespeed-cache/litespeed-cache.php'         => ['LiteSpeed Cache', 'cache'],
    'wp-rocket/wp-rocket.php'                     => ['WP Rocket', 'cache'],
    'wp-fastest-cache/wpFastestCache.php'         => ['WP Fastest Cache', 'cache'],
    'redis-cache/redis-cache.php'                 => ['Redis Object Cache', 'cache'],
    'memcached-redux/memcached.php'               => ['Memcached Redux', 'cache'],
    // Monitoring
    'query-monitor/query-monitor.php'             => ['Query Monitor', 'debug'],
    'debug-bar/debug-bar.php'                     => ['Debug Bar', 'debug'],
    'new-relic-browser/new-relic-browser.php'     => ['New Relic', 'monitoring'],
    // Potentially heavy
    'jetpack/jetpack.php'                         => ['Jetpack', 'heavy'],
    'woocommerce/woocommerce.php'                 => ['WooCommerce', 'ecommerce'],
    'elementor/elementor.php'                     => ['Elementor', 'builder'],
    'js_composer/js_composer.php'                 => ['WPBakery', 'builder'],
    'gravityforms/gravityforms.php'               => ['Gravity Forms', 'forms'],
    'really-simple-ssl/rlrsssl-really-simple-ssl.php' => ['Really Simple SSL', 'ssl'],
    'wordfence/wordfence.php'                     => ['Wordfence', 'security'],
    'all-in-one-wp-security-and-firewall/wp-security.php' => ['AIO Security', 'security'],
    // SEO
    'wordpress-seo/wp-seo.php'                   => ['Yoast SEO', 'seo'],
    'seo-by-rank-math/rank-math.php'              => ['Rank Math SEO', 'seo'],
    'google-site-kit/google-site-kit.php'         => ['Site Kit by Google', 'analytics'],
    // Page builders / addons
    'ultimate-elementor/ultimate-elementor.php'   => ['Ultimate Addons for Elementor', 'builder'],
    'essential-addons-for-elementor-lite/essential_adons_for_elementor.php' => ['Essential Addons for Elementor', 'builder'],
    'beaver-builder-lite-version/fl-builder.php'  => ['Beaver Builder', 'builder'],
    'divi-builder/divi-builder.php'               => ['Divi Builder', 'builder'],
    // Forms
    'contact-form-7/wp-contact-form-7.php'        => ['Contact Form 7', 'forms'],
    'ninja-forms/ninja-forms.php'                 => ['Ninja Forms', 'forms'],
    // WooCommerce
    'woocommerce/woocommerce.php'                 => ['WooCommerce', 'ecommerce'],
    // Other heavy hitters
    'the-events-calendar/the-events-calendar.php' => ['The Events Calendar', 'heavy'],
    'bbpress/bbpress.php'                         => ['bbPress', 'heavy'],
    'buddypress/bp-loader.php'                    => ['BuddyPress', 'heavy'],
];

$found_perf_plugins = [];
foreach ($perf_plugins as $slug => $info) {
    // Match on exact slug OR by directory prefix (handles version suffixes in filenames)
    $dir = dirname($slug);
    $matched = in_array($slug, $active_plugins);
    if (!$matched) {
        foreach ($active_plugins as $ap) {
            if (dirname($ap) === $dir) {
                $matched = true;
                break;
            }
        }
    }
    if ($matched) {
        $found_perf_plugins[] = $info;
    }
}

if ($found_perf_plugins) {
    $out[] = '  Notable active plugins:';
    foreach ($found_perf_plugins as $p) {
        row('  ' . $p[0], '[' . $p[1] . ']');
    }
}

// List all active plugins with version
$out[] = '';
$out[] = '  All active plugins:';
foreach ($active_plugins as $plugin_file) {
    $plugin_data = $all_plugins[$plugin_file] ?? null;
    $name    = $plugin_data ? $plugin_data['Name'] : $plugin_file;
    $version = $plugin_data ? 'v' . $plugin_data['Version'] : '';
    $out[] = sprintf("    %-50s %s", substr($name, 0, 50), $version);
}

// ─────────────────────────────────────────────────────────────
// 5. WP QUERY & HOOK STATS (this request)
// ─────────────────────────────────────────────────────────────
section('5. WP QUERY & HOOK STATS');

// SAVEQUERIES must be on - check if we can enable it
$savequeries_on = defined('SAVEQUERIES') && SAVEQUERIES;
row('SAVEQUERIES', $savequeries_on ? 'Enabled' : 'Not enabled (define in wp-config.php for query logging)',
    $savequeries_on ? 'OK' : 'INFO');

if ($savequeries_on && !empty($wpdb->queries)) {
    $total_time  = array_sum(array_column($wpdb->queries, 1));
    $query_count = count($wpdb->queries);
    row('Queries this request',   $query_count,    $query_count > 100 ? 'WARN' : 'OK');
    row('Total DB time',          ms($total_time), $total_time > 0.5 ? 'WARN' : 'OK');
    row('Avg query time',         ms($total_time / max(1, $query_count)));

    // Slowest queries
    $sorted = $wpdb->queries;
    usort($sorted, fn($a, $b) => $b[1] <=> $a[1]);
    $out[] = '  Slowest 5 queries:';
    foreach (array_slice($sorted, 0, 5) as $q) {
        $out[] = sprintf("    %s | %s", ms($q[1]), substr($q[0], 0, 120));
    }
} else {
    row('Queries (estimate)', $wpdb->num_queries . ' (add SAVEQUERIES=true for detail)',
        $wpdb->num_queries > 100 ? 'WARN' : 'OK');
}

// Hooks
global $wp_filter;
$hook_count = isset($wp_filter) ? count($wp_filter) : 0;
row('Registered hooks', $hook_count);

// Count callbacks
$total_callbacks = 0;
if (isset($wp_filter)) {
    foreach ($wp_filter as $hook) {
        if (is_object($hook) && isset($hook->callbacks)) {
            foreach ($hook->callbacks as $priority) {
                $total_callbacks += count($priority);
            }
        }
    }
}
row('Total hook callbacks', $total_callbacks, $total_callbacks > 5000 ? 'WARN' : 'OK');

// ─────────────────────────────────────────────────────────────
// 6. CRON
// ─────────────────────────────────────────────────────────────
section('6. WP-CRON');

row('DISABLE_WP_CRON', defined('DISABLE_WP_CRON') && DISABLE_WP_CRON ? 'Yes (using server cron)' : 'No (HTTP cron)',
    defined('DISABLE_WP_CRON') && DISABLE_WP_CRON ? 'OK' : 'INFO');
row('ALTERNATE_WP_CRON', defined('ALTERNATE_WP_CRON') && ALTERNATE_WP_CRON ? 'Yes' : 'No');

$cron_jobs  = _get_cron_array();
$total_jobs = 0;
$overdue    = 0;
$now        = time();
if (is_array($cron_jobs)) {
    foreach ($cron_jobs as $timestamp => $hooks) {
        $total_jobs += array_sum(array_map('count', $hooks));
        if ($timestamp < $now) $overdue++;
    }
}
row('Scheduled cron events', $total_jobs);
row('Overdue timestamps',    $overdue, $overdue > 10 ? 'WARN' : 'OK');

// ─────────────────────────────────────────────────────────────
// 7. ASSET & HTTP DELIVERY
// ─────────────────────────────────────────────────────────────
section('7. ASSETS & DELIVERY');

global $wp_scripts, $wp_styles;
$script_count = isset($wp_scripts->registered) ? count($wp_scripts->registered) : 0;
$style_count  = isset($wp_styles->registered)  ? count($wp_styles->registered)  : 0;
row('Registered scripts', $script_count, $script_count > 100 ? 'WARN' : 'OK');
row('Registered styles',  $style_count,  $style_count  > 80  ? 'WARN' : 'OK');

// Enqueued
$enqueued_scripts = isset($wp_scripts->queue) ? count($wp_scripts->queue) : 0;
$enqueued_styles  = isset($wp_styles->queue)  ? count($wp_styles->queue)  : 0;
row('Enqueued scripts', $enqueued_scripts, $enqueued_scripts > 20 ? 'WARN' : 'OK');
row('Enqueued styles',  $enqueued_styles,  $enqueued_styles  > 15 ? 'WARN' : 'OK');

row('CONCATENATE_SCRIPTS', defined('CONCATENATE_SCRIPTS') ? (CONCATENATE_SCRIPTS ? 'true' : 'false') : 'not set');
row('COMPRESS_SCRIPTS',    defined('COMPRESS_SCRIPTS')    ? (COMPRESS_SCRIPTS    ? 'true' : 'false') : 'not set');
row('COMPRESS_CSS',        defined('COMPRESS_CSS')        ? (COMPRESS_CSS        ? 'true' : 'false') : 'not set');

// ─────────────────────────────────────────────────────────────
// 8. REACHABILITY & HEADERS (self HTTP check)
// ─────────────────────────────────────────────────────────────
section('8. HTTP SELF-CHECK (TTFB & CACHE HEADERS)');

$home_url = get_home_url();
note("Making HTTP request to: $home_url");
note("This checks cache headers, TTFB, and edge caching signals.");

$start_http = microtime(true);
$response = wp_remote_get($home_url, [
    'timeout'    => 15,
    'user-agent' => 'WPPerfDiag/1.0',
    'headers'    => ['Cache-Control' => 'no-cache'],
    'sslverify'  => false,
]);
$ttfb = microtime(true) - $start_http;

if (is_wp_error($response)) {
    bad('HTTP request failed: ' . $response->get_error_message());
} else {
    $code    = wp_remote_retrieve_response_code($response);
    $headers = wp_remote_retrieve_headers($response);
    $body    = wp_remote_retrieve_body($response);

    row('HTTP Status',  $code, $code === 200 ? 'OK' : 'WARN');
    row('TTFB (uncached)', ms($ttfb), $ttfb > 1.5 ? 'BAD' : ($ttfb > 0.6 ? 'WARN' : 'OK'));
    row('Response size', bytes(strlen($body)));

    // Cache-related headers
    $cache_headers = [
        'x-nananana', 'x-ac',
        'x-cache', 'x-batcache', 'x-cache-status', 'x-varnish',
        'x-fastly-request-id', 'cf-cache-status', 'age',
        'cache-control', 'pragma', 'expires',
        'x-cacheable', 'x-wp-cf-super-cache', 'x-wpe-request-id',
        'x-powered-by', 'server', 'x-litespeed-cache',
        'x-kinsta-cache', 'x-served-by', 'x-cache-hits',
        'surrogate-control', 'x-proxy-cache',
    ];

    $out[] = '';
    $out[] = '  Cache & CDN headers:';
    foreach ($cache_headers as $h) {
        $val = $headers->offsetGet($h);
        if ($val) {
            row("  $h", $val);
        }
    }

    // Batcache (x-nananana)
    $x_nananana = $headers->offsetGet('x-nananana');
    if ($x_nananana) {
        if (stripos($x_nananana, 'Batcache-Hit') !== false) {
            good("Batcache HIT (x-nananana: $x_nananana)");
        } elseif (stripos($x_nananana, 'Batcache-Set') !== false) {
            row('Batcache SET (x-nananana)', $x_nananana, 'WARN');
            note('Page was just cached — it will serve as a HIT on the next request');
        } else {
            row('x-nananana (unexpected value)', $x_nananana, 'WARN');
        }
    } else {
        bad('x-nananana header absent — Batcache may not be running or is bypassed');
    }

    // Edge cache (x-ac)
    $x_ac = $headers->offsetGet('x-ac');
    if ($x_ac) {
        if (stripos($x_ac, 'HIT') !== false) {
            good("Edge cache HIT (x-ac: $x_ac)");
        } elseif (stripos($x_ac, 'MISS') !== false) {
            row('Edge cache MISS (x-ac)', $x_ac, 'WARN');
            note('Not yet in edge cache — try a second request');
        } elseif (stripos($x_ac, 'BYPASS') !== false) {
            bad("Edge cache BYPASS (x-ac: $x_ac) — caching is being skipped");
        } else {
            row('x-ac (unexpected value)', $x_ac, 'WARN');
        }
    } else {
        bad('x-ac header absent — edge cache may not be active for this URL');
    }

    // Other Edge/CDN cache status
    $cf_status   = $headers->offsetGet('cf-cache-status');
    $x_cache     = $headers->offsetGet('x-cache');
    $cache_ctrl  = $headers->offsetGet('cache-control');

    if ($cf_status)  row('Cloudflare cache status', $cf_status, in_array(strtoupper($cf_status), ['HIT', 'REVALIDATED', 'UPDATING']) ? 'OK' : 'INFO');
    if ($x_cache)    row('Edge cache (x-cache)', $x_cache, stripos($x_cache, 'HIT') !== false ? 'OK' : 'INFO');
    if ($cache_ctrl) {
        row('Cache-Control', $cache_ctrl);
        if (stripos($cache_ctrl, 'no-store') !== false || stripos($cache_ctrl, 'no-cache') !== false) {
            warn('Cache-Control prevents caching. Check if intentional.');
        }
    }

    // Second request to check if cache kicks in
    $out[] = '';
    note("Making second request to check cache hit...");
    $start2   = microtime(true);
    $response2 = wp_remote_get($home_url, [
        'timeout'    => 15,
        'user-agent' => 'WPPerfDiag/1.0',
        'sslverify'  => false,
    ]);
    $ttfb2 = microtime(true) - $start2;

    if (!is_wp_error($response2)) {
        $headers2 = wp_remote_retrieve_headers($response2);
        row('TTFB (2nd request)', ms($ttfb2), $ttfb2 > 1.0 ? 'WARN' : 'OK');
        $speedup = $ttfb > 0 ? round((($ttfb - $ttfb2) / $ttfb) * 100) : 0;
        row('Speed improvement', "$speedup% faster on 2nd request", $speedup > 30 ? 'OK' : 'INFO');

        $age = $headers2->offsetGet('age');
        if ($age) row('Cache Age header', $age . 's');

        $x_nananana2 = $headers2->offsetGet('x-nananana');
        if ($x_nananana2) row('x-nananana (2nd request)', $x_nananana2,
            stripos($x_nananana2, 'Batcache-Hit') !== false ? 'OK' : 'WARN');

        $x_ac2 = $headers2->offsetGet('x-ac');
        if ($x_ac2) row('x-ac (2nd request)', $x_ac2,
            stripos($x_ac2, 'HIT') !== false ? 'OK' : 'WARN');

        $cf2 = $headers2->offsetGet('cf-cache-status');
        if ($cf2) row('Cloudflare (2nd request)', $cf2, in_array(strtoupper($cf2), ['HIT', 'REVALIDATED']) ? 'OK' : 'INFO');
    }
}

// ─────────────────────────────────────────────────────────────
// 8b. INCOMING COOKIES ($_COOKIE)
// ─────────────────────────────────────────────────────────────
section('8b. INCOMING COOKIES (sent by browser this request)');

note('These are cookies the browser sent with this request — equivalent to DevTools > Application > Cookies.');
note('Run via WP-CLI for most accurate results (no browser cookies); web access reflects a real browser session.');
$out[] = '';

if (empty($_COOKIE)) {
    good('No cookies sent with this request');
} else {
    $all_cookies     = $_COOKIE;
    $wp_cookies      = [];
    $woo_cookies     = [];
    $other_cookies   = [];

    foreach ($all_cookies as $name => $value) {
        if (stripos($name, 'woocommerce_') === 0) {
            $woo_cookies[$name] = $value;
        } elseif (stripos($name, 'wp_') === 0 || stripos($name, 'wordpress_') === 0) {
            $wp_cookies[$name] = $value;
        } else {
            $other_cookies[$name] = $value;
        }
    }

    row('Total cookies present', count($all_cookies));
    $out[] = '';

    if ($wp_cookies) {
        bad('WordPress cookies present (' . count($wp_cookies) . ') — request will bypass page cache:');
        foreach ($wp_cookies as $name => $value) {
            $display = strlen($value) > 60 ? substr($value, 0, 60) . '…' : $value;
            row('  ' . $name, $display);
        }
        note('wp_/wordpress_ cookies are typically set on login. If seen on anonymous requests, a plugin may be misusing sessions.');
        $out[] = '';
    }

    if ($woo_cookies) {
        bad('WooCommerce cookies present (' . count($woo_cookies) . ') — request will bypass page cache:');
        foreach ($woo_cookies as $name => $value) {
            $display = strlen($value) > 60 ? substr($value, 0, 60) . '…' : $value;
            row('  ' . $name, $display);
        }
        note('woocommerce_ cookies on non-cart/checkout pages suggest session handling is too broad.');
        $out[] = '';
    }

    if ($other_cookies) {
        row('Other cookies (' . count($other_cookies) . ')', '');
        foreach ($other_cookies as $name => $value) {
            $display = strlen($value) > 60 ? substr($value, 0, 60) . '…' : $value;
            row('  ' . $name, $display);
        }
    }

    if ($wp_cookies || $woo_cookies) {
        $out[] = '';
        warn('Cache-breaking cookies detected. If this is an anonymous/logged-out request, investigate which plugin is setting these.');
    }
}


section('9. WP-CONFIG CONSTANTS');

$wp_config_checks = [
    'WP_CACHE'              => [true,  'Should be true for page caching'],
    'SAVEQUERIES'           => [false, 'Should be false in production'],
    'WP_DEBUG'              => [false, 'Should be false in production'],
    'WP_DEBUG_DISPLAY'      => [false, 'Should be false in production'],
    'EMPTY_TRASH_DAYS'      => [null,  'Consider setting (default 30)'],
    'WP_POST_REVISIONS'     => [null,  'Consider limiting (e.g. 5)'],
    'DISABLE_WP_CRON'       => [true,  'Consider using server cron instead'],
    'COMPRESS_SCRIPTS'      => [true,  'Enables JS compression'],
    'COMPRESS_CSS'          => [true,  'Enables CSS compression'],
    'WP_HTTP_BLOCK_EXTERNAL' => [null, 'Blocks unexpected outbound HTTP'],
];

foreach ($wp_config_checks as $const => $info) {
    [$expected, $note_text] = $info;
    if (!defined($const)) {
        row($const, 'not defined', 'INFO');
    } else {
        $val = constant($const);
        $display = is_bool($val) ? ($val ? 'true' : 'false') : $val;
        $status = 'OK';
        if ($expected !== null && $val !== $expected) $status = 'WARN';
        row($const, $display, $status);
    }
    note($note_text);
}

// ─────────────────────────────────────────────────────────────
// 10. FILE SYSTEM & DISK
// ─────────────────────────────────────────────────────────────
section('10. FILESYSTEM');

$paths = [
    'ABSPATH'         => ABSPATH,
    'WP_CONTENT_DIR'  => WP_CONTENT_DIR,
    'Uploads dir'     => wp_upload_dir()['basedir'] ?? 'unknown',
];

foreach ($paths as $label => $path) {
    $writable = is_writable($path);
    $size = function_exists('disk_total_space') ? disk_total_space($path) : null;
    $free = function_exists('disk_free_space')  ? disk_free_space($path)  : null;
    row($label, $path);
    row("  Writable", $writable ? 'Yes' : 'No');
    if ($free && $size) {
        $pct_used = round((($size - $free) / $size) * 100);
        row("  Disk free", bytes((int)$free) . ' / ' . bytes((int)$size) . " ($pct_used% used)",
            $pct_used > 90 ? 'BAD' : ($pct_used > 75 ? 'WARN' : 'OK'));
    }
}

// WP uploads folder size estimate (top level only for speed)
$upload_base = wp_upload_dir()['basedir'] ?? null;
if ($upload_base && is_dir($upload_base)) {
    $years = glob($upload_base . '/[0-9][0-9][0-9][0-9]', GLOB_ONLYDIR);
    row('Upload year folders', count($years ?: []));
}

// ─────────────────────────────────────────────────────────────
// 11. OUTBOUND HTTP HEALTH
// ─────────────────────────────────────────────────────────────
section('11. OUTBOUND HTTP');

// Check common endpoints WP uses
$endpoints = [
    'api.wordpress.org'   => 'https://api.wordpress.org/core/version-check/1.7/',
    'downloads.wordpress.org' => 'https://downloads.wordpress.org/',
];

foreach ($endpoints as $label => $url) {
    $t = microtime(true);
    $r = wp_remote_head($url, ['timeout' => 5, 'sslverify' => false]);
    $elapsed = microtime(true) - $t;
    $code = is_wp_error($r) ? $r->get_error_message() : wp_remote_retrieve_response_code($r);
    row($label, "$code in " . ms($elapsed), is_int($code) && $code < 400 ? 'OK' : 'WARN');
}

// Check if site can do loopback
$loopback = wp_remote_get(admin_url('admin-ajax.php'), [
    'timeout'   => 5,
    'sslverify' => false,
    'body'      => ['action' => 'heartbeat'],
]);
$lb_ok = !is_wp_error($loopback) && wp_remote_retrieve_response_code($loopback) < 500;
row('Loopback (admin-ajax)', $lb_ok ? 'OK' : (is_wp_error($loopback) ? $loopback->get_error_message() : 'Failed'),
    $lb_ok ? 'OK' : 'WARN');

// ─────────────────────────────────────────────────────────────
// 12. RESOURCE USAGE (this script execution)
// ─────────────────────────────────────────────────────────────
section('12. RESOURCE USAGE (this script)');

$elapsed_total = microtime(true) - WP_PERF_DIAG_START;
row('Script execution time', ms($elapsed_total));
row('Peak memory usage',     bytes(memory_get_peak_usage(true)));
row('Memory at end',         bytes(memory_get_usage(true)));

// ─────────────────────────────────────────────────────────────
// SUMMARY
// ─────────────────────────────────────────────────────────────
section('SUMMARY & RECOMMENDATIONS');

// Collect issues
$issues = [];
$wins   = [];

if (!$using_external_cache)      $issues[] = 'No external object cache — consider Redis or Memcached';
if (!$is_batcache && !defined('DISABLE_WP_CRON')) $issues[] = 'No Batcache drop-in detected in wp-content/';
if ($autoload_kb > 200)          $issues[] = "Autoloaded options are large ({$autoload_kb} KB) — audit with Query Monitor";
if ((int)$expired_transients > 100) $issues[] = "Many expired transients ({$expired_transients}) — run WP-CLI: wp transient delete --expired";
if ((int)$revision_count > 500)  $issues[] = "High revision count ({$revision_count}) — set WP_POST_REVISIONS in wp-config.php";
if ((int)$spam_comments > 500)   $issues[] = "High spam comment count — empty spam from Dashboard > Comments";
if (!(defined('DISABLE_WP_CRON') && DISABLE_WP_CRON)) $issues[] = 'WP-Cron runs on HTTP requests — consider DISABLE_WP_CRON with server cron';
if (defined('WP_DEBUG') && WP_DEBUG) $issues[] = 'WP_DEBUG is ON in production';
if (!function_exists('opcache_get_status') || @opcache_get_status() === false) $issues[] = 'OPcache is not enabled';
if ($enqueued_scripts > 20)      $issues[] = "High enqueued script count ($enqueued_scripts) — check for bloat";
if ($enqueued_styles > 15)       $issues[] = "High enqueued style count ($enqueued_styles) — check for bloat";

if ($using_external_cache)       $wins[] = 'External object cache is active';
if ($is_batcache)                $wins[] = 'Batcache page caching is present';
if ($savequeries_on && $wpdb->num_queries < 50) $wins[] = 'Low query count for this request';
if (function_exists('opcache_get_status') && @opcache_get_status() !== false) $wins[] = 'OPcache is enabled';
if (isset($ttfb) && $ttfb < 0.3) $wins[] = 'Excellent TTFB (' . ms($ttfb) . ')';

$out[] = '';
if ($wins) {
    $out[] = '  ✓ Positives:';
    foreach ($wins as $w) good("  $w");
}

$out[] = '';
if ($issues) {
    $out[] = '  ⚠ Issues/Recommendations:';
    foreach ($issues as $i) warn("  $i");
} else {
    good('  No major issues detected!');
}

$out[] = '';
$out[] = '  Generated: ' . date('Y-m-d H:i:s T');
$out[] = '  Site: ' . get_site_url();
$out[] = '';

// ── Output ────────────────────────────────────────────────────
if (!$is_cli) {
    // Only send header if output hasn't started yet
    if (!headers_sent()) {
        header('Content-Type: text/plain; charset=utf-8');
    }
}

// Flush any buffered output that may be suppressing our content
while (ob_get_level() > 0) {
    ob_end_clean();
}

echo implode("\n", $out) . "\n";
