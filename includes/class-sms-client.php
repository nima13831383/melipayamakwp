<?php
if (! defined('ABSPATH')) {
    exit;
}

/**
 * کلاینت ارسال پیامک ملی‌پیامک – خط خدماتی (REST / BaseServiceNumber)
 * مرجع: webservice-SharedNumber.pdf صفحه ۶
 */
class NLSMS_SMS_Client
{

    const ENDPOINT = 'https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber';

    /** نگاشت کدهای خطای سند به پیام فارسی (صفحات ۱،۲،۴،۶) */
    private static $error_map = array(
        '0'   => 'نام کاربری یا رمز عبور صحیح نیست',
        '1'   => 'دسترسی استفاده از وب‌سرویس غیرفعال است',
        '2'   => 'فقط یک شماره موبایل مجاز است / اعتبار کافی نیست',
        '3'   => 'خط ارسالی در سیستم تعریف نشده',
        '4'   => 'کد متن (bodyId) صحیح نیست یا توسط مدیر تأیید نشده',
        '5'   => 'متن ارسالی با متغیرهای متن پیش‌فرض همخوانی ندارد',
        '6'   => 'خطای داخلی / سامانه در حال بروزرسانی',
        '7'   => 'متن حاوی کلمه فیلترشده است',
        '10'  => 'ممنوعیت ارسال لینک در متغیرها / کاربر فعال نیست',
        '11'  => 'ارسال نشد',
        '12'  => 'مدارک کاربر کامل نیست',
        '18'  => 'شماره موبایل معتبر نیست',
        '19'  => 'سقف محدودیت روزانه ارسال از وب‌سرویس',
        '108' => 'IP به دلیل تلاش ناموفق مسدود شده است',
        '109' => 'الزام تنظیم IP مجاز برای استفاده از API',
        '110' => 'الزام استفاده از ApiKey به جای رمز عبور',
    );

    /**
     * نرمال‌سازی شماره موبایل به فرمت 09xxxxxxxxx
     */
    public static function normalize_number($number)
    {
        // تبدیل ارقام فارسی/عربی به انگلیسی
        $number = self::fa_to_en($number);
        // حذف هر چیز غیر عددی
        $number = preg_replace('/\D/', '', $number);

        if (strpos($number, '0098') === 0) {
            $number = '0' . substr($number, 4);
        } elseif (strpos($number, '98') === 0 && strlen($number) === 12) {
            $number = '0' . substr($number, 2);
        } elseif (strpos($number, '9') === 0 && strlen($number) === 10) {
            $number = '0' . $number;
        }
        return $number;
    }

    private static function fa_to_en($str)
    {
        $fa = array('۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩');
        $en = array('0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9');
        return str_replace($fa, $en, $str);
    }

    /**
     * ارسال پیامک با خط خدماتی
     *
     * @param string $to        شماره مقصد (یک شماره)
     * @param string $bodyId    کد الگوی تأییدشده
     * @param array  $variables متغیرها به‌ترتیب الگو (با ; جدا می‌شوند)
     * @param string $scenario  برای لاگ
     * @return array{ok:bool, recId:?string, error:?string, raw:mixed}
     */
    public static function send($to, $bodyId, $variables = array(), $scenario = 'manual')
    {
        $opts     = get_option('nlsms_settings', array());
        $username = isset($opts['username']) ? trim($opts['username']) : '';
        $apikey = isset($opts['apikey'])   ? trim($opts['apikey'])   : '';
        $password = isset($opts['password']) ? trim($opts['password']) : '';
        // اگر ApiKey پر بود اولویت داره (کد خطای 110)
        $credential = ! empty($apikey) ? $apikey : $password;
        $password =$credential;
        $to = self::normalize_number($to);

        // اعتبارسنجی اولیه
        if (empty($username) || empty($password)) {
            $err = 'نام کاربری یا رمز عبور در تنظیمات وارد نشده است';
            NLSMS_Logger::error($scenario, $to, $err);
            return array('ok' => false, 'recId' => null, 'error' => $err, 'raw' => null);
        }

        if (empty($bodyId)) {
            $err = 'bodyId برای این سناریو تنظیم نشده است';
            NLSMS_Logger::error($scenario, $to, $err, array('variables' => $variables));
            return array('ok' => false, 'recId' => null, 'error' => $err, 'raw' => null);
        }

        if (! preg_match('/^09\d{9}$/', $to)) {
            $err = 'شماره موبایل معتبر نیست';
            NLSMS_Logger::error($scenario, $to, $err);
            return array('ok' => false, 'recId' => null, 'error' => $err, 'raw' => null);
        }

        // متغیرها به‌ترتیب با ; جدا می‌شوند (صفحه ۶)
        $text = implode(';', array_map('strval', $variables));

        $response = wp_remote_post(
            self::ENDPOINT,
            array(
                'timeout' => 30,
                'body'    => array(
                    'username' => $username,
                    'password' => $password,
                    'text'     => $text,
                    'to'       => $to,
                    'bodyId'   => $bodyId,
                ),
            )
        );

        if (is_wp_error($response)) {
            $err = 'خطای ارتباط: ' . $response->get_error_message();
            NLSMS_Logger::error($scenario, $to, $err, array('bodyId' => $bodyId, 'variables' => $variables));
            return array('ok' => false, 'recId' => null, 'error' => $err, 'raw' => null);
        }

        $body   = wp_remote_retrieve_body($response);
        $parsed = json_decode($body, true);

        // پاسخ REST: Value (recID), RetStatus, StrRetStatus (صفحه ۶)
        $value      = is_array($parsed) && isset($parsed['Value']) ? (string) $parsed['Value'] : trim($body, "\" \n\r\t");
        $ret_status = is_array($parsed) && isset($parsed['RetStatus']) ? (string) $parsed['RetStatus'] : null;

        // موفقیت: Value بیش از ۱۵ رقم (صفحه ۶)
        if (preg_match('/^\d{16,}$/', $value)) {
            NLSMS_Logger::success(
                $scenario,
                $to,
                $bodyId,
                $value,
                array('variables' => $variables, 'retStatus' => $ret_status)
            );
            return array('ok' => true, 'recId' => $value, 'error' => null, 'raw' => $parsed ?: $body);
        }

        // در غیر این صورت کد خطا
        $code   = (null !== $ret_status) ? $ret_status : $value;
        $err_fa = isset(self::$error_map[$code]) ? self::$error_map[$code] : 'کد خطای ناشناخته';
        $err    = sprintf('ارسال ناموفق (کد %s): %s', $code, $err_fa);

        NLSMS_Logger::error(
            $scenario,
            $to,
            $err,
            array('bodyId' => $bodyId, 'variables' => $variables, 'raw' => $parsed ?: $body)
        );

        return array('ok' => false, 'recId' => null, 'error' => $err, 'raw' => $parsed ?: $body);
    }
}
