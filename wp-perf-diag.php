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
// Use $GLOBALS directly — WP-CLI eval-file wraps execution in a function
// scope, so `global $out` inside helpers won't bind to the file-level var.
$GLOBALS['out']    = [];
$GLOBALS['is_cli'] = $is_cli;

function section(string $title): void {
    $is_cli = $GLOBALS['is_cli'];
    $bar = str_repeat('─', 60);
    // Pink (#b61d6f) for titles, dark (#3b4956) for dividers
    $GLOBALS['out'][] = '';
    $GLOBALS['out'][] = $is_cli ? "\033[1;38;2;59;73;86m$bar\033[0m" : $bar;
    $GLOBALS['out'][] = $is_cli ? "\033[1;38;2;182;29;111m  $title\033[0m" : "  $title";
    $GLOBALS['out'][] = $is_cli ? "\033[1;38;2;59;73;86m$bar\033[0m" : $bar;
}

function row(string $label, $value, string $status = ''): void {
    $is_cli = $GLOBALS['is_cli'];
    $statusColors = ['OK' => '32', 'WARN' => '33', 'BAD' => '31', 'INFO' => '36', '' => '0'];
    $color = $statusColors[$status] ?? '0';
    $badge = $status ? "[$status]" : '';
    if ($is_cli && $status) {
        $badge = "\033[{$color}m$badge\033[0m";
    }
    $line = sprintf("  %-35s %s %s", $label, $value, $badge);
    $GLOBALS['out'][] = $line;
}

function note(string $msg): void {
    $is_cli = $GLOBALS['is_cli'];
    $GLOBALS['out'][] = $is_cli ? "  \033[1;38;2;255;255;255m↳ $msg\033[0m" : "    ↳ $msg";
}

function warn(string $msg): void {
    $is_cli = $GLOBALS['is_cli'];
    $GLOBALS['out'][] = $is_cli ? "  \033[33m⚠ $msg\033[0m" : "  ⚠ $msg";
}

function good(string $msg): void {
    $is_cli = $GLOBALS['is_cli'];
    $GLOBALS['out'][] = $is_cli ? "  \033[32m✓ $msg\033[0m" : "  ✓ $msg";
}

function bad(string $msg): void {
    $is_cli = $GLOBALS['is_cli'];
    $GLOBALS['out'][] = $is_cli ? "  \033[31m✗ $msg\033[0m" : "  ✗ $msg";
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

// Fetch version data from api.wordpress.org once — used for both WP and PHP version checks.
// Response includes: offers[0].version (latest WP), recommended_php, minimum_php.
$wp_current_ver = get_bloginfo('version');
$wp_ver_response = wp_remote_get('https://api.wordpress.org/core/version-check/1.7/', [
    'timeout'   => 5,
    'sslverify' => false,
    'headers'   => ['Accept' => 'application/json'],
]);
$wp_latest_ver    = null;
$php_recommended  = null;
$php_minimum      = null;
if (!is_wp_error($wp_ver_response)) {
    $wp_ver_body   = json_decode(wp_remote_retrieve_body($wp_ver_response), true);
    $wp_latest_ver = $wp_ver_body['offers'][0]['version'] ?? null;
    $php_recommended = $wp_ver_body['recommended_php'] ?? null;
    $php_minimum     = $wp_ver_body['minimum_php']     ?? null;
}

// PHP Version — flag against WordPress's recommended and minimum PHP versions
if ($php_recommended) {
    $php_below_recommended = version_compare(PHP_VERSION, $php_recommended, '<');
    $php_below_minimum     = $php_minimum && version_compare(PHP_VERSION, $php_minimum, '<');
    if ($php_below_minimum) {
        $php_status = 'BAD';
        $php_note   = " (below WP minimum: $php_minimum)";
    } elseif ($php_below_recommended) {
        $php_status = 'WARN';
        $php_note   = " (recommended: $php_recommended)";
    } else {
        $php_status = 'OK';
        $php_note   = " (meets recommended $php_recommended)";
    }
    row('PHP Version', PHP_VERSION . $php_note, $php_status);
} else {
    // Fallback if API unavailable: flag anything below 8.1 (security-only as of late 2024)
    row('PHP Version', PHP_VERSION, version_compare(PHP_VERSION, '8.1', '>=') ? 'OK' : 'WARN');
}

// WordPress version
if ($wp_latest_ver) {
    $wp_outdated = version_compare($wp_current_ver, $wp_latest_ver, '<');
    row('WordPress Version',
        $wp_outdated
            ? "$wp_current_ver (latest: $wp_latest_ver)"
            : "$wp_current_ver (up to date)",
        $wp_outdated ? 'WARN' : 'OK');
} else {
    row('WordPress Version', $wp_current_ver . ' (could not check latest)', 'INFO');
}
row('WP Multisite',       is_multisite() ? 'Yes' : 'No');
row('WP Debug',           defined('WP_DEBUG') && WP_DEBUG ? 'ON' : 'off', defined('WP_DEBUG') && WP_DEBUG ? 'WARN' : 'OK');
row('WP Debug Log',       defined('WP_DEBUG_LOG') && WP_DEBUG_LOG ? 'ON' : 'off');
row('Script Debug',       defined('SCRIPT_DEBUG') && SCRIPT_DEBUG ? 'ON' : 'off');
row('WP Memory Limit',    WP_MEMORY_LIMIT);
row('WP Max Memory Limit', defined('WP_MAX_MEMORY_LIMIT') ? WP_MAX_MEMORY_LIMIT : 'not set');
row('PHP memory_limit',   ini_get('memory_limit'));
$opcache_enabled = function_exists('opcache_get_status') && @opcache_get_status() !== false;
row('OPcache enabled', $opcache_enabled ? 'Yes' : 'No', $opcache_enabled ? 'OK' : 'INFO');

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

// Server Software only shown for non-CLI (not meaningful when running via WP-CLI)
if (!$is_cli && isset($_SERVER['SERVER_SOFTWARE'])) {
    row('Server Software', $_SERVER['SERVER_SOFTWARE']);
}
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

// DB table sizes — use SHOW TABLE STATUS (works on managed hosts; information_schema often restricted)
$tables = $wpdb->get_results("SHOW TABLE STATUS");
if ($tables) {
    usort($tables, fn($a, $b) => (($b->Data_length + $b->Index_length) <=> ($a->Data_length + $a->Index_length)));
    $GLOBALS['out'][] = '';
    $GLOBALS['out'][] = '  Top tables by size:';
    foreach (array_slice($tables, 0, 15) as $t) {
        $size = (int)$t->Data_length + (int)$t->Index_length;
        $rows = number_format((int)$t->Rows);
        row('  ' . $t->Name, bytes($size) . " (~{$rows} rows)");
    }
} else {
    $GLOBALS['out'][] = '  Top tables by size: (unavailable — insufficient permissions)';
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
    $GLOBALS['out'][] = '  Largest autoloaded options:';
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

// ── DB: Table fragmentation ──────────────────────────────────
// Data_free is already in the SHOW TABLE STATUS results we fetched above.
if ($tables) {
    $fragmented = [];
    foreach ($tables as $t) {
        $free = (int)$t->Data_free;
        $used = (int)$t->Data_length + (int)$t->Index_length;
        if ($free > 1048576 && $used > 0) { // >1 MB free space
            $pct = round($free / ($used + $free) * 100);
            if ($pct >= 10) { // only flag if ≥10% fragmented
                $fragmented[$t->Name] = ['free' => $free, 'pct' => $pct];
            }
        }
    }
    if ($fragmented) {
        arsort($fragmented);
        $GLOBALS['out'][] = '';
        $GLOBALS['out'][] = '  Fragmented tables (>1 MB wasted, ≥10% overhead):';
        foreach (array_slice($fragmented, 0, 10, true) as $tname => $info) {
            row('  ' . $tname, bytes($info['free']) . ' free (' . $info['pct'] . '% overhead)', 'WARN');
        }
        note('Run OPTIMIZE TABLE or use WP-CLI: wp db optimize');
    } else {
        good('No significant table fragmentation detected');
    }
}

// ── DB: Missing indexes on core tables ──────────────────────
$GLOBALS['out'][] = '';
$GLOBALS['out'][] = '  Core table index checks:';
$index_checks = [
    $wpdb->posts    => ['post_author', 'post_status', 'post_type', 'post_parent', 'post_date'],
    $wpdb->postmeta => ['post_id', 'meta_key'],
    $wpdb->options  => ['autoload'],
    $wpdb->comments => ['comment_post_ID', 'comment_approved_date_gmt', 'comment_author_email'],
    $wpdb->usermeta => ['user_id', 'meta_key'],
];
$index_issues = 0;
foreach ($index_checks as $table => $required_cols) {
    $indexes = $wpdb->get_results("SHOW INDEX FROM `$table`");
    if ($indexes === null) continue; // table may not exist
    $indexed_cols = array_unique(array_column($indexes, 'Column_name'));
    foreach ($required_cols as $col) {
        if (!in_array($col, $indexed_cols)) {
            warn("Missing index on `$table`.`$col`");
            $index_issues++;
        }
    }
}
if ($index_issues === 0) {
    good('All checked core table indexes present');
}

// ── DB: Orphaned postmeta ────────────────────────────────────
$orphaned_postmeta = $wpdb->get_var("
    SELECT COUNT(*) FROM {$wpdb->postmeta} pm
    LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
    WHERE p.ID IS NULL
");
row('Orphaned postmeta rows', $orphaned_postmeta,
    (int)$orphaned_postmeta > 1000 ? 'WARN' : ((int)$orphaned_postmeta > 0 ? 'INFO' : 'OK'));
if ((int)$orphaned_postmeta > 1000) {
    note('Clean up with: DELETE pm FROM ' . $wpdb->postmeta . ' pm LEFT JOIN ' . $wpdb->posts . ' p ON p.ID = pm.post_id WHERE p.ID IS NULL');
}

// ── DB: Orphaned usermeta ─────────────────────────────────────
$orphaned_usermeta = $wpdb->get_var("
    SELECT COUNT(*) FROM {$wpdb->usermeta} um
    LEFT JOIN {$wpdb->users} u ON u.ID = um.user_id
    WHERE u.ID IS NULL
");
row('Orphaned usermeta rows', $orphaned_usermeta,
    (int)$orphaned_usermeta > 500 ? 'WARN' : ((int)$orphaned_usermeta > 0 ? 'INFO' : 'OK'));

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
        $GLOBALS['out'][] = '  Cache stats output captured (raw):';
        $GLOBALS['out'][] = '  ' . strip_tags(str_replace('<br />', "\n  ", $stats_output));
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

if (!function_exists('get_plugins')) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
$all_plugins = get_plugins();

// Plugin update counts
$update_transient = get_site_transient('update_plugins');
$plugins_needing_update = isset($update_transient->response) ? count($update_transient->response) : 0;

row('Total plugins (installed)', count($all_plugins));
row('Active plugins',            count($active_plugins), count($active_plugins) > 30 ? 'WARN' : 'OK');
row('Inactive plugins',          count($all_plugins) - count($active_plugins));
row('Plugins needing updates',   $plugins_needing_update, $plugins_needing_update > 0 ? 'WARN' : 'OK');

// List plugins with available updates
if ($plugins_needing_update > 0 && isset($update_transient->response)) {
    $GLOBALS['out'][] = '  Plugins with available updates:';
    foreach ($update_transient->response as $slug => $data) {
        $current = $all_plugins[$slug]['Version'] ?? '?';
        $new_ver = $data->new_version ?? '?';
        $name    = $all_plugins[$slug]['Name'] ?? $slug;
        $GLOBALS['out'][] = sprintf("    %-45s %s → %s", substr($name, 0, 45), $current, $new_ver);
    }
    $GLOBALS['out'][] = '';
}

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
    $GLOBALS['out'][] = '  Notable active plugins:';
    foreach ($found_perf_plugins as $p) {
        row('  ' . $p[0], '[' . $p[1] . ']');
    }
}

// List all active plugins with version
$GLOBALS['out'][] = '';
$GLOBALS['out'][] = '  All active plugins:';
foreach ($active_plugins as $plugin_file) {
    $plugin_data = $all_plugins[$plugin_file] ?? null;
    $name    = $plugin_data ? $plugin_data['Name'] : $plugin_file;
    $version = $plugin_data ? 'v' . $plugin_data['Version'] : '';
    $GLOBALS['out'][] = sprintf("    %-50s %s", substr($name, 0, 50), $version);
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
    $GLOBALS['out'][] = '  Slowest 5 queries:';
    foreach (array_slice($sorted, 0, 5) as $q) {
        $GLOBALS['out'][] = sprintf("    %s | %s", ms($q[1]), substr($q[0], 0, 120));
    }
} else {
    row('Queries (estimate)', $wpdb->num_queries . ' (add SAVEQUERIES=true for detail)',
        $wpdb->num_queries > 100 ? 'WARN' : 'OK');
}

// ── Hook analysis ─────────────────────────────────────────────
// $wp_filter is WordPress's global hook registry, populated as plugins
// and themes call add_action()/add_filter() during load. By the time
// wp eval-file runs (after plugins_loaded + init), it reflects the full
// set of hooks registered for a typical front-end request.
global $wp_filter;
$hook_count = isset($wp_filter) ? count($wp_filter) : 0;
row('Registered hook names', $hook_count);

// Walk every hook and tally callbacks, priorities, and per-source counts
$total_callbacks  = 0;
$hooks_by_count   = []; // hook_name => callback count
$callbacks_by_src = []; // plugin/theme slug => callback count
$priority_spread  = []; // hook_name => [min_priority, max_priority]

// Known core hooks that are heavy by design — not worth flagging
$core_hooks = [
    'plugins_loaded', 'init', 'wp_loaded', 'wp', 'template_redirect',
    'wp_head', 'wp_footer', 'wp_enqueue_scripts', 'admin_init',
    'admin_menu', 'admin_notices', 'save_post', 'the_content',
    'sanitize_text_field', 'esc_html', 'esc_attr',
];

if (isset($wp_filter)) {
    foreach ($wp_filter as $hook_name => $hook_obj) {
        if (!is_object($hook_obj) || !isset($hook_obj->callbacks)) continue;

        $hook_cb_count = 0;
        $priorities    = [];

        foreach ($hook_obj->callbacks as $priority => $callbacks) {
            $priorities[]   = (int) $priority;
            $hook_cb_count += count($callbacks);

            foreach ($callbacks as $cb_id => $cb_data) {
                $fn = $cb_data['function'] ?? null;

                // Resolve the callback to a file path for source attribution
                try {
                    if (is_string($fn) && function_exists($fn)) {
                        $ref  = new ReflectionFunction($fn);
                        $file = $ref->getFileName();
                    } elseif (is_array($fn) && count($fn) === 2) {
                        $ref  = is_object($fn[0])
                            ? new ReflectionMethod(get_class($fn[0]), $fn[1])
                            : new ReflectionMethod($fn[0], $fn[1]);
                        $file = $ref->getFileName();
                    } elseif ($fn instanceof Closure) {
                        $ref  = new ReflectionFunction($fn);
                        $file = $ref->getFileName();
                    } else {
                        $file = null;
                    }
                } catch (Throwable $e) {
                    $file = null;
                }

                if ($file) {
                    // Attribute to plugin, theme, mu-plugin, or core
                    $rel = str_replace(WP_CONTENT_DIR . '/', '', $file);
                    if (str_starts_with($rel, 'plugins/')) {
                        $parts = explode('/', $rel);
                        $src   = 'plugin: ' . ($parts[1] ?? '?');
                    } elseif (str_starts_with($rel, 'themes/')) {
                        $parts = explode('/', $rel);
                        $src   = 'theme: ' . ($parts[1] ?? '?');
                    } elseif (str_starts_with($rel, 'mu-plugins/')) {
                        $src   = 'mu-plugin';
                    } elseif (str_contains($file, ABSPATH . 'wp-includes/')) {
                        $src   = 'wp-core';
                    } elseif (str_contains($file, ABSPATH . 'wp-admin/')) {
                        $src   = 'wp-admin';
                    } else {
                        $src   = 'other';
                    }
                    $callbacks_by_src[$src] = ($callbacks_by_src[$src] ?? 0) + 1;
                }
            }
        }

        $total_callbacks              += $hook_cb_count;
        $hooks_by_count[$hook_name]    = $hook_cb_count;
        if ($priorities) {
            $priority_spread[$hook_name] = [min($priorities), max($priorities)];
        }
    }
}

row('Total hook callbacks', $total_callbacks, $total_callbacks > 5000 ? 'WARN' : 'OK');

// Callbacks by source
if ($callbacks_by_src) {
    arsort($callbacks_by_src);
    $GLOBALS['out'][] = '';
    $GLOBALS['out'][] = '  Callbacks by source:';
    foreach ($callbacks_by_src as $src => $count) {
        row('  ' . $src, $count);
    }
}

// Top 15 hooks by callback count, excluding known-heavy core hooks
arsort($hooks_by_count);
$notable_hooks = array_filter(
    $hooks_by_count,
    fn($name) => !in_array($name, $core_hooks),
    ARRAY_FILTER_USE_KEY
);

$GLOBALS['out'][] = '';
$GLOBALS['out'][] = '  Top 15 hooks by callback count (excluding routine core hooks):';
$shown = 0;
foreach ($notable_hooks as $name => $count) {
    if ($shown++ >= 15) break;
    $spread  = isset($priority_spread[$name])
        ? ' [priority ' . $priority_spread[$name][0] . '–' . $priority_spread[$name][1] . ']'
        : '';
    $flag    = $count > 20 ? ' [WARN]' : '';
    $GLOBALS['out'][] = sprintf("    %-45s %d callbacks%s%s",
        substr($name, 0, 45), $count, $spread, $flag);
}

// Hooks with unusually wide priority spreads (can indicate load-order conflicts)
$wide_spread = array_filter($priority_spread, fn($p) => ($p[1] - $p[0]) > 100);
if ($wide_spread) {
    arsort($wide_spread); // sort by hook name; could sort by spread size
    uasort($wide_spread, fn($a, $b) => ($b[1] - $b[0]) <=> ($a[1] - $a[0]));
    $GLOBALS['out'][] = '';
    $GLOBALS['out'][] = '  Hooks with wide priority spreads (possible load-order conflicts):';
    $shown = 0;
    foreach ($wide_spread as $name => $p) {
        if ($shown++ >= 10) break;
        $GLOBALS['out'][] = sprintf("    %-45s priority %d–%d (spread: %d)",
            substr($name, 0, 45), $p[0], $p[1], $p[1] - $p[0]);
    }
}

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
$hook_counts     = []; // hook_name => count of scheduled instances
$hook_schedules  = []; // hook_name => [schedule_name, ...]
$fast_hooks      = []; // hook_name => interval_seconds (flagged if < 300s / 5min)

// Known WP core cron hooks — not worth flagging individually
$core_cron_hooks = [
    'wp_scheduled_delete', 'wp_update_plugins', 'wp_update_themes',
    'wp_update_user_counts', 'wp_delete_temp_updater_backups',
    'wp_scheduled_auto_draft_delete', 'delete_expired_transients',
    'recovery_mode_clean_expired_keys', 'wp_privacy_delete_old_export_files',
    'wp_site_health_scheduled_check', 'wp_https_detection',
    'wp_version_check', 'wp_maybe_auto_update',
];

// Get registered schedules to look up interval
$schedules = wp_get_schedules();

if (is_array($cron_jobs)) {
    foreach ($cron_jobs as $timestamp => $hooks) {
        if ($timestamp < $now) $overdue++;
        foreach ($hooks as $hook_name => $events) {
            $count = count($events);
            $total_jobs += $count;
            $hook_counts[$hook_name] = ($hook_counts[$hook_name] ?? 0) + $count;

            // Capture schedule name and check for fast scheduling
            foreach ($events as $event) {
                $sched = $event['schedule'] ?? null;
                if ($sched) {
                    $hook_schedules[$hook_name][] = $sched;
                    $interval = $schedules[$sched]['interval'] ?? ($event['interval'] ?? null);
                    if ($interval && (int)$interval < 300) {
                        // Flag if runs more often than every 5 minutes
                        $fast_hooks[$hook_name] = (int)$interval;
                    }
                }
            }
        }
    }
}
row('Scheduled cron events', $total_jobs);
row('Overdue timestamps',    $overdue, $overdue > 10 ? 'WARN' : 'OK');

// Fast-scheduling hooks
if ($fast_hooks) {
    $GLOBALS['out'][] = '';
    $GLOBALS['out'][] = '  Hooks scheduled more often than every 5 minutes:';
    foreach ($fast_hooks as $hook => $interval) {
        $every = $interval >= 60 ? round($interval / 60, 1) . ' min' : $interval . 's';
        warn("  $hook (every $every)");
    }
}

// Top hooks by scheduled instance count, excluding core
arsort($hook_counts);
$plugin_cron = array_filter(
    $hook_counts,
    fn($name) => !in_array($name, $core_cron_hooks),
    ARRAY_FILTER_USE_KEY
);

if ($plugin_cron) {
    $GLOBALS['out'][] = '';
    $GLOBALS['out'][] = '  Cron hook breakdown (non-core, by instance count):';
    $shown = 0;
    foreach ($plugin_cron as $hook => $count) {
        if ($shown++ >= 20) break;
        // Resolve schedule label
        $scheds = array_unique($hook_schedules[$hook] ?? []);
        $sched_label = $scheds ? implode('/', $scheds) : 'once';
        $flag = '';
        if ($count > 10) $flag = ' [WARN: many instances]';
        $GLOBALS['out'][] = sprintf("    %-50s %d × %s%s",
            substr($hook, 0, 50), $count, $sched_label, $flag);
    }
} else {
    good('No non-core cron hooks found');
}

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

// Initialise cache-header vars — populated inside the response block, read in summary.
$x_nananana  = null; // from 1st request (no-cache)
$x_ac        = null; // from 1st request
$x_nananana2 = null; // from 2nd request (warm)
$x_ac2       = null; // from 2nd request

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

    $GLOBALS['out'][] = '';
    $GLOBALS['out'][] = '  Cache & CDN headers:';
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
    $GLOBALS['out'][] = '';
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
$GLOBALS['out'][] = '';

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
    $GLOBALS['out'][] = '';

    if ($wp_cookies) {
        bad('WordPress cookies present (' . count($wp_cookies) . ') — request will bypass page cache:');
        foreach ($wp_cookies as $name => $value) {
            $display = strlen($value) > 60 ? substr($value, 0, 60) . '…' : $value;
            row('  ' . $name, $display);
        }
        note('wp_/wordpress_ cookies are typically set on login. If seen on anonymous requests, a plugin may be misusing sessions.');
        $GLOBALS['out'][] = '';
    }

    if ($woo_cookies) {
        bad('WooCommerce cookies present (' . count($woo_cookies) . ') — request will bypass page cache:');
        foreach ($woo_cookies as $name => $value) {
            $display = strlen($value) > 60 ? substr($value, 0, 60) . '…' : $value;
            row('  ' . $name, $display);
        }
        note('woocommerce_ cookies on non-cart/checkout pages suggest session handling is too broad.');
        $GLOBALS['out'][] = '';
    }

    if ($other_cookies) {
        row('Other cookies (' . count($other_cookies) . ')', '');
        foreach ($other_cookies as $name => $value) {
            $display = strlen($value) > 60 ? substr($value, 0, 60) . '…' : $value;
            row('  ' . $name, $display);
        }
    }

    if ($wp_cookies || $woo_cookies) {
        $GLOBALS['out'][] = '';
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
// 10. FILESYSTEM
// ─────────────────────────────────────────────────────────────
section('10. FILESYSTEM');

$wp_content = WP_CONTENT_DIR;
$upload_dir = wp_upload_dir()['basedir'] ?? null;

// wp-content total size
$site_size_raw = shell_exec("du -sh " . escapeshellarg($wp_content) . " 2>/dev/null");
$site_size = $site_size_raw ? trim(explode("\t", $site_size_raw)[0]) : 'unavailable';
row('wp-content total size', $site_size);

// uploads total size
if ($upload_dir && is_dir($upload_dir)) {
    $uploads_size_raw = shell_exec("du -sh " . escapeshellarg($upload_dir) . " 2>/dev/null");
    $uploads_size = $uploads_size_raw ? trim(explode("\t", $uploads_size_raw)[0]) : 'unavailable';
    row('uploads total size', $uploads_size);
}

// Top 10 directories within wp-content by size
$GLOBALS['out'][] = '';
$GLOBALS['out'][] = '  Top 10 directories in wp-content by size:';
$dir_sizes_raw = shell_exec("du -hsx " . escapeshellarg($wp_content) . "/* 2>/dev/null | sort -rh | head -n 10");
if ($dir_sizes_raw) {
    foreach (explode("\n", trim($dir_sizes_raw)) as $line) {
        if (!$line) continue;
        [$size, $path] = explode("\t", $line, 2);
        row('  ' . basename($path), trim($size));
    }
} else {
    $GLOBALS['out'][] = '  (unavailable — shell_exec may be disabled)';
}

// Writability checks
$GLOBALS['out'][] = '';
$write_paths = [
    'WP_CONTENT_DIR' => WP_CONTENT_DIR,
    'Uploads dir'    => $upload_dir ?? 'unknown',
];
foreach ($write_paths as $label => $path) {
    row($label . ' writable', is_writable($path) ? 'Yes' : 'No');
}

// Upload year folders (rough indicator of media library age/volume)
if ($upload_dir && is_dir($upload_dir)) {
    $years = glob($upload_dir . '/[0-9][0-9][0-9][0-9]', GLOB_ONLYDIR);
    row('Upload year folders', count($years ?: []));
}

// ── Media library checks ─────────────────────────────────────
$GLOBALS['out'][] = '';
$GLOBALS['out'][] = '  Media library:';

// Total media items
$total_media = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'");
row('  Total media items', $total_media, (int)$total_media > 10000 ? 'WARN' : 'OK');

// Unattached media (post_parent = 0)
$unattached_media = $wpdb->get_var("
    SELECT COUNT(*) FROM {$wpdb->posts}
    WHERE post_type = 'attachment'
    AND post_status = 'inherit'
    AND post_parent = 0
");
row('  Unattached media items', $unattached_media,
    (int)$unattached_media > 1000 ? 'WARN' : ((int)$unattached_media > 100 ? 'INFO' : 'OK'));
if ((int)$unattached_media > 100) {
    note('Unattached media is not necessarily a problem, but large counts may indicate orphaned uploads');
}

// Large image files on disk (>2 MB) via find
if ($upload_dir && is_dir($upload_dir)) {
    $large_files_raw = shell_exec("find " . escapeshellarg($upload_dir) . " -type f \\( -iname '*.jpg' -o -iname '*.jpeg' -o -iname '*.png' -o -iname '*.gif' -o -iname '*.webp' \\) -size +2M 2>/dev/null | wc -l");
    $large_file_count = $large_files_raw ? (int)trim($large_files_raw) : null;
    if ($large_file_count !== null) {
        row('  Images >2 MB on disk', $large_file_count,
            $large_file_count > 50 ? 'WARN' : ($large_file_count > 20 ? 'INFO' : 'OK'));
        if ($large_file_count > 0) {
            // Show up to 5 examples, largest first
            $large_examples_raw = shell_exec("find " . escapeshellarg($upload_dir) . " -type f \\( -iname '*.jpg' -o -iname '*.jpeg' -o -iname '*.png' -o -iname '*.gif' -o -iname '*.webp' \\) -size +2M -printf '%s\t%p\n' 2>/dev/null | sort -rn | head -5");
            if ($large_examples_raw) {
                $GLOBALS['out'][] = '  Largest images on disk:';
                foreach (explode("\n", trim($large_examples_raw)) as $line) {
                    if (!$line) continue;
                    [$size_bytes, $fpath] = explode("\t", $line, 2);
                    $rel = ltrim(str_replace($upload_dir, '', $fpath), '/');
                    row('    ' . substr($rel, 0, 55), bytes((int)$size_bytes));
                }
            }
        }
    } else {
        row('  Images >2 MB on disk', 'unavailable (find/shell_exec disabled)', 'INFO');
    }
}

// ── Error log checks ─────────────────────────────────────────
$GLOBALS['out'][] = '';
$GLOBALS['out'][] = '  Error logs:';

// WP debug.log
$debug_log = WP_CONTENT_DIR . '/debug.log';
if (file_exists($debug_log)) {
    $debug_size = filesize($debug_log);
    row('  debug.log size', bytes($debug_size),
        $debug_size > 5242880 ? 'WARN' : ($debug_size > 524288 ? 'INFO' : 'OK'));
    if ($debug_size > 5242880) {
        note('debug.log is large — rotate or clear it, and ensure WP_DEBUG_LOG is off in production');
    }
} else {
    row('  debug.log', 'not found', 'INFO');
    note('Absence is normal when WP_DEBUG_LOG is off');
}

// PHP error log
$php_error_log = ini_get('error_log');
if ($php_error_log && file_exists($php_error_log)) {
    $php_log_size = filesize($php_error_log);
    row('  PHP error_log size', bytes($php_log_size),
        $php_log_size > 10485760 ? 'WARN' : ($php_log_size > 1048576 ? 'INFO' : 'OK'));
} elseif ($php_error_log) {
    row('  PHP error_log', 'configured but not found: ' . basename($php_error_log), 'INFO');
} else {
    row('  PHP error_log', 'not configured', 'INFO');
    note('Absence is normal on managed hosting where logs are handled at server level');
}

// ─────────────────────────────────────────────────────────────
// 11. WORDPRESS SITE HEALTH
// ─────────────────────────────────────────────────────────────
section('11. WORDPRESS SITE HEALTH');

// ── Post & page counts ───────────────────────────────────────
$post_types = get_post_types(['public' => true], 'objects');
$GLOBALS['out'][] = '  Published content counts:';
foreach ($post_types as $pt) {
    $counts = wp_count_posts($pt->name);
    $published = (int)($counts->publish ?? 0);
    if ($published === 0) continue; // skip empty post types
    $flag = ($published > 5000 && !in_array($pt->name, ['post', 'page', 'attachment']))
        ? 'WARN' : 'OK';
    row('  ' . $pt->label . ' (' . $pt->name . ')', number_format($published) . ' published', $flag);
    // Show other statuses if notable
    foreach (['draft', 'pending', 'future', 'trash'] as $status) {
        $cnt = (int)($counts->$status ?? 0);
        if ($cnt > 100) {
            row('    → ' . $status, number_format($cnt), 'INFO');
        }
    }
}

// ── Users by role ────────────────────────────────────────────
$GLOBALS['out'][] = '';
$GLOBALS['out'][] = '  Users by role:';
global $wp_roles;
if (!isset($wp_roles)) {
    $wp_roles = new WP_Roles();
}
$total_users = 0;
foreach (array_keys($wp_roles->roles) as $role) {
    $count = $wpdb->get_var($wpdb->prepare("
        SELECT COUNT(*) FROM {$wpdb->users} u
        INNER JOIN {$wpdb->usermeta} um ON u.ID = um.user_id
        WHERE um.meta_key = %s
        AND um.meta_value LIKE %s
    ", $wpdb->prefix . 'capabilities', '%"' . $role . '"%'));
    if ((int)$count === 0) continue;
    $flag = ($role === 'subscriber' && (int)$count > 10000) ? 'WARN' : 'OK';
    row('  ' . $role, number_format((int)$count), $flag);
    $total_users += (int)$count;
}
row('  Total (approx)', number_format($total_users));
if ($total_users > 10000) {
    note('Large user bases can slow admin queries; ensure proper indexing');
}

// ── Active theme details ─────────────────────────────────────
$GLOBALS['out'][] = '';
$theme = wp_get_theme();
$parent = $theme->parent();
$GLOBALS['out'][] = '  Active theme:';
// Fetch theme update transient once — keyed by stylesheet (directory name)
$theme_updates = get_site_transient('update_themes');
$theme_stylesheet  = $theme->get_stylesheet();   // child theme dir
$parent_stylesheet = $parent ? $parent->get_stylesheet() : null; // parent dir

$theme_new_ver  = $theme_updates->response[$theme_stylesheet]['new_version']  ?? null;
$parent_new_ver = $parent_stylesheet
    ? ($theme_updates->response[$parent_stylesheet]['new_version'] ?? null)
    : null;

row('  Theme name',    $theme->get('Name'));
$theme_ver_display = $theme->get('Version');
if ($theme_new_ver) {
    $theme_ver_display .= " (update available: $theme_new_ver)";
}
row('  Theme version', $theme_ver_display, $theme_new_ver ? 'WARN' : 'OK');

row('  Is child theme', $parent ? 'Yes (parent: ' . $parent->get('Name') . ')' : 'No');
if ($parent) {
    $parent_ver_display = $parent->get('Version');
    if ($parent_new_ver) {
        $parent_ver_display .= " (update available: $parent_new_ver)";
    }
    row('  Parent theme version', $parent_ver_display, $parent_new_ver ? 'WARN' : 'OK');
}

// Flag known resource-heavy themes
$heavy_themes = ['Divi', 'Avada', 'BeTheme', 'Enfold', 'X', 'Jupiter', 'Bridge', 'TheGem'];
$theme_name = $theme->get('Name');
$parent_name = $parent ? $parent->get('Name') : '';
foreach ($heavy_themes as $ht) {
    if (stripos($theme_name, $ht) !== false || stripos($parent_name, $ht) !== false) {
        warn("  '$ht' is a known resource-heavy theme — ensure adequate caching");
        break;
    }
}

// Count template/PHP files in theme
$theme_dir = $theme->get_stylesheet_directory();
$theme_php_files = glob($theme_dir . '/*.php');
$theme_file_count = $theme_php_files ? count($theme_php_files) : 0;
row('  Theme PHP files (root)', $theme_file_count);

// ── Orphaned term relationships ──────────────────────────────
$GLOBALS['out'][] = '';
$orphaned_terms = $wpdb->get_var("
    SELECT COUNT(*) FROM {$wpdb->term_relationships} tr
    LEFT JOIN {$wpdb->posts} p ON p.ID = tr.object_id
    WHERE p.ID IS NULL
");
row('Orphaned term relationships', $orphaned_terms,
    (int)$orphaned_terms > 500 ? 'WARN' : ((int)$orphaned_terms > 0 ? 'INFO' : 'OK'));

// ── Multisite-specific ───────────────────────────────────────
if (is_multisite()) {
    $GLOBALS['out'][] = '';
    $GLOBALS['out'][] = '  Multisite:';
    $site_count = get_sites(['count' => true]);
    row('  Network sites', $site_count, (int)$site_count > 100 ? 'INFO' : 'OK');
    $blog_table = $wpdb->blogs;
    $archived   = $wpdb->get_var("SELECT COUNT(*) FROM $blog_table WHERE archived = '1'");
    $deleted    = $wpdb->get_var("SELECT COUNT(*) FROM $blog_table WHERE deleted = '1'");
    row('  Archived sites', $archived);
    row('  Deleted sites',  $deleted);
}

// ─────────────────────────────────────────────────────────────
// 12. WOOCOMMERCE (only if WooCommerce is active)
// ─────────────────────────────────────────────────────────────
section('12. WOOCOMMERCE');
if (!class_exists('WooCommerce')) {
    note('WooCommerce not detected — section skipped');
} else {

    // WC version — check update_plugins transient for available update
    $wc_current_ver = defined('WC_VERSION') ? WC_VERSION : (WC()->version ?? 'unknown');
    $wc_new_ver = $update_transient->response['woocommerce/woocommerce.php']->new_version ?? null;
    $wc_ver_display = $wc_current_ver;
    if ($wc_new_ver && version_compare($wc_current_ver, $wc_new_ver, '<')) {
        $wc_ver_display .= " (update available: $wc_new_ver)";
    }
    row('WooCommerce version', $wc_ver_display, ($wc_new_ver && version_compare($wc_current_ver, $wc_new_ver, '<')) ? 'WARN' : 'OK');

    // ── HPOS (High-Performance Order Storage) ───────────────
    $hpos_enabled = get_option('woocommerce_feature_hpos_enabled') === 'yes'
        || (function_exists('wc_get_container') && class_exists('\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController'));
    // More reliable: check the feature flag option
    $hpos_option = get_option('woocommerce_custom_orders_table_enabled', 'no');
    row('HPOS (Custom Orders Table)', $hpos_option === 'yes' ? 'Enabled' : 'Disabled (legacy post table)',
        $hpos_option === 'yes' ? 'OK' : 'INFO');

    // ── Product counts ───────────────────────────────────────
    $product_counts = wp_count_posts('product');
    $published_products = (int)($product_counts->publish ?? 0);
    row('Published products', number_format($published_products),
        $published_products > 10000 ? 'WARN' : 'OK');
    if ($published_products > 10000) {
        note('Large product catalogs may benefit from dedicated query optimisation and object caching');
    }

    // ── Order counts by status ───────────────────────────────
    $GLOBALS['out'][] = '';
    $GLOBALS['out'][] = '  Order counts by status:';
    if ($hpos_option === 'yes') {
        // HPOS: orders are in wc_orders table
        $orders_table = $wpdb->prefix . 'wc_orders';
        $order_statuses = $wpdb->get_results("
            SELECT status, COUNT(*) AS cnt
            FROM `$orders_table`
            WHERE type = 'shop_order'
            GROUP BY status
            ORDER BY cnt DESC
        ");
        if ($order_statuses) {
            foreach ($order_statuses as $s) {
                row('  ' . $s->status, number_format((int)$s->cnt),
                    in_array($s->status, ['wc-on-hold', 'wc-pending']) && (int)$s->cnt > 100 ? 'WARN' : 'OK');
            }
        }
    } else {
        // Legacy: orders in wp_posts
        $wc_statuses = ['wc-pending', 'wc-processing', 'wc-on-hold', 'wc-completed', 'wc-cancelled', 'wc-refunded', 'wc-failed'];
        foreach ($wc_statuses as $status) {
            $cnt = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'shop_order' AND post_status = %s",
                $status
            ));
            if ((int)$cnt === 0) continue;
            $flag = in_array($status, ['wc-on-hold', 'wc-pending']) && (int)$cnt > 100 ? 'WARN' : 'OK';
            row('  ' . $status, number_format((int)$cnt), $flag);
        }
    }

    // ── Sessions table ───────────────────────────────────────
    $GLOBALS['out'][] = '';
    $sessions_table = $wpdb->prefix . 'woocommerce_sessions';
    $sessions_exists = $wpdb->get_var("SHOW TABLES LIKE '$sessions_table'") === $sessions_table;
    if ($sessions_exists) {
        $sess_count = $wpdb->get_var("SELECT COUNT(*) FROM `$sessions_table`");
        $sess_table_info = $wpdb->get_row("SHOW TABLE STATUS LIKE '$sessions_table'");
        $sess_size = $sess_table_info ? (int)$sess_table_info->Data_length + (int)$sess_table_info->Index_length : 0;
        row('WC sessions (total)', number_format((int)$sess_count),
            (int)$sess_count > 10000 ? 'WARN' : 'OK');
        row('WC sessions table size', bytes($sess_size));

        // Abandoned sessions (session_expiry in the past = expired, or > 24h old)
        $abandoned = $wpdb->get_var("
            SELECT COUNT(*) FROM `$sessions_table`
            WHERE session_expiry < UNIX_TIMESTAMP()
        ");
        row('WC expired sessions', number_format((int)$abandoned),
            (int)$abandoned > 1000 ? 'WARN' : ((int)$abandoned > 0 ? 'INFO' : 'OK'));
        if ((int)$abandoned > 1000) {
            note('Expired sessions bloat the DB — run: wp wc session delete --all or use WooCommerce built-in cleanup');
        }
    } else {
        row('WC sessions table', 'not found', 'INFO');
    }

    // ── Active payment gateways ──────────────────────────────
    $GLOBALS['out'][] = '';
    $gateways_raw = get_option('woocommerce_gateway_order', []);
    // Get enabled gateways more reliably from individual gateway options
    $enabled_gateways = [];
    if (function_exists('WC')) {
        $available_gateways = WC()->payment_gateways ? WC()->payment_gateways->get_available_payment_gateways() : [];
        foreach ($available_gateways as $gw_id => $gw) {
            $enabled_gateways[] = $gw->get_title() . ' (' . $gw_id . ')';
        }
    }
    if ($enabled_gateways) {
        row('Active payment gateways', count($enabled_gateways));
        foreach ($enabled_gateways as $gw) {
            $GLOBALS['out'][] = '    ' . $gw;
        }
    } else {
        row('Active payment gateways', 'none detected', 'WARN');
    }

    // ── WooCommerce cron jobs ────────────────────────────────
    $GLOBALS['out'][] = '';
    $wc_cron_jobs = [];
    $overdue_wc_cron = 0;
    if (is_array($cron_jobs)) {
        foreach ($cron_jobs as $timestamp => $hooks) {
            foreach ($hooks as $hook_name => $events) {
                if (str_starts_with($hook_name, 'woocommerce_') || str_starts_with($hook_name, 'wc_')) {
                    $wc_cron_jobs[$hook_name] = ($wc_cron_jobs[$hook_name] ?? 0) + count($events);
                    if ($timestamp < $now) $overdue_wc_cron++;
                }
            }
        }
    }
    row('WC cron hook types', count($wc_cron_jobs));
    row('WC overdue cron events', $overdue_wc_cron, $overdue_wc_cron > 5 ? 'WARN' : 'OK');
    if ($wc_cron_jobs) {
        arsort($wc_cron_jobs);
        $GLOBALS['out'][] = '  WooCommerce cron hooks:';
        foreach (array_slice($wc_cron_jobs, 0, 15, true) as $hook => $count) {
            $GLOBALS['out'][] = sprintf("    %-55s %d instance(s)", substr($hook, 0, 55), $count);
        }
    }
}

// ─────────────────────────────────────────────────────────────
// 13. OUTBOUND HTTP HEALTH
// ─────────────────────────────────────────────────────────────
section('13. OUTBOUND HTTP');

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
// 14. RESOURCE USAGE (this script execution)
// ─────────────────────────────────────────────────────────────
section('14. RESOURCE USAGE (this script)');

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

// Batcache: use the warm (2nd) request header for the most reliable signal
$batcache_hit   = $x_nananana2 !== null && stripos($x_nananana2, 'Batcache-Hit') !== false;
$batcache_set   = ($x_nananana !== null && stripos($x_nananana, 'Batcache-Set') !== false)
               || ($x_nananana2 !== null && stripos($x_nananana2, 'Batcache-Set') !== false);
$batcache_seen  = $x_nananana !== null || $x_nananana2 !== null;
if (!$batcache_hit && !$batcache_set && !$batcache_seen && $is_batcache)
    $issues[] = 'Batcache drop-in is present but x-nananana header was absent — Batcache may be bypassed';
elseif (!$batcache_hit && $batcache_set)
    $issues[] = 'Batcache SET on warm request — cache was written but a HIT was not confirmed';
elseif (!$batcache_seen && !$is_batcache)
    $issues[] = 'No Batcache drop-in detected and x-nananana absent — page caching is likely not active';

// Edge cache (x-ac)
$edge_hit    = ($x_ac2 !== null && stripos($x_ac2, 'HIT') !== false)
            || ($x_ac !== null && stripos($x_ac, 'HIT') !== false);
$edge_bypass = ($x_ac !== null && stripos($x_ac, 'BYPASS') !== false)
            || ($x_ac2 !== null && stripos($x_ac2, 'BYPASS') !== false);
$edge_miss   = !$edge_hit && !$edge_bypass
            && ($x_ac !== null || $x_ac2 !== null)
            && (($x_ac !== null && stripos($x_ac, 'MISS') !== false)
                || ($x_ac2 !== null && stripos($x_ac2, 'MISS') !== false));
if ($edge_bypass)
    $issues[] = 'Edge cache BYPASS (x-ac) — caching is being skipped; investigate cache-busting cookies or headers';
elseif ($edge_miss)
    $issues[] = 'Edge cache MISS on warm request (x-ac) — page may not be caching at the edge';
elseif ($x_ac === null && $x_ac2 === null)
    $issues[] = 'x-ac header absent — edge cache not confirmed active for this URL';

if ($autoload_kb > 200)          $issues[] = "Autoloaded options are large ({$autoload_kb} KB) — audit with Query Monitor";
if ((int)$expired_transients > 100) $issues[] = "Many expired transients ({$expired_transients}) — run WP-CLI: wp transient delete --expired";
if ((int)$revision_count > 500)  $issues[] = "High revision count ({$revision_count}) — set WP_POST_REVISIONS in wp-config.php";
if ((int)$spam_comments > 500)   $issues[] = "High spam comment count — empty spam from Dashboard > Comments";
if (!(defined('DISABLE_WP_CRON') && DISABLE_WP_CRON)) $issues[] = 'WP-Cron runs on HTTP requests — consider DISABLE_WP_CRON with server cron';
if (defined('WP_DEBUG') && WP_DEBUG) $issues[] = 'WP_DEBUG is ON in production';
if (isset($php_minimum) && $php_minimum && version_compare(PHP_VERSION, $php_minimum, '<'))
    $issues[] = 'PHP ' . PHP_VERSION . " is below WordPress minimum ($php_minimum) — upgrade urgently";
elseif (isset($php_recommended) && $php_recommended && version_compare(PHP_VERSION, $php_recommended, '<'))
    $issues[] = 'PHP ' . PHP_VERSION . " is below WordPress recommended version ($php_recommended)";
if (isset($wp_latest_ver) && $wp_latest_ver && version_compare($wp_current_ver, $wp_latest_ver, '<'))
    $issues[] = "WordPress is outdated ($wp_current_ver → $wp_latest_ver)";
if (isset($theme_new_ver) && $theme_new_ver)
    $issues[] = "Active theme \"" . $theme->get('Name') . "\" has an update available (→ $theme_new_ver)";
if (isset($parent_new_ver) && $parent_new_ver)
    $issues[] = "Parent theme \"" . $parent->get('Name') . "\" has an update available (→ $parent_new_ver)";
if (isset($wc_new_ver) && $wc_new_ver && isset($wc_current_ver) && version_compare($wc_current_ver, $wc_new_ver, '<'))
    $issues[] = "WooCommerce is outdated ($wc_current_ver → $wc_new_ver)";
// OPcache: only recommend enabling it if the site has enough PHP complexity
// to make a meaningful difference — i.e. a page builder, high plugin count,
// or high asset count. Low-complexity sites wouldn't benefit noticeably.
$has_builder        = !empty(array_filter($found_perf_plugins, fn($p) => $p[1] === 'builder'));
$high_plugin_count  = count($active_plugins) >= 40;
$high_scripts       = $enqueued_scripts > 20;
$high_styles        = $enqueued_styles > 15;
$other_signals      = (int)$has_builder + (int)$high_scripts + (int)$high_styles;

// Show OPcache recommendation only when there's meaningful PHP complexity:
// - 40+ plugins alone, OR
// - 40+ plugins plus at least one other signal, OR
// - both high scripts AND high styles (without needing high plugin count)
$opcache_worthwhile = !$opcache_enabled && (
    $high_plugin_count ||
    $other_signals >= 2
);
if ($opcache_worthwhile) {
    $reasons = [];
    if ($high_plugin_count) $reasons[] = count($active_plugins) . ' active plugins';
    if ($has_builder)       $reasons[] = 'page builder active';
    if ($high_scripts)      $reasons[] = $enqueued_scripts . ' enqueued scripts';
    if ($high_styles)       $reasons[] = $enqueued_styles . ' enqueued styles';
    $issues[] = 'OPcache not enabled — consider enabling it (' . implode(', ', $reasons) . ')';
}
if ($enqueued_scripts > 20)      $issues[] = "High enqueued script count ($enqueued_scripts) — check for bloat";
if ($enqueued_styles > 15)       $issues[] = "High enqueued style count ($enqueued_styles) — check for bloat";

// DB enhancements
if (isset($orphaned_postmeta) && (int)$orphaned_postmeta > 1000)
    $issues[] = "Large orphaned postmeta count ({$orphaned_postmeta} rows) — safe to clean up";
if (isset($orphaned_usermeta) && (int)$orphaned_usermeta > 500)
    $issues[] = "Orphaned usermeta rows ({$orphaned_usermeta}) — may indicate plugin cleanup issues";
if (isset($orphaned_terms) && (int)$orphaned_terms > 500)
    $issues[] = "Orphaned term relationships ({$orphaned_terms}) — run wp term recount";
if (isset($fragmented) && count($fragmented) > 0)
    $issues[] = count($fragmented) . ' fragmented table(s) detected — consider OPTIMIZE TABLE';
if (isset($index_issues) && $index_issues > 0)
    $issues[] = "$index_issues missing core table index(es) detected";

// Cron
if (isset($fast_hooks) && count($fast_hooks) > 0)
    $issues[] = count($fast_hooks) . ' cron hook(s) running more often than every 5 min';

// Media
if (isset($large_file_count) && $large_file_count > 50)
    $issues[] = "$large_file_count images >2 MB found — consider optimising or off-loading media";

// Error logs
if (isset($debug_size) && $debug_size > 5242880)
    $issues[] = 'debug.log is very large (' . bytes($debug_size) . ') — investigate and rotate';
if (isset($php_log_size) && $php_log_size > 10485760)
    $issues[] = 'PHP error_log is very large (' . bytes($php_log_size) . ') — investigate and rotate';

// ── Positives ────────────────────────────────────────────────

// Caching
if ($using_external_cache)       $wins[] = 'External object cache is active';
if ($opcache_enabled)            $wins[] = 'OPcache is enabled';
if ($batcache_hit)               $wins[] = 'Batcache is working — confirmed HIT on warm request (x-nananana)';
if ($edge_hit)                   $wins[] = 'Edge cache is working — confirmed HIT (x-ac)';

// Performance
if (isset($ttfb) && $ttfb < 0.3) $wins[] = 'Excellent TTFB (' . ms($ttfb) . ')';
if ($savequeries_on && $wpdb->num_queries < 50) $wins[] = 'Low query count for this request';

// PHP / WordPress versions
if (isset($php_recommended) && !version_compare(PHP_VERSION, $php_recommended, '<'))
    $wins[] = 'PHP ' . PHP_VERSION . ' meets WordPress recommended version';
if (isset($wp_latest_ver) && $wp_latest_ver && !version_compare($wp_current_ver, $wp_latest_ver, '<'))
    $wins[] = 'WordPress is up to date (' . $wp_current_ver . ')';

// Plugins
if ($plugins_needing_update === 0)
    $wins[] = 'All plugins are up to date';

// Theme
if (isset($theme_new_ver) && !$theme_new_ver && isset($parent_new_ver) && !$parent_new_ver)
    $wins[] = 'Active theme is up to date';
elseif (isset($theme_new_ver) && !$theme_new_ver && !$parent)
    $wins[] = 'Active theme is up to date';

// DB health
if (isset($orphaned_postmeta) && (int)$orphaned_postmeta === 0) $wins[] = 'No orphaned postmeta';
if (isset($orphaned_usermeta) && (int)$orphaned_usermeta === 0) $wins[] = 'No orphaned usermeta';
if (isset($fragmented) && count($fragmented) === 0)             $wins[] = 'No table fragmentation detected';
if (isset($index_issues) && $index_issues === 0)                $wins[] = 'All core table indexes present';
if ((int)$expired_transients === 0)                             $wins[] = 'No expired transients in DB';
if ((int)$revision_count <= 100)                                $wins[] = 'Post revision count is healthy (' . $revision_count . ')';
if ($autoload_kb <= 200)                                        $wins[] = 'Autoloaded options size is healthy (' . $autoload_kb . ' KB)';

// Cron
if (isset($fast_hooks) && count($fast_hooks) === 0) $wins[] = 'No unusually fast cron scheduling';
if ($overdue === 0)                                  $wins[] = 'No overdue cron events';

// WooCommerce (only meaningful if WC is active)
if (class_exists('WooCommerce')) {
    if (isset($wc_new_ver) && !($wc_new_ver && version_compare($wc_current_ver, $wc_new_ver, '<')))
        $wins[] = 'WooCommerce is up to date (' . ($wc_current_ver ?? '') . ')';
    if (isset($abandoned) && (int)$abandoned === 0)
        $wins[] = 'No expired WooCommerce sessions';
}

$GLOBALS['out'][] = '';
if ($wins) {
    $GLOBALS['out'][] = '  ✓ Positives:';
    foreach ($wins as $w) good("  $w");
}

$GLOBALS['out'][] = '';
if ($issues) {
    $GLOBALS['out'][] = '  ⚠ Issues/Recommendations:';
    foreach ($issues as $i) warn("  $i");
} else {
    good('  No major issues detected!');
}

$GLOBALS['out'][] = '';
$GLOBALS['out'][] = '  Generated: ' . date('Y-m-d H:i:s T');
$GLOBALS['out'][] = '  Site: ' . get_site_url();
$GLOBALS['out'][] = '';

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

echo implode("\n", $GLOBALS['out']) . "\n";
