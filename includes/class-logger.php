<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * مدیریت لاگ خطا و موفقیت
 */
class NLSMS_Logger {

    const ERROR_FILE   = 'error.log';
    const SUCCESS_FILE = 'success.log';

    /**
     * نوشتن در یک فایل لاگ مشخص (JSON Lines)
     */
    private static function write( $file, array $data ) {
        $line = wp_json_encode(
            array_merge(
                array( 'time' => current_time( 'mysql' ) ),
                $data
            ),
            JSON_UNESCAPED_UNICODE
        ) . PHP_EOL;

        // قفل برای جلوگیری از خرابی همزمانی
        file_put_contents( NLSMS_LOG_DIR . $file, $line, FILE_APPEND | LOCK_EX );
    }

    /**
     * ثبت خطا
     *
     * @param string $scenario سناریو (register/order/shipped/test)
     * @param string $to       شماره مقصد
     * @param mixed  $message  پیام خطا یا کد خطا
     * @param array  $context  اطلاعات تکمیلی
     */
    public static function error( $scenario, $to, $message, $context = array() ) {
        self::write(
            self::ERROR_FILE,
            array(
                'level'    => 'ERROR',
                'scenario' => $scenario,
                'to'       => $to,
                'message'  => $message,
                'context'  => $context,
            )
        );
    }

    /**
     * ثبت ارسال موفق
     *
     * @param string $scenario  سناریو
     * @param string $to        شماره مقصد
     * @param string $bodyId    کد الگو
     * @param string $recId     شناسه پیام بازگشتی
     * @param array  $context   اطلاعات تکمیلی (مثلاً متغیرها)
     */
    public static function success( $scenario, $to, $bodyId, $recId, $context = array() ) {
        self::write(
            self::SUCCESS_FILE,
            array(
                'level'    => 'SUCCESS',
                'scenario' => $scenario,
                'to'       => $to,
                'bodyId'   => $bodyId,
                'recId'    => $recId,
                'context'  => $context,
            )
        );
    }

    /**
     * خواندن آخرین خطوط یک فایل لاگ برای نمایش در پنل
     */
    public static function tail( $file, $lines = 100 ) {
        $path = NLSMS_LOG_DIR . $file;
        if ( ! file_exists( $path ) ) {
            return array();
        }
        $all = file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
        return array_slice( $all, -$lines );
    }
}
