<?php
/**
 * مدیریت متاباکس کد رهگیری سفارش
 *
 * مسئول ثبت، رندر و ذخیره‌ی فیلد کد رهگیری (_nlsms_tracking_code)
 * در صفحه ویرایش سفارش ووکامرس (Classic + HPOS).
 */

if (! defined('ABSPATH')) {
    exit;
}

class NLSMS_Tracking_MetaBox
{
    /**
     * کلید متای ذخیره‌ی کد رهگیری
     */
    const META_KEY = '_nlsms_tracking_code';

    /**
     * راه‌اندازی و اتصال هوک‌ها
     */
    public function __construct()
    {
        add_action('add_meta_boxes', array($this, 'register_meta_box'));
        add_action('woocommerce_process_shop_order_meta', array($this, 'save_meta'), 10, 1);
    }

    /**
     * متد کمکی برای راه‌اندازی سریع از فایل اصلی افزونه
     */
    public static function init()
    {
        return new self();
    }

    /**
     * ثبت متاباکس در صفحه ویرایش سفارش (Classic + HPOS)
     */
    public function register_meta_box()
    {
        $screens = array('shop_order');

        // پشتیبانی از HPOS
        if (function_exists('wc_get_page_screen_id')) {
            $screens[] = wc_get_page_screen_id('shop-order');
        }

        foreach ($screens as $screen) {
            add_meta_box(
                'nlsms_tracking_code_box',
                '📦 کد رهگیری پیامک',
                array($this, 'render_meta_box'),
                $screen,
                'side',
                'default'
            );
        }
    }

    /**
     * رندر محتوای متاباکس
     */
    public function render_meta_box($post_or_order)
    {
        // سازگاری Classic (WP_Post) و HPOS (WC_Order)
        if ($post_or_order instanceof \WP_Post) {
            $order = wc_get_order($post_or_order->ID);
        } else {
            $order = $post_or_order;
        }

        $tracking_code = $order ? $order->get_meta(self::META_KEY) : '';

        wp_nonce_field('nlsms_save_tracking', 'nlsms_tracking_nonce');
        ?>
        <p style="margin:0 0 6px">
            <label for="nlsms_tracking_code_input" style="font-weight:600">
                کد رهگیری پستی:
            </label>
        </p>
        <input
            type="text"
            id="nlsms_tracking_code_input"
            name="_nlsms_tracking_code"
            value="<?php echo esc_attr($tracking_code); ?>"
            placeholder="مثلاً: RH123456789IR"
            style="width:100%;direction:ltr"/>
        <p style="color:#888;font-size:11px;margin:4px 0 0">
            پس از ذخیره و تغییر وضعیت به «تکمیل‌شده» ارسال می‌شود.
        </p>
        <?php
    }

    /**
     * ذخیره کد رهگیری هنگام save سفارش در ادمین
     */
    public function save_meta($order_id)
    {
        // بررسی nonce
        if (
            empty($_POST['nlsms_tracking_nonce']) ||
            ! wp_verify_nonce(
                sanitize_text_field(wp_unslash($_POST['nlsms_tracking_nonce'])),
                'nlsms_save_tracking'
            )
        ) {
            return;
        }

        if (! isset($_POST['_nlsms_tracking_code'])) {
            return;
        }

        $order = wc_get_order($order_id);
        if (! $order) {
            return;
        }

        $tracking_code = sanitize_text_field(wp_unslash($_POST['_nlsms_tracking_code']));
        $order->update_meta_data(self::META_KEY, $tracking_code);
        $order->save();
    }

    /**
     * خواندن کد رهگیری یک سفارش (برای استفاده در کلاس هوک‌ها)
     */
    public static function get_code($order)
    {
        if (is_numeric($order)) {
            $order = wc_get_order($order);
        }

        return $order ? $order->get_meta(self::META_KEY) : '';
    }
}
