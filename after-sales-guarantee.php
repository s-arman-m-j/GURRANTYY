<?php
/*
Plugin Name: After Sales Guarantee
Description: مدیریت گارانتی و خدمات پس از فروش برای ووکامرس.
Version: 1.8
Author: Arman MJ
Author URI: https://github.com/s-arman-m-j
Last Updated: 2025-02-14
*/

// جلوگیری از دسترسی مستقیم
if (!defined('ABSPATH')) {
    exit;
}

// تعریف ثابت‌های افزونه
define('ASG_VERSION', '1.8');
define('ASG_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ASG_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ASG_LAST_UPDATE', '2025-02-14');

// اتولود کلاس‌ها
spl_autoload_register(function ($class) {
    $prefix = 'ASG_';
    $base_dir = ASG_PLUGIN_DIR . 'includes/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . 'class-' . strtolower(str_replace('_', '-', $relative_class)) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// لود کردن لودر اصلی
require_once ASG_PLUGIN_DIR . 'includes/class-asg-loader.php';

/**
 * راه‌اندازی افزونه
 * @since 1.8
 * @return ASG_Loader
 */
function asg_init() {
    // ایجاد نمونه از لودر
    $loader = ASG_Loader::instance();
    
    // اضافه کردن اکشن برای لود کردن ماژول‌های سفارشی
    add_action('init', function() use ($loader) {
        // بررسی و کش کردن ماژول‌های پرکاربرد
        try {
            $security = $loader->get_module('security');
            $notifications = $loader->get_module('notifications');
            $api = $loader->get_module('api');
            $reports = $loader->get_module('reports');
            $assets_optimizer = $loader->get_module('assets-optimizer');
            
            // ذخیره در کش برای استفاده‌های بعدی
            wp_cache_set('asg_core_modules', [
                'security' => $security,
                'notifications' => $notifications,
                'api' => $api,
                'reports' => $reports,
                'assets_optimizer' => $assets_optimizer
            ], '', 3600);
            
            // اجرای اکشن‌های بعد از لود
            do_action('asg_after_init');
            
        } catch (Exception $e) {
            // لاگ خطا
            error_log('ASG Init Error: ' . $e->getMessage());
        }
    }, 15);
    
    return $loader;
}

// راه‌اندازی پلاگین
add_action('plugins_loaded', 'asg_init');

// اضافه کردن لینک تنظیمات در صفحه افزونه‌ها
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'asg_add_settings_link');
function asg_add_settings_link($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=warranty-management-settings') . '">' . __('تنظیمات') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}

// فعال‌سازی افزونه و ایجاد جدول‌های دیتابیس
register_activation_hook(__FILE__, 'asg_create_tables');
function asg_create_tables() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    // جدول درخواست‌های گارانتی
    $table_name = $wpdb->prefix . 'asg_guarantee_requests';
    $sql = "CREATE TABLE $table_name (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        user_id INT NOT NULL,
        tamin_user_id INT,
        defect_description TEXT,
        expert_comment TEXT,
        status VARCHAR(50),
        receipt_day INT,
        receipt_month VARCHAR(20),
        receipt_year INT,
        image_id INT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) $charset_collate;";

    // جدول یادداشت‌ها
    $table_notes = $wpdb->prefix . 'asg_guarantee_notes';
    $sql_notes = "CREATE TABLE $table_notes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        request_id INT NOT NULL,
        note TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) $charset_collate;";

    // جدول نوتیفیکیشن‌ها
    $sql_notifications = "CREATE TABLE {$wpdb->prefix}asg_notifications (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        request_id bigint(20) NOT NULL,
        type varchar(50) NOT NULL,
        message text NOT NULL,
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY request_id (request_id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    dbDelta($sql_notes);
    dbDelta($sql_notifications);

    // وضعیت‌های پیش‌فرض
    add_option('asg_statuses', array('آماده ارسال', 'ارسال شده', 'تعویض شده', 'خارج از گارانتی'));
}

// افزودن منو به پیشخوان وردپرس
add_action('admin_menu', 'asg_admin_menu');
// افزودن منو به پیشخوان وردپرس
function asg_admin_menu() {
    // افزودن منوی اصلی
    add_menu_page(
        'مدیریت گارانتی',
        'گارانتی',
        'manage_options',
        'warranty-management',
        'asg_admin_page',
        'dashicons-shield',
        6
    );

    // افزودن زیرمنو برای دیباگ
add_submenu_page(
    'warranty-management',
    'دیباگ',
    'manage_options',
    'warranty-management-debug', // تغییر اسلاگ
    'asg_debug_page'
);

    // افزودن زیرمنو برای ثبت گارانتی جدید
    add_submenu_page(
        'warranty-management',
        'ثبت گارانتی جدید',
        'ثبت گارانتی جدید',
        'manage_options',
        'warranty-management-add',
        'asg_add_guarantee_page'
    );

    // افزودن زیرمنو برای ثبت گارانتی دسته‌ای
    add_submenu_page(
        'warranty-management',
        'ثبت گارانتی دسته‌ای',
        'ثبت گارانتی دسته‌ای',
        'manage_options',
        'warranty-management-bulk',
        'asg_bulk_guarantee_page'
    );

    // افزودن زیرمنو برای گزارشات
    add_submenu_page(
        'warranty-management',
        'گزارشات',
        'گزارشات',
        'manage_options',
        'warranty-management-reports', // تغییر اسلاگ
        'asg_reports_main_page'
    );

    // افزودن زیرمنو برای نمودارها
    add_submenu_page(
        'warranty-management',
        'نمودارها',
        'نمودارها',
        'manage_options',
        'warranty-management-charts', // تغییر اسلاگ
        'asg_charts_page'
    );

    // افزودن زیرمنو برای تنظیمات
    add_submenu_page(
        'warranty-management',
        'تنظیمات',
        'تنظیمات',
        'manage_options',
        'warranty-management-settings',
        'asg_status_settings_page'
    );

    // افزودن زیرمنو برای دیباگ
    add_submenu_page(
        'warranty-management',
        'دیباگ',
        'دیباگ',
        'manage_options',
        'warranty-management-debug', // تغییر اسلاگ
        'asg_debug_page'
    );

    // افزودن صفحه مخفی برای ویرایش
    add_submenu_page(
        null,
        'ویرایش گارانتی',
        'ویرایش گارانتی',
        'manage_options',
        'warranty-management-edit',
        'asg_edit_guarantee_page'
    );
}

// صفحه دیباگ (اضافه شده)
// تابع دیباگ
function asg_debug_page() {
    global $wpdb;

    try {
        // بررسی دسترسی به عملکردهای مورد نیاز
        if (!function_exists('sys_getloadavg')) {
            throw new Exception('برخی از عملکردهای مورد نیاز برای بررسی منابع سیستم در دسترس نیستند: sys_getloadavg.');
        }

        // استایل‌های CSS
        echo '<style>
            .asg-debug-section {
                background: #fff;
                padding: 20px;
                margin: 20px 0;
                border: 1px solid #ccd0d4;
                box-shadow: 0 1px 1px rgba(0,0,0,.04);
            }
            .asg-status-ok {
                color: #46b450;
                font-weight: bold;
            }
            .asg-status-error {
                color: #dc3232;
                font-weight: bold;
            }
            .asg-status-warning {
                color: #ffb900;
                font-weight: bold;
            }
            .debug-actions {
                margin: 20px 0;
            }
            .refresh-debug {
                float: right;
            }
            .file-path {
                font-family: monospace;
                background: #f0f0f1;
                padding: 2px 5px;
                border-radius: 3px;
            }
        </style>';

        echo '<div class="wrap">';
        echo '<h1>صفحه دیباگ گارانتی</h1>';
        
        // دکمه بروزرسانی
        echo '<div class="debug-actions">';
        echo '<button class="button button-primary refresh-debug" onclick="window.location.reload();">بروزرسانی اطلاعات</button>';
        echo '</div>';

        // بخش اطلاعات مسیر فایل‌ها
        echo '<div class="asg-debug-section">';
        echo '<h2>اطلاعات مسیر فایل‌ها</h2>';
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<tr><td>ASG_PLUGIN_DIR:</td><td><span class="file-path">' . ASG_PLUGIN_DIR . '</span></td></tr>';
        echo '<tr><td>مسیر کامل فایل دیتابیس:</td><td><span class="file-path">' . ASG_PLUGIN_DIR . 'includes/class-asg-db.php' . '</span></td></tr>';
        echo '<tr><td>آیا فایل وجود دارد:</td><td>' . (file_exists(ASG_PLUGIN_DIR . 'includes/class-asg-db.php') ? '✅ بله' : '❌ خیر') . '</td></tr>';
        echo '<tr><td>مجوزهای دسترسی:</td><td>' . (is_readable(ASG_PLUGIN_DIR . 'includes/class-asg-db.php') ? '✅ قابل خواندن' : '❌ غیر قابل خواندن') . '</td></tr>';
        echo '</table>';
        echo '</div>';

        // بخش اطلاعات سیستم
        echo '<div class="asg-debug-section">';
        echo '<h2>اطلاعات سیستم</h2>';
        echo '<table class="wp-list-table widefat fixed striped">';
        
        $system_info = array(
            'نسخه PHP' => array(
                'value' => phpversion(),
                'status' => version_compare(phpversion(), '7.4', '>=') ? 'ok' : 'error',
                'message' => version_compare(phpversion(), '7.4', '>=') ? '' : 'نسخه PHP باید 7.4 یا بالاتر باشد'
            ),
            'نسخه وردپرس' => array(
                'value' => get_bloginfo('version'),
                'status' => 'ok'
            ),
            'نسخه پلاگین' => array(
                'value' => ASG_VERSION,
                'status' => 'ok'
            ),
            'زمان سرور' => array(
                'value' => current_time('mysql'),
                'status' => 'ok'
            ),
            'محدودیت حافظه PHP' => array(
                'value' => ini_get('memory_limit'),
                'status' => (int)ini_get('memory_limit') >= 128 ? 'ok' : 'warning',
                'message' => (int)ini_get('memory_limit') >= 128 ? '' : 'پیشنهاد می‌شود حداقل 128MB باشد'
            ),
            'حداکثر زمان اجرا' => array(
                'value' => ini_get('max_execution_time') . ' ثانیه',
                'status' => (int)ini_get('max_execution_time') >= 30 ? 'ok' : 'warning',
                'message' => (int)ini_get('max_execution_time') >= 30 ? '' : 'پیشنهاد می‌شود حداقل 30 ثانیه باشد'
            )
        );

        foreach ($system_info as $label => $info) {
            $status_icon = $info['status'] === 'ok' ? '✅' : ($info['status'] === 'error' ? '❌' : '⚠️');
            echo "<tr><td>$label:</td><td>";
            echo $status_icon . ' ' . $info['value'];
            if (!empty($info['message'])) {
                echo " <span class='description'>(" . $info['message'] . ")</span>";
            }
            echo "</td></tr>";
        }
        
        echo '</table>';
        echo '</div>';

        // بخش دیتابیس
        echo '<div class="asg-debug-section">';
        echo '<h2>وضعیت دیتابیس</h2>';
        echo '<table class="wp-list-table widefat fixed striped">';
        
        $tables = array(
            $wpdb->prefix . 'asg_guarantee_requests' => 'درخواست‌های گارانتی',
            $wpdb->prefix . 'asg_guarantee_notes' => 'یادداشت‌های گارانتی',
            $wpdb->prefix . 'asg_notifications' => 'نوتیفیکیشن‌ها'
        );

        foreach ($tables as $table => $label) {
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'") == $table;
            $status_icon = $table_exists ? '✅' : '❌';
            
            echo "<tr><td>$label:</td><td>";
            echo $status_icon . ' ';
            if ($table_exists) {
                $count = $wpdb->get_var("SELECT COUNT(*) FROM $table");
                echo "$count رکورد";
                echo " <span class='description'>(نام جدول: $table)</span>";
            } else {
                echo "جدول وجود ندارد!";
            }
            echo "</td></tr>";
        }
        
        echo '</table>';
        echo '</div>';

        // بخش تنظیمات
        echo '<div class="asg-debug-section">';
        echo '<h2>تنظیمات پلاگین</h2>';
        echo '<table class="wp-list-table widefat fixed striped">';
        
        $settings = array(
            'asg_statuses' => array(
                'label' => 'وضعیت‌های تعریف شده',
                'default' => array('آماده ارسال', 'ارسال شده', 'تعویض شده', 'خارج از گارانتی')
            ),
            'asg_version' => array(
                'label' => 'نسخه نصب شده',
                'default' => ASG_VERSION
            ),
            'asg_notification_enabled' => array(
                'label' => 'وضعیت نوتیفیکیشن‌ها',
                'default' => '0'
            )
        );

        foreach ($settings as $option_name => $setting) {
            $value = get_option($option_name, $setting['default']);
            $status_icon = !empty($value) ? '✅' : '⚠️';
            
            echo "<tr><td>{$setting['label']}:</td><td>";
            echo $status_icon . ' ';
            if (is_array($value)) {
                echo implode(', ', array_map('esc_html', $value));
            } else {
                echo esc_html($value ?: 'تنظیم نشده');
            }
            echo "</td></tr>";
        }
        
        echo '</table>';
        echo '</div>';

        // بخش بررسی فایل‌های اصلی
        echo '<div class="asg-debug-section">';
        echo '<h2>بررسی فایل‌های اصلی</h2>';
        echo '<table class="wp-list-table widefat fixed striped">';
        
        $files_to_check = array(
            'includes/class-asg-notifications.php' => 'فایل نوتیفیکیشن‌ها',
            'includes/class-asg-db.php' => 'فایل دیتابیس',
            'includes/class-asg-api.php' => 'فایل API',
            'includes/class-asg-reports.php' => 'فایل گزارشات',
            'includes/class-asg-security.php' => 'فایل امنیت'
        );

        foreach ($files_to_check as $file => $label) {
            $full_path = ASG_PLUGIN_DIR . $file;
            $file_exists = file_exists($full_path);
            $status_icon = $file_exists ? '✅' : '❌';
            
            echo "<tr><td>$label:</td><td>";
            echo $status_icon . ' ';
            if ($file_exists) {
                echo 'موجود است';
                echo " <span class='description file-path'>($full_path)</span>";
            } else {
                echo 'یافت نشد!';
                echo " <span class='description file-path'>($full_path)</span>";
            }
            echo "</td></tr>";
        }
        
        echo '</table>';
        echo '</div>';

        // بخش بررسی دسترسی‌ها
        echo '<div class="asg-debug-section">';
        echo '<h2>بررسی دسترسی‌ها</h2>';
        echo '<table class="wp-list-table widefat fixed striped">';
        
        $upload_dir = wp_upload_dir();
        $directories = array(
            $upload_dir['basedir'] => 'پوشه آپلود',
            ASG_PLUGIN_DIR => 'پوشه افزونه',
            ASG_PLUGIN_DIR . 'includes' => 'پوشه includes'
        );

        foreach ($directories as $dir => $label) {
            $is_writable = wp_is_writable($dir);
            $exists = file_exists($dir);
            $status_icon = $exists ? ($is_writable ? '✅' : '⚠️') : '❌';
            
            echo "<tr><td>$label:</td><td>";
            echo $status_icon . ' ';
            if (!$exists) {
                echo 'پوشه وجود ندارد!';
            } else {
                echo $is_writable ? 'قابل نوشتن' : 'غیر قابل نوشتن';
            }
            echo " <span class='description file-path'>($dir)</span>";
            echo "</td></tr>";
        }
        
        echo '</table>';
        echo '</div>';
       
        // بخش منابع سیستم
echo '<div class="asg-debug-section">';
echo '<h2>استفاده از منابع سیستم</h2>';
echo '<table class="wp-list-table widefat fixed striped">';

// بهینه‌سازی محاسبه مصرف CPU
$cpu_info = array(
    'cores' => 1,
    'load' => array(0, 0, 0)
);

// از کش برای ذخیره اطلاعات CPU استفاده کنید
$cached_cpu_info = wp_cache_get('asg_cpu_info');
if (false === $cached_cpu_info) {
    if (is_readable('/proc/cpuinfo')) {
        $cpuinfo = file_get_contents('/proc/cpuinfo');
        preg_match_all('/^processor/m', $cpuinfo, $matches);
        $cpu_info['cores'] = count($matches[0]);
    } elseif (stripos(PHP_OS, 'win') === false) {
        $cpu_info['cores'] = (int) trim(shell_exec("nproc"));
    }
    
    $cpu_info['load'] = sys_getloadavg();
    wp_cache_set('asg_cpu_info', $cpu_info, '', 60); // کش برای 60 ثانیه
    
    $cached_cpu_info = $cpu_info;
}

// محاسبه درصد استفاده از CPU با استفاده از مقادیر کش شده
$cpu_usage_percent_1m = round(($cached_cpu_info['load'][0] / $cached_cpu_info['cores']) * 100, 2);
$cpu_usage_percent_5m = round(($cached_cpu_info['load'][1] / $cached_cpu_info['cores']) * 100, 2);
$cpu_usage_percent_15m = round(($cached_cpu_info['load'][2] / $cached_cpu_info['cores']) * 100, 2);

// محاسبه استفاده از حافظه
$memory_usage = round(memory_get_usage() / 1024 / 1024, 2);
$memory_peak_usage = round(memory_get_peak_usage() / 1024 / 1024, 2);

// اضافه کردن کلاس‌های CSS برای رنگ‌بندی درصدها
function get_usage_class($percent) {
    if ($percent < 50) return 'usage-normal';
    if ($percent < 80) return 'usage-warning';
    return 'usage-critical';
}

// استایل‌های CSS برای نمایش بهتر
echo '<style>
    .usage-normal { color: #00a32a; font-weight: bold; }
    .usage-warning { color: #dba617; font-weight: bold; }
    .usage-critical { color: #d63638; font-weight: bold; }
    .resource-value { 
        font-family: monospace;
        background: #f0f0f1;
        padding: 2px 6px;
        border-radius: 3px;
        margin-right: 5px;
    }
    .resource-percent {
        display: inline-block;
        min-width: 60px;
        text-align: right;
    }
</style>';

// نمایش اطلاعات با فرمت جدید
echo '<tr>
        <td>استفاده از حافظه فعلی:</td>
        <td>
            <span class="resource-value">' . $memory_usage . ' MB</span>
        </td>
    </tr>';
echo '<tr>
        <td>بیشترین استفاده از حافظه:</td>
        <td>
            <span class="resource-value">' . $memory_peak_usage . ' MB</span>
        </td>
    </tr>';
echo '<tr>
        <td>بار پردازنده (1 دقیقه):</td>
        <td>
            <span class="resource-value">' . $cached_cpu_info['load'][0] . '</span>
            <span class="' . get_usage_class($cpu_usage_percent_1m) . ' resource-percent">
                ' . $cpu_usage_percent_1m . '%
            </span>
        </td>
    </tr>';
echo '<tr>
        <td>بار پردازنده (5 دقیقه):</td>
        <td>
            <span class="resource-value">' . $cached_cpu_info['load'][1] . '</span>
            <span class="' . get_usage_class($cpu_usage_percent_5m) . ' resource-percent">
                ' . $cpu_usage_percent_5m . '%
            </span>
        </td>
    </tr>';
echo '<tr>
        <td>بار پردازنده (15 دقیقه):</td>
        <td>
            <span class="resource-value">' . $cached_cpu_info['load'][2] . '</span>
            <span class="' . get_usage_class($cpu_usage_percent_15m) . ' resource-percent">
                ' . $cpu_usage_percent_15m . '%
            </span>
        </td>
    </tr>';
echo '<tr>
        <td>تعداد هسته‌های CPU:</td>
        <td>
            <span class="resource-value">' . $cached_cpu_info['cores'] . '</span>
        </td>
    </tr>';

echo '</table>';
echo '</div>';   

        // محاسبه تعداد هسته‌های CPU
        $cpu_cores = 1;
        if (is_readable('/proc/cpuinfo')) {
            $cpuinfo = file_get_contents('/proc/cpuinfo');
            preg_match_all('/^processor/m', $cpuinfo, $matches);
            $cpu_cores = count($matches[0]);
        } elseif (stripos(PHP_OS, 'win') === false) {
            $cpu_cores = (int) trim(shell_exec("nproc"));
        } else {
            $cpu_cores = 1; // پیش‌فرض به 1 اگر تعداد هسته‌ها در دسترس نباشد
        }

        // محاسبه درصد استفاده از CPU
        $cpu_load = sys_getloadavg();
        $cpu_usage_percent_1m = round(($cpu_load[0] / $cpu_cores) * 100, 2) . '%';
        $cpu_usage_percent_5m = round(($cpu_load[1] / $cpu_cores) * 100, 2) . '%';
        $cpu_usage_percent_15m = round(($cpu_load[2] / $cpu_cores) * 100, 2) . '%';

        echo '<tr><td>استفاده از حافظه فعلی:</td><td>' . $memory_usage . ' (' . $memory_usage_percent . ')</td></tr>';
        echo '<tr><td>بیشترین استفاده از حافظه:</td><td>' . $memory_peak_usage . '</td></tr>';
        echo '<tr><td>بار پردازنده (1 دقیقه):</td><td>' . $cpu_load[0] . ' (' . $cpu_usage_percent_1m . ')</td></tr>';
        echo '<tr><td>بار پردازنده (5 دقیقه):</td><td>' . $cpu_load[1] . ' (' . $cpu_usage_percent_5m . ')</td></tr>';
        echo '<tr><td>بار پردازنده (15 دقیقه):</td><td>' . $cpu_load[2] . ' (' . $cpu_usage_percent_15m . ')</td></tr>';

        echo '</table>';
        echo '</div>';

        echo '</div>'; // پایان wrap

    } catch (Exception $e) {
        echo '<div class="asg-debug-section">';
        echo '<h2>خطا</ه2>';
        echo '<p class="asg-status-error">' . $e->getMessage() . '</p>';
        echo '</div>';
    }
}

// صفحه مدیریت درخواست‌ها
function asg_admin_page() {
    global $wpdb;

    echo '<div class="wrap">';
    echo '<h1>مدیریت گارانتی</h1>';
    echo '<a href="' . admin_url('admin.php?page=asg-add-guarantee') . '" class="button button-primary">ثبت گارانتی جدید</a>';
    echo '<h2>لیست درخواست‌های گارانتی</h2>';
    asg_show_requests_table();
    echo '</div>';
}

// صفحه ثبت گارانتی جدید
function asg_add_guarantee_page() {
    global $wpdb;

    // پردازش ارسال فرم
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_guarantee_request'])) {
        // اعتبارسنجی و سانیتیزیشن داده‌ها
        $product_id = intval($_POST['product_id']);
        $user_id = intval($_POST['user_id']);
        $tamin_user_id = intval($_POST['tamin_user_id']);
        $defect_description = sanitize_textarea_field($_POST['defect_description']);
        $expert_comment = sanitize_textarea_field($_POST['expert_comment']);
        $status = sanitize_text_field($_POST['status']);
        $receipt_day = intval($_POST['receipt_day']);
        $receipt_month = sanitize_text_field($_POST['receipt_month']);
        $receipt_year = intval($_POST['receipt_year']);
        $image_id = intval($_POST['image_id']);

        // درج داده در دیتابیس
        $result = $wpdb->insert(
            $wpdb->prefix . 'asg_guarantee_requests',
            array(
                'product_id' => $product_id,
                'user_id' => $user_id,
                'tamin_user_id' => $tamin_user_id,
                'defect_description' => $defect_description,
                'expert_comment' => $expert_comment,
                'status' => $status,
                'receipt_day' => $receipt_day,
                'receipt_month' => $receipt_month,
                'receipt_year' => $receipt_year,
                'image_id' => $image_id,
                'created_at' => current_time('mysql')
            ),
            array(
                '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%s'
            )
        );

        // نمایش پیام وضعیت
        if ($result) {
            echo '<div class="notice notice-success"><p>درخواست گارانتی با موفقیت ثبت شد.</p></div>';
        } else {
            $error_message = $wpdb->last_error ? $wpdb->last_error : __('خطای ناشناخته رخ داده است', 'textdomain');
            echo '<div class="notice notice-error"><p>خطا در ثبت درخواست: ' . esc_html($error_message) . '</p></div>';
        }
    }

    // شروع ساختار HTML
    echo '<div class="wrap">';
    echo '<h1>ثبت گارانتی جدید</h1>';
    echo '<form method="post" action="" class="asg-form-container">';

    // سطر 1: اطلاعات پایه
    echo '<div class="asg-row">';
    
    // کارت انتخاب محصول
    echo '<div class="asg-col">';
    echo '<div class="asg-card">';
    echo '<div class="asg-card-header">انتخاب محصول</div>';
    echo '<div class="asg-card-body">';
    echo '<div class="asg-form-group">';
    echo '<label for="product_id">محصول:</label>';
    echo '<select name="product_id" id="product_id" class="asg-select2" required>';
    echo '<option value="">جستجو و انتخاب محصول...</option>';
    echo '</select>';
    echo '</div>';
    echo '</div></div></div>';

    // کارت انتخاب مشتری
    echo '<div class="asg-col">';
    echo '<div class="asg-card">';
    echo '<div class="asg-card-header">مشتری</div>';
    echo '<div class="asg-card-body">';
    echo '<div class="asg-form-group">';
    echo '<label for="user_id">مشتری:</label>';
    echo '<select name="user_id" id="user_id" class="asg-select2" required></select>';
    echo '</div>';
    echo '</div></div></div>';

    // کارت تامین کننده
    echo '<div class="asg-col">';
    echo '<div class="asg-card">';
    echo '<div class="asg-card-header">تامین کننده</div>';
    echo '<div class="asg-card-body">';
    echo '<div class="asg-form-group">';
    echo '<label for="tamin_user_id">تامین کننده:</label>';
    echo '<select name="tamin_user_id" id="tamin_user_id" class="asg-select2">';
    $tamin_users = get_users(array('role' => 'tamin', 'fields' => array('ID', 'display_name')));
    foreach ($tamin_users as $user) {
        echo '<option value="' . esc_attr($user->ID) . '">' . esc_html($user->display_name) . '</option>';
    }
    echo '</select>';
    echo '</div>';
    echo '</div></div></div>';
    echo '</div>'; // پایان سطر 1

    // سطر 2: اطلاعات زمانی
    echo '<div class="asg-row">';
    echo '<div class="asg-col">';
    echo '<div class="asg-card">';
    echo '<div class="asg-card-header">تاریخ دریافت (شمسی)</div>';
    echo '<div class="asg-card-body">';
    echo '<div class="asg-date-fields">';
    
    // فیلدهای تاریخ
    echo '<div class="asg-form-group">';
    echo '<label for="receipt_day">روز:</label>';
    echo '<select name="receipt_day" id="receipt_day" class="asg-date-select" required>';
    echo generate_day_options();
    echo '</select>';
    echo '</div>';
    
    echo '<div class="asg-form-group">';
    echo '<label for="receipt_month">ماه:</label>';
    echo '<select name="receipt_month" id="receipt_month" class="asg-date-select" required>';
    echo generate_month_options();
    echo '</select>';
    echo '</div>';
    
    echo '<div class="asg-form-group">';
    echo '<label for="receipt_year">سال:</label>';
    echo '<select name="receipt_year" id="receipt_year" class="asg-date-select" required>';
    echo generate_year_options();
    echo '</select>';
    echo '</div>';
    
    echo '</div></div></div></div>';
    echo '</div>'; // پایان سطر 2

    // سطر 3: اطلاعات فنی
    echo '<div class="asg-row">';
    
    // کارت نقص فنی
    echo '<div class="asg-col">';
    echo '<div class="asg-card">';
    echo '<div class="asg-card-header">مشخصات فنی</div>';
    echo '<div class="asg-card-body">';
    echo '<div class="asg-form-group">';
    echo '<label for="defect_description">شرح کامل نقص:</label>';
    echo '<textarea name="defect_description" id="defect_description" rows="5" required class="asg-textarea"></textarea>';
    echo '</div>';
    echo '<div class="asg-form-group">';
    echo '<label for="expert_comment">نظر کارشناسی:</label>';
    echo '<textarea name="expert_comment" id="expert_comment" rows="5" class="asg-textarea"></textarea>';
    echo '</div>';
    echo '</div></div></div>';

    // کارت وضعیت و مستندات
    echo '<div class="asg-col">';
    echo '<div class="asg-card">';
    echo '<div class="asg-card-header">وضعیت و مستندات</div>';
    echo '<div class="asg-card-body">';
    
    // وضعیت
    echo '<div class="asg-form-group">';
    echo '<label for="status">وضعیت فعلی:</label>';
    echo '<select name="status" id="status" class="asg-select2" required>';
    $statuses = get_option('asg_statuses', array('آماده ارسال', 'ارسال شده', 'تعویض شده', 'خارج از گارانتی'));
    foreach ($statuses as $status) {
        echo '<option value="' . esc_attr($status) . '">' . esc_html($status) . '</option>';
    }
    echo '</select>';
    echo '</div>';
    
    // آپلود عکس
    echo '<div class="asg-form-group">';
    echo '<label>مستندات تصویری:</label>';
    echo '<div class="asg-upload-wrapper">';
    echo '<input type="hidden" name="image_id" id="image_id">';
    echo '<button type="button" class="button button-secondary" id="asg-upload-btn">انتخاب تصویر</button>';
    echo '<div id="asg-image-preview" class="asg-image-preview"></div>';
    echo '</div>';
    echo '</div>';
    
    echo '</div></div></div>';
    echo '</div>'; // پایان سطر 3

    // دکمه ثبت نهایی
    echo '<div class="asg-row">';
    echo '<div class="asg-col">';
    echo '<div class="asg-submit-wrapper">';
    echo '<input type="submit" name="submit_guarantee_request" value="ثبت نهایی درخواست" class="button button-primary button-large">';
    echo '</div>';
    echo '</div>';
    echo '</div>';

    echo '</form>';

    // اسکریپت‌های ضروری
    echo '<script type="text/javascript">
    jQuery(document).ready(function($) {
        // Select2 برای محصولات
        $("#product_id").select2({
            ajax: {
                url: ajaxurl,
                dataType: "json",
                delay: 250,
                data: function(params) {
                    return {
                        action: "asg_search_products",
                        search: params.term,
                        page: params.page || 1
                    };
                },
                processResults: function(data, params) {
                    params.page = params.page || 1;
                    return {
                        results: data,
                        pagination: {
                            more: (params.page * 30) < data.total_count
                        }
                    };
                },
                cache: true
            },
            minimumInputLength: 3,
            placeholder: "جستجوی محصول...",
            language: {
                noResults: function() {
                    return "نتیجه‌ای یافت نشد";
                },
                searching: function() {
                    return "در حال جستجو...";
                }
            }
        });

        // Select2 برای کاربران
        $("#user_id").select2({
            ajax: {
                url: ajaxurl,
                dataType: "json",
                delay: 250,
                data: function(params) {
                    return {
                        action: "asg_search_users",
                        search: params.term,
                        page: params.page || 1
                    };
                },
                processResults: function(data, params) {
                    params.page = params.page || 1;
                    return {
                        results: data,
                        pagination: {
                            more: (params.page * 30) < data.total_count
                        }
                    };
                },
                cache: true
            },
            minimumInputLength: 3,
            placeholder: "جستجوی مشتری...",
            language: {
                noResults: function() {
                    return "مشتری یافت نشد";
                }
            }
        });

        // سیستم آپلود عکس
        $("#asg-upload-btn").click(function(e) {
            e.preventDefault();
            var frame = wp.media({
                title: "انتخاب تصویر",
                button: { text: "انتخاب" },
                multiple: false,
                library: { type: "image" }
            });

            frame.on("select", function() {
                var attachment = frame.state().get("selection").first().toJSON();
                $("#image_id").val(attachment.id);
                $("#asg-image-preview").html(
                    \'<div class="asg-preview-item">\' +
                        \'<img src="\' + attachment.sizes.thumbnail.url + \'" alt="پیش‌نمایش">\' +
                        \'<button type="button" class="button button-small asg-remove-image">حذف</button>\' +
                    \'</div>\'
                );
            });

            frame.open();
        });

        // حذف عکس
        $(document).on("click", ".asg-remove-image", function() {
            $("#image_id").val("");
            $("#asg-image-preview").html("");
        });
    });
    </script>';

    echo '</div>'; // پایان wrap
}

// توابع کمکی برای تولید options
function generate_day_options() {
    $html = '';
    for ($day = 1; $day <= 31; $day++) {
        $html .= "<option value='$day'>$day</option>";
    }
    return $html;
}

function generate_month_options() {
    $months = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    $html = '';
    foreach ($months as $month) {
        $html .= "<option value='$month'>$month</option>";
    }
    return $html;
}

function generate_year_options() {
    $html = '';
    for ($year = 1403; $year <= 1410; $year++) {
        $html .= "<option value='$year'>$year</option>";
    }
    return $html;
}

function generate_status_options() {
    $statuses = get_option('asg_statuses', ['آماده ارسال', 'ارسال شده', 'تعویض شده', 'خارج از گارانتی']);
    $html = '';
    foreach ($statuses as $status) {
        $html .= "<option value='$status'>$status</option>";
    }
    return $html;
}

// صفحه ویرایش گارانتی
function asg_edit_guarantee_page() {
    global $wpdb;

    // دریافت شناسه درخواست از URL
    $request_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

    // بررسی وجود درخواست
    $request = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}asg_guarantee_requests WHERE id = %d", $request_id));

    if (!$request) {
        echo '<div class="notice notice-error"><p>درخواست گارانتی یافت نشد.</p></div>';
        return;
    }

    // بررسی ارسال فرم
    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        if (isset($_POST['submit_guarantee_request'])) {
            // دریافت وضعیت جدید
            $status = sanitize_text_field($_POST['status']);

            // بررسی اگر وضعیت تغییر کرده است
            if ($status != $request->status) {
                // به‌روزرسانی وضعیت درخواست
                $result = $wpdb->update(
                    $wpdb->prefix . 'asg_guarantee_requests',
                    array('status' => $status), // فیلدهای به‌روزرسانی
                    array('id' => $request_id) // شرط به‌روزرسانی
                );

                if ($result !== false) {
                    // ثبت تغییرات وضعیت در یادداشت‌ها
                    $wpdb->insert(
                        $wpdb->prefix . 'asg_guarantee_notes',
                        array(
                            'request_id' => $request_id,
                            'note' => '[تغییر وضعیت] وضعیت تغییر کرد به: ' . $status,
                            'created_at' => current_time('mysql') // زمان فعلی
                        )
                    );
                    echo '<div class="notice notice-success"><p>وضعیت درخواست با موفقیت به‌روزرسانی شد.</p></div>';
                } else {
                    echo '<div class="notice notice-error"><p>خطا در به‌روزرسانی وضعیت درخواست: ' . $wpdb->last_error . '</p></div>';
                }
            } else {
                echo '<div class="notice notice-warning"><p>وضعیت تغییر نکرده است.</p></div>';
            }
        } elseif (isset($_POST['submit_new_note'])) {
            // افزودن یادداشت جدید
            $new_note = sanitize_textarea_field($_POST['new_note']);
            $wpdb->insert(
                $wpdb->prefix . 'asg_guarantee_notes',
                array(
                    'request_id' => $request_id,
                    'note' => $new_note,
                    'created_at' => current_time('mysql') // زمان فعلی
                )
            );
            echo '<div class="notice notice-success"><p>یادداشت با موفقیت افزوده شد.</p></div>';
        }
    }

    // نمایش فرم ویرایش
    echo '<div class="wrap">';
    echo '<h1>ویرایش گارانتی</h1>';

    // نمایش اطلاعات درخواست
    echo '<h2>اطلاعات درخواست:</h2>';
    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<tbody>';
    echo '<tr><th>محصول</th><td>' . get_the_title($request->product_id) . '</td></tr>';
    echo '<tr><th>مشتری</th><td>' . get_userdata($request->user_id)->display_name . '</td></tr>';
    echo '<tr><th>تامین‌کننده</th><td>' . ($request->tamin_user_id ? get_userdata($request->tamin_user_id)->display_name : '-') . '</td></tr>';
    echo '<tr><th>نقص کالا</th><td>' . esc_html($request->defect_description) . '</td></tr>';
    echo '<tr><th>نظر کارشناس</th><td>' . esc_html($request->expert_comment) . '</td></tr>';
    echo '<tr><th>تاریخ دریافت</th><td>' . $request->receipt_day . ' ' . $request->receipt_month . ' ' . $request->receipt_year . '</td></tr>';
    echo '<tr><th>عکس</th><td>';
    if ($request->image_id) {
        $image_url = wp_get_attachment_url($request->image_id);
        if ($image_url) {
            echo '<img src="' . esc_url($image_url) . '" style="max-width: 200px; height: auto;">';
        }
    } else {
        echo '-';
    }
    echo '</td></tr>';
    echo '</tbody>';
    echo '</table>';

    // فرم تغییر وضعیت
    echo '<h2>تغییر وضعیت:</h2>';
    echo '<form method="post" action="">';
    echo '<label for="status">وضعیت:</label>';
    echo '<select name="status" id="status" required>';
    $statuses = get_option('asg_statuses', array('آماده ارسال', 'ارسال شده', 'تعویض شده', 'خارج از گارانتی'));
    foreach ($statuses as $status) {
        $selected = $status == $request->status ? 'selected' : '';
        echo '<option value="' . $status . '" ' . $selected . '>' . $status . '</option>';
    }
    echo '</select>';
    echo '<input type="submit" name="submit_guarantee_request" value="به‌روزرسانی وضعیت" class="button button-primary">';
    echo '</form>';

    // نمایش یادداشت‌ها (شامل تغییرات وضعیت)
    echo '<h2>یادداشت‌ها و تغییرات وضعیت:</h2>';
    $notes = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}asg_guarantee_notes WHERE request_id = %d ORDER BY created_at DESC", $request_id));
    if ($notes) {
        echo '<ul>';
        foreach ($notes as $note) {
            echo '<li>' . esc_html($note->note) . ' - <small>' . esc_html($note->created_at) . '</small></li>';
        }
        echo '</ul>';
    } else {
        echo '<p>هیچ یادداشتی ثبت نشده است.</p>';
    }

    // فرم افزودن یادداشت جدید
    echo '<h2>افزودن یادداشت جدید:</h2>';
    echo '<form method="post" action="">';
    echo '<label for="new_note">یادداشت جدید:</label><br>';
    echo '<textarea name="new_note" id="new_note" rows="5" cols="40" required></textarea><br>';
    echo '<input type="submit" name="submit_new_note" value="افزودن یادداشت" class="button">';
    echo '</form>';

    echo '</div>'; // پایان wrap
}

// صفحه تنظیمات وضعیت‌ها
function asg_status_settings_page() {
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_statuses'])) {
        $statuses = explode("\n", sanitize_textarea_field($_POST['statuses']));
        $statuses = array_map('trim', $statuses);
        update_option('asg_statuses', $statuses);
        echo '<div class="notice notice-success"><p>وضعیت‌ها با موفقیت به‌روزرسانی شدند.</p></div>';
    }

    $current_statuses = get_option('asg_statuses', array('آماده ارسال', 'ارسال شده', 'تعویض شده', 'خارج از گارانتی'));

    echo '<div class="wrap">';
    echo '<h1>تنظیمات وضعیت‌ها</h1>';
    echo '<form method="post" action="">';
    echo '<label for="statuses">وضعیت‌ها (هر خط یک وضعیت):</label><br>';
    echo '<textarea name="statuses" id="statuses" rows="10" cols="50">' . implode("\n", $current_statuses) . '</textarea><br>';
    echo '<input type="submit" name="submit_statuses" value="ذخیره وضعیت‌ها" class="button button-primary">';
    echo '</form>';
    echo '</div>';
}

// نمایش جدول درخواست‌ها
function asg_show_requests_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'asg_guarantee_requests';

    // Initialize variables for filter conditions
    $where = array();
    $query_vars = array();

    // Get filter values from the URL
    $filter_id = isset($_GET['filter_id']) ? intval($_GET['filter_id']) : '';
    $filter_product = isset($_GET['filter_product']) ? intval($_GET['filter_product']) : '';
    $filter_user = isset($_GET['filter_user']) ? intval($_GET['filter_user']) : '';
    $filter_tamin = isset($_GET['filter_tamin']) ? intval($_GET['filter_tamin']) : '';
    $filter_status = isset($_GET['filter_status']) ? sanitize_text_field($_GET['filter_status']) : '';
    $filter_receipt_year = isset($_GET['filter_receipt_year']) ? intval($_GET['filter_receipt_year']) : '';
    $filter_receipt_month = isset($_GET['filter_receipt_month']) ? sanitize_text_field($_GET['filter_receipt_month']) : '';

    // تنظیمات صفحه‌بندی
    $per_page = 10;
    $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $offset = ($current_page - 1) * $per_page;

    // Build WHERE conditions based on filter values
    if (!empty($filter_id)) {
        $where[] = "id = %d";
        $query_vars[] = $filter_id;
    }
    if (!empty($filter_product)) {
        $where[] = "product_id = %d";
        $query_vars[] = $filter_product;
    }
    if (!empty($filter_user)) {
        $where[] = "user_id = %d";
        $query_vars[] = $filter_user;
    }
    if (!empty($filter_tamin)) {
        $where[] = "tamin_user_id = %d";
        $query_vars[] = $filter_tamin;
    }
    if (!empty($filter_status)) {
        $where[] = "status = %s";
        $query_vars[] = $filter_status;
    }
    if (!empty($filter_receipt_year)) {
        $where[] = "receipt_year = %d";
        $query_vars[] = $filter_receipt_year;
    }
    if (!empty($filter_receipt_month)) {
        $where[] = "receipt_month = %s";
        $query_vars[] = $filter_receipt_month;
    }

    // Build the final WHERE clause
    $where_clause = !empty($where) ? " WHERE " . implode(" AND ", $where) : "";

    // Get total count for pagination
    $total_items = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table_name" . $where_clause,
        $query_vars
    ));
    $total_pages = ceil($total_items / $per_page);

    // Prepare the SQL query with filters and pagination
    $query_vars[] = $per_page;
    $query_vars[] = $offset;
    $sql = $wpdb->prepare(
        "SELECT * FROM $table_name" . $where_clause . " ORDER BY id DESC LIMIT %d OFFSET %d",
        $query_vars
    );
    $requests = $wpdb->get_results($sql);

    // Start the table HTML
    echo '<div class="wrap">';
    echo '<h1>لیست درخواست‌های گارانتی</h1>';
    
    // Filters form
    echo '<form method="get" action="">';
    echo '<input type="hidden" name="page" value="warranty-management">';
    echo '<div class="tablenav top">';
    echo '<div class="alignleft actions">';
    
    // ID filter
    echo '<input type="number" name="filter_id" id="filter_id" placeholder="شماره" value="' . esc_attr($filter_id) . '" style="width: 80px;">';
    
    // Product filter
    echo '<select name="filter_product" id="filter_product" style="width: 200px;">';
    echo '<option value="">تمامی محصولات</option>';
    $products = wc_get_products(array('status' => 'publish', 'limit' => -1));
    foreach ($products as $product) {
        $selected = ($product->get_id() == $filter_product) ? 'selected' : '';
        echo '<option value="' . $product->get_id() . '" ' . $selected . '>' . $product->get_name() . '</option>';
    }
    echo '</select>';
    
    // User filter
    echo '<select name="filter_user" id="filter_user" style="width: 150px;">';
    echo '<option value="">تمامی مشتریان</option>';
    $users = get_users();
    foreach ($users as $user) {
        $selected = ($user->ID == $filter_user) ? 'selected' : '';
        echo '<option value="' . $user->ID . '" ' . $selected . '>' . $user->display_name . '</option>';
    }
    echo '</select>';
    
    // Tamin filter
    echo '<select name="filter_tamin" id="filter_tamin" style="width: 150px;">';
    echo '<option value="">تمامی تامین‌کنندگان</option>';
    $tamin_users = get_users(array('role' => 'tamin'));
    foreach ($tamin_users as $user) {
        $selected = ($user->ID == $filter_tamin) ? 'selected' : '';
        echo '<option value="' . $user->ID . '" ' . $selected . '>' . $user->display_name . '</option>';
    }
    echo '</select>';
    
    // Status filter
    echo '<select name="filter_status" id="filter_status" style="width: 120px;">';
    echo '<option value="">تمامی وضعیت‌ها</option>';
    $statuses = get_option('asg_statuses', array('آماده ارسال', 'ارسال شده', 'تعویض شده', 'خارج از گارانتی'));
    foreach ($statuses as $status) {
        $selected = ($status == $filter_status) ? 'selected' : '';
        echo '<option value="' . $status . '" ' . $selected . '>' . $status . '</option>';
    }
    echo '</select>';

    // Year filter
    echo '<select name="filter_receipt_year" id="filter_receipt_year" style="width: 100px;">';
    echo '<option value="">سال دریافت</option>';
    for ($year = 1402; $year <= 1410; $year++) {
        $selected = ($year == $filter_receipt_year) ? 'selected' : '';
        echo '<option value="' . $year . '" ' . $selected . '>' . $year . '</option>';
    }
    echo '</select>';

    // Month filter
    echo '<select name="filter_receipt_month" id="filter_receipt_month" style="width: 100px;">';
    echo '<option value="">ماه دریافت</option>';
    $months = array(
        'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
        'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'
    );
    foreach ($months as $month) {
        $selected = ($month == $filter_receipt_month) ? 'selected' : '';
        echo '<option value="' . $month . '" ' . $selected . '>' . $month . '</option>';
    }
    echo '</select>';
    
    // Filter buttons
    echo '<input type="submit" value="اعمال فیلتر" class="button">';
    echo '<a href="' . admin_url('admin.php?page=warranty-management') . '" class="button">حذف فیلترها</a>';
    
    echo '</div>';
    echo '</div>';
    echo '</form>';

    // Table
    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<thead>
        <tr>
            <th width="60">شماره</th>
            <th width="150">محصول</th>
            <th width="120">مشتری</th>
            <th width="120">تامین‌کننده</th>
            <th width="100">وضعیت</th>
            <th width="120">تاریخ دریافت</th>
            <th width="60">عکس</th>
            <th>یادداشت‌ها</th>
            <th width="80">عملیات</th>
        </tr>
    </thead>';
    echo '<tbody>';
    
    if ($requests) {
        foreach ($requests as $request) {
            echo '<tr>';
            echo '<td>' . $request->id . '</td>';
            echo '<td>' . get_the_title($request->product_id) . '</td>';
            echo '<td>' . get_userdata($request->user_id)->display_name . '</td>';
            echo '<td>' . ($request->tamin_user_id ? get_userdata($request->tamin_user_id)->display_name : '-') . '</td>';
            
            // وضعیت با استایل
            echo '<td>';
            $status_class = '';
            $status_text = $request->status;
            switch ($request->status) {
                case 'pending':
                    $status_class = 'background: #fff6d9; color: #856404;';
                    $status_text = 'در انتظار بررسی';
                    break;
                case 'approved':
                    $status_class = 'background: #e5f5e8; color: #155724;';
                    $status_text = 'تایید شده';
                    break;
                case 'rejected':
                    $status_class = 'background: #ffebee; color: #721c24;';
                    $status_text = 'رد شده';
                    break;
            }
            echo '<span style="display: inline-block; padding: 3px 8px; border-radius: 3px; ' . $status_class . '">';
            echo $status_text;
            echo '</span>';
            echo '</td>';
            
            echo '<td>' . $request->receipt_day . ' ' . $request->receipt_month . ' ' . $request->receipt_year . '</td>';
            
            // نمایش آیکون عکس
            echo '<td style="text-align: center;">';
            if ($request->image_id) {
                echo '<span class="dashicons dashicons-yes-alt" style="color: #46b450;" title="دارای تصویر"></span>';
            } else {
                echo '<span class="dashicons dashicons-no-alt" style="color: #dc3232;" title="بدون تصویر"></span>';
            }
            echo '</td>';
            
            // نمایش دو یادداشت آخر
            echo '<td>';
            $notes = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}asg_guarantee_notes 
                WHERE request_id = %d 
                ORDER BY created_at DESC 
                LIMIT 2",
                $request->id
            ));
            if ($notes) {
                foreach ($notes as $note) {
                    echo '<div style="margin-bottom: 5px;">';
                    echo '<small style="color: #666; font-size: 11px;">' . 
                         date_i18n('Y/m/d H:i', strtotime($note->created_at)) . '</small>';
                    echo '<p style="margin: 2px 0; font-size: 12px;">' . 
                         wp_trim_words(esc_html($note->note), 10, '...') . '</p>';
                    echo '</div>';
                }
            } else {
                echo '-';
            }
            echo '</td>';
            
            // دکمه ویرایش
            echo '<td>';
            echo '<a href="' . admin_url('admin.php?page=warranty-management-edit&id=' . $request->id) . '" class="button button-small">ویرایش</a>';
            echo '</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="9">موردی یافت نشد.</td></tr>';
    }
    
    echo '</tbody>';
    echo '</table>';

    // Pagination
    if ($total_pages > 1) {
        echo '<div class="tablenav bottom">';
        echo '<div class="tablenav-pages">';
        echo paginate_links(array(
            'base' => add_query_arg('paged', '%#%'),
            'format' => '',
            'prev_text' => __('&laquo;'),
            'next_text' => __('&raquo;'),
            'total' => $total_pages,
            'current' => $current_page,
            'add_args' => array_filter([
                'filter_id' => $filter_id,
                'filter_product' => $filter_product,
                'filter_user' => $filter_user,
                'filter_tamin' => $filter_tamin,
                'filter_status' => $filter_status,
                'filter_receipt_year' => $filter_receipt_year,
                'filter_receipt_month' => $filter_receipt_month,
            ])
        ));
        echo '</div>';
        echo '</div>';
    }

    echo '</div>'; // end wrap
}

// انتقال اسکریپت‌ها و استایل‌ها
function asg_enqueue_scripts() {
    // بارگذاری کتابخانه رسانه وردپرس
    wp_enqueue_media();

    // بارگذاری اسکریپت سفارشی
    wp_enqueue_script(
        'asg-script',
        plugins_url('asg-script.js', __FILE__),
        array('jquery'),
        filemtime(plugin_dir_path(__FILE__) . 'asg-script.js'),
        true
    );

    // بارگذاری Select2 CSS از CDN
    wp_enqueue_style(
        'select2',
        'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css',
        array(),
        '4.0.13'
    );

    // بارگذاری Select2 JS از CDN
    wp_enqueue_script(
        'select2',
        'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js',
        array('jquery'),
        '4.0.13',
        true
    );

    // بارگذاری فایل CSS سفارشی
    wp_enqueue_style(
        'asg-custom-style',
        plugins_url('asg-custom-style.css', __FILE__),
        array(),
        filemtime(plugin_dir_path(__FILE__) . 'asg-custom-style.css')
    );

    // افزودن داده‌های لازم برای اسکریپت (اختیاری)
    wp_localize_script('asg-script', 'asg_params', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('asg-nonce')
    ));
}
add_action('admin_enqueue_scripts', 'asg_enqueue_scripts');

// اکشن Ajax برای جستجوی محصولات
add_action('wp_ajax_asg_search_products', 'asg_search_products');
function asg_search_products() {
    $search = sanitize_text_field($_GET['search']);
    $products = wc_get_products(array(
        'status' => 'publish',
        'limit' => -1,
        's' => $search
    ));

    $results = array();
    foreach ($products as $product) {
        $results[] = array(
            'id' => $product->get_id(),
            'text' => $product->get_name()
        );
    }

    wp_send_json($results);
}

// اکشن Ajax برای جستجوی کاربران
add_action('wp_ajax_asg_search_users', 'asg_search_users');
function asg_search_users() {
    $search = sanitize_text_field($_GET['search']);
    $users = get_users(array(
        'search' => '*' . $search . '*'
    ));

    $results = array();
    foreach ($users as $user) {
        $results[] = array(
            'id' => $user->ID,
            'text' => $user->display_name
        );
    }

    wp_send_json($results);
}
// صفحه ثبت گارانتی دسته‌ای
function asg_bulk_guarantee_page() {
    global $wpdb;

    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_bulk_guarantee'])) {
        $user_id = intval($_POST['user_id']);
        $receipt_day = intval($_POST['receipt_day']);
        $receipt_month = sanitize_text_field($_POST['receipt_month']);
        $receipt_year = intval($_POST['receipt_year']);

        // ثبت هر گارانتی به صورت جداگانه
        for ($i = 1; $i <= 10; $i++) {
            if (!empty($_POST['product_id_' . $i])) {
                $product_id = intval($_POST['product_id_' . $i]);
                $tamin_user_id = intval($_POST['tamin_user_id_' . $i]);
                $defect_description = sanitize_textarea_field($_POST['defect_description_' . $i]);
                $expert_comment = sanitize_textarea_field($_POST['expert_comment_' . $i]);
                $status = sanitize_text_field($_POST['status_' . $i]);
                $image_id = intval($_POST['image_id_' . $i]);

                $result = $wpdb->insert(
                    $wpdb->prefix . 'asg_guarantee_requests',
                    array(
                        'product_id' => $product_id,
                        'user_id' => $user_id,
                        'tamin_user_id' => $tamin_user_id,
                        'defect_description' => $defect_description,
                        'expert_comment' => $expert_comment,
                        'status' => $status,
                        'receipt_day' => $receipt_day,
                        'receipt_month' => $receipt_month,
                        'receipt_year' => $receipt_year,
                        'image_id' => $image_id
                    )
                );

                if ($result) {
                    echo '<div class="notice notice-success"><p>گارانتی شماره ' . $i . ' با موفقیت ثبت شد.</p></div>';
                } else {
                    echo '<div class="notice notice-error"><p>خطا در ثبت گارانتی شماره ' . $i . '.</p></div>';
                }
            }
        }
    }

    echo '<div class="wrap">';
    echo '<h1>ثبت گارانتی دسته‌ای</h1>';
    echo '<form method="post" action="" class="asg-form-container">';

    // بخش اطلاعات مشتری و تاریخ دریافت
    echo '<div class="asg-card">';
    echo '<div class="asg-card-header">اطلاعات مشتری و تاریخ دریافت</div>';
    echo '<div class="asg-card-body">';
    echo '<div class="asg-form-group"><label for="user_id">مشتری:</label><select name="user_id" id="user_id" required></select></div>';
    echo '<div class="asg-form-group"><label for="receipt_day">روز:</label>';
    echo '<select name="receipt_day" id="receipt_day" required>';
    for ($day = 1; $day <= 31; $day++) {
        echo '<option value="' . $day . '">' . $day . '</option>';
    }
    echo '</select></div>';
    echo '<div class="asg-form-group"><label for="receipt_month">ماه:</label><select name="receipt_month" id="receipt_month" required>';
    $months = array('فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند');
    foreach ($months as $month) {
        echo '<option value="' . $month . '">' . $month . '</option>';
    }
    echo '</select></div>';
    echo '<div class="asg-form-group"><label for="receipt_year">سال:</label>';
    echo '<select name="receipt_year" id="receipt_year" required>';
    for ($year = 1403; $year <= 1410; $year++) {
        $selected = ($year == 1403) ? 'selected' : '';
        echo '<option value="' . $year . '" ' . $selected . '>' . $year . '</option>';
    }
    echo '</select></div>';
    echo '</div></div>';

    // گارانتی‌های پیش‌فرض (۱ و ۲)
    for ($i = 1; $i <= 2; $i++) {
        echo '<div class="asg-card asg-guarantee-card" id="asg-guarantee-' . $i . '">';
        echo '<div class="asg-card-header">';
        echo 'گارانتی شماره ' . $i;
        echo '<button type="button" class="asg-remove-guarantee" data-guarantee-id="' . $i . '">حذف</button>';
        echo '</div>';
        echo '<div class="asg-card-body">';
        echo '<div class="asg-form-group"><label for="product_id_' . $i . '">محصول:</label><select name="product_id_' . $i . '" id="product_id_' . $i . '"></select></div>';
        echo '<div class="asg-form-group"><label for="tamin_user_id_' . $i . '">تامین‌کننده (tamin):</label><select name="tamin_user_id_' . $i . '" id="tamin_user_id_' . $i . '">';
        $tamin_users = get_users(array('role' => 'tamin'));
        foreach ($tamin_users as $user) {
            echo '<option value="' . $user->ID . '">' . $user->display_name . '</option>';
        }
        echo '</select></div>';
        echo '<div class="asg-form-group"><label for="defect_description_' . $i . '">نقص کالا:</label><textarea name="defect_description_' . $i . '" id="defect_description_' . $i . '" rows="5" cols="40" required></textarea></div>';
        echo '<div class="asg-form-group"><label for="expert_comment_' . $i . '">نظر کارشناس:</label><textarea name="expert_comment_' . $i . '" id="expert_comment_' . $i . '" rows="5" cols="40"></textarea></div>';
        echo '<div class="asg-form-group"><label for="status_' . $i . '">وضعیت:</label><select name="status_' . $i . '" id="status_' . $i . '" required>';
        $statuses = get_option('asg_statuses', array('آماده ارسال', 'ارسال شده', 'تعویض شده', 'خارج از گارانتی'));
        foreach ($statuses as $status) {
            echo '<option value="' . $status . '">' . $status . '</option>';
        }
        echo '</select></div>';
        echo '<div class="asg-form-group"><label for="image_url_' . $i . '">عکس:</label><input type="text" name="image_id_' . $i . '" id="image_url_' . $i . '" class="regular-text"><input type="button" value="آپلود عکس" class="button" id="upload_image_' . $i . '"></div>';
        echo '</div></div>';
    }

    // دکمه + برای افزودن گارانتی‌های بیشتر
    echo '<button type="button" id="asg-add-guarantee" class="asg-button">+ افزودن گارانتی</button>';

    // دکمه ثبت
    echo '<input type="submit" name="submit_bulk_guarantee" value="ثبت گارانتی‌ها" class="asg-button">';
    echo '</form>';
    echo '</div>'; // پایان wrap
}
add_filter('woocommerce_account_menu_items', 'asg_add_guarantee_tracking_menu');
function asg_add_guarantee_tracking_menu($items) {
    $items['guarantee-tracking'] = 'پیگیری گارانتی';
    return $items;
}
add_action('woocommerce_account_guarantee-tracking_endpoint', 'asg_guarantee_tracking_page');
function asg_guarantee_tracking_page() {
    global $wpdb;
    $user_id = get_current_user_id();
    $table_name = $wpdb->prefix . 'asg_guarantee_requests';

    // دریافت لیست گارانتی‌های کاربر
    $requests = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table_name WHERE user_id = %d", $user_id
    ));

    if ($requests) {
        echo '<h2>گارانتی‌های من</h2>';
        echo '<table class="woocommerce-orders-table woocommerce-MyAccount-orders shop_table shop_table_responsive my_account_orders account-orders-table">';
        echo '<thead><tr><th>محصول</th><th>وضعیت</th><th>تاریخ دریافت</th><th>عملیات</th></tr></thead>';
        echo '<tbody>';
        foreach ($requests as $request) {
            $product_name = get_the_title($request->product_id);
            $status = $request->status;
            $receipt_date = $request->receipt_day . ' ' . $request->receipt_month . ' ' . $request->receipt_year;

            echo '<tr>';
            echo '<td>' . esc_html($product_name) . '</td>';
            echo '<td>' . esc_html($status) . '</td>';
            echo '<td>' . esc_html($receipt_date) . '</td>';
            echo '<td><a href="' . esc_url(add_query_arg('request_id', $request->id, wc_get_account_endpoint_url('add-guarantee-note'))) . '" class="button">افزودن یادداشت</a></td>';
            echo '</tr>';
        }
        echo '</tbody>';
        echo '</table>';
    } else {
        echo '<p>هیچ گارانتی‌ای برای نمایش وجود ندارد.</p>';
    }
}
add_action('woocommerce_account_add-guarantee-note_endpoint', 'asg_add_guarantee_note_page');
add_action('woocommerce_account_add-guarantee-note_endpoint', 'asg_add_guarantee_note_page');
function asg_add_guarantee_note_page() {
    global $wpdb;
    $user_id = get_current_user_id();
    $request_id = isset($_GET['request_id']) ? intval($_GET['request_id']) : 0;

    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_note'])) {
        $note = sanitize_textarea_field($_POST['note']);
        $note = '**' . $note . '**'; // افزودن دو ستاره به ابتدا و انتهای یادداشت
        $wpdb->insert(
            $wpdb->prefix . 'asg_guarantee_notes',
            array(
                'request_id' => $request_id,
                'note' => $note,
                'created_at' => current_time('mysql')
            )
        );

        echo '<div class="woocommerce-message">یادداشت با موفقیت افزوده شد.</div>';
    }

    echo '<h2>افزودن یادداشت جدید</h2>';
    echo '<form method="post" action="">';
    echo '<div class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">';
    echo '<label for="note">یادداشت:</label>';
    echo '<textarea name="note" id="note" rows="5" cols="40" required></textarea>';
    echo '</div>';
    echo '<input type="submit" name="submit_note" value="افزودن یادداشت" class="button">';
    echo '</form>';
}
add_action('init', 'asg_add_custom_endpoints');
function asg_add_custom_endpoints() {
    add_rewrite_endpoint('guarantee-tracking', EP_ROOT | EP_PAGES);
    add_rewrite_endpoint('add-guarantee-note', EP_ROOT | EP_PAGES);
}
// در تابع asg_admin_menu زیرمنوهای موجود، این زیرمنو را اضافه کنید

function asg_reports_page() {
    global $wpdb;

    // استایل‌های درون صفحه
    echo '<style>
        .asg-reports-container {
            margin: 20px 0;
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }
        .asg-report-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .asg-report-card.full-width {
            grid-column: 1 / -1;
        }
        .asg-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }
        .asg-stat-item {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 4px;
            text-align: center;
        }
        .stat-value {
            font-size: 24px;
            font-weight: bold;
            color: #007cba;
        }
    </style>';

    echo '<div class="wrap">';
    echo '<h1>گزارش‌های سیستم گارانتی</h1>';

    // آمار کلی
    $stats = $wpdb->get_row("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'آماده ارسال' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'ارسال شده' THEN 1 ELSE 0 END) as sent,
            SUM(CASE WHEN status = 'تعویض شده' THEN 1 ELSE 0 END) as replaced,
            SUM(CASE WHEN status = 'خارج از گارانتی' THEN 1 ELSE 0 END) as expired
        FROM {$wpdb->prefix}asg_guarantee_requests
    ");

    echo '<div class="asg-reports-container">';
    
    // کارت آمار کلی
    echo '<div class="asg-report-card">';
    echo '<h2>آمار کلی</h2>';
    echo '<div class="asg-stats-grid">';
    echo '<div class="asg-stat-item"><div class="stat-label">کل درخواست‌ها</div><div class="stat-value">' . number_format_i18n($stats->total) . '</div></div>';
    echo '<div class="asg-stat-item"><div class="stat-label">آماده ارسال</div><div class="stat-value">' . number_format_i18n($stats->pending) . '</div></div>';
    echo '<div class="asg-stat-item"><div class="stat-label">ارسال شده</div><div class="stat-value">' . number_format_i18n($stats->sent) . '</div></div>';
    echo '<div class="asg-stat-item"><div class="stat-label">تعویض شده</div><div class="stat-value">' . number_format_i18n($stats->replaced) . '</div></div>';
    echo '</div>';
    echo '</div>';

    // کارت نمودار وضعیت‌ها
    echo '<div class="asg-report-card">';
    echo '<h2>نمودار وضعیت‌ها</h2>';
    echo '<canvas id="asgStatusChart"></canvas>';
    echo '</div>';

    // کارت نمودار ماهانه (تمام عرض)
    echo '<div class="asg-report-card full-width">';
    echo '<h2>نمودار ماهانه درخواست‌ها</h2>';
    echo '<canvas id="asgMonthlyChart"></canvas>';
    echo '</div>';

    echo '</div>'; // پایان asg-reports-container

    // اضافه کردن اسکریپت Chart.js
    echo '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>';
    
    // اسکریپت مخصوص نمودارها
    echo '<script>
    jQuery(document).ready(function($) {
        // دریافت داده‌ها از سرور
        $.post(ajaxurl, {
            action: "asg_get_stats",
            nonce: "' . wp_create_nonce('asg-reports-nonce') . '"
        }, function(response) {
            if (response.success) {
                const data = response.data;

                // نمودار وضعیت‌ها
                new Chart($("#asgStatusChart"), {
                    type: "pie",
                    data: {
                        labels: data.status_labels,
                        datasets: [{
                            data: data.status_counts,
                            backgroundColor: [
                                "#FF6384",
                                "#36A2EB",
                                "#FFCE56",
                                "#4BC0C0"
                            ]
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: {
                                position: "right"
                            }
                        }
                    }
                });

                // نمودار ماهانه
                new Chart($("#asgMonthlyChart"), {
                    type: "line",
                    data: {
                        labels: data.monthly_labels,
                        datasets: [{
                            label: "تعداد درخواست‌ها",
                            data: data.monthly_counts,
                            borderColor: "#36A2EB",
                            tension: 0.1,
                            fill: false
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: {
                                position: "top"
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    stepSize: 1
                                }
                            }
                        }
                    }
                });
            }
        });
    });
    </script>';

    echo '</div>'; // پایان wrap
}
// اکشن Ajax برای دریافت آمار
// اکشن Ajax برای دریافت آمار
add_action('wp_ajax_asg_get_stats', 'asg_get_stats');
function asg_get_stats() {
    check_ajax_referer('asg-reports-nonce', 'nonce');

    global $wpdb;

    // آمار وضعیت‌ها
    $status_stats = $wpdb->get_results($wpdb->prepare("
        SELECT status, COUNT(*) as count 
        FROM {$wpdb->prefix}asg_guarantee_requests 
        GROUP BY status
    "));

    $status_labels = array();
    $status_counts = array();
    foreach ($status_stats as $stat) {
        $status_labels[] = $stat->status ?: 'نامشخص';
        $status_counts[] = (int)$stat->count;
    }

    // آمار ماهانه
    $monthly_stats = $wpdb->get_results($wpdb->prepare("
        SELECT 
            DATE_FORMAT(created_at, '%Y-%m') as month,
            COUNT(*) as count
        FROM {$wpdb->prefix}asg_guarantee_requests 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
        GROUP BY month 
        ORDER BY month DESC
        LIMIT 12
    "));

    $monthly_labels = array();
    $monthly_counts = array();
    foreach ($monthly_stats as $stat) {
        // تبدیل تاریخ میلادی به شمسی
        $date = explode('-', $stat->month);
        $jdate = gregorian_to_jalali($date[0], $date[1], '01');
        $monthly_labels[] = $jdate[0] . '/' . str_pad($jdate[1], 2, '0', STR_PAD_LEFT);
        $monthly_counts[] = (int)$stat->count;
    }

    wp_send_json_success(array(
        'status_labels' => $status_labels,
        'status_counts' => $status_counts,
        'monthly_labels' => array_reverse($monthly_labels),
        'monthly_counts' => array_reverse($monthly_counts)
    ));
}

// تابع کمکی برای تبدیل تاریخ میلادی به شمسی
function gregorian_to_jalali($gy, $gm, $gd) {
    $g_d_m = array(0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334);
    $jy = ($gy <= 1600) ? 0 : 979;
    $gy -= ($gy <= 1600) ? 621 : 1600;
    $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
    $days = (365 * $gy) + floor(($gy2 + 3) / 4) - floor(($gy2 + 99) / 100) + 
            floor(($gy2 + 399) / 400) - 80 + $gd + $g_d_m[$gm - 1];
    $jy += 33 * floor($days / 12053);
    $days %= 12053;
    $jy += 4 * floor($days / 1461);
    $days %= 1461;
    $jy += floor(($days - 1) / 365);
    if ($days > 365) $days = ($days - 1) % 365;
    $jm = ($days < 186) ? 1 + floor($days / 31) : 7 + floor(($days - 186) / 30);
    $jd = 1 + (($days < 186) ? ($days % 31) : (($days - 186) % 30));
    return array($jy, $jm, $jd);
}

// افزودن استایل‌های ادمین
function asg_enqueue_admin_scripts($hook) {
    // لود کردن استایل‌های ادمین در تمام صفحات پلاگین
    if (strpos($hook, 'warranty-management') !== false) {
        wp_enqueue_style(
            'asg-admin-style',
            ASG_PLUGIN_URL . 'assets/css/asg-admin.css',
            array(),
            ASG_VERSION
        );
    }
}
add_action('admin_enqueue_scripts', 'asg_enqueue_admin_scripts');
function asg_reports_main_page() {
    global $wpdb;

    // دریافت پارامترهای فیلتر
    $filter_tamin = isset($_GET['filter_tamin']) ? intval($_GET['filter_tamin']) : '';
    $filter_customer = isset($_GET['filter_customer']) ? intval($_GET['filter_customer']) : '';
    $filter_status = isset($_GET['filter_status']) ? sanitize_text_field($_GET['filter_status']) : '';
    $filter_product = isset($_GET['filter_product']) ? intval($_GET['filter_product']) : '';
    $selected_columns = isset($_GET['columns']) ? (array)$_GET['columns'] : array('id', 'product_name', 'customer_name', 'status', 'receipt_date');
    $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
    $date_to = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '';

    // تعریف ستون‌های موجود
    $available_columns = array(
        'id' => 'ID درخواست',
        'product_name' => 'نام محصول',
        'customer_name' => 'نام مشتری',
        'tamin_name' => 'تامین کننده',
        'status' => 'وضعیت',
        'receipt_date' => 'تاریخ دریافت',
        'created_at' => 'تاریخ ایجاد',
        'defect_description' => 'توضیحات نقص',
        'expert_comment' => 'نظر کارشناسی',
        'image' => 'عکس',
        'notes_count' => 'تعداد یادداشت‌ها'
    );

    // اگر درخواست خروجی اکسل است
    if (isset($_GET['export']) && $_GET['export'] === 'excel') {
        ob_clean();
        ob_start();
        
        // تنظیم هدرهای لازم برای دانلود فایل اکسل
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment;filename="warranty-report-' . date('Y-m-d') . '.xls"');
        header('Cache-Control: max-age=0');
        header('Cache-Control: max-age=1');
        
        // خروجی به صورت UTF-8 با BOM برای پشتیبانی از فارسی
        echo chr(0xEF) . chr(0xBB) . chr(0xBF);

        // ساخت SELECT برای کوئری
        $select = array();
        foreach ($selected_columns as $col) {
            switch ($col) {
                case 'product_name':
                    $select[] = 'p.post_title as product_name';
                    break;
                case 'customer_name':
                    $select[] = 'cu.display_name as customer_name';
                    break;
                case 'tamin_name':
                    $select[] = 'tu.display_name as tamin_name';
                    break;
                case 'receipt_date':
                    $select[] = "CONCAT(r.receipt_day, '/', r.receipt_month, '/', r.receipt_year) as receipt_date";
                    break;
                case 'notes_count':
                    $select[] = '(SELECT COUNT(*) FROM ' . $wpdb->prefix . 'asg_guarantee_notes n WHERE n.request_id = r.id) as notes_count';
                    break;
                default:
                    $select[] = 'r.' . $col;
                    break;
            }
        }

        // ساخت شروط WHERE
        $where = array();
        $params = array();

        if (!empty($filter_tamin)) {
            $where[] = 'r.tamin_user_id = %d';
            $params[] = $filter_tamin;
        }
        if (!empty($filter_customer)) {
            $where[] = 'r.user_id = %d';
            $params[] = $filter_customer;
        }
        if (!empty($filter_status)) {
            $where[] = 'r.status = %s';
            $params[] = $filter_status;
        }
        if (!empty($filter_product)) {
            $where[] = 'r.product_id = %d';
            $params[] = $filter_product;
        }
        if (!empty($date_from) && !empty($date_to)) {
            $where[] = 'r.created_at BETWEEN %s AND %s';
            $params[] = $date_from . ' 00:00:00';
            $params[] = $date_to . ' 23:59:59';
        }

        $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // ساخت و اجرای کوئری نهایی
        $sql = "
            SELECT DISTINCT " . implode(', ', $select) . "
            FROM {$wpdb->prefix}asg_guarantee_requests r
            LEFT JOIN {$wpdb->posts} p ON r.product_id = p.ID
            LEFT JOIN {$wpdb->users} cu ON r.user_id = cu.ID
            LEFT JOIN {$wpdb->users} tu ON r.tamin_user_id = tu.ID
            $where_sql
            ORDER BY r.id DESC
        ";

        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, $params);
        }

        $results = $wpdb->get_results($sql);

        // ساخت جدول اکسل
        echo '<table border="1" dir="rtl">';
        
        // هدر جدول
        echo '<tr>';
        foreach ($selected_columns as $col) {
            if (isset($available_columns[$col])) {
                echo '<th style="background-color: #f0f0f0; font-weight: bold; text-align: center; padding: 5px;">' . 
                     $available_columns[$col] . '</th>';
            }
        }
        echo '</tr>';

        // داده‌های جدول
        if (!empty($results)) {
            foreach ($results as $row) {
                echo '<tr>';
                foreach ($selected_columns as $col) {
                    echo '<td style="text-align: right; padding: 5px;">';
                    switch ($col) {
                        case 'notes_count':
                            echo isset($row->notes_count) ? $row->notes_count : '0';
                            break;
                        case 'image':
                            echo !empty($row->image_id) ? 'دارد' : 'ندارد';
                            break;
                        case 'created_at':
                            echo isset($row->created_at) ? date('Y/m/d H:i', strtotime($row->created_at)) : '-';
                            break;
                        default:
                            echo isset($row->$col) ? $row->$col : '-';
                    }
                    echo '</td>';
                }
                echo '</tr>';
            }
        }
        
        echo '</table>';
        
        ob_end_flush();
        exit;
    }

    // کد برای نمایش صفحه اصلی
    // ساخت SELECT برای نمایش در صفحه
    $select = array();
    foreach ($selected_columns as $col) {
        switch ($col) {
            case 'product_name':
                $select[] = 'p.post_title as product_name';
                break;
            case 'customer_name':
                $select[] = 'cu.display_name as customer_name';
                break;
            case 'tamin_name':
                $select[] = 'tu.display_name as tamin_name';
                break;
            case 'receipt_date':
                $select[] = "CONCAT(r.receipt_day, '/', r.receipt_month, '/', r.receipt_year) as receipt_date";
                break;
            case 'image':
                $select[] = 'r.image_id';
                break;
            case 'notes_count':
                $select[] = '(SELECT COUNT(*) FROM ' . $wpdb->prefix . 'asg_guarantee_notes n WHERE n.request_id = r.id) as notes_count';
                break;
            default:
                $select[] = 'r.' . $col;
                break;
        }
    }

    // ساخت شروط WHERE برای نمایش در صفحه
    $where = array();
    $params = array();

    if (!empty($filter_tamin)) {
        $where[] = 'r.tamin_user_id = %d';
        $params[] = $filter_tamin;
    }
    if (!empty($filter_customer)) {
        $where[] = 'r.user_id = %d';
        $params[] = $filter_customer;
    }
    if (!empty($filter_status)) {
        $where[] = 'r.status = %s';
        $params[] = $filter_status;
    }
    if (!empty($filter_product)) {
        $where[] = 'r.product_id = %d';
        $params[] = $filter_product;
    }
    if (!empty($date_from) && !empty($date_to)) {
        $where[] = 'r.created_at BETWEEN %s AND %s';
        $params[] = $date_from . ' 00:00:00';
        $params[] = $date_to . ' 23:59:59';
    }

    $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    // ساخت و اجرای کوئری نهایی برای نمایش در صفحه
    $sql = "
        SELECT DISTINCT " . implode(', ', $select) . "
        FROM {$wpdb->prefix}asg_guarantee_requests r
        LEFT JOIN {$wpdb->posts} p ON r.product_id = p.ID
        LEFT JOIN {$wpdb->users} cu ON r.user_id = cu.ID
        LEFT JOIN {$wpdb->users} tu ON r.tamin_user_id = tu.ID
        $where_sql
        ORDER BY r.id DESC
        LIMIT 500
    ";

    if (!empty($params)) {
        $sql = $wpdb->prepare($sql, $params);
    }

    $results = $wpdb->get_results($sql);

    // شروع نمایش صفحه
    ?>
    <div class="wrap">
        <h1>گزارشات پیشرفته گارانتی</h1>
        
        <form method="get" action="" class="asg-report-filters">
            <input type="hidden" name="page" value="warranty-management-reports">
            
            <div class="asg-filter-row">
                <div class="asg-filter">
                    <label>مشتری:</label>
                    <select name="filter_customer" id="filter_customer" class="asg-select2-customer">
                        <?php if ($filter_customer): ?>
                            <?php $user = get_user_by('id', $filter_customer); ?>
                            <?php if ($user): ?>
                                <option value="<?php echo esc_attr($filter_customer); ?>" selected>
                                    <?php echo esc_html($user->display_name); ?>
                                </option>
                            <?php endif; ?>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="asg-filter">
                    <label>تامین کننده:</label>
                    <select name="filter_tamin" id="filter_tamin" class="asg-select2-tamin">
                        <?php if ($filter_tamin): ?>
                            <?php $user = get_user_by('id', $filter_tamin); ?>
                            <?php if ($user): ?>
                                <option value="<?php echo esc_attr($filter_tamin); ?>" selected>
                                    <?php echo esc_html($user->display_name); ?>
                                </option>
                            <?php endif; ?>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="asg-filter">
                    <label>وضعیت:</label>
                    <select name="filter_status">
                        <option value="">همه وضعیت‌ها</option>
                        <?php 
                        $statuses = get_option('asg_statuses', array('آماده ارسال', 'ارسال شده', 'تعویض شده', 'خارج از گارانتی'));
                        foreach ($statuses as $status): 
                            $selected = ($status == $filter_status) ? 'selected' : '';
                        ?>
                            <option value="<?php echo esc_attr($status); ?>" <?php echo $selected; ?>>
                                <?php echo esc_html($status); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="asg-filter">
                    <label>محصول:</label>
                    <select name="filter_product" id="filter_product" class="asg-select2-product">
                        <?php if ($filter_product): ?>
                            <?php $product = wc_get_product($filter_product); ?>
                            <?php if ($product): ?>
                                <option value="<?php echo esc_attr($filter_product); ?>" selected>
                                    <?php echo esc_html($product->get_name()); ?>
                                </option>
                            <?php endif; ?>
                        <?php endif; ?>
                    </select>
                </div>
            </div>

            <div class="asg-filter-row">
                <div class="asg-filter">
                    <label>تاریخ ایجاد:</label>
                    <input type="date" name="date_from" value="<?php echo esc_attr($date_from); ?>" placeholder="از تاریخ">
                    <input type="date" name="date_to" value="<?php echo esc_attr($date_to); ?>" placeholder="تا تاریخ">
                </div>
            </div>

            <div class="asg-column-selector">
                <h3>انتخاب ستون‌های گزارش:</h3>
                <?php foreach ($available_columns as $key => $label): ?>
                    <label>
                        <input type="checkbox" name="columns[]" value="<?php echo esc_attr($key); ?>" 
                               <?php checked(in_array($key, $selected_columns)); ?>>
                        <?php echo esc_html($label); ?>
                    </label>
                <?php endforeach; ?>
            </div>

            <div class="asg-export-buttons">
                <button type="submit" class="button button-primary">اعمال فیلترها</button>
                <a href="<?php echo esc_url(add_query_arg('export', 'excel', $_SERVER['REQUEST_URI'])); ?>" 
                   class="button button-secondary">
                    <span class="dashicons dashicons-media-spreadsheet"></span> خروجی اکسل
                </a>
                <button type="button" class="button button-secondary print-preview">
                    <span class="dashicons dashicons-printer"></span> پیش‌نمایش چاپ
                </button>
                <a href="<?php echo admin_url('admin.php?page=warranty-management-reports'); ?>" class="button">
                    پاکسازی فیلترها
                </a>
            </div>
        </form>

        <?php if (!empty($results)): ?>
            <div class="asg-report-results">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <?php foreach ($selected_columns as $col): ?>
                                <th><?php echo esc_html($available_columns[$col]); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results as $row): ?>
                            <tr>
                                <?php foreach ($selected_columns as $col): ?>
                                    <td>
                                        <?php
                                        switch ($col) {
                                            case 'image':
                                                if (!empty($row->image_id)) {
                                                    $image_url = wp_get_attachment_url($row->image_id);
                                                    if ($image_url) {
                                                        echo '<a href="' . esc_url($image_url) . '" target="_blank">';
                                                        echo '<img src="' . esc_url($image_url) . '" style="max-width: 100px; height: auto;">';
                                                        echo '</a>';
                                                    }
                                                } else {
                                                    echo '-';
                                                }
                                                break;
                                            case 'notes_count':
                                                echo '<a href="' . admin_url('admin.php?page=warranty-management-edit&id=' . $row->id) . '">';
                                                echo esc_html($row->notes_count);
                                                echo '</a>';
                                                break;
                                            default:
                                                $value = isset($row->$col) ? $row->$col : '-';
                                                echo esc_html($value);
                                                break;
                                        }
                                        ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="asg-no-results">هیچ موردی با فیلترهای انتخاب شده یافت نشد.</p>
        <?php endif; ?>
    </div>

    <style>
        .asg-filter-row {
            margin: 15px 0;
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }
        .asg-filter {
            flex: 1;
            min-width: 200px;
        }
        .asg-filter label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        .asg-column-selector {
            margin: 20px 0;
            padding: 15px;
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .asg-column-selector label {
            margin-right: 15px;
            display: inline-block;
            margin-bottom: 8px;
        }
        .asg-export-buttons {
            margin: 20px 0;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .asg-export-buttons .button {
            display: inline-flex;
            align-items: center;
        }
        .asg-export-buttons .dashicons {
            margin-right: 5px;
        }
        @media print {
            .asg-filter-row,
            .asg-column-selector,
            .asg-export-buttons,
            #adminmenumain,
            #wpadminbar,
            #wpfooter {
                display: none !important;
            }
            .asg-report-results {
                margin: 0;
                padding: 0;
            }
            table {
                border-collapse: collapse;
                width: 100%;
            }
            th, td {
                border: 1px solid #000;
                padding: 8px;
                text-align: right;
            }
        }
    </style>

    <script>
    jQuery(document).ready(function($) {
        // Select2 برای مشتری
        $('.asg-select2-customer').select2({
            ajax: {
                url: ajaxurl,
                dataType: 'json',
                delay: 250,
                data: function(params) {
                    return {
                        action: 'asg_search_users',
                        search: params.term,
                        role: 'customer'
                    };
                },
                processResults: function(data) {
                    return { results: data };
                }
            },
            minimumInputLength: 2,
            placeholder: 'جستجوی مشتری...',
            width: '100%'
        });

        // Select2 برای تامین کننده
        $('.asg-select2-tamin').select2({
            ajax: {
                url: ajaxurl,
                dataType: 'json',
                delay: 250,
                data: function(params) {
                    return {
                        action: 'asg_search_users',
                        search: params.term,
                        role: 'tamin'
                    };
                },
                processResults: function(data) {
                    return { results: data };
                }
            },
            minimumInputLength: 2,
            placeholder: 'جستجوی تامین کننده...',
            width: '100%'
        });

        // Select2 برای محصولات
        $('.asg-select2-product').select2({
            ajax: {
                url: ajaxurl,
                dataType: 'json',
                delay: 250,
                data: function(params) {
                    return {
                        action: 'asg_search_products',
                        search: params.term
                    };
                },
                processResults: function(data) {
                    return { results: data };
                }
            },
            minimumInputLength: 2,
            placeholder: 'جستجوی محصول...',
            width: '100%'
        });

        // اضافه کردن عملکرد پرینت
        $('.print-preview').click(function(e) {
            e.preventDefault();
            window.print();
        });

        // نمایش لودینگ هنگام دانلود اکسل
        $('a[href*="export=excel"]').click(function() {
            $(this).addClass('updating-message').text('در حال آماده‌سازی فایل...');
        });
    });
    </script>
    <?php
}
function asg_charts_page() {
    global $wpdb;

    // دریافت وضعیت‌ها از تنظیمات
    $statuses = get_option('asg_statuses', array('آماده ارسال', 'ارسال شده', 'تعویض شده', 'خارج از گارانتی'));
    $status_counts = array_fill_keys($statuses, 0);

    // دریافت تعداد درخواست‌ها برای هر وضعیت
    $status_data = $wpdb->get_results("
        SELECT status, COUNT(*) as count
        FROM {$wpdb->prefix}asg_guarantee_requests
        GROUP BY status
    ");

    // پر کردن آرایه تعداد با داده‌های دریافتی
    foreach ($status_data as $data) {
        if (isset($status_counts[$data->status])) {
            $status_counts[$data->status] = $data->count;
        }
    }

    // دریافت داده‌های نمودار ماهانه
    $monthly_data = $wpdb->get_results("
        SELECT 
            DATE_FORMAT(created_at, '%Y-%m') as month,
            COUNT(*) as count
        FROM {$wpdb->prefix}asg_guarantee_requests
        GROUP BY month
        ORDER BY month DESC
        LIMIT 12
    ");

    // استایل‌های درون صفحه
    echo '<style>
        .asg-reports-container {
            margin: 20px 0;
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }
        .asg-report-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .asg-report-card.full-width {
            grid-column: 1 / -1;
        }
    </style>';

    echo '<div class="wrap">';
    echo '<h1>نمودارهای گارانتی</h1>';

    echo '<div class="asg-reports-container">';
    
    // کارت نمودار وضعیت‌ها
    echo '<div class="asg-report-card">';
    echo '<h2>نمودار وضعیت‌ها</h2>';
    echo '<canvas id="statusChart"></canvas>';
    echo '</div>';

    // کارت نمودار ماهانه
    echo '<div class="asg-report-card">';
    echo '<h2>نمودار ماهانه درخواست‌ها</h2>';
    echo '<canvas id="monthlyChart"></canvas>';
    echo '</div>';

    echo '</div>'; // پایان container

    // اضافه کردن Chart.js
    wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js', array(), null, true);
    
    // اسکریپت نمودارها
    add_action('admin_footer', function() use ($statuses, $status_counts, $monthly_data) {
        ?>
        <script>
        jQuery(document).ready(function($) {
            // نمودار وضعیت‌ها
            new Chart(document.getElementById('statusChart'), {
                type: 'pie',
                data: {
                    labels: <?php echo json_encode($statuses); ?>,
                    datasets: [{
                        data: <?php echo json_encode(array_values($status_counts)); ?>,
                        backgroundColor: ['#ffc107', '#17a2b8', '#28a745', '#dc3545', '#6c757d', '#007bff']
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });

            // نمودار ماهانه
            new Chart(document.getElementById('monthlyChart'), {
                type: 'line',
                data: {
                    labels: [<?php 
                        $labels = array();
                        foreach(array_reverse($monthly_data) as $data) {
                            $labels[] = "'" . $data->month . "'";
                        }
                        echo implode(',', $labels);
                    ?>],
                    datasets: [{
                        label: 'تعداد درخواست‌ها',
                        data: [<?php 
                            $counts = array();
                            foreach(array_reverse($monthly_data) as $data) {
                                $counts[] = $data->count;
                            }
                            echo implode(',', $counts);
                        ?>],
                        borderColor: '#007bff',
                        fill: false
                    }]
                },
                options: {
                    responsive: true,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1
                            }
                        }
                    }
                }
            });
        });
        </script>
        <?php
    });

    echo '</div>'; // پایان wrap
}
function asg_add_help_page() {
    add_menu_page(
        'راهنمای استفاده',
        'راهنما',
        'manage_options',
        'asg-help',
        'asg_help_page_callback',
        'dashicons-editor-help',
        100
    );
}
add_action('admin_menu', 'asg_add_help_page');

function asg_help_page_callback() {
    echo '<div class="wrap">';
    echo '<h1>راهنمای استفاده</h1>';
    echo '<p>در این بخش می‌توانید راهنمایی‌های مربوط به استفاده از سیستم را مشاهده کنید.</p>';
    // محتوای راهنما را اینجا اضافه کنید
    echo '</div>';
}
register_activation_hook(__FILE__, function() {
    $db = new ASG_DB();
    $db->create_tables();
});
?>