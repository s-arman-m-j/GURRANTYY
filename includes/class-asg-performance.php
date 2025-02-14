<?php
/**
 * کلاس بهینه‌سازی عملکرد افزونه
 */
class ASG_Performance {
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        // اضافه کردن هوک‌های مربوط به بهینه‌سازی
        add_action('init', array($this, 'init_caching'));
        add_action('wp_enqueue_scripts', array($this, 'optimize_assets'), 100);
        add_filter('script_loader_tag', array($this, 'add_async_defer'), 10, 3);
        add_action('wp_footer', array($this, 'dequeue_unnecessary_scripts'), 1);
        
        // فعال‌سازی فشرده‌سازی GZIP
        $this->enable_gzip_compression();
    }

    /**
     * راه‌اندازی سیستم کش
     */
    public function init_caching() {
        // تنظیم کش‌های پایه
        wp_cache_add_global_groups(array('asg_global_cache'));
        wp_cache_add_non_persistent_groups(array('asg_temp_cache'));

        // کش کردن تنظیمات پرکاربرد
        $this->cache_frequent_settings();
    }

    /**
     * کش کردن تنظیمات پرکاربرد
     */
    private function cache_frequent_settings() {
        $cache_key = 'asg_frequent_settings';
        $settings = wp_cache_get($cache_key, 'asg_global_cache');
        
        if (false === $settings) {
            $settings = array(
                'statuses' => get_option('asg_statuses'),
                'file_types' => get_option('asg_allowed_file_types'),
                'max_file_size' => get_option('asg_max_file_size'),
                'email_settings' => get_option('asg_email_settings')
            );
            wp_cache_set($cache_key, $settings, 'asg_global_cache', 3600);
        }
        
        return $settings;
    }

    /**
     * بهینه‌سازی فایل‌های CSS و JavaScript
     */
    public function optimize_assets() {
        global $wp_scripts, $wp_styles;

        // فشرده‌سازی و ترکیب فایل‌های CSS
        if (!is_admin()) {
            // حذف نسخه از URL فایل‌ها
            foreach ($wp_styles->registered as $handle => $style) {
                $wp_styles->registered[$handle]->ver = null;
            }

            // اضافه کردن فایل‌های CSS بهینه شده
            wp_enqueue_style(
                'asg-optimized-styles',
                ASG_PLUGIN_URL . 'assets/css/optimized.min.css',
                array(),
                ASG_VERSION
            );
        }

        // بهینه‌سازی JavaScript
        wp_deregister_script('jquery');
        wp_register_script(
            'jquery',
            'https://code.jquery.com/jquery-3.6.0.min.js',
            array(),
            '3.6.0',
            true
        );
        
        // اضافه کردن فایل‌های JS بهینه شده
        wp_enqueue_script(
            'asg-optimized-scripts',
            ASG_PLUGIN_URL . 'assets/js/optimized.min.js',
            array('jquery'),
            ASG_VERSION,
            true
        );
    }

    /**
     * اضافه کردن async/defer به اسکریپت‌ها
     */
    public function add_async_defer($tag, $handle, $src) {
        $async_scripts = array('asg-optimized-scripts', 'google-maps');
        $defer_scripts = array('asg-analytics');

        if (in_array($handle, $async_scripts)) {
            return str_replace(' src', ' async src', $tag);
        }
        if (in_array($handle, $defer_scripts)) {
            return str_replace(' src', ' defer src', $tag);
        }
        
        return $tag;
    }

    /**
     * حذف اسکریپت‌های غیرضروری
     */
    public function dequeue_unnecessary_scripts() {
        if (!is_admin()) {
            $unnecessary_scripts = array(
                'wp-embed',
                'jquery-migrate'
            );
            
            foreach ($unnecessary_scripts as $script) {
                wp_dequeue_script($script);
            }
        }
    }

    /**
     * فعال‌سازی فشرده‌سازی GZIP
     */
    private function enable_gzip_compression() {
        if (!in_array('mod_deflate', apache_get_modules()) && !headers_sent()) {
            ob_start('ob_gzhandler');
        }
    }

    /**
     * بهینه‌سازی تصاویر
     */
    public function optimize_images($file_path) {
        // بررسی وجود فایل
        if (!file_exists($file_path)) {
            return false;
        }

        // دریافت نوع فایل
        $mime_type = mime_content_type($file_path);
        
        // بهینه‌سازی بر اساس نوع تصویر
        switch ($mime_type) {
            case 'image/jpeg':
                $image = imagecreatefromjpeg($file_path);
                imagejpeg($image, $file_path, 85); // کیفیت 85%
                break;
                
            case 'image/png':
                $image = imagecreatefrompng($file_path);
                // حفظ شفافیت
                imagealphablending($image, false);
                imagesavealpha($image, true);
                imagepng($image, $file_path, 6); // سطح فشرده‌سازی 6
                break;
        }
        
        if (isset($image)) {
            imagedestroy($image);
        }
        
        return true;
    }

    /**
     * کش کردن نتایج API
     */
    public function cache_api_response($endpoint, $response, $expiration = 3600) {
        $cache_key = 'asg_api_' . md5($endpoint);
        wp_cache_set($cache_key, $response, 'asg_global_cache', $expiration);
    }

    /**
     * پاکسازی کش
     */
    public function clear_cache($type = 'all') {
        switch ($type) {
            case 'api':
                $this->clear_api_cache();
                break;
                
            case 'settings':
                wp_cache_delete('asg_frequent_settings', 'asg_global_cache');
                break;
                
            case 'all':
                wp_cache_flush();
                break;
        }
    }

    /**
     * پاکسازی کش API
     */
    private function clear_api_cache() {
        global $wpdb;
        $wpdb->query(
            "DELETE FROM $wpdb->options 
            WHERE option_name LIKE '_transient_asg_api_%' 
            OR option_name LIKE '_transient_timeout_asg_api_%'"
        );
    }
}