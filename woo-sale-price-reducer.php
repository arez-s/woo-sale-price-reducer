<?php
/**
 * Plugin Name: کاهش قیمت فروش ویژه و بازگردانی
 * Description: کاهش درصدی قیمت فروش ویژهٔ فعال ووکامرس، با گزارش قیمت و بازگردانی هر نوبت.
 * Version: 1.0.1
 * Author: Alireza Sabahi
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 8.5
 * License: GPL-3.0-or-later
 * Text Domain: woo-sale-price-reducer
 */

defined( 'ABSPATH' ) || exit;

define( 'WSPR_VERSION', '1.0.1' );
define( 'WSPR_FILE', __FILE__ );
require_once __DIR__ . '/includes/class-wspr-engine.php';
require_once __DIR__ . '/includes/class-wspr-admin.php';

register_activation_hook( __FILE__, array( 'WSPR_Engine', 'install' ) );
add_action( 'plugins_loaded', function () {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function () {
            if ( current_user_can( 'activate_plugins' ) ) {
                echo '<div class="notice notice-error"><p>افزونهٔ کاهش قیمت فروش ویژه به ووکامرسِ فعال نیاز دارد.</p></div>';
            }
        } );
        return;
    }
    if ( class_exists( 'SGSPR_Engine', false ) ) {
        add_action( 'admin_notices', function () {
            if ( current_user_can( 'manage_woocommerce' ) ) {
                echo '<div class="notice notice-warning"><p>نسخهٔ قبلی افزونهٔ کاهش قیمت را غیرفعال کنید و صفحه را تازه کنید. گزارش‌های قبلی به نسخهٔ جدید منتقل می‌شوند.</p></div>';
            }
        } );
        return;
    }
    if ( get_option( 'wspr_version' ) !== WSPR_VERSION ) {
        WSPR_Engine::install();
    }
    new WSPR_Admin();
} );
