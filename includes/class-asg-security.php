<?php
/**
 * کلاس مدیریت امنیت افزونه
 *
 * @package After_Sales_Guarantee
 * @since 1.8
 * @author Arman MJ
 * @last_modified 2025-02-14
 */

if (!defined('ABSPATH')) {
    exit;
}

class ASG_Security {
    /**
     * نمونه singleton
     *
     * @var ASG_Security
     */
    private static $instance = null;

    /**
     * تنظیمات امنیتی
     *
     * @var array
     */
    private $security_settings;

    /**
     * دریافت نمونه کلاس
     *
     * @return ASG_Security
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
        $this->security_settings = get_option('asg_security_settings', $this->get_default_settings());
        
        // اضافه کردن فیلترها و اکشن‌های امنیتی
        add_filter('asg_validate_request', array($this, 'validate_request'), 10, 2);
        add_action('init', array($this, 'setup_security_headers'));
        add_action('admin_init', array($this, 'check_user_permissions'));
    }

    /**
     * تنظیمات پیش‌فرض امنیتی
     *
     * @return array
     */
    private function get_default_settings() {
        return array(
            'max_attempts' => 5,
            'lockout_duration' => 1800, // 30 دقیقه
            'allowed_roles' => array('administrator', 'shop_manager'),
            'secure_headers' => true,
            'enable_logging' => true
        );
    }

    /**
     * اعتبارسنجی درخواست‌ها
     *
     * @param bool $valid
     * @param array $request_data
     * @return bool
     */
    public function validate_request($valid, $request_data) {
        if (!$valid) {
            return false;
        }

        // بررسی نانس
        if (!isset($request_data['nonce']) || 
            !wp_verify_nonce($request_data['nonce'], 'asg_security_nonce')) {
            $this->log_security_event('نانس نامعتبر', $request_data);
            return false;
        }

        // بررسی دسترسی کاربر
        if (!$this->check_user_permissions()) {
            $this->log_security_event('دسترسی غیرمجاز', $request_data);
            return false;
        }

        // بررسی محدودیت تعداد درخواست
        if ($this->is_rate_limited()) {
            $this->log_security_event('محدودیت تعداد درخواست', $request_data);
            return false;
        }

        return true;
    }

    /**
     * تنظیم هدرهای امنیتی
     */
    public function setup_security_headers() {
        if ($this->security_settings['secure_headers']) {
            // تنظیم هدرهای امنیتی
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: SAMEORIGIN');
            header('X-XSS-Protection: 1; mode=block');
            header('Referrer-Policy: strict-origin-when-cross-origin');
            
            // اضافه کردن CSP در صورت نیاز
            $this->add_content_security_policy();
        }
    }

    /**
     * افزودن Content Security Policy
     */
    private function add_content_security_policy() {
        $csp = array(
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval' *.wordpress.org",
            "style-src 'self' 'unsafe-inline' *.googleapis.com",
            "img-src 'self' data: *.wp.com",
            "font-src 'self' *.googleapis.com *.gstatic.com",
            "frame-src 'self'",
            "connect-src 'self'"
        );

        header("Content-Security-Policy: " . implode('; ', $csp));
    }

    /**
     * بررسی دسترسی کاربر
     *
     * @return bool
     */
    public function check_user_permissions() {
        if (!is_user_logged_in()) {
            return false;
        }

        $user = wp_get_current_user();
        $allowed_roles = $this->security_settings['allowed_roles'];

        // بررسی نقش کاربر
        $has_role = array_intersect($allowed_roles, (array) $user->roles);
        
        return !empty($has_role);
    }

    /**
     * بررسی محدودیت تعداد درخواست
     *
     * @return bool
     */
    private function is_rate_limited() {
        $ip = $this->get_client_ip();
        $key = 'asg_rate_limit_' . md5($ip);
        $attempts = (int) get_transient($key);

        if ($attempts >= $this->security_settings['max_attempts']) {
            return true;
        }

        set_transient($key, $attempts + 1, $this->security_settings['lockout_duration']);
        return false;
    }

    /**
     * دریافت IP کاربر
     *
     * @return string
     */
    private function get_client_ip() {
        $ip = '';
        
        if (isset($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif (isset($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }

    /**
     * ثبت رویدادهای امنیتی
     *
     * @param string $event
     * @param array $data
     */
    private function log_security_event($event, $data) {
        if (!$this->security_settings['enable_logging']) {
            return;
        }

        $log = array(
            'time' => current_time('mysql'),
            'event' => $event,
            'ip' => $this->get_client_ip(),
            'user_id' => get_current_user_id(),
            'data' => $data
        );

        $logs = get_option('asg_security_logs', array());
        array_unshift($logs, $log);
        
        // نگهداری حداکثر 1000 لاگ
        $logs = array_slice($logs, 0, 1000);
        
        update_option('asg_security_logs', $logs);
    }

    /**
     * دریافت تنظیمات امنیتی
     *
     * @return array
     */
    public function get_security_settings() {
        return $this->security_settings;
    }

    /**
     * به‌روزرسانی تنظیمات امنیتی
     *
     * @param array $new_settings
     * @return bool
     */
    public function update_security_settings($new_settings) {
        $this->security_settings = wp_parse_args($new_settings, $this->get_default_settings());
        return update_option('asg_security_settings', $this->security_settings);
    }
}