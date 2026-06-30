<?php

/**
 * test-runner.php
 * اجرای مستقل برای تست متد BaseServiceNumber بدون نیاز به هوک‌های ووکامرس/Digits
 *
 * استفاده:
 *   - مرورگر: yoursite.com/wp-content/plugins/nilsonlab-sms/test-runner.php?to=09120000000&bodyId=12345&text=val1;val2
 *   - CLI:    php test-runner.php "09120000000" "12345" "val1;val2"
 */

// --- بارگذاری هسته وردپرس برای دسترسی به تنظیمات و کلاینت ---
$wp_load = null;
$dir     = __DIR__;

for ( $i = 0; $i < 8; $i++ ) {
    $candidate = $dir . '/wp-load.php';
    if ( file_exists( $candidate ) ) {
        $wp_load = $candidate;
        break;
    }
    $parent = dirname( $dir );
    if ( $parent === $dir ) {
        break; // به ریشه فایل‌سیستم رسیدیم
    }
    $dir = $parent;
}

if ( ! $wp_load ) {
    http_response_code( 500 );
    exit( 'wp-load.php پیدا نشد. مسیر افزونه را بررسی کن.' );
}

require_once $wp_load;

// فقط ادمین لاگین‌شده اجازه اجرا دارد
if ( ! current_user_can( 'manage_options' ) ) {
    http_response_code( 403 );
    exit( 'دسترسی غیرمجاز.' );
}

// --- محدودسازی دسترسی: فقط ادمین یا اجرای CLI ---
$is_cli = (php_sapi_name() === 'cli');
if (!$is_cli && !current_user_can('manage_options')) {
    wp_die('دسترسی مجاز نیست.');
}

header('Content-Type: text/plain; charset=utf-8');

// --- دریافت ورودی‌ها ---
if ($is_cli) {
    $to     = $argv[1] ?? '';
    $bodyId = $argv[2] ?? '';
    $text   = $argv[3] ?? '';
} else {
    $to     = isset($_GET['to'])     ? sanitize_text_field(wp_unslash($_GET['to']))     : '';
    $bodyId = isset($_GET['bodyId'])  ? sanitize_text_field(wp_unslash($_GET['bodyId']))  : '';
    $text   = isset($_GET['text'])    ? sanitize_text_field(wp_unslash($_GET['text']))    : '';
}

if (empty($to) || empty($bodyId)) {
    die("پارامترهای to و bodyId الزامی هستند.\nمثال: ?to=09120000000&bodyId=12345&text=val1;val2\n");
}

// --- خواندن اعتبارنامه‌ها از تنظیمات افزونه ---
$opts     = get_option('nilsonlab_sms_settings', []);
$username = $opts['username'] ?? '9034030903';
$password = $opts['password'] ?? '8T7G46LF';
$apikey   = $opts['apikey']   ?? 'c0814e4e-4046-4cc0-9652-aadc0daf5b61';
var_dump($opts);
echo "=== NilsonLab SMS — Test Runner (BaseServiceNumber) ===\n\n";

// --- نرمال‌سازی شماره (09xxxxxxxxx) ---
$to_normalized = preg_replace('/\D+/', '', $to);
if (strpos($to_normalized, '98') === 0) {
    $to_normalized = '0' . substr($to_normalized, 2);
} elseif (strpos($to_normalized, '9') === 0 && strlen($to_normalized) === 10) {
    $to_normalized = '0' . $to_normalized;
}

echo "شماره ورودی : {$to}\n";
echo "شماره نرمال : {$to_normalized}\n";
echo "bodyId      : {$bodyId}\n";
echo "text        : {$text}\n";

// --- ساخت بدنه درخواست؛ اولویت با ApiKey (رفع خطای 110) ---
$endpoint = 'https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber';
$body = [
    'to'     => $to_normalized,
    'bodyId' => $bodyId,
    'text'   => $text,
];

if (!empty($apikey)) {
    // طبق سند: کد 110 الزام استفاده از ApiKey به جای رمز عبور
    $body['username'] = $apikey;
    echo "روش احراز  : ApiKey\n\n";
} else {
    $body['username'] = $username;
    $body['password'] = $password;
    echo "روش احراز  : username/password\n\n";
}

// --- ارسال درخواست ---
echo "در حال ارسال به: {$endpoint}\n";
echo str_repeat('-', 50) . "\n";

$response = wp_remote_post($endpoint, [
    'timeout'   => 30,
    'sslverify' => true,
    'body'      => $body,
]);

// --- بررسی خطای ارتباطی (مثل cURL error 7) ---
if (is_wp_error($response)) {
    echo "خطای ارتباطی: " . $response->get_error_message() . "\n";
    exit(1);
}

$http_code = wp_remote_retrieve_response_code($response);
$raw       = wp_remote_retrieve_body($response);
echo "HTTP Status : {$http_code}\n";
echo "Raw Response: {$raw}\n";
echo str_repeat('-', 50) . "\n";

$data = json_decode($raw, true);
if (!is_array($data)) {
    echo "پاسخ JSON معتبر نیست.\n";
    exit(1);
}
var_dump($data);
$value     = $data['Value']        ?? '';
$retStatus = $data['RetStatus']    ?? '';
$strRet    = $data['StrRetStatus'] ?? '';

echo "Value        : {$value}\n";
echo "RetStatus    : {$retStatus}\n";
echo "StrRetStatus : {$strRet}\n\n";

// --- ارزیابی نتیجه طبق سند صفحه ۶ ---
// شرط موفقیت: Value بیش از 15 رقم
if (is_numeric($value) && strlen((string) $value) > 15) {
    echo "✅ موفق — پیامک ارسال شد (recID معتبر).\n";
} else {
    echo "❌ ناموفق.\n";
    // نگاشت کدهای خطای شناخته‌شده
    $errors = [
        '0'   => 'نام کاربری یا رمز عبور اشتباه است',
        '2'   => 'در to بیش از یک شماره ارسال شده (فقط یک شماره مجاز است)',
        '4'   => 'bodyId نامعتبر یا تأییدنشده',
        '5'   => 'متن (text) با الگوی تأییدشده مطابقت ندارد',
        '35'  => 'شماره در لیست سیاه مخابرات',
        '110' => 'الزام استفاده از ApiKey به جای رمز عبور — apikey را در تنظیمات وارد کن',
    ];
    $code = (string) $value;
    if (isset($errors[$code])) {
        echo "   → علت ({$code}): {$errors[$code]}\n";
    } elseif (isset($errors[(string) $retStatus])) {
        echo "   → علت (RetStatus {$retStatus}): {$errors[(string)$retStatus]}\n";
    }
}

echo "\n=== پایان تست ===\n";
