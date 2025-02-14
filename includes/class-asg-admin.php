<?php
/**
 * کلاس مدیریت بخش ادمین افزونه
 *
 * @package After_Sales_Guarantee
 * @since 1.8
 * @author Arman MJ
 * @last_modified 2025-02-14
 */

if (!defined('ABSPATH')) {
    exit;
}

class ASG_Admin {
    /**
     * نمونه singleton
     *
     * @var ASG_Admin
     */
    private static $instance = null;

    /**
     * نمونه‌های کلاس‌های مورد نیاز
     */
    private $warranty;
    private $utils;
    private $reports;
    private $assets;

    /**
     * دریافت نمونه کلاس
     *
     * @return ASG_Admin
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * سازنده کلاس
     */
    public function __construct() {
        // بارگذاری کلاس‌های مورد نیاز
        $this->warranty = ASG_Warranty::instance();
        $this->utils = ASG_Utils::instance();
        $this->reports = ASG_Reports::instance();
        $this->assets = ASG_Assets_Optimizer::instance();

        // اضافه کردن منوهای مدیریت
        add_action('admin_menu', array($this, 'add_admin_menus'));
        
        // ثبت تنظیمات
        add_action('admin_init', array($this, 'register_settings'));
        
        // اضافه کردن لینک تنظیمات به صفحه افزونه‌ها
        add_filter('plugin_action_links_' . ASG_PLUGIN_BASENAME, array($this, 'add_settings_link'));

        // اضافه کردن اسکریپت‌ها و استایل‌های مدیریت
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // اضافه کردن Ajax handlers
        add_action('wp_ajax_asg_update_warranty', array($this, 'handle_update_warranty'));
        add_action('wp_ajax_asg_generate_report', array($this, 'handle_generate_report'));
        add_action('wp_ajax_asg_clear_cache', array($this, 'handle_clear_cache'));
    }

    /**
     * اضافه کردن منوهای مدیریت
     */
    public function add_admin_menus() {
        // منوی اصلی
        add_menu_page(
            'مدیریت گارانتی',
            'گارانتی',
            'manage_options',
            'asg-warranty',
            array($this, 'render_main_page'),
            'dashicons-shield',
            30
        );

        // زیرمنوها
        add_submenu_page(
            'asg-warranty',
            'لیست گارانتی‌ها',
            'لیست گارانتی‌ها',
            'manage_options',
            'asg-warranty',
            array($this, 'render_main_page')
        );

        add_submenu_page(
            'asg-warranty',
            'افزودن گارانتی جدید',
            'افزودن جدید',
            'manage_options',
            'asg-add-warranty',
            array($this, 'render_add_warranty_page')
        );

        add_submenu_page(
            'asg-warranty',
            'گزارشات',
            'گزارشات',
            'manage_options',
            'asg-reports',
            array($this, 'render_reports_page')
        );

        add_submenu_page(
            'asg-warranty',
            'تنظیمات',
            'تنظیمات',
            'manage_options',
            'asg-settings',
            array($this, 'render_settings_page')
        );
    }

    /**
     * ثبت تنظیمات
     */
    public function register_settings() {
        register_setting('asg_warranty_settings', 'asg_warranty_settings');
        register_setting('asg_report_settings', 'asg_report_settings');
        register_setting('asg_optimization_settings', 'asg_optimization_settings');
    }

    /**
     * اضافه کردن لینک تنظیمات
     *
     * @param array $links
     * @return array
     */
    public function add_settings_link($links) {
        $settings_link = '<a href="' . admin_url('admin.php?page=asg-settings') . '">' . __('تنظیمات', 'warranty') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * افزودن اسکریپت‌ها و استایل‌های مدیریت
     */
    public function enqueue_admin_assets() {
        $screen = get_current_screen();
        
        if (strpos($screen->id, 'asg-') !== false) {
            // استایل‌ها
            wp_enqueue_style(
                'asg-admin-styles',
                ASG_PLUGIN_URL . 'assets/css/admin.css',
                array(),
                ASG_VERSION
            );

            // اسکریپت‌ها
            wp_enqueue_script(
                'asg-admin-scripts',
                ASG_PLUGIN_URL . 'assets/js/admin.js',
                array('jquery'),
                ASG_VERSION,
                true
            );

            wp_localize_script('asg-admin-scripts', 'asgAdmin', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('asg_admin_nonce')
            ));
        }
    }

    /**
     * نمایش صفحه اصلی
     */
    public function render_main_page() {
        // بررسی دسترسی
        if (!current_user_can('manage_options')) {
            wp_die(__('شما اجازه دسترسی به این صفحه را ندارید.'));
        }

        // دریافت پارامترهای فیلتر
        $current_page = isset($_GET['paged']) ? absint($_GET['paged']) : 1;
        $per_page = 20;
        $offset = ($current_page - 1) * $per_page;

        // دریافت لیست گارانتی‌ها
        global $wpdb;
        $warranties = $wpdb->get_results($wpdb->prepare("
            SELECT w.*, p.post_title as product_name, u.display_name as customer_name
            FROM {$wpdb->prefix}asg_warranty_registrations w
            LEFT JOIN {$wpdb->posts} p ON w.product_id = p.ID
            LEFT JOIN {$wpdb->users} u ON w.user_id = u.ID
            ORDER BY w.created_at DESC
            LIMIT %d OFFSET %d
        ", $per_page, $offset));

        // محاسبه تعداد کل
        $total_items = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}asg_warranty_registrations");
        $total_pages = ceil($total_items / $per_page);

        // نمایش قالب
        include ASG_PLUGIN_DIR . 'templates/admin/main-page.php';
    }

    /**
     * نمایش صفحه افزودن گارانتی
     */
    public function render_add_warranty_page() {
        // بررسی دسترسی
        if (!current_user_can('manage_options')) {
            wp_die(__('شما اجازه دسترسی به این صفحه را ندارید.'));
        }

        // پردازش فرم در صورت ارسال
        if (isset($_POST['submit_warranty'])) {
            check_admin_referer('asg_add_warranty');

            $warranty_data = array(
                'product_id' => absint($_POST['product_id']),
                'user_id' => absint($_POST['user_id']),
                'warranty_type' => sanitize_text_field($_POST['warranty_type']),
                'warranty_duration' => absint($_POST['warranty_duration']),
                'serial_number' => sanitize_text_field($_POST['serial_number']),
                'start_date' => current_time('mysql'),
                'status' => 'active'
            );

            $result = $this->warranty->create_warranty($warranty_data);

            if (is_wp_error($result)) {
                $error_message = $result->get_error_message();
            } else {
                $success_message = 'گارانتی با موفقیت ایجاد شد.';
            }
        }

        // نمایش قالب
        include ASG_PLUGIN_DIR . 'templates/admin/add-warranty.php';
    }

    /**
     * نمایش صفحه گزارشات
     */
    public function render_reports_page() {
        // بررسی دسترسی
        if (!current_user_can('manage_options')) {
            wp_die(__('شما اجازه دسترسی به این صفحه را ندارید.'));
        }

        // دریافت آمار کلی
        $stats = array(
            'total_warranties' => $this->get_total_warranties(),
            'active_warranties' => $this->get_active_warranties(),
            'expired_warranties' => $this->get_expired_warranties(),
            'warranty_types' => $this->get_warranty_types_stats()
        );

        // نمایش قالب
        include ASG_PLUGIN_DIR . 'templates/admin/reports.php';
    }

    /**
     * نمایش صفحه تنظیمات
     */
    public function render_settings_page() {
        // بررسی دسترسی
        if (!current_user_can('manage_options')) {
            wp_die(__('شما اجازه دسترسی به این صفحه را ندارید.'));
        }

        // پردازش فرم در صورت ارسال
        if (isset($_POST['submit_settings'])) {
            check_admin_referer('asg_save_settings');

            // به‌روزرسانی تنظیمات
            $warranty_settings = array(
                'default_warranty_duration' => absint($_POST['default_warranty_duration']),
                'warranty_types' => $this->sanitize_warranty_types($_POST['warranty_types']),
                'require_serial_number' => isset($_POST['require_serial_number']),
                'require_invoice_number' => isset($_POST['require_invoice_number']),
                'auto_activate' => isset($_POST['auto_activate']),
                'notification_settings' => array(
                    'enable_email' => isset($_POST['enable_email']),
                    'enable_sms' => isset($_POST['enable_sms']),
                    'expiry_reminder_days' => array_map('absint', $_POST['expiry_reminder_days'])
                )
            );

            $this->warranty->update_warranty_settings($warranty_settings);
            $success_message = 'تنظیمات با موفقیت ذخیره شد.';
        }

        // نمایش قالب
        include ASG_PLUGIN_DIR . 'templates/admin/settings.php';
    }

    /**
     * پردازش به‌روزرسانی گارانتی از طریق Ajax
     */
    public function handle_update_warranty() {
        check_ajax_referer('asg_admin_nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('دسترسی غیرمجاز');
        }

        $warranty_id = absint($_POST['warranty_id']);
        $status = sanitize_text_field($_POST['status']);

        $result = $this->warranty->update_warranty_status($warranty_id, $status);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        } else {
            wp_send_json_success('وضعیت گارانتی با موفقیت به‌روزرسانی شد.');
        }
    }

    /**
     * پردازش تولید گزارش از طریق Ajax
     */
    public function handle_generate_report() {
        check_ajax_referer('asg_admin_nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('دسترسی غیرمجاز');
        }

        $report_type = sanitize_text_field($_POST['report_type']);
        $date_range = array(
            'start' => sanitize_text_field($_POST['start_date']),
            'end' => sanitize_text_field($_POST['end_date'])
        );

        $report = $this->reports->generate_custom_report($report_type, $date_range);

        if (is_wp_error($report)) {
            wp_send_json_error($report->get_error_message());
        } else {
            wp_send_json_success($report);
        }
    }

    /**
     * پردازش پاکسازی کش از طریق Ajax
     */
    public function handle_clear_cache() {
        check_ajax_referer('asg_admin_nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('دسترسی غیرمجاز');
        }

        $this->assets->clear_cache();
        wp_send_json_success('کش با موفقیت پاکسازی شد.');
    }

    /**
     * پاکسازی آرایه انواع گارانتی
     *
     * @param array $warranty_types
     * @return array
     */
    private function sanitize_warranty_types($warranty_types) {
        $clean_types = array();
        
        foreach ($warranty_types as $type => $settings) {
            $clean_types[sanitize_key($type)] = array(
                'title' => sanitize_text_field($settings['title']),
                'duration' => absint($settings['duration']),
                'description' => wp_kses_post($settings['description'])
            );
        }
        
        return $clean_types;
    }

    /**
     * دریافت تعداد کل گارانتی‌ها
     *
     * @return int
     */
    private function get_total_warranties() {
        global $wpdb;
        return $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}asg_warranty_registrations");
    }

    /**
     * دریافت تعداد گارانتی‌های فعال
     *
     * @return int
     */
    private function get_active_warranties() {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) 
            FROM {$wpdb->prefix}asg_warranty_registrations 
            WHERE status = %s AND end_date > %s",
            'active',
            current_time('mysql')
        ));
    }

    /**
     * دریافت تعداد گارانتی‌های منقضی شده
     *
     * @return int
     */
    private function get_expired_warranties() {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) 
            FROM {$wpdb->prefix}asg_warranty_registrations 
            WHERE end_date <= %s",
            current_time('mysql')
        ));
    }

    /**
     * دریافت آمار انواع گارانتی
     *
     * @return array
     */
    private function get_warranty_types_stats() {
        global $wpdb;
        return $wpdb->get_results("
            SELECT warranty_type, COUNT(*) as count 
            FROM {$wpdb->prefix}asg_warranty_registrations 
            GROUP BY warranty_type
        ", OBJECT_K);
    }

    /**
     * نمایش پیام‌های ادمین
     *
     * @param string $message
     * @param string $type
     */
    private function show_admin_notice($message, $type = 'info') {
        ?>
        <div class="notice notice-<?php echo esc_attr($type); ?> is-dismissible">
            <p><?php echo esc_html($message); ?></p>
        </div>
        <?php
    }

    /**
     * دریافت داشبورد وضعیت سیستم
     *
     * @return array
     */
    private function get_system_status() {
        return array(
            'php_version' => PHP_VERSION,
            'wp_version' => get_bloginfo('version'),
            'wc_version' => WC()->version,
            'plugin_version' => ASG_VERSION,
            'memory_limit' => ini_get('memory_limit'),
            'upload_max_size' => ini_get('upload_max_filesize'),
            'max_execution_time' => ini_get('max_execution_time'),
            'database_size' => $this->get_database_size(),
            'cache_status' => $this->assets->is_cache_enabled(),
            'error_log_size' => $this->get_error_log_size()
        );
    }

    /**
     * دریافت حجم دیتابیس
     *
     * @return string
     */
    private function get_database_size() {
        global $wpdb;
        $size = 0;
        $tables = $wpdb->get_results("SHOW TABLE STATUS LIKE '{$wpdb->prefix}asg_%'");
        
        foreach ($tables as $table) {
            $size += $table->Data_length + $table->Index_length;
        }
        
        return size_format($size);
    }

    /**
     * دریافت حجم فایل لاگ خطاها
     *
     * @return string
     */
    private function get_error_log_size() {
        $log_file = WP_CONTENT_DIR . '/asg-error.log';
        return file_exists($log_file) ? size_format(filesize($log_file)) : '0 KB';
    }

    /**
     * اکسپورت داده‌های گارانتی
     *
     * @param string $format
     * @return string|WP_Error
     */
    public function export_warranties($format = 'csv') {
        global $wpdb;

        try {
            $warranties = $wpdb->get_results("
                SELECT w.*, p.post_title as product_name, u.display_name as customer_name
                FROM {$wpdb->prefix}asg_warranty_registrations w
                LEFT JOIN {$wpdb->posts} p ON w.product_id = p.ID
                LEFT JOIN {$wpdb->users} u ON w.user_id = u.ID
                ORDER BY w.created_at DESC
            ");

            if ($format === 'csv') {
                return $this->export_to_csv($warranties);
            } elseif ($format === 'excel') {
                return $this->export_to_excel($warranties);
            } else {
                throw new Exception('فرمت خروجی نامعتبر است');
            }

        } catch (Exception $e) {
            return new WP_Error('export_failed', $e->getMessage());
        }
    }

    /**
     * اکسپورت به CSV
     *
     * @param array $warranties
     * @return string
     */
    private function export_to_csv($warranties) {
        $filename = 'warranty-export-' . date('Y-m-d') . '.csv';
        $file = fopen(WP_CONTENT_DIR . '/exports/' . $filename, 'w');
        
        // هدرها
        fputcsv($file, array(
            'شناسه',
            'محصول',
            'مشتری',
            'نوع گارانتی',
            'شماره سریال',
            'تاریخ شروع',
            'تاریخ پایان',
            'وضعیت'
        ));

        // داده‌ها
        foreach ($warranties as $warranty) {
            fputcsv($file, array(
                $warranty->id,
                $warranty->product_name,
                $warranty->customer_name,
                $warranty->warranty_type,
                $warranty->serial_number,
                $this->utils->gregorian_to_jalali($warranty->start_date),
                $this->utils->gregorian_to_jalali($warranty->end_date),
                $warranty->status
            ));
        }

        fclose($file);
        return WP_CONTENT_URL . '/exports/' . $filename;
    }

    /**
     * اکسپورت به Excel
     *
     * @param array $warranties
     * @return string
     */
    private function export_to_excel($warranties) {
        require_once ASG_PLUGIN_DIR . 'includes/libs/phpspreadsheet/autoload.php';

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // تنظیم هدرها
        $sheet->setCellValue('A1', 'شناسه');
        $sheet->setCellValue('B1', 'محصول');
        $sheet->setCellValue('C1', 'مشتری');
        $sheet->setCellValue('D1', 'نوع گارانتی');
        $sheet->setCellValue('E1', 'شماره سریال');
        $sheet->setCellValue('F1', 'تاریخ شروع');
        $sheet->setCellValue('G1', 'تاریخ پایان');
        $sheet->setCellValue('H1', 'وضعیت');

        // تنظیم داده‌ها
        $row = 2;
        foreach ($warranties as $warranty) {
            $sheet->setCellValue('A' . $row, $warranty->id);
            $sheet->setCellValue('B' . $row, $warranty->product_name);
            $sheet->setCellValue('C' . $row, $warranty->customer_name);
            $sheet->setCellValue('D' . $row, $warranty->warranty_type);
            $sheet->setCellValue('E' . $row, $warranty->serial_number);
            $sheet->setCellValue('F' . $row, $this->utils->gregorian_to_jalali($warranty->start_date));
            $sheet->setCellValue('G' . $row, $this->utils->gregorian_to_jalali($warranty->end_date));
            $sheet->setCellValue('H' . $row, $warranty->status);
            $row++;
        }

        // ذخیره فایل
        $filename = 'warranty-export-' . date('Y-m-d') . '.xlsx';
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save(WP_CONTENT_DIR . '/exports/' . $filename);

        return WP_CONTENT_URL . '/exports/' . $filename;
    }
}