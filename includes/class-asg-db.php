<?php
/**
 * کلاس مدیریت دیتابیس افزونه
 *
 * @package After_Sales_Guarantee
 * @since 1.8
 * @author Arman MJ
 * @last_modified 2025-02-14
 */

if (!defined('ABSPATH')) {
    exit;
}

class ASG_DB {
    /**
     * نمونه singleton
     *
     * @var ASG_DB
     */
    private static $instance = null;

    /**
     * نسخه دیتابیس
     *
     * @var string
     */
    private $db_version = '1.8';

    /**
     * پیشوند جداول
     *
     * @var string
     */
    private $table_prefix;

    /**
     * دریافت نمونه کلاس
     *
     * @return ASG_DB
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
        global $wpdb;
        $this->table_prefix = $wpdb->prefix . 'asg_';
        
        // اضافه کردن اکشن‌های مربوط به به‌روزرسانی دیتابیس
        add_action('plugins_loaded', array($this, 'check_version'));
        
        // اضافه کردن کرون جاب برای پاکسازی دیتابیس
        if (!wp_next_scheduled('asg_db_cleanup')) {
            wp_schedule_event(time(), 'daily', 'asg_db_cleanup');
        }
        add_action('asg_db_cleanup', array($this, 'cleanup_database'));
    }

    /**
     * بررسی نسخه دیتابیس و به‌روزرسانی در صورت نیاز
     */
    public function check_version() {
        if (get_option('asg_db_version') != $this->db_version) {
            $this->create_tables();
            $this->update_db_version();
        }
    }

    /**
     * ایجاد جداول مورد نیاز
     */
    public function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // جدول درخواست‌های گارانتی
        $sql_requests = "CREATE TABLE IF NOT EXISTS {$this->table_prefix}guarantee_requests (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            product_id bigint(20) NOT NULL,
            user_id bigint(20) NOT NULL,
            tamin_user_id bigint(20) DEFAULT NULL,
            defect_description text,
            expert_comment text,
            status varchar(50) NOT NULL DEFAULT 'pending',
            receipt_day int(2),
            receipt_month varchar(20),
            receipt_year int(4),
            image_id bigint(20) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY product_id (product_id),
            KEY user_id (user_id),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset_collate;";

        // جدول یادداشت‌ها
        $sql_notes = "CREATE TABLE IF NOT EXISTS {$this->table_prefix}guarantee_notes (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            request_id bigint(20) NOT NULL,
            user_id bigint(20) NOT NULL,
            note text NOT NULL,
            is_private tinyint(1) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY request_id (request_id),
            KEY user_id (user_id)
        ) $charset_collate;";

        // جدول نوتیفیکیشن‌ها
        $sql_notifications = "CREATE TABLE IF NOT EXISTS {$this->table_prefix}notifications (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            request_id bigint(20) NOT NULL,
            user_id bigint(20) NOT NULL,
            type varchar(50) NOT NULL,
            message text NOT NULL,
            is_read tinyint(1) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY request_id (request_id),
            KEY user_id (user_id),
            KEY type (type),
            KEY is_read (is_read)
        ) $charset_collate;";

        // جدول لاگ‌ها
        $sql_logs = "CREATE TABLE IF NOT EXISTS {$this->table_prefix}logs (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) DEFAULT NULL,
            action varchar(100) NOT NULL,
            object_type varchar(50) NOT NULL,
            object_id bigint(20) DEFAULT NULL,
            details text,
            ip_address varchar(45),
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY action (action),
            KEY object_type (object_type),
            KEY object_id (object_id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_requests);
        dbDelta($sql_notes);
        dbDelta($sql_notifications);
        dbDelta($sql_logs);

        // ایجاد ایندکس‌های اضافی برای بهبود کارایی
        $this->create_additional_indexes();
    }

    /**
     * ایجاد ایندکس‌های اضافی
     */
    private function create_additional_indexes() {
        global $wpdb;

        // ایندکس ترکیبی برای جستجوی سریع‌تر
        $wpdb->query("ALTER TABLE {$this->table_prefix}guarantee_requests 
                     ADD INDEX search_index (product_id, user_id, status)");

        // ایندکس برای جستجوی تاریخ
        $wpdb->query("ALTER TABLE {$this->table_prefix}guarantee_requests 
                     ADD INDEX date_index (receipt_year, receipt_month(20))");
    }

    /**
     * به‌روزرسانی نسخه دیتابیس
     */
    private function update_db_version() {
        update_option('asg_db_version', $this->db_version);
    }

    /**
     * پاکسازی دیتابیس
     */
    public function cleanup_database() {
        global $wpdb;

        // پاکسازی نوتیفیکیشن‌های قدیمی (بیشتر از 3 ماه)
        $wpdb->query("DELETE FROM {$this->table_prefix}notifications 
                     WHERE created_at < DATE_SUB(NOW(), INTERVAL 3 MONTH) 
                     AND is_read = 1");

        // پاکسازی لاگ‌های قدیمی (بیشتر از 6 ماه)
        $wpdb->query("DELETE FROM {$this->table_prefix}logs 
                     WHERE created_at < DATE_SUB(NOW(), INTERVAL 6 MONTH)");

        // بهینه‌سازی جداول
        $tables = array(
            'guarantee_requests',
            'guarantee_notes',
            'notifications',
            'logs'
        );

        foreach ($tables as $table) {
            $wpdb->query("OPTIMIZE TABLE {$this->table_prefix}$table");
        }
    }

    /**
     * دریافت نام کامل جدول
     *
     * @param string $table_name
     * @return string
     */
    public function get_table_name($table_name) {
        return $this->table_prefix . $table_name;
    }

    /**
     * پشتیبان‌گیری از دیتابیس
     *
     * @return string|WP_Error
     */
    public function backup_database() {
        try {
            global $wpdb;

            $tables = array(
                $this->get_table_name('guarantee_requests'),
                $this->get_table_name('guarantee_notes'),
                $this->get_table_name('notifications'),
                $this->get_table_name('logs')
            );

            $backup = '';

            foreach ($tables as $table) {
                // ساختار جدول
                $row = $wpdb->get_row("SHOW CREATE TABLE $table", ARRAY_N);
                $backup .= "\n\n" . $row[1] . ";\n\n";

                // داده‌های جدول
                $rows = $wpdb->get_results("SELECT * FROM $table", ARRAY_N);
                foreach ($rows as $row) {
                    $backup .= "INSERT INTO $table VALUES(" . 
                              implode(',', array_map(array($wpdb, 'prepare'), $row)) . 
                              ");\n";
                }
            }

            $backup_dir = WP_CONTENT_DIR . '/backups';
            if (!file_exists($backup_dir)) {
                mkdir($backup_dir, 0755, true);
            }

            $filename = $backup_dir . '/warranty_backup_' . date('Y-m-d_H-i-s') . '.sql';
            file_put_contents($filename, $backup);

            return $filename;

        } catch (Exception $e) {
            return new WP_Error('backup_failed', $e->getMessage());
        }
    }

    /**
     * بازیابی دیتابیس از فایل پشتیبان
     *
     * @param string $file_path
     * @return bool|WP_Error
     */
    public function restore_database($file_path) {
        try {
            global $wpdb;

            if (!file_exists($file_path)) {
                return new WP_Error('file_not_found', 'فایل پشتیبان یافت نشد');
            }

            $sql = file_get_contents($file_path);
            $queries = explode(';', $sql);

            foreach ($queries as $query) {
                if (!empty(trim($query))) {
                    $wpdb->query($query);
                }
            }

            return true;

        } catch (Exception $e) {
            return new WP_Error('restore_failed', $e->getMessage());
        }
    }
}