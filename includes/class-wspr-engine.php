<?php
defined( 'ABSPATH' ) || exit;

/** Price-only write-ahead journal. No orders, inventory or product content are changed. */
class WSPR_Engine {
    private $runs;
    private $items;

    public function __construct() {
        global $wpdb;
        $this->runs  = $wpdb->prefix . 'wspr_runs';
        $this->items = $wpdb->prefix . 'wspr_items';
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        dbDelta( "CREATE TABLE {$wpdb->prefix}wspr_runs (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            request_key varchar(64) NOT NULL,
            percent decimal(5,2) NOT NULL,
            currency varchar(12) NOT NULL,
            decimals tinyint unsigned NOT NULL,
            status varchar(32) NOT NULL,
            created_at datetime NOT NULL,
            created_by bigint(20) unsigned NOT NULL,
            total bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY request_key (request_key),
            KEY status (status)
        ) $charset;" );
        dbDelta( "CREATE TABLE {$wpdb->prefix}wspr_items (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            run_id bigint(20) unsigned NOT NULL,
            product_id bigint(20) unsigned NOT NULL,
            status varchar(32) NOT NULL DEFAULT 'pending',
            before_json longtext NULL,
            after_json longtext NULL,
            changed_at datetime NULL,
            restored_at datetime NULL,
            message text NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY run_product (run_id,product_id),
            KEY run_status (run_id,status),
            KEY product_id (product_id)
        ) $charset;" );
        $tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'wspr_' ) . '%' ) );
        if ( count( $tables ) < 2 ) {
            throw new RuntimeException( 'ساخت جدول گزارش قیمت ممکن نشد. دسترسی پایگاه داده را بررسی کنید.' );
        }
        self::import_legacy_journal();
        update_option( 'wspr_version', WSPR_VERSION, false );
    }

    /** Preserve rollback data when upgrading from the first package's journal namespace. */
    private static function import_legacy_journal() {
        global $wpdb;
        if ( get_option( 'wspr_legacy_imported' ) ) {
            return;
        }
        $columns = array(
            'runs' => 'id,request_key,percent,currency,decimals,status,created_at,created_by,total',
            'items' => 'id,run_id,product_id,status,before_json,after_json,changed_at,restored_at,message',
        );
        $legacy = $wpdb->prefix . 'sgspr_';
        foreach ( $columns as $suffix => $list ) {
            $table = $legacy . $suffix;
            $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
            if ( $wpdb->last_error ) {
                throw new RuntimeException( 'بررسی گزارش‌های قیمت نسخهٔ قبلی انجام نشد.' );
            }
            if ( $exists ) {
                $result = $wpdb->query( "INSERT IGNORE INTO {$wpdb->prefix}wspr_{$suffix} ($list) SELECT $list FROM $table" );
                if ( false === $result ) {
                    throw new RuntimeException( 'انتقال گزارش‌های قیمت نسخهٔ قبلی انجام نشد؛ عملیات قیمت‌گذاری متوقف است.' );
                }
            }
        }
        update_option( 'wspr_legacy_imported', 1, false );
    }

    /** MySQL connection-scoped mutex: automatically released even if PHP is killed. */
    public function locked( $callback ) {
        global $wpdb;
        $name = 'wspr_' . md5( DB_NAME . '|' . $wpdb->prefix );
        if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) ) ) {
            throw new RuntimeException( 'عملیات دیگری در حال اجراست؛ چند ثانیه بعد دوباره تلاش کنید.' );
        }
        try {
            return call_user_func( $callback );
        } finally {
            $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
        }
    }

    public static function percent( $input ) {
        if ( ! is_scalar( $input ) ) {
            throw new InvalidArgumentException( 'درصد معتبر وارد کنید.' );
        }
        $input = strtr( trim( (string) $input ), array_combine(
            preg_split( '//u', '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩٫', -1, PREG_SPLIT_NO_EMPTY ),
            str_split( '01234567890123456789.' )
        ) );
        if ( ! preg_match( '/^\d{1,2}(?:\.\d{1,2})?$/D', $input ) || (float) $input <= 0 || (float) $input >= 100 ) {
            throw new InvalidArgumentException( 'درصد باید بیشتر از صفر و کمتر از ۱۰۰ باشد؛ حداکثر دو رقم اعشار.' );
        }
        return number_format( (float) $input, 2, '.', '' );
    }

    public static function reduced_price( $sale, $percent, $decimals ) {
        // WooCommerce currency precision, half-up. Never silently create a free product.
        $value = round( (float) $sale * ( 10000 - (int) round( (float) $percent * 100 ) ) / 10000, (int) $decimals, PHP_ROUND_HALF_UP );
        return number_format( $value, (int) $decimals, '.', '' );
    }

    private function checked( $result ) {
        if ( false === $result ) {
            throw new RuntimeException( 'ثبت گزارش در پایگاه داده انجام نشد؛ عملیات متوقف شد. پس از رفع مشکل ادامه دهید.' );
        }
        return $result;
    }

    private function checked_read() {
        global $wpdb;
        if ( $wpdb->last_error ) {
            throw new RuntimeException( 'خواندن گزارش قیمت از پایگاه داده ممکن نشد؛ عملیات متوقف شد.' );
        }
    }

    private function item_update( $id, $data ) {
        global $wpdb;
        $this->checked( $wpdb->update( $this->items, $data, array( 'id' => (int) $id ) ) );
    }

    public function run( $id ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->runs} WHERE id = %d", $id ), ARRAY_A );
        if ( ! $row ) {
            throw new RuntimeException( 'نوبت موردنظر پیدا نشد.' );
        }
        return $row;
    }

    public function start( $input, $request_key ) {
        global $wpdb;
        $percent = self::percent( $input );
        if ( ! is_string( $request_key ) || ! preg_match( '/^[a-zA-Z0-9-]{16,64}$/D', $request_key ) ) {
            throw new InvalidArgumentException( 'شناسهٔ درخواست معتبر نیست. صفحه را تازه کنید.' );
        }
        $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->runs} WHERE request_key = %s", $request_key ) );
        $this->checked_read();
        if ( $existing ) {
            return $this->summary( (int) $existing );
        }
        $busy = $wpdb->get_var( "SELECT id FROM {$this->runs} WHERE status IN ('applying','restoring') LIMIT 1" );
        $this->checked_read();
        if ( $busy ) {
            throw new RuntimeException( 'ابتدا عملیات ناتمام شمارهٔ ' . (int) $busy . ' را ادامه دهید یا بازگردانی کنید.' );
        }
        $this->checked( $wpdb->insert( $this->runs, array(
            'request_key' => $request_key,
            'percent' => $percent,
            'currency' => get_woocommerce_currency(),
            'decimals' => wc_get_price_decimals(),
            'status' => 'applying',
            'created_at' => current_time( 'mysql', true ),
            'created_by' => get_current_user_id(),
        ) ) );
        $id = (int) $wpdb->insert_id;
        // Freeze candidate IDs. Eligibility (including active schedule and type) is rechecked on each item.
        // INSERT SELECT avoids loading the whole catalogue into PHP memory.
        try {
            $this->checked( $wpdb->query( $wpdb->prepare(
                "INSERT INTO {$this->items} (run_id, product_id, status)
                 SELECT DISTINCT %d, p.ID, 'pending'
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_sale_price'
                 LEFT JOIN {$wpdb->posts} parent ON parent.ID = p.post_parent
                 WHERE p.post_status = 'publish' AND m.meta_value <> '' AND CAST(m.meta_value AS DECIMAL(30,8)) > 0
                 AND (p.post_type = 'product' OR (p.post_type = 'product_variation' AND parent.post_status = 'publish'))
                 AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} future_sale WHERE future_sale.post_id = p.ID AND future_sale.meta_key = '_sale_price_dates_from' AND CAST(future_sale.meta_value AS SIGNED) > %d)
                 AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} expired_sale WHERE expired_sale.post_id = p.ID AND expired_sale.meta_key = '_sale_price_dates_to' AND CAST(expired_sale.meta_value AS SIGNED) > 0 AND CAST(expired_sale.meta_value AS SIGNED) < %d)", $id, time(), time()
            ) ) );
            $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->items} WHERE run_id = %d", $id ) );
            $this->checked_read();
            $this->checked( $wpdb->update( $this->runs, array( 'total' => $total, 'status' => $total ? 'applying' : 'completed' ), array( 'id' => $id ) ) );
        } catch ( Throwable $e ) {
            // No product has been touched yet. Keep the failed run visible, without blocking later runs.
            $wpdb->update( $this->runs, array( 'status' => 'failed' ), array( 'id' => $id ) );
            throw $e;
        }
        return $this->summary( $id );
    }

    public function begin_restore( $id ) {
        global $wpdb;
        $run = $this->run( $id );
        if ( 'restoring' === $run['status'] ) {
            return $this->summary( $id );
        }
        $other = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->runs} WHERE id <> %d AND status IN ('applying','restoring') LIMIT 1", $id ) );
        $this->checked_read();
        if ( $other ) {
            throw new RuntimeException( 'ابتدا عملیات ناتمام شمارهٔ ' . (int) $other . ' را تمام کنید.' );
        }
        // Also cancels unprocessed products when rolling back a partially completed run.
        $this->checked( $wpdb->query( $wpdb->prepare( "UPDATE {$this->items} SET status = 'cancelled', message = 'پیش از تغییر لغو شد.' WHERE run_id = %d AND status = 'pending'", $id ) ) );
        $this->checked( $wpdb->query( $wpdb->prepare( "UPDATE {$this->items} SET status = 'applied' WHERE run_id = %d AND status = 'restore_conflict'", $id ) ) );
        $this->checked( $wpdb->update( $this->runs, array( 'status' => 'restoring' ), array( 'id' => $id ) ) );
        return $this->summary( $id );
    }

    public static function snapshot( $product ) {
        $from = $product->get_date_on_sale_from( 'edit' );
        $to   = $product->get_date_on_sale_to( 'edit' );
        return array(
            'regular' => (string) $product->get_regular_price( 'edit' ),
            'sale' => (string) $product->get_sale_price( 'edit' ),
            'from' => $from ? $from->getTimestamp() : null,
            'to' => $to ? $to->getTimestamp() : null,
        );
    }

    public static function same( $a, $b ) {
        if ( ! is_array( $a ) || ! is_array( $b ) ) {
            return false;
        }
        foreach ( array( 'sale', 'regular' ) as $key ) {
            if ( ! array_key_exists( $key, $a ) || ! array_key_exists( $key, $b ) ) {
                return false;
            }
            if ( '' === $a[$key] || '' === $b[$key] ) {
                if ( $a[$key] !== $b[$key] ) {
                    return false;
                }
            } elseif ( (float) $a[$key] !== (float) $b[$key] ) {
                return false;
            }
        }
        return array_key_exists( 'from', $a ) && array_key_exists( 'to', $a ) &&
            array_key_exists( 'from', $b ) && array_key_exists( 'to', $b ) &&
            $a['from'] === $b['from'] && $a['to'] === $b['to'];
    }

    private function fresh_product( $id ) {
        clean_post_cache( $id );
        wp_cache_delete( $id, 'post_meta' );
        return wc_get_product( $id );
    }

    private function eligible( $product ) {
        if ( ! $product || ! $product->is_type( array( 'simple', 'variation', 'external' ) ) || 'publish' !== $product->get_status( 'edit' ) ) {
            return false;
        }
        if ( $product->is_type( 'variation' ) && 'publish' !== get_post_status( $product->get_parent_id() ) ) {
            return false;
        }
        return $product->is_on_sale( 'edit' ) && (float) $product->get_sale_price( 'edit' ) > 0;
    }

    private function save_sale( $product, $target ) {
        // Only sale price is changed; WooCommerce updates the effective price and lookup caches.
        $product->set_sale_price( $target['sale'] );
        if ( ! $product->save() ) {
            throw new RuntimeException( 'ذخیرهٔ قیمت محصول انجام نشد؛ عملیات را ادامه دهید.' );
        }
        $this->sync_parent( $product );
        $actual = $this->fresh_product( $product->get_id() );
        if ( ! $actual || ! self::same( self::snapshot( $actual ), $target ) ) {
            throw new RuntimeException( 'قیمت ذخیره‌شده با مقدار موردنظر یکسان نیست؛ احتمال دخالت افزونهٔ دیگر وجود دارد. گزارش را بررسی کنید.' );
        }
    }

    private function sync_parent( $product ) {
        wc_delete_product_transients( $product->get_id() );
        if ( $product->is_type( 'variation' ) && $product->get_parent_id() ) {
            WC_Product_Variable::sync( $product->get_parent_id() );
            wc_delete_product_transients( $product->get_parent_id() );
        }
    }

    private function apply_item( $row, $run ) {
        $product = $this->fresh_product( (int) $row['product_id'] );
        if ( ! current_user_can( 'edit_post', $product && $product->get_parent_id() ? $product->get_parent_id() : (int) $row['product_id'] ) ) {
            // A prepared journal must stay recoverable by an authorized administrator.
            if ( 'prepared' === $row['status'] ) {
                throw new RuntimeException( 'برای ادامهٔ این محصول دسترسی ویرایش لازم است.' );
            }
            $this->item_update( $row['id'], array( 'status' => 'skipped', 'message' => 'دسترسی ویرایش این محصول وجود ندارد.' ) );
            return;
        }
        if ( 'pending' === $row['status'] ) {
            if ( ! $this->eligible( $product ) ) {
                $this->item_update( $row['id'], array( 'status' => 'skipped', 'message' => 'فروش ویژهٔ فعال یا نوع محصولِ پشتیبانی‌شده ندارد.' ) );
                return;
            }
            $before = self::snapshot( $product );
            $after  = $before;
            $after['sale'] = self::reduced_price( $before['sale'], $run['percent'], $run['decimals'] );
            if ( (float) $after['sale'] <= 0 || (float) $after['sale'] >= (float) $before['sale'] ) {
                $this->item_update( $row['id'], array( 'status' => 'skipped', 'message' => 'با دقت واحد پول، قیمت کاهش نمی‌یابد یا صفر می‌شود.' ) );
                return;
            }
            // This write MUST succeed before any WooCommerce write.
            $this->item_update( $row['id'], array( 'status' => 'prepared', 'before_json' => wp_json_encode( $before ), 'after_json' => wp_json_encode( $after ) ) );
        } else {
            $before = json_decode( $row['before_json'], true );
            $after  = json_decode( $row['after_json'], true );
        }
        if ( ! $product || ! $before || ! $after ) {
            $this->item_update( $row['id'], array( 'status' => 'apply_conflict', 'message' => 'محصول حذف شده یا گزارش قیمت قابل خواندن نیست.' ) );
            return;
        }
        // Reload after the journal write, so concurrent edits during preparation are noticed.
        $product = $this->fresh_product( (int) $row['product_id'] );
        if ( ! $product ) {
            $this->item_update( $row['id'], array( 'status' => 'apply_conflict', 'message' => 'محصول پس از ثبت گزارش حذف شده است.' ) );
            return;
        }
        $current = self::snapshot( $product );
        if ( self::same( $current, $after ) ) {
            // Retry after save succeeded but the request ended before marking the journal.
            $this->sync_parent( $product );
        } elseif ( self::same( $current, $before ) && $this->eligible( $product ) ) {
            $this->save_sale( $product, $after );
        } else {
            $this->item_update( $row['id'], array( 'status' => 'apply_conflict', 'message' => 'قیمت یا زمان‌بندی پس از ثبت گزارش تغییر کرده؛ محصول تغییر نکرد.' ) );
            return;
        }
        $this->item_update( $row['id'], array( 'status' => 'applied', 'changed_at' => current_time( 'mysql', true ), 'message' => 'قیمت ویژهٔ جدید ذخیره شد.' ) );
    }

    private function restore_item( $row ) {
        global $wpdb;
        $product = $this->fresh_product( (int) $row['product_id'] );
        if ( ! current_user_can( 'edit_post', $product && $product->get_parent_id() ? $product->get_parent_id() : (int) $row['product_id'] ) ) {
            throw new RuntimeException( 'برای بازگردانی این محصول دسترسی ویرایش لازم است.' );
        }
        $before = json_decode( $row['before_json'], true );
        $after  = json_decode( $row['after_json'], true );
        $newer  = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$this->items} WHERE product_id = %d AND id > %d AND status IN ('applied','prepared','restoring','restore_conflict') LIMIT 1", $row['product_id'], $row['id']
        ) );
        $this->checked_read();
        if ( ! $product || ! $before || ! $after || $newer ) {
            $this->item_update( $row['id'], array( 'status' => 'restore_conflict', 'message' => $newer ? 'ابتدا نوبت جدیدتر این محصول را بازگردانی کنید.' : 'محصول حذف شده یا گزارش قیمت قابل خواندن نیست.' ) );
            return;
        }
        $current = self::snapshot( $product );
        if ( self::same( $current, $before ) ) {
            // Already restored, or an interrupted apply never reached the WooCommerce save.
            $this->sync_parent( $product );
        } elseif ( self::same( $current, $after ) ) {
            $this->item_update( $row['id'], array( 'status' => 'restoring' ) );
            $this->save_sale( $product, $before );
        } else {
            $this->item_update( $row['id'], array( 'status' => 'restore_conflict', 'message' => 'قیمت عادی، قیمت ویژه یا زمان‌بندی بعداً تغییر کرده است؛ برای حفظ تغییر جدید بازگردانی نشد.' ) );
            return;
        }
        $this->item_update( $row['id'], array( 'status' => 'restored', 'restored_at' => current_time( 'mysql', true ), 'message' => 'قیمت فروش ویژهٔ قبلی بازگردانده شد.' ) );
    }

    public function step( $id ) {
        global $wpdb;
        $run = $this->run( $id );
        if ( ! in_array( $run['status'], array( 'applying', 'restoring' ), true ) ) {
            return $this->summary( $id );
        }
        if ( $run['currency'] !== get_woocommerce_currency() || (int) $run['decimals'] !== (int) wc_get_price_decimals() ) {
            throw new RuntimeException( 'واحد پول یا دقت اعشار فروشگاه از زمان شروع عوض شده است؛ ابتدا تنظیمات قبلی را برگردانید.' );
        }
        $restoring = 'restoring' === $run['status'];
        $states = $restoring ? "'applied','prepared','restoring'" : "'pending','prepared'";
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->items} WHERE run_id = %d AND status IN ($states) ORDER BY id ASC LIMIT 15", $id ), ARRAY_A );
        if ( $wpdb->last_error ) {
            throw new RuntimeException( 'خواندن گزارش قیمت ممکن نشد.' );
        }
        $started = microtime( true );
        foreach ( $rows as $row ) {
            if ( $restoring ) {
                $this->restore_item( $row );
            } else {
                $this->apply_item( $row, $run );
            }
            if ( microtime( true ) - $started > 8 ) {
                break;
            }
        }
        $left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->items} WHERE run_id = %d AND status IN ($states)", $id ) );
        $this->checked_read();
        if ( 0 === $left ) {
            $conflicts = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->items} WHERE run_id = %d AND status = 'restore_conflict'", $id ) );
            $this->checked_read();
            $status = $restoring ? ( $conflicts ? 'restored_with_conflicts' : 'restored' ) : 'completed';
            $this->checked( $wpdb->update( $this->runs, array( 'status' => $status ), array( 'id' => $id ) ) );
        }
        return $this->summary( $id );
    }

    public function summary( $id ) {
        global $wpdb;
        $run = $this->run( $id );
        $groups = $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) AS amount FROM {$this->items} WHERE run_id = %d GROUP BY status", $id ), ARRAY_A );
        $this->checked_read();
        $run['counts'] = array();
        foreach ( $groups as $group ) {
            $run['counts'][$group['status']] = (int) $group['amount'];
        }
        // A request can end after queue insertion but before total was stored.
        $actual_total = array_sum( $run['counts'] );
        if ( (int) $run['total'] !== $actual_total ) {
            $this->checked( $wpdb->update( $this->runs, array( 'total' => $actual_total ), array( 'id' => (int) $id ) ) );
            $run['total'] = $actual_total;
        }
        return $run;
    }

    public function recent() {
        global $wpdb;
        return $wpdb->get_results( "SELECT * FROM {$this->runs} ORDER BY id DESC LIMIT 30", ARRAY_A );
    }

    public function logs( $id, $page = 1 ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->items} WHERE run_id = %d ORDER BY id ASC LIMIT 50 OFFSET %d", $id, ( max( 1, (int) $page ) - 1 ) * 50 ), ARRAY_A );
    }
}
