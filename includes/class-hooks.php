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
    }

    /* فقط زمان‌بندی می‌کنه — ۶۰ ثانیه بعد */
    public function schedule_register_sms($user_id)
    {
        if (!wp_next_scheduled('nlsms_send_register_sms', array($user_id))) {
            wp_schedule_single_event(time() + 15, 'nlsms_send_register_sms', array($user_id));
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
        // وضعیت‌هایی که به معنای تحویل به پست هستند
        $shipped_statuses = apply_filters('nlsms_shipped_statuses', array('completed'));

        if (! in_array($to_status, $shipped_statuses, true)) {
            return;
        }

        $phone = $order->get_billing_phone();
        if (empty($phone)) {
            NLSMS_Logger::error('shipped', '', 'شماره موبایل سفارش خالی است', array('order_id' => $order_id));
            return;
        }

        // کد رهگیری: ترجیحاً از متای سفارش، در غیر این‌صورت خالی
        $tracking_code = $this->get_tracking_code($order);

        $opts   = get_option('nlsms_settings', array());
        $bodyId = $opts['bodyid_shipped'] ?? '';

        NLSMS_SMS_Client::send(
            $phone,
            $bodyId,
            array($tracking_code),   // متغیر اول الگو = کد رهگیری
            'shipped'
        );
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
