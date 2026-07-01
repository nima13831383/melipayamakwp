<?php
if (! defined('ABSPATH')) {
    exit;
}

/**
 * هوک‌هایووکامرس و دیجیتس
 */
class NLSMS_Hooks
{

    private static $instance = null;

    public static function instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $opts = get_option('nlsms_settings', array());
        if (empty($opts['enabled'])) {
            return;
        }

        // به‌جای ارسال فوری، فقط یه ایونت زمان‌بندی‌شده می‌سازیم
        add_action('user_register', array($this, 'schedule_register_sms'), 10, 1);

        // هندلر واقعی که سر وقت اجرا می‌شه
        add_action('nlsms_send_register_sms', array($this, 'on_user_register'), 10, 1);

        add_action('woocommerce_order_status_processing', array($this, 'on_order_created'), 10, 1);
        add_action('woocommerce_order_status_on-hold',    array($this, 'on_order_created'), 10, 1);


        add_action('woocommerce_order_status_changed', array($this, 'on_order_status_changed'), 10, 4);
        add_action('nlsms_send_comment_sms', array($this, 'send_comment_sms'), 10, 1);
        add_action('woocommerce_order_status_cancelled', array($this, 'cancel_comment_sms'), 10, 1);
        add_action('woocommerce_order_status_refunded', array($this, 'cancel_comment_sms'), 10, 1);


        // ارسال پیامک تبلیغاتی
        add_action('nlsms_send_promotional_sms', array($this, 'send_promotional_sms'));

        // لغو پیامک تبلیغاتی در صورت کنسل/بازپرداخت
        add_action('woocommerce_order_status_cancelled', array($this, 'cancel_promotional_sms'));
        add_action('woocommerce_order_status_refunded', array($this, 'cancel_promotional_sms'));
    }

    /* فقط زمان‌بندی می‌کنه — ۶۰ ثانیه بعد */
    public function schedule_register_sms($user_id)
    {
        if (!wp_next_scheduled('nlsms_send_register_sms', array($user_id))) {
            wp_schedule_single_event(time() + 15, 'nlsms_send_register_sms', array($user_id));
        }
    }
    public function cancel_comment_sms($order_id)
    {
        $scheduled = wp_next_scheduled('nlsms_send_comment_sms', array($order_id));
        if ($scheduled) {
            wp_unschedule_event($scheduled, 'nlsms_send_comment_sms', array($order_id));
            NLSMS_Logger::success(
                'comment',
                '',
                '',
                'پیامک نظرسنجی لغو شد',

                array('order_id' => $order_id)
            );
        }
    }

    /* این متد ۱ دقیقه بعد توسط کرون فراخوانی می‌شه — حالا متا حتماً موجوده */
    // public function on_user_register($user_id)
    // {
    //     $phone = $this->get_register_phone($user_id);
    //     if (empty($phone)) {
    //         NLSMS_Logger::error('register', '', 'شماره موبایل کاربر یافت نشد', array('user_id' => $user_id));
    //         return;
    //     }
    //     $opts   = get_option('nlsms_settings', array());
    //     $bodyId = $opts['bodyid_register'] ?? '';
    //     NLSMS_SMS_Client::send($phone, $bodyId, array(), 'register');
    // }
    public function on_user_register($user_id)
    {
        $phone = $this->get_register_phone($user_id);
        if (empty($phone)) {
            NLSMS_Logger::error('register', '', 'شماره موبایل کاربر یافت نشد', array('user_id' => $user_id));
            return;
        }

        // استخراج نام کاربر برای جایگذاری در {0}
        $name = $this->get_register_name($user_id);

        $opts   = get_option('nlsms_settings', array());
        $bodyId = $opts['bodyid_register'] ?? '';

        // متغیرها به‌ترتیب قالب؛ اینجا فقط {0} داریم
        NLSMS_SMS_Client::send($phone, $bodyId, array($name), 'register');
    }

    private function get_register_name($user_id)
    {
        $user = get_userdata($user_id);
        if (! $user) {
            return 'کاربر';
        }

        // اولویت: first_name → billing_first_name → display_name → nickname
        $name = get_user_meta($user_id, 'first_name', true);

        if (empty($name)) {
            $name = get_user_meta($user_id, 'billing_first_name', true);
        }
        if (empty($name)) {
            $name = $user->display_name;
        }
        if (empty($name)) {
            $name = get_user_meta($user_id, 'nickname', true);
        }
        if (empty($name)) {
            $name = $user->user_nicename;
        }
        if (empty($name)) {
            $name = 'کاربر';
        }

        // لاگ برای دیباگ — ببینیم کدوم فیلد پر بوده
        NLSMS_Logger::error('register', '', 'نام resolve شد', array(
            'user_id'           => $user_id,
            'first_name'        => get_user_meta($user_id, 'first_name', true),
            'billing_first_name' => get_user_meta($user_id, 'billing_first_name', true),
            'display_name'      => $user->display_name,
            'nickname'          => get_user_meta($user_id, 'nickname', true),
            'resolved'          => $name,
        ));

        return $name; // ← این خط جا افتاده بود
    }

    /**
     * استخراج شماره موبایل کاربر از user_meta
     * چند کلید رایج (دیجیتس و ووکامرس) به‌ترتیب اولویت چک می‌شن
     */
    private function get_register_phone($user_id)
    {
        $meta_keys = array(
            'digits_phone',
            'digits_phone_no',
            'user_phone',
            'billing_phone',
        );

        foreach ($meta_keys as $key) {
            $val = get_user_meta($user_id, $key, true);
            if (! empty($val)) {
                return $val;
            }
        }

        // fallback: اگر یوزرنیم خودش شماره موبایل باشه
        $user = get_userdata($user_id);
        if ($user && preg_match('/^(\+98|0098|98|0)?9\d{9}$/', $user->user_login)) {
            return $user->user_login;
        }

        return null;
    }


    /* ──────────────────────────────────
       سناریو ۲: ثبت سفارش
    ────────────────────────────────── */

    public function on_order_created($order_id)
    {
        NLSMS_Logger::error('order', '', 'on_order_created فراخوانی شد', array('order_id' => $order_id));

        $order = wc_get_order($order_id);
        if (! $order) {
            return;
        }
        $phone = $order->get_billing_phone();
        if (empty($phone)) {
            NLSMS_Logger::error('order', '', 'شماره موبایل سفارش خالی است', array('order_id' => $order_id));
            return;
        }

        // {0} = نام مشتری
        $name = $order->get_billing_first_name();
        if (empty($name)) {
            $name = $order->get_formatted_billing_full_name();
        }
        if (empty($name)) {
            $name = 'کاربر';
        }

        // {1} = کد سفارش
        $order_code = $order->get_order_number();

        $opts   = get_option('nlsms_settings', array());
        $bodyId = $opts['bodyid_order'] ?? '';

        NLSMS_SMS_Client::send(
            $phone,
            $bodyId,
            array($name, $order_code),   // {0}=نام ، {1}=کد سفارش
            'order'
        );
    }


    /* ──────────────────────────────────
       سناریو ۳: تحویل به پست
    ────────────────────────────────── */

    public function on_order_status_changed($order_id, $from, $to_status, $order)
    {
        if ($to_status !== 'completed') {
            return;
        }
        $shipped_statuses = apply_filters('nlsms_shipped_statuses', array('completed'));

        if (! in_array($to_status, $shipped_statuses, true)) {
            return;
        }

        $phone = $order->get_billing_phone();
        if (empty($phone)) {
            NLSMS_Logger::error('shipped', '', 'شماره موبایل سفارش خالی است', array('order_id' => $order_id));
            return;
        }

        // خواندن کد رهگیری از فیلد اختصاصی
        $tracking_code = $order->get_meta('_nlsms_tracking_code');

        if (empty($tracking_code)) {
            NLSMS_Logger::error(
                'shipped',
                $phone,
                'کد رهگیری وارد نشده (_nlsms_tracking_code خالی است)',
                array('order_id' => $order_id)
            );
            return;
        }

        // نام مشتری برای {0}
        $name = $order->get_billing_first_name();
        if (empty($name)) {
            $name = $order->get_formatted_billing_full_name();
        }
        if (empty($name)) {
            $name = 'رفیق';
        }

        $opts   = get_option('nlsms_settings', array());
        $bodyId = ! empty($opts['bodyid_shipped']) ? $opts['bodyid_shipped'] : '485323';

        NLSMS_SMS_Client::send(
            $phone,
            $bodyId,
            array($name, $tracking_code),  // {0}=نام، {1}=کد رهگیری
            'shipped'
        );
        $this->schedule_comment_sms($order_id);
        // زمان‌بندی پیامک تبلیغاتی
        $this->schedule_promotional_sms($order_id);
    }
    /**
     * زمان‌بندی ارسال پیامک نظرسنجی
     *
     * @param int $order_id
     */
    private function schedule_comment_sms($order_id)
    {
        // خواندن تأخیر از تنظیمات
        // $delay = (int) get_option('nlsms_comment_delay', 604800); // پیش‌فرض 7 روز

        // یا اگه از فیلدهای جدا استفاده کردی:
        $opts = get_option(NLSMS_Settings::OPTION_KEY, array());

        $days    = absint($opts['comment_delay_days'] ?? 7);
        $hours   = absint($opts['comment_delay_hours'] ?? 0);
        $minutes = absint($opts['comment_delay_minutes'] ?? 0);

        $delay = ($days * DAY_IN_SECONDS) + ($hours * HOUR_IN_SECONDS) + ($minutes * MINUTE_IN_SECONDS);
        $send_time = time() + $delay;


        // چک کنیم قبلاً زمان‌بندی نشده باشه
        $scheduled = wp_next_scheduled('nlsms_send_comment_sms', array($order_id));
        if ($scheduled) {
            wp_unschedule_event($scheduled, 'nlsms_send_comment_sms', array($order_id));
        }

        wp_schedule_single_event($send_time, 'nlsms_send_comment_sms', array($order_id));

        // NLSMS_Logger::log('info', 'comment', 'پیامک نظرسنجی زمان‌بندی شد', array(
        //     'order_id'  => $order_id,
        //     'send_time' => date('Y-m-d H:i:s', $send_time),
        // ));
        NLSMS_Logger::success(
            'comment',
            '', // شماره موبایل رو نداری، خالی بذار یا بعداً اضافه کن
            '',
            '',
            array(
                'action'    => 'scheduled',
                'order_id'  => $order_id,
                'send_time' => date('Y-m-d H:i:s', $send_time),
            )
        );
    }
    /**
     * ارسال پیامک نظرسنجی (از طریق کرون)
     *
     * @param int $order_id
     */
    public function send_comment_sms($order_id)
    {
        // ✅ اگه قبلاً زمان‌بندی شده، کاری نکن
        if (wp_next_scheduled('nlsms_send_comment_sms', array($order_id))) {
            NLSMS_Logger::success(
                'comment',
                '',
                '',
                'پیامک نظرسنجی قبلاً زمان‌بندی شده بود',
                array('order_id' => $order_id)
            );
            return; // ← خروج بدون زمان‌بندی مجدد
        }
        $order = wc_get_order($order_id);
        // ❌ سفارش یافت نشد
        if (! $order) {
            NLSMS_Logger::error(
                'comment',
                '',
                'سفارش یافت نشد',
                array('order_id' => $order_id)
            );
            return;
        }

        // فقط اگه سفارش هنوز completed باشه (اگه کنسل یا refund نشده)
        if ($order->get_status() !== 'completed') {
            NLSMS_Logger::error(
                'comment',
                $order->get_billing_phone(),
                'وضعیت سفارش دیگه completed نیست، پیامک ارسال نشد',
                array(
                    'order_id' => $order_id,
                    'status'   => $order->get_status(),
                )
            );
            return;
        }


        $phone = $order->get_billing_phone();
        if (empty($phone)) {
            NLSMS_Logger::error(
                'comment',
                '',
                'شماره موبایل سفارش یافت نشد',
                array('order_id' => $order_id)
            );
            return;
        }


        // نام مشتری
        $name = $order->get_billing_first_name();
        if (empty($name)) {
            $name = $order->get_formatted_billing_full_name();
        }
        if (empty($name)) {
            $name = 'کاربر';
        }

        // bodyId از تنظیمات
        $opts = get_option(NLSMS_Settings::OPTION_KEY, array());
        $bodyId = $opts['bodyid_comment'] ?? '';


        // ارسال پیامک
        // قالب: {0} = نام مشتری
        NLSMS_SMS_Client::send($phone, $bodyId, array($name), 'comment');
    }

    /**
     * زمان‌بندی پیامک تبلیغاتی
     */
    public function schedule_promotional_sms($order_id)
    {
        if (wp_next_scheduled('nlsms_send_promotional_sms', array($order_id))) {
            NLSMS_Logger::success(
                'promotional',
                '',
                '',
                'پیامک تبلیغاتی قبلاً زمان‌بندی شده بود',
                array('order_id' => $order_id)
            );
            return; // ← خروج بدون زمان‌بندی مجدد
        }
        $days = absint(NLSMS_Settings::get('promotional_delay_days', 30));
        $hours = absint(NLSMS_Settings::get('promotional_delay_hours', 0));
        $minutes = absint(NLSMS_Settings::get('promotional_delay_minutes', 0));


        $delay = ($days * DAY_IN_SECONDS) + ($hours * HOUR_IN_SECONDS) + ($minutes * MINUTE_IN_SECONDS);
        $send_time = time() + $delay;

        if (wp_schedule_single_event($send_time, 'nlsms_send_promotional_sms', array($order_id))) {
            NLSMS_Logger::success(
                'promotional',
                '',
                '',
                'پیامک تبلیغاتی زمان‌بندی شد',
                array(
                    'order_id' => $order_id,
                    'send_at' => date('Y-m-d H:i:s', $send_time),
                    'delay_days' => $days,
                    'delay_hours' => $hours,
                    'delay_minutes' => $minutes
                )
            );
        } else {
            NLSMS_Logger::error('promotional', '', 'خطا در زمان‌بندی پیامک تبلیغاتی', array('order_id' => $order_id));
        }
    }

    /**
     * ارسال پیامک تبلیغاتی
     */
    public function send_promotional_sms($order_id)
    {
        $order = wc_get_order($order_id);

        if (!$order) {
            NLSMS_Logger::error('promotional', '', 'سفارش یافت نشد', array('order_id' => $order_id));
            return;
        }

        // چک کردن وضعیت سفارش
        $status = $order->get_status();
        if (in_array($status, array('cancelled', 'refunded', 'failed'))) {
            NLSMS_Logger::error('promotional', '', 'سفارش در وضعیت نامناسب برای ارسال پیامک تبلیغاتی', array('order_id' => $order_id, 'status' => $status));
            return;
        }

        $phone = $order->get_billing_phone();
        if (empty($phone)) {
            NLSMS_Logger::error('promotional', '', 'شماره موبایل سفارش یافت نشد', array('order_id' => $order_id));
            return;
        }

        $name = $order->get_billing_first_name();
        if (empty($name)) {
            $name = $order->get_formatted_billing_full_name();
        }
        if (empty($name)) {
            $name = 'مشتری';
        }

        $text_template = NLSMS_Settings::get('promotional_text', '');
        if (empty($text_template)) {
            NLSMS_Logger::error('promotional', $phone, 'متن پیامک تبلیغاتی تنظیم نشده');
            return;
        }

        $text = str_replace('{0}', $name, $text_template);

        $from = NLSMS_Settings::get('promotional_line', '');
        if (empty($from)) {
            NLSMS_Logger::error('promotional', $phone, 'خط ارسال تبلیغاتی تنظیم نشده');
            return;
        }

        NLSMS_SMS_Client::send_normal($phone, $from, $text, 'promotional');
    }

    /**
     * لغو پیامک تبلیغاتی
     */
    public function cancel_promotional_sms($order_id)
    {
        $timestamp = wp_next_scheduled('nlsms_send_promotional_sms', array($order_id));

        if ($timestamp) {
            wp_unschedule_event($timestamp, 'nlsms_send_promotional_sms', array($order_id));
            NLSMS_Logger::success('promotional', '', '', 'پیامک تبلیغاتی لغو شد', array('order_id' => $order_id));
        }
    }

    /**
     * استخراج کد رهگیری از متای سفارش
     * افزونه‌های مختلف متاکی‌های متفاوتی دارند؛ چند کلید رایج رو چک می‌کنیم
     */
    private function get_tracking_code($order)
    {
        $meta_keys = apply_filters(
            'nlsms_tracking_meta_keys',
            array(
                '_wc_shipment_tracking_items', // WooCommerce Shipment Tracking
                'tracking_code',
                '_tracking_code',
                'post_tracking_number',        // AfterShip
                '_ywot_tracking_code',         // YITH Order Tracking
            )
        );

        foreach ($meta_keys as $key) {
            $val = $order->get_meta($key);
            if (! empty($val)) {
                // WooCommerce Shipment Tracking آرایه سریالایز‌شده برمی‌گردونه
                if (is_array($val)) {
                    $first = reset($val);
                    return is_array($first)
                        ? ($first['tracking_number'] ?? $first['tracking_id'] ?? '')
                        : (string) $first;
                }
                return (string) $val;
            }
        }
        return '';
    }
}
