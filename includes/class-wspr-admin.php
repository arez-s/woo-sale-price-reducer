<?php
defined( 'ABSPATH' ) || exit;

class WSPR_Admin {
    private $engine;

    public function __construct() {
        $this->engine = new WSPR_Engine();
        add_action( 'admin_menu', array( $this, 'menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
        add_action( 'wp_ajax_wspr_action', array( $this, 'ajax' ) );
    }

    public function menu() {
        add_submenu_page( 'woocommerce', 'کاهش قیمت فروش ویژه', 'کاهش قیمت ویژه', 'manage_woocommerce', 'wspr', array( $this, 'page' ) );
    }

    public function assets( $hook ) {
        if ( 'woocommerce_page_wspr' !== $hook ) {
            return;
        }
        wp_enqueue_style( 'wspr', plugins_url( 'assets/admin.css', WSPR_FILE ), array(), WSPR_VERSION );
        wp_enqueue_script( 'wspr', plugins_url( 'assets/admin.js', WSPR_FILE ), array(), WSPR_VERSION, true );
        wp_localize_script( 'wspr', 'WSPR', array(
            'url' => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'wspr' ),
            'page' => admin_url( 'admin.php?page=wspr' ),
        ) );
    }

    public function ajax() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => 'دسترسی کافی ندارید.' ), 403 );
        }
        check_ajax_referer( 'wspr', 'nonce' );
        $command = isset( $_POST['command'] ) && is_string( $_POST['command'] ) ? sanitize_key( $_POST['command'] ) : '';
        $id = isset( $_POST['run_id'] ) ? absint( $_POST['run_id'] ) : 0;
        try {
            $result = $this->engine->locked( function () use ( $command, $id ) {
                switch ( $command ) {
                    case 'start':
                        $percent = isset( $_POST['percent'] ) ? wp_unslash( $_POST['percent'] ) : '';
                        $key = isset( $_POST['request_key'] ) ? wp_unslash( $_POST['request_key'] ) : '';
                        return $this->engine->start( $percent, $key );
                    case 'restore':
                        return $this->engine->begin_restore( $id );
                    case 'step':
                        return $this->engine->step( $id );
                    default:
                        throw new InvalidArgumentException( 'درخواست ناشناخته است.' );
                }
            } );
            wp_send_json_success( $result );
        } catch ( Throwable $e ) {
            wp_send_json_error( array( 'message' => $e->getMessage() ), 400 );
        }
    }

    public static function status( $status ) {
        $labels = array(
            'applying' => 'در حال کاهش قیمت', 'completed' => 'کاهش قیمت تمام شد',
            'restoring' => 'در حال بازگردانی', 'restored' => 'بازگردانده شد',
            'restored_with_conflicts' => 'بازگردانی با موارد نیازمند بررسی', 'failed' => 'ناموفق؛ بدون تغییر قیمت',
            'pending' => 'در صف', 'prepared' => 'ثبت قیمت قبلی؛ در انتظار ذخیره',
            'applied' => 'قیمت جدید ذخیره شد', 'skipped' => 'بدون تغییر',
            'cancelled' => 'لغو شد', 'apply_conflict' => 'تغییر قیمت نیازمند بررسی',
            'restore_conflict' => 'بازگردانی نیازمند بررسی',
        );
        return isset( $labels[$status] ) ? $labels[$status] : $status;
    }

    private function date( $value ) {
        return $value ? wp_date( 'Y/m/d H:i:s', strtotime( $value . ' UTC' ) ) : '—';
    }

    private function price( $value, $currency ) {
        return '' === $value ? '—' : wp_kses_post( wc_price( $value, array( 'currency' => $currency ) ) );
    }

    public function page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'دسترسی کافی ندارید.' );
        }
        $runs = $this->engine->recent();
        $selected = isset( $_GET['run_id'] ) ? absint( $_GET['run_id'] ) : ( $runs ? (int) $runs[0]['id'] : 0 );
        if ( $selected && ! in_array( $selected, array_map( 'intval', array_column( $runs, 'id' ) ), true ) ) {
            try {
                // Older journals remain actionable when opened through their permanent URL.
                $runs[] = $this->engine->run( $selected );
            } catch ( Throwable $e ) {
                // The log section below displays the missing-run message.
            }
        }
        $page = isset( $_GET['log_page'] ) ? max( 1, absint( $_GET['log_page'] ) ) : 1;
        ?>
        <div class="wrap wspr" dir="rtl">
            <h1>کاهش قیمت فروش ویژه</h1>
            <p>درصد دلخواه از <strong>قیمت فروش ویژهٔ فعلی</strong> کم و مستقیماً در فروشگاه ذخیره می‌شود.</p>
            <section class="wspr-card">
                <form id="wspr-form">
                    <label for="wspr-percent"><strong>درصد کاهش قیمت ویژه</strong></label>
                    <div class="wspr-fields">
                        <input id="wspr-percent" name="percent" type="text" inputmode="decimal" placeholder="مثلاً ۱۰" required maxlength="6" autocomplete="off" aria-describedby="wspr-example">
                        <span>درصد</span>
                        <button class="button button-primary" type="submit">اعمال و ذخیرهٔ قیمت‌های جدید</button>
                    </div>
                    <p id="wspr-example">مثال: قیمت ویژهٔ ۸۰۰٬۰۰۰ با ۱۰٪ کاهش ← ۷۲۰٬۰۰۰</p>
                    <p>فقط محصولات منتشرشده با فروش ویژهٔ فعال و تنوع‌های آن‌ها. قیمت عادی، موجودی و محتوای محصول تغییر نمی‌کند. تخفیف‌های زمان‌بندی‌شدهٔ آینده و تخفیف‌های تمام‌شده کنار گذاشته می‌شوند.</p>
                    <p>هر نوبت جدید، درصد را از قیمت ویژهٔ همان لحظه کم می‌کند. برای برگرداندن قیمت قبلی از دکمهٔ «بازگردانی» همان نوبت استفاده کنید.</p>
                </form>
            </section>
            <section class="wspr-card" id="wspr-progress" hidden aria-live="polite">
                <strong id="wspr-progress-title">در حال پردازش…</strong>
                <progress id="wspr-bar" max="100" value="0"></progress>
                <p id="wspr-progress-text"></p>
                <button class="button" id="wspr-pause" type="button">توقف بعد از این بخش</button>
                <p>تا پایان عملیات این صفحه را باز نگه دارید. در صورت قطع ارتباط، از تاریخچه «ادامه» را بزنید.</p>
            </section>
            <div id="wspr-error" class="notice notice-error inline" role="alert" hidden></div>
            <h2>تاریخچه و بازگردانی قیمت‌ها</h2>
            <p>بازگردانی با یک کلیک، قیمت ویژه را به مقدار پیش از همان نوبت برمی‌گرداند. اگر بعداً قیمت یا زمان‌بندی محصول ویرایش شده باشد، آن مورد تغییر نمی‌کند و در گزارش مشخص می‌شود. نوبت‌های متعدد را از جدیدترین به قدیمی‌ترین بازگردانی کنید.</p>
            <div class="wspr-scroll">
                <table class="widefat striped"><thead><tr><th>نوبت</th><th>زمان شروع</th><th>کاهش</th><th>تعداد نامزدها</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody>
                <?php if ( ! $runs ) : ?>
                    <tr><td colspan="6">هنوز قیمتی تغییر نکرده است.</td></tr>
                <?php endif; ?>
                <?php foreach ( $runs as $run ) : ?>
                    <tr>
                        <td>#<?php echo (int) $run['id']; ?></td>
                        <td><?php echo esc_html( $this->date( $run['created_at'] ) ); ?></td>
                        <td><?php echo esc_html( $run['percent'] ); ?>٪</td>
                        <td><?php echo (int) $run['total']; ?></td>
                        <td><?php echo esc_html( self::status( $run['status'] ) ); ?></td>
                        <td class="wspr-actions">
                            <a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'wspr', 'run_id' => $run['id'] ), admin_url( 'admin.php' ) ) ); ?>">گزارش قیمت</a>
                            <?php if ( in_array( $run['status'], array( 'applying', 'restoring' ), true ) ) : ?>
                                <button class="button wspr-command" data-command="step" data-run="<?php echo (int) $run['id']; ?>">ادامه</button>
                            <?php endif; ?>
                            <?php if ( in_array( $run['status'], array( 'completed', 'applying', 'restored_with_conflicts' ), true ) ) : ?>
                                <button class="button wspr-command" data-command="restore" data-run="<?php echo (int) $run['id']; ?>">بازگردانی قیمت‌های قبلی</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table>
            </div>
            <?php
            if ( $selected ) {
                try {
                    $this->render_logs( $selected, $page );
                } catch ( Throwable $e ) {
                    echo '<p>' . esc_html( $e->getMessage() ) . '</p>';
                }
            }
            ?>
        </div>
        <?php
    }

    private function render_logs( $id, $page ) {
        $run = $this->engine->summary( $id );
        $rows = $this->engine->logs( $id, $page );
        echo '<h2>گزارش قیمت نوبت #' . (int) $id . '</h2><p>';
        foreach ( $run['counts'] as $status => $count ) {
            echo '<span class="wspr-count">' . esc_html( self::status( $status ) ) . ': ' . (int) $count . '</span> ';
        }
        echo '</p><div class="wspr-scroll"><table class="widefat striped"><thead><tr><th>محصول / تنوع</th><th>ویژهٔ قبلی</th><th>ویژهٔ جدید</th><th>زمان تغییر</th><th>زمان بازگردانی</th><th>وضعیت</th><th>توضیح</th></tr></thead><tbody>';
        foreach ( $rows as $row ) {
            $before = json_decode( $row['before_json'] ?? '', true );
            $after = json_decode( $row['after_json'] ?? '', true );
            $title = get_the_title( $row['product_id'] );
            $url = get_edit_post_link( $row['product_id'] );
            echo '<tr><td>#' . (int) $row['product_id'] . ' ';
            if ( $url ) {
                echo '<a href="' . esc_url( $url ) . '">' . esc_html( $title ?: 'محصول حذف‌شده' ) . '</a>';
            } else {
                echo esc_html( $title ?: 'محصول حذف‌شده' );
            }
            echo '</td><td>' . ( $before ? $this->price( $before['sale'], $run['currency'] ) : '—' ) . '</td>';
            echo '<td>' . ( $after ? $this->price( $after['sale'], $run['currency'] ) : '—' ) . '</td>';
            echo '<td>' . esc_html( $this->date( $row['changed_at'] ) ) . '</td>';
            echo '<td>' . esc_html( $this->date( $row['restored_at'] ) ) . '</td>';
            echo '<td>' . esc_html( self::status( $row['status'] ) ) . '</td>';
            echo '<td>' . esc_html( $row['message'] ?? '' ) . '</td></tr>';
        }
        if ( ! $rows ) {
            echo '<tr><td colspan="7">موردی برای نمایش نیست.</td></tr>';
        }
        echo '</tbody></table></div><div class="wspr-pagination">';
        $pages = max( 1, (int) ceil( (int) $run['total'] / 50 ) );
        if ( $page > 1 ) {
            echo '<a class="button" href="' . esc_url( add_query_arg( array( 'page' => 'wspr', 'run_id' => $id, 'log_page' => $page - 1 ), admin_url( 'admin.php' ) ) ) . '">صفحهٔ قبل</a> ';
        }
        echo '<span>صفحهٔ ' . (int) $page . ' از ' . (int) $pages . '</span> ';
        if ( $page < $pages ) {
            echo '<a class="button" href="' . esc_url( add_query_arg( array( 'page' => 'wspr', 'run_id' => $id, 'log_page' => $page + 1 ), admin_url( 'admin.php' ) ) ) . '">صفحهٔ بعد</a>';
        }
        echo '</div>';
    }
}
