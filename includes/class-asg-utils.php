<?php
/**
 * کلاس توابع کمکی افزونه
 *
 * @package After_Sales_Guarantee
 * @since 1.8
 * @author Arman MJ
 * @last_modified 2025-02-14
 */

if (!defined('ABSPATH')) {
    exit;
}

class ASG_Utils {
    /**
     * نمونه singleton
     *
     * @var ASG_Utils
     */
    private static $instance = null;

    /**
     * دریافت نمونه کلاس
     *
     * @return ASG_Utils
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * تبدیل تاریخ میلادی به شمسی
     *
     * @param string $date
     * @param string $format
     * @return string
     */
    public function gregorian_to_jalali($date, $format = 'Y/m/d') {
        if (!class_exists('jDateTime')) {
            require_once ASG_PLUGIN_DIR . 'includes/libs/jdatetime/jdatetime.class.php';
        }

        try {
            $jDate = new jDateTime(true, true, 'Asia/Tehran');
            return $jDate->date($format, strtotime($date));
        } catch (Exception $e) {
            return $date;
        }
    }

    /**
     * تبدیل تاریخ شمسی به میلادی
     *
     * @param string $date
     * @param string $format
     * @return string
     */
    public function jalali_to_gregorian($date, $format = 'Y-m-d') {
        if (!class_exists('jDateTime')) {
            require_once ASG_PLUGIN_DIR . 'includes/libs/jdatetime/jdatetime.class.php';
        }

        try {
            $jDate = new jDateTime(true, true, 'Asia/Tehran');
            return $jDate->toGregorian($date);
        } catch (Exception $e) {
            return $date;
        }
    }

    /**
     * فرمت‌بندی قیمت
     *
     * @param float $price
     * @param string $currency
     * @return string
     */
    public function format_price($price, $currency = 'تومان') {
        return number_format($price, 0, '.', ',') . ' ' . $currency;
    }

    /**
     * تولید کد یکتا
     *
     * @param int $length
     * @return string
     */
    public function generate_unique_code($length = 8) {
        $chars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $code = '';
        
        for ($i = 0; $i < $length; $i++) {
            $code .= $chars[rand(0, strlen($chars) - 1)];
        }
        
        return $code;
    }

    /**
     * اعتبارسنجی کد ملی
     *
     * @param string $code
     * @return bool
     */
    public function validate_national_code($code) {
        if (!preg_match('/^[0-9]{10}$/', $code)) {
            return false;
        }
        
        for ($i = 0; $i < 10; $i++) {
            if (preg_match('/^' . $i . '{10}$/', $code)) {
                return false;
            }
        }
        
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += ((10 - $i) * intval(substr($code, $i, 1)));
        }
        
        $ret = $sum % 11;
        $parity = intval(substr($code, 9, 1));
        
        return ($ret < 2 && $ret == $parity) || ($ret >= 2 && $ret == 11 - $parity);
    }

    /**
     * اعتبارسنجی شماره موبایل
     *
     * @param string $mobile
     * @return bool
     */
    public function validate_mobile($mobile) {
        return preg_match('/^09[0-9]{9}$/', $mobile);
    }

    /**
     * پاکسازی HTML
     *
     * @param string $html
     * @return string
     */
    public function clean_html($html) {
        return wp_kses($html, array(
            'a' => array('href' => array(), 'title' => array(), 'target' => array()),
            'br' => array(),
            'p' => array(),
            'strong' => array(),
            'em' => array(),
            'span' => array('class' => array()),
            'div' => array('class' => array()),
            'ul' => array('class' => array()),
            'li' => array('class' => array()),
        ));
    }

    /**
     * تبدیل اعداد انگلیسی به فارسی
     *
     * @param string $string
     * @return string
     */
    public function convert_to_persian_numbers($string) {
        $persian = array('۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹');
        $english = array('0', '1', '2', '3', '4', '5', '6', '7', '8', '9');
        return str_replace($english, $persian, $string);
    }

    /**
     * تبدیل اعداد فارسی به انگلیسی
     *
     * @param string $string
     * @return string
     */
    public function convert_to_english_numbers($string) {
        $persian = array('۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹');
        $english = array('0', '1', '2', '3', '4', '5', '6', '7', '8', '9');
        return str_replace($persian, $english, $string);
    }

    /**
     * لاگ کردن خطاها
     *
     * @param string $message
     * @param string $type
     * @param array $context
     */
    public function log_error($message, $type = 'error', $context = array()) {
        $log = array(
            'time' => current_time('mysql'),
            'type' => $type,
            'message' => $message,
            'context' => $context,
            'user_id' => get_current_user_id(),
            'ip' => $this->get_client_ip()
        );

        $logs = get_option('asg_error_logs', array());
        array_unshift($logs, $log);
        
        // نگهداری حداکثر 1000 لاگ
        $logs = array_slice($logs, 0, 1000);
        
        update_option('asg_error_logs', $logs);
    }

    /**
     * دریافت IP کاربر
     *
     * @return string
     */
    public function get_client_ip() {
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
     * ارسال پیامک
     *
     * @param string $mobile
     * @param string $message
     * @return bool
     */
    public function send_sms($mobile, $message) {
        // پیاده‌سازی ارسال SMS با سرویس مورد نظر
        // این متد باید با توجه به سرویس SMS انتخابی پیاده‌سازی شود
        return true;
    }
}