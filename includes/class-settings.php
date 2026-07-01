<?php
if (! defined('ABSPATH')) {
    exit;
}

/**
 * صفحه تنظیمات افزونه در پنل ادمین
 * شامل: اعتبارسنجی، فیلدهای password/ApiKey، bodyIdها، و سیستم تست
 */
class NLSMS_Settings
{

    private static $instance = null;

    const OPTION_KEY = 'nlsms_settings';

    public static function instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('admin_menu', array($this, 'add_menu'));
        add_action('admin_init',    array($this, 'register_settings'));
        add_action('admin_post_nlsms_test_send', array($this, 'handle_test_send'));
        add_action('admin_post_nlsms_clear_log', array($this, 'handle_clear_log'));
    }

    /*───────── منو ───────── */

    public function add_menu()
    {
        add_options_page(
            'تنظیمات Nilson Lab SMS',
            'Nilson SMS',
            'manage_options',
            'nilsonlab-sms',
            array($this, 'render_page')
        );
    }

    /* ───────── ثبت تنظیمات───────── */

    public function register_settings()
    {
        register_setting(
            'nlsms_group',
            self::OPTION_KEY,
            array($this, 'sanitize_options')
        );

        /* --- بخش احراز هویت --- */
        add_settings_section('nlsms_auth', '🔐 احراز هویت', null, 'nlsms');

        $auth_fields = array(
            'username' => 'نام کاربری (Username)',
            'password' => 'رمز عبور (Password)',
            'apikey'   => 'API Key (در صورت خطای110)',
        );
        foreach ($auth_fields as $key => $label) {
            add_settings_field(
                'nlsms_' . $key,
                $label,
                array($this, 'field_text'),
                'nlsms',
                'nlsms_auth',
                array('key' => $key, 'type' => ($key !== 'username') ? 'password' : 'text')
            );
        }

        /* --- بخش bodyIdها --- */
        add_settings_section('nlsms_bodies', '📋 کد الگوها (bodyId)', array($this, 'render_bodies_hint'), 'nlsms');

        $body_fields = array(
            'bodyid_register' => 'ثبت‌نام (دیجیتس)',
            'bodyid_order'    => 'ثبت سفارش (ووکامرس)',
            'bodyid_shipped'  => 'تحویل به پست + کد رهگیری',
        );
        foreach ($body_fields as $key => $label) {
            add_settings_field(
                'nlsms_' . $key,
                $label,
                array($this, 'field_text'),
                'nlsms',
                'nlsms_bodies',
                array('key' => $key, 'type' => 'text')
            );
        }

        /* --- بخش نظرسنجی --- */
        add_settings_section('nlsms_comment', '💬 پیامک نظرسنجی', array($this, 'render_comment_hint'), 'nlsms');

        // bodyId نظرسنجی
        add_settings_field(
            'nlsms_bodyid_comment',
            'bodyId پیامک نظرسنجی',
            array($this, 'field_text'),
            'nlsms',
            'nlsms_comment',
            array('key' => 'bodyid_comment', 'type' => 'text')
        );

        // روز
        add_settings_field(
            'nlsms_comment_delay_days',
            'تأخیر ارسال - روز',
            array($this, 'field_number'),
            'nlsms',
            'nlsms_comment',
            array('key' => 'comment_delay_days', 'default' => '7', 'min' => '0')
        );

        // ساعت
        add_settings_field(
            'nlsms_comment_delay_hours',
            'تأخیر ارسال - ساعت',
            array($this, 'field_number'),
            'nlsms',
            'nlsms_comment',
            array('key' => 'comment_delay_hours', 'default' => '0', 'min' => '0', 'max' => '23')
        );

        // دقیقه
        add_settings_field(
            'nlsms_comment_delay_minutes',
            'تأخیر ارسال - دقیقه',
            array($this, 'field_number'),
            'nlsms',
            'nlsms_comment',
            array('key' => 'comment_delay_minutes', 'default' => '0', 'min' => '0', 'max' => '59')
        );


        /* --- تنظیمات عمومی --- */
        add_settings_section('nlsms_general', '⚙️ عمومی', null, 'nlsms');
        add_settings_field(
            'nlsms_enabled',
            'وضعیت افزونه',
            array($this, 'field_checkbox'),
            'nlsms',
            'nlsms_general',
            array('key' => 'enabled', 'label' => 'فعال باشد')
        );
    }

    /* ───────── Render فیلدها ───────── */

    public function field_text($args)
    {
        $opts = get_option(self::OPTION_KEY, array());
        $key   = $args['key'];
        $type  = $args['type'] ?? 'text';
        $value = isset($opts[$key]) ? esc_attr($opts[$key]) : '';
        printf(
            '<input type="%s" name="%s[%s]" value="%s" class="regular-text" autocomplete="new-password">',
            esc_attr($type),
            esc_attr(self::OPTION_KEY),
            esc_attr($key),
            $value
        );
    }

    public function field_checkbox($args)
    {
        $opts  = get_option(self::OPTION_KEY, array());
        $key   = $args['key'];
        $label = $args['label'];
        $checked = isset($opts[$key]) && $opts[$key] ? 'checked' : '';
        printf(
            '<label><input type="checkbox" name="%s[%s]" value="1" %s> %s</label>',
            esc_attr(self::OPTION_KEY),
            esc_attr($key),
            $checked,
            esc_html($label)
        );
    }
    public function field_number($args)
    {
        $opts    = get_option(self::OPTION_KEY, array());
        $key     = $args['key'];
        $default = $args['default'] ?? '';
        $value   = isset($opts[$key]) ? esc_attr($opts[$key]) : $default;

        $min = isset($args['min']) ? ' min="' . esc_attr($args['min']) . '"' : '';
        $max = isset($args['max']) ? ' max="' . esc_attr($args['max']) . '"' : '';

        printf(
            '<input type="number" name="%s[%s]" value="%s" class="small-text"%s%s>',
            esc_attr(self::OPTION_KEY),
            esc_attr($key),
            $value,
            $min,
            $max
        );
    }

    public function render_bodies_hint()
    {
        echo '<p style="color:#666">هر کد را از پنل ملی‌پیامک ← پیامک الگو دریافت کنید. برای سناریوی تحویل به پست، الگو باید یک متغیر (کد رهگیری) داشته باشد.</p>';
    }
    public function render_comment_hint()
    {
        echo '<p style="color:#666">پیامک نظرسنجی چند روز بعد از تکمیل سفارش ارسال می‌شود. زمان را به روز/ساعت/دقیقه تنظیم کنید (مثلاً 7 روز = 604800 ثانیه).</p>';
    }


    /* ───────── پاکسازی ورودی ───────── */

    public function sanitize_options($input)
    {
        $clean = array();
        $text_fields = array('username', 'password', 'apikey', 'bodyid_register', 'bodyid_order', 'bodyid_shipped', 'bodyid_comment');
        foreach ($text_fields as $f) {
            $clean[$f] = isset($input[$f]) ? sanitize_text_field($input[$f]) : '';
        }
        // فیلدهای عددی
        $number_fields = array(
            'comment_delay_days'    => 7,
            'comment_delay_hours'   => 0,
            'comment_delay_minutes' => 0,
        );
        foreach ($number_fields as $f => $default) {
            $clean[$f] = isset($input[$f]) ? absint($input[$f]) : $default;
        }
        $clean['enabled'] = ! empty($input['enabled']) ? 1 : 0;
        return $clean;
    }
    /* ───────── رندر صفحه اصلی ───────── */

    public function render_page()
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        $opts = get_option(self::OPTION_KEY, array());
?>
        <div class="wrap" dir="rtl">
            <h1>⚡ Nilson Lab SMS</h1>

            <form method="post" action="options.php">
                <?php
                settings_fields('nlsms_group');
                do_settings_sections('nlsms');
                submit_button('ذخیره تنظیمات');
                ?>
            </form>

            <hr>

            <!--───── بخش تست ارسال───── -->
            <h2>🧪 تست ارسال پیامک</h2>
            <p>یک شماره وارد کنید، سناریو را انتخاب کنید؛ پیامک با bodyId تنظیم‌شده ارسال می‌شود.</p>
            // ✅ درست
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('nlsms_test_nonce', 'nlsms_nonce'); ?>
                <input type="hidden" name="action" value="nlsms_test_send">
                <table class="form-table">
                    <tr>
                        <th><label for="test_to">شماره مقصد</label></th>
                        <td><input type="text" id="test_to" name="test_to" class="regular-text" placeholder="09xxxxxxxxx"></td>
                    </tr>
                    <tr>
                        <th><label for="test_scenario">سناریو</label></th>
                        <td>
                            <select id="test_scenario" name="test_scenario">
                                <option value="register">ثبت‌نام</option>
                                <option value="order">ثبت سفارش</option>
                                <option value="shipped">تحویل به پست (کد رهگیری نمونه)</option>
                            </select>
                        </td>
                    </tr>
                </table>
                <?php submit_button('ارسال تست', 'secondary'); ?>
            </form>

            <?php $this->render_test_result(); ?>

            <hr>

            <!-- ───── لاگ‌ها ───── -->
            <h2>📜 آخرین لاگ‌ها</h2>
            <?php $this->render_logs(); ?>
        </div>
<?php
    }

    /* ───────── نمایش نتیجه تست ───────── */

    private function render_test_result()
    {
        if (! isset($_GET['nlsms_test'])) {
            return;
        }
        $status = sanitize_text_field($_GET['nlsms_test']);
        $message = isset($_GET['nlsms_msg']) ? urldecode($_GET['nlsms_msg']) : '';
        $class   = ($status === 'ok') ? 'notice-success' : 'notice-error';
        printf(
            '<div class="notice %s inline"><p>%s</p></div>',
            esc_attr($class),
            esc_html($message)
        );
    }

    /* ───────── نمایش لاگ‌ها ───────── */

    private function render_logs()
    {
        foreach (array('error.log' => '🔴 خطاها', 'success.log' => '🟢 موفقیت') as $file => $title) {
            $lines = NLSMS_Logger::tail($file, 50);
            echo '<h3>' . esc_html($title) . '</h3>';

            // دکمه پاک‌کردن لاگ
            $nonce = wp_create_nonce('nlsms_clear_' . $file);
            printf(
                '<form method="post" action="%s" style="margin-bottom:6px">
                    <input type="hidden" name="action" value="nlsms_clear_log">
                    <input type="hidden" name="log_file" value="%s">
                    %s
                    <button type="submit" class="button button-small" onclick="return confirm(\'پاک شود؟\')">پاک کردن لاگ</button>
                </form>',
                esc_url(admin_url('admin-post.php')),
                esc_attr($file),
                wp_nonce_field('nlsms_clear_' . $file, 'nlsms_clear_nonce', true, false)
            );

            if (empty($lines)) {
                echo '<p style="color:#999">خالی</p>';
            } else {
                echo '<textarea readonly style="width:100%;height:180px;font-family:monospace;font-size:12px;direction:ltr">';
                echo esc_textarea(implode("\n", array_reverse($lines)));
                echo '</textarea>';
            }
        }
    }

    /* ───────── هندلر تست ───────── */

    public function handle_test_send()
    {
        if (! current_user_can('manage_options') || ! check_admin_referer('nlsms_test_nonce', 'nlsms_nonce')) {
            wp_die('دسترسی غیرمجاز');
        }

        $to = sanitize_text_field($_POST['test_to'] ?? '');
        $scenario = sanitize_text_field($_POST['test_scenario'] ?? 'register');
        $opts     = get_option(self::OPTION_KEY, array());

        $body_map = array(
            'register' => $opts['bodyid_register'] ?? '',
            'order'    => $opts['bodyid_order']    ?? '',
            'shipped'  => $opts['bodyid_shipped']  ?? '',
        );
        $bodyId    = $body_map[$scenario] ?? '';
        $variables = ($scenario === 'shipped') ? array('TRACK-TEST-001') : array();

        $result = NLSMS_SMS_Client::send($to, $bodyId, $variables, 'test_' . $scenario);

        $status = $result['ok'] ? 'ok' : 'error';
        $message = $result['ok']
            ? '✅ ارسال موفق — recId: ' . $result['recId']
            : '❌ ' . $result['error'];

        wp_redirect(
            add_query_arg(
                array(
                    'page' => 'nilsonlab-sms',
                    'nlsms_test' => $status,
                    'nlsms_msg'  => rawurlencode($message),
                ),
                admin_url('options-general.php')
            )
        );
        exit;
    }

    /* ───────── هندلر پاک‌کردن لاگ ───────── */

    public function handle_clear_log()
    {
        if (! current_user_can('manage_options')) {
            wp_die('دسترسی غیرمجاز');
        }
        $file = sanitize_file_name($_POST['log_file'] ?? '');
        if (! check_admin_referer('nlsms_clear_' . $file, 'nlsms_clear_nonce')) {
            wp_die('nonce نامعتبر');
        }
        $allowed = array('error.log', 'success.log');
        if (in_array($file, $allowed, true)) {
            file_put_contents(NLSMS_LOG_DIR . $file, '');
        }
        wp_redirect(
            add_query_arg('page', 'nilsonlab-sms', admin_url('options-general.php'))
        );
        exit;
    }
}
