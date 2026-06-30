<?php
/**
 * Plugin Name: Nilson Lab SMS
 * Description: ارسال خودکار پیامک (ملی‌پیامک – خط خدماتی) بر اساس ثبت‌نام دیجیتس و وضعیت سفارش ووکامرس.
 * Version: 1.0.0
 * Author: Nilson Lab
 * Text Domain: nilsonlab-sms
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // جلوگیری از دسترسی مستقیم
}

define( 'NLSMS_VERSION', '1.0.0' );
define( 'NLSMS_PATH', plugin_dir_path( __FILE__ ) );
define( 'NLSMS_URL', plugin_dir_url( __FILE__ ) );
define( 'NLSMS_LOG_DIR', NLSMS_PATH . 'logs/' );

require_once NLSMS_PATH . 'includes/class-logger.php';
require_once NLSMS_PATH . 'includes/class-sms-client.php';
require_once NLSMS_PATH . 'includes/class-settings.php';
require_once NLSMS_PATH . 'includes/class-hooks.php';

/**
 * ساخت پوشه و فایل‌های لاگ هنگام فعال‌سازی + محافظت از دسترسی مستقیم
 */
function nlsms_activate() {
    if ( ! file_exists( NLSMS_LOG_DIR ) ) {
        wp_mkdir_p( NLSMS_LOG_DIR );
    }

    // محافظت لاگ‌ها از دسترسی مستقیم وب
    $htaccess = NLSMS_LOG_DIR . '.htaccess';
    if ( ! file_exists( $htaccess ) ) {
        file_put_contents( $htaccess, "Order deny,allow\nDeny from all\n" );
    }

    foreach ( array( 'error.log', 'success.log' ) as $file ) {
        $path = NLSMS_LOG_DIR . $file;
        if ( ! file_exists( $path ) ) {
            file_put_contents( $path, '' );
        }
    }
}
register_activation_hook( __FILE__, 'nlsms_activate' );

/**
 * راه‌اندازی افزونه
 */
function nlsms_init() {
    NLSMS_Settings::instance();
    NLSMS_Hooks::instance();
}
add_action( 'plugins_loaded', 'nlsms_init' );
