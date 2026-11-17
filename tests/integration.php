<?php
/** Run ONLY against a disposable, local WordPress installation with WooCommerce active. */
if ( PHP_SAPI !== 'cli' || empty( $argv[1] ) ) {
    exit( "Usage: php tests/integration.php /path/to/disposable/wordpress\n" );
}
require rtrim( $argv[1], '/\\' ) . '/wp-load.php';
if ( 'local' !== wp_get_environment_type() || ! class_exists( 'WooCommerce' ) ) {
    exit( "Requires WP_ENVIRONMENT_TYPE=local and active WooCommerce.\n" );
}
require_once dirname( __DIR__ ) . '/includes/class-wspr-engine.php';
if ( ! defined( 'WSPR_VERSION' ) ) {
    define( 'WSPR_VERSION', '1.0.1' );
}
WSPR_Engine::install();
$tester = get_user_by( 'login', 'plugin_tester' );
if ( ! $tester ) {
    exit( "Create an administrator named plugin_tester in this disposable test site.\n" );
}
wp_set_current_user( $tester->ID );
$original_currency = get_option( 'woocommerce_currency' );
$original_decimals = get_option( 'woocommerce_price_num_decimals' );
update_option( 'woocommerce_currency', 'IRT' );
update_option( 'woocommerce_price_num_decimals', 0 );
$engine = new WSPR_Engine();
$products = array();
$runs = array();
$checks = 0;
$legacy_fixture = null;

function verify( $condition, $label ) {
    global $checks;
    if ( ! $condition ) {
        throw new RuntimeException( 'FAIL: ' . $label );
    }
    ++$checks;
    echo 'PASS: ' . $label . PHP_EOL;
}

function simple_fixture( $sale = '800000', $props = array() ) {
    global $products;
    $p = new WC_Product_Simple();
    $p->set_props( array_merge( array( 'name' => 'آزمایش قیمت ویژه', 'status' => 'publish', 'regular_price' => '1000000', 'sale_price' => $sale ), $props ) );
    $p->save();
    $products[] = $p->get_id();
    return $p->get_id();
}

function sale( $id ) {
    clean_post_cache( $id );
    wp_cache_delete( $id, 'post_meta' );
    return (float) wc_get_product( $id )->get_sale_price( 'edit' );
}

function start_run( $percent = '10' ) {
    global $engine, $runs;
    $run = $engine->start( $percent, 'integration-' . wp_generate_uuid4() );
    $runs[] = (int) $run['id'];
    return $run;
}

function finish_run( $run ) {
    global $engine;
    for ( $i = 0; $i < 200; ++$i ) {
        if ( ! in_array( $run['status'], array( 'applying', 'restoring' ), true ) ) {
            return $run;
        }
        $run = $engine->step( $run['id'] );
    }
    throw new RuntimeException( 'Run did not finish' );
}

function restore_run( $run ) {
    global $engine;
    return finish_run( $engine->begin_restore( $run['id'] ) );
}

function journal_for( $run_id, $product_id ) {
    global $wpdb;
    return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wspr_items WHERE run_id = %d AND product_id = %d", $run_id, $product_id ), ARRAY_A );
}

try {
    // Upgrade fixture: imported prices must remain reversible under the generic namespace.
    $wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}sgspr_runs LIKE {$wpdb->prefix}wspr_runs" );
    $wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}sgspr_items LIKE {$wpdb->prefix}wspr_items" );
    $legacy_product = simple_fixture();
    $old_snapshot = WSPR_Engine::snapshot( wc_get_product( $legacy_product ) );
    $new_snapshot = $old_snapshot;
    $new_snapshot['sale'] = '720000';
    $p = wc_get_product( $legacy_product ); $p->set_sale_price( '720000' ); $p->save();
    $legacy_key = 'integration-legacy-' . wp_generate_uuid4();
    $wpdb->insert( $wpdb->prefix . 'sgspr_runs', array(
        'request_key' => $legacy_key, 'percent' => '10.00', 'currency' => 'IRT', 'decimals' => 0,
        'status' => 'completed', 'created_at' => current_time( 'mysql', true ), 'created_by' => $tester->ID, 'total' => 1,
    ) );
    $legacy_id = (int) $wpdb->insert_id;
    $legacy_fixture = $legacy_id;
    $wpdb->insert( $wpdb->prefix . 'sgspr_items', array(
        'run_id' => $legacy_id, 'product_id' => $legacy_product, 'status' => 'applied',
        'before_json' => wp_json_encode( $old_snapshot ), 'after_json' => wp_json_encode( $new_snapshot ),
        'changed_at' => current_time( 'mysql', true ),
    ) );
    delete_option( 'wspr_legacy_imported' );
    WSPR_Engine::install();
    $runs[] = $legacy_id;
    $imported = $engine->summary( $legacy_id );
    verify( $imported['request_key'] === $legacy_key && $imported['counts']['applied'] === 1, 'Previous package price journal imported' );
    verify( sale( $legacy_product ) === 720000.0, 'Journal migration never changes product price' );
    restore_run( $imported );
    verify( sale( $legacy_product ) === 800000.0, 'Imported previous-package price restores from 720000 to 800000' );
    $p = wc_get_product( $legacy_product ); $p->set_sale_price( '' ); $p->save();

    if ( ! defined( 'DB_ENGINE' ) || 'sqlite' !== DB_ENGINE ) {
        verify( $engine->locked( function () { return true; } ), 'MySQL mutex acquired and callback executed' );
        $contention = $engine->locked( function () use ( $wpdb ) {
            $port = 3306;
            $host = DB_HOST;
            if ( preg_match( '/^([^:]+):(\d+)$/', $host, $parts ) ) { $host = $parts[1]; $port = (int) $parts[2]; }
            $other = new mysqli( $host, DB_USER, DB_PASSWORD, DB_NAME, $port );
            $name = 'wspr_' . md5( DB_NAME . '|' . $wpdb->prefix );
            $result = $other->query( "SELECT GET_LOCK('" . $other->real_escape_string( $name ) . "', 0)" )->fetch_row();
            $other->close();
            return (int) $result[0] === 0;
        } );
        verify( $contention, 'A second MySQL connection cannot enter an active batch' );
        try { $engine->locked( function () { throw new RuntimeException( 'test' ); } ); } catch ( RuntimeException $e ) { /* Expected. */ }
        verify( $engine->locked( function () { return true; } ), 'Mutex released even after an exception' );
    }
    verify( WSPR_Engine::percent( '۱۰٫۵' ) === '10.50', 'Persian percent and decimal separator' );
    verify( WSPR_Engine::percent( '٢٫٢٥' ) === '2.25', 'Arabic percent input' );
    foreach ( array( '0', '100', '-1', '10.001', '1e1', '<script>', array( 10 ) ) as $invalid ) {
        $rejected = false;
        try { WSPR_Engine::percent( $invalid ); } catch ( InvalidArgumentException $e ) { $rejected = true; }
        verify( $rejected, 'Reject invalid percent ' . wp_json_encode( $invalid ) );
    }
    verify( WSPR_Engine::reduced_price( '800000', '10.00', 0 ) === '720000', '800000 to 720000' );
    verify( WSPR_Engine::reduced_price( '19.99', '12.50', 2 ) === '17.49', 'Currency decimal rounding' );

    $normal = simple_fixture( '' );
    $active = simple_fixture();
    $draft = simple_fixture( '800000', array( 'status' => 'draft' ) );
    $future = simple_fixture( '800000', array( 'date_on_sale_from' => time() + DAY_IN_SECONDS ) );
    $expired = simple_fixture( '800000', array( 'date_on_sale_to' => time() - DAY_IN_SECONDS ) );
    $zero = simple_fixture( '0' );
    $tiny = simple_fixture( '0.001' );
    $scheduled = simple_fixture( '800000', array( 'date_on_sale_from' => time() - 3600, 'date_on_sale_to' => time() + DAY_IN_SECONDS ) );
    $initial_schedule = WSPR_Engine::snapshot( wc_get_product( $scheduled ) );
    for ( $i = 0; $i < 17; ++$i ) {
        simple_fixture();
    }
    $parent = new WC_Product_Variable();
    $parent->set_name( 'والد آزمایشی' );
    $parent->set_status( 'publish' );
    $parent->save();
    $products[] = $parent->get_id();
    $variation = new WC_Product_Variation();
    $variation->set_props( array( 'parent_id' => $parent->get_id(), 'status' => 'publish', 'regular_price' => '1000000', 'sale_price' => '800000' ) );
    $variation->save();
    $variation_id = $variation->get_id();
    $products[] = $variation_id;
    WC_Product_Variable::sync( $parent->get_id() );

    $key = 'integration-' . wp_generate_uuid4();
    $run = $engine->start( '۱۰', $key );
    $runs[] = (int) $run['id'];
    verify( $engine->start( '۱۰', $key )['id'] == $run['id'], 'Start request is idempotent' );
    verify( ! journal_for( $run['id'], $normal ), 'No-sale products never enter the queue' );
    verify( ! journal_for( $run['id'], $future ), 'Future sales excluded at start' );
    verify( ! journal_for( $run['id'], $expired ), 'Expired sales excluded at start' );
    $busy = false;
    try { start_run(); } catch ( RuntimeException $e ) { $busy = true; }
    verify( $busy, 'Unfinished run blocks new price reduction' );
    $run = $engine->step( $run['id'] );
    verify( $run['status'] === 'applying' && ! empty( $run['counts']['pending'] ), 'Large run preserves remaining queue after one batch' );
    $engine = new WSPR_Engine();
    $run = finish_run( $run );
    verify( sale( $active ) === 720000.0, 'Active simple sale reduced and persisted' );
    verify( (float) wc_get_product( $active )->get_price( 'edit' ) === 720000.0, 'Effective storefront price updated' );
    verify( (float) wc_get_product( $active )->get_regular_price( 'edit' ) === 1000000.0, 'Regular price preserved' );
    verify( sale( $variation_id ) === 720000.0, 'Variation sale reduced' );
    verify( (float) wc_get_product( $parent->get_id() )->get_variation_price( 'min', false ) === 720000.0, 'Variable parent price synchronized' );
    verify( sale( $draft ) === 800000.0, 'Draft product untouched' );
    verify( wc_get_product( $normal )->get_sale_price( 'edit' ) === '', 'Product without sale untouched' );
    verify( sale( $zero ) === 0.0 && sale( $tiny ) === 0.001, 'Free and rounded-to-zero sales untouched' );
    $new_schedule = WSPR_Engine::snapshot( wc_get_product( $scheduled ) );
    verify( $new_schedule['from'] === $initial_schedule['from'] && $new_schedule['to'] === $initial_schedule['to'], 'Sale schedule preserved' );
    $journal = journal_for( $run['id'], $active );
    verify( json_decode( $journal['before_json'], true )['sale'] == '800000' && json_decode( $journal['after_json'], true )['sale'] == '720000' && $journal['changed_at'], 'Price journal contains before, after and time' );
    $engine->step( $run['id'] );
    verify( sale( $active ) === 720000.0, 'Repeating completed batch does not compound discount' );
    $run = restore_run( $run );
    verify( sale( $active ) === 800000.0 && sale( $variation_id ) === 800000.0, 'One restore action returns 720000 to 800000 including variation' );
    verify( (float) wc_get_product( $parent->get_id() )->get_variation_price( 'min', false ) === 800000.0, 'Parent price synchronized after rollback' );
    verify( $run['status'] === 'restored' && journal_for( $run['id'], $active )['restored_at'], 'Restoration journal recorded' );
    restore_run( $run );
    verify( sale( $active ) === 800000.0, 'Repeat rollback is idempotent' );

    $a = finish_run( start_run() );
    $b = finish_run( start_run() );
    verify( sale( $active ) === 648000.0, 'Separate runs intentionally use current sale price' );
    $a = restore_run( $a );
    verify( sale( $active ) === 648000.0 && $a['status'] === 'restored_with_conflicts', 'Older rollback cannot overwrite newer run' );
    restore_run( $b );
    verify( sale( $active ) === 720000.0, 'Latest rollback returns to prior run price' );
    restore_run( $a );
    verify( sale( $active ) === 800000.0, 'Older rollback succeeds after latest was restored' );

    $run = finish_run( start_run() );
    $p = wc_get_product( $active );
    $p->set_sale_price( '700000' );
    $p->save();
    $run = restore_run( $run );
    verify( sale( $active ) === 700000.0 && $run['status'] === 'restored_with_conflicts', 'Manual subsequent sale edit preserved by rollback' );
    $p = wc_get_product( $active ); $p->set_sale_price( '720000' ); $p->save();
    restore_run( $run );
    verify( sale( $active ) === 800000.0, 'Conflict can be retried after review' );

    $run = finish_run( start_run() );
    $p = wc_get_product( $active ); $p->set_regular_price( '1100000' ); $p->save();
    $run = restore_run( $run );
    verify( sale( $active ) === 720000.0 && journal_for( $run['id'], $active )['status'] === 'restore_conflict', 'Subsequent regular-price edit preserved' );
    $p = wc_get_product( $active ); $p->set_regular_price( '1000000' ); $p->save();
    restore_run( $run );

    $run = finish_run( start_run() );
    $p = wc_get_product( $active ); $p->set_date_on_sale_to( time() + DAY_IN_SECONDS ); $p->save();
    $run = restore_run( $run );
    verify( sale( $active ) === 720000.0 && journal_for( $run['id'], $active )['status'] === 'restore_conflict', 'Subsequent sale-schedule edit preserved' );
    $p = wc_get_product( $active ); $p->set_date_on_sale_to( null ); $p->save();
    restore_run( $run );

    $run = start_run();
    restore_run( $run );
    verify( sale( $active ) === 800000.0 && journal_for( $run['id'], $active )['status'] === 'cancelled', 'Rollback of unfinished run cancels unprocessed items' );

    $run = start_run();
    $row = journal_for( $run['id'], $active );
    $before = WSPR_Engine::snapshot( wc_get_product( $active ) );
    $after = $before; $after['sale'] = '720000';
    $wpdb->update( $wpdb->prefix . 'wspr_items', array( 'status' => 'prepared', 'before_json' => wp_json_encode( $before ), 'after_json' => wp_json_encode( $after ) ), array( 'id' => $row['id'] ) );
    $p = wc_get_product( $active ); $p->set_sale_price( '720000' ); $p->save();
    $run = finish_run( $run );
    verify( sale( $active ) === 720000.0 && journal_for( $run['id'], $active )['status'] === 'applied', 'Recover interrupted save without applying percent twice' );
    restore_run( $run );

    // Fail the journal write itself. Prices must remain unchanged.
    $run = start_run();
    $reject_journal = function ( $sql ) use ( $wpdb ) {
        if ( strpos( $sql, 'UPDATE `' . $wpdb->prefix . 'wspr_items`' ) === 0 && strpos( $sql, "'prepared'" ) !== false ) {
            return 'SELECT * FROM wspr_intentionally_missing_table';
        }
        return $sql;
    };
    $wpdb->suppress_errors( true );
    add_filter( 'query', $reject_journal );
    $stopped = false;
    try { $engine->step( $run['id'] ); } catch ( RuntimeException $e ) { $stopped = true; }
    remove_filter( 'query', $reject_journal );
    $wpdb->suppress_errors( false );
    verify( $stopped && sale( $active ) === 800000.0, 'Journal write failure stops before any product price write' );
    restore_run( $run );

    $run = start_run();
    update_option( 'woocommerce_currency', 'USD' );
    $stopped = false;
    try { $engine->step( $run['id'] ); } catch ( RuntimeException $e ) { $stopped = true; }
    update_option( 'woocommerce_currency', 'IRT' );
    verify( $stopped && sale( $active ) === 800000.0, 'Currency change stops processing' );
    restore_run( $run );
    echo wp_json_encode( array( 'passed' => $checks, 'wordpress' => get_bloginfo( 'version' ), 'woocommerce' => WC_VERSION, 'php' => PHP_VERSION, 'database' => defined( 'DB_ENGINE' ) ? DB_ENGINE : 'mysql', 'note' => 'SQLite does not verify the MySQL named mutex; use MySQL/MariaDB to test that feature.' ), JSON_PRETTY_PRINT ) . PHP_EOL;
} finally {
    // Only fixture IDs created by this script are deleted. Do not run on a real shop.
    foreach ( array_reverse( $products ) as $id ) {
        wp_delete_post( $id, true );
    }
    foreach ( $runs as $id ) {
        $wpdb->delete( $wpdb->prefix . 'wspr_items', array( 'run_id' => $id ) );
        $wpdb->delete( $wpdb->prefix . 'wspr_runs', array( 'id' => $id ) );
    }
    update_option( 'woocommerce_currency', $original_currency );
    update_option( 'woocommerce_price_num_decimals', $original_decimals );
    if ( $legacy_fixture ) {
        $wpdb->delete( $wpdb->prefix . 'sgspr_items', array( 'run_id' => $legacy_fixture ) );
        $wpdb->delete( $wpdb->prefix . 'sgspr_runs', array( 'id' => $legacy_fixture ) );
    }
}
