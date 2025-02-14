<?php
/**
 * کلاس مدیریت اطلاع‌رسانی افزونه
 * 
 * @package After_Sales_Guarantee
 * @since 1.8
 * @author s-arman-m-j
 * @last_modified 2025-02-14 11:19:40
 */

if (!defined('ABSPATH')) {
    exit;
}

class ASG_Notifications {
    /**
     * نمونه singleton
     *
     * @var ASG_Notifications
     */
    private static $instance = null;

    /**
     * نمونه‌های کلاس‌های مورد نیاز
     */
    private $warranty;
    private $utils;

    /**
     * تنظیمات اطلاع‌رسانی
     *
     * @var array
     */
    private $notification_settings;

    /**
     * قالب‌های پیش‌فرض اطلاع‌رسانی
     *
     * @var array
     */
    private $default_templates = array(
        'warranty_expiring' => array(
            'subject' => 'هشدار: گارانتی محصول {product_name} رو به انقضا است',
            'message' => "مشتری گرامی {customer_name}،\n\n" .
                        "به اطلاع می‌رساند گارانتی محصول {product_name} با شماره سریال {serial_number} " .
                        "در تاریخ {expiry_date} منقضی خواهد شد.\n\n" .
                        "لطفاً برای تمدید گارانتی اقدام فرمایید.\n\n" .
                        "با احترام\n{site_name}"
        ),
        'service_request_status' => array(
            'subject' => 'به‌روزرسانی وضعیت درخواست خدمات #{request_id}',
            'message' => "مشتری گرامی {customer_name}،\n\n" .
                        "وضعیت درخواست خدمات شما برای محصول {product_name} به‌روز شد:\n\n" .
                        "وضعیت جدید: {status}\n" .
                        "توضیحات: {description}\n\n" .
                        "برای پیگیری به پنل کاربری خود مراجعه کنید.\n\n" .
                        "با احترام\n{site_name}"
        ),
        'periodic_report' => array(
            'subject' => 'گزارش دوره‌ای سیستم گارانتی - {date}',
            'message' => "مدیر گرامی،\n\n" .
                        "گزارش دوره‌ای سیستم گارانتی به پیوست ارسال می‌گردد.\n\n" .
                        "خلاصه گزارش:\n" .
                        "- تعداد کل گارانتی‌های فعال: {active_warranties}\n" .
                        "- تعداد گارانتی‌های رو به انقضا: {expiring_warranties}\n" .
                        "- تعداد درخواست‌های خدمات باز: {open_requests}\n\n" .
                        "با احترام\n{site_name}"
        ),
        'system_alert' => array(
            'subject' => 'هشدار سیستم گارانتی: {alert_type}',
            'message' => "مدیر گرامی،\n\n" .
                        "یک هشدار سیستمی جدید ثبت شده است:\n\n" .
                        "نوع هشدار: {alert_type}\n" .
                        "توضیحات: {description}\n" .
                        "زمان: {datetime}\n\n" .
                        "لطفاً در اسرع وقت بررسی فرمایید.\n\n" .
                        "با احترام\n{site_name}"
        )
    );

    /**
     * دریافت نمونه کلاس
     *
     * @return ASG_Notifications
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
        $this->warranty = ASG_Warranty::instance();
        $this->utils = ASG_Utils::instance();
        $this->notification_settings = get_option('asg_notification_settings', $this->get_default_settings());

        // تنظیم کرون جاب‌های اطلاع‌رسانی
        $this->setup_cron_jobs();

        // افزودن اکشن‌های مرتبط با وردپرس
        add_action('asg_check_expiring_warranties', array($this, 'notify_expiring_warranties'));
        add_action('asg_warranty_status_changed', array($this, 'notify_warranty_status_change'), 10, 3);
        add_action('asg_service_request_status_changed', array($this, 'notify_service_request_status_change'), 10, 3);
    }
/**
     * تنظیمات پیش‌فرض اطلاع‌رسانی
     *
     * @return array
     */
    private function get_default_settings() {
        return array(
            'expiring_warranty' => array(
                'enabled' => true,
                'days_before' => 30,
                'notify_customer' => true,
                'notify_admin' => true,
                'notification_methods' => array(
                    'email' => true,
                    'sms' => false,
                    'dashboard' => true
                )
            ),
            'service_request' => array(
                'enabled' => true,
                'notify_customer' => true,
                'notify_admin' => true,
                'notification_methods' => array(
                    'email' => true,
                    'sms' => false,
                    'dashboard' => true
                )
            ),
            'periodic_report' => array(
                'enabled' => true,
                'frequency' => 'weekly',
                'recipients' => array(),
                'notification_methods' => array(
                    'email' => true,
                    'dashboard' => true
                )
            ),
            'system_alerts' => array(
                'enabled' => true,
                'priority_level' => 'high',
                'recipients' => array(),
                'notification_methods' => array(
                    'email' => true,
                    'dashboard' => true
                )
            )
        );
    }

    /**
     * تنظیم کرون جاب‌های اطلاع‌رسانی
     */
    private function setup_cron_jobs() {
        // کرون جاب بررسی گارانتی‌های رو به انقضا
        if ($this->notification_settings['expiring_warranty']['enabled']) {
            if (!wp_next_scheduled('asg_check_expiring_warranties')) {
                wp_schedule_event(time(), 'daily', 'asg_check_expiring_warranties');
            }
        } else {
            wp_clear_scheduled_hook('asg_check_expiring_warranties');
        }

        // کرون جاب گزارشات دوره‌ای
        if ($this->notification_settings['periodic_report']['enabled']) {
            $schedule = $this->notification_settings['periodic_report']['frequency'];
            if (!wp_next_scheduled('asg_send_periodic_report')) {
                wp_schedule_event(time(), $schedule, 'asg_send_periodic_report');
            }
        } else {
            wp_clear_scheduled_hook('asg_send_periodic_report');
        }
    }

    /**
     * اطلاع‌رسانی گارانتی‌های رو به انقضا
     */
    public function notify_expiring_warranties() {
        if (!$this->notification_settings['expiring_warranty']['enabled']) {
            return;
        }

        $days_before = $this->notification_settings['expiring_warranty']['days_before'];
        $expiring_warranties = $this->warranty->get_expiring_warranties($days_before);

        foreach ($expiring_warranties as $warranty) {
            $this->send_warranty_expiry_notification($warranty);
        }
    }

    /**
     * ارسال اطلاع‌رسانی انقضای گارانتی
     *
     * @param object $warranty اطلاعات گارانتی
     */
    private function send_warranty_expiry_notification($warranty) {
        $template = $this->default_templates['warranty_expiring'];
        
        // جایگزینی متغیرها در قالب
        $variables = array(
            '{product_name}' => get_the_title($warranty->product_id),
            '{customer_name}' => get_user_meta($warranty->user_id, 'first_name', true),
            '{serial_number}' => $warranty->serial_number,
            '{expiry_date}' => $this->utils->gregorian_to_jalali($warranty->end_date),
            '{site_name}' => get_bloginfo('name')
        );

        $subject = strtr($template['subject'], $variables);
        $message = strtr($template['message'], $variables);

        // ارسال به مشتری
        if ($this->notification_settings['expiring_warranty']['notify_customer']) {
            $customer_email = get_user_meta($warranty->user_id, 'billing_email', true);
            
            if ($this->notification_settings['expiring_warranty']['notification_methods']['email']) {
                wp_mail($customer_email, $subject, $message);
            }

            if ($this->notification_settings['expiring_warranty']['notification_methods']['sms']) {
                $this->send_sms($customer_email, $message);
            }

            if ($this->notification_settings['expiring_warranty']['notification_methods']['dashboard']) {
                $this->add_dashboard_notification($warranty->user_id, 'warranty_expiring', $message);
            }
        }

        // ارسال به مدیر
        if ($this->notification_settings['expiring_warranty']['notify_admin']) {
            $admin_email = get_option('admin_email');
            wp_mail($admin_email, $subject, $message);
        }
    }

    /**
     * اطلاع‌رسانی تغییر وضعیت گارانتی
     *
     * @param int $warranty_id شناسه گارانتی
     * @param string $old_status وضعیت قبلی
     * @param string $new_status وضعیت جدید
     */
    public function notify_warranty_status_change($warranty_id, $old_status, $new_status) {
        $warranty = $this->warranty->get_warranty($warranty_id);
        if (!$warranty) {
            return;
        }

        $template = array(
            'subject' => 'تغییر وضعیت گارانتی محصول {product_name}',
            'message' => "مشتری گرامی {customer_name}،\n\n" .
                        "وضعیت گارانتی محصول {product_name} با شماره سریال {serial_number} " .
                        "از {old_status} به {new_status} تغییر یافت.\n\n" .
                        "با احترام\n{site_name}"
        );

        $variables = array(
            '{product_name}' => get_the_title($warranty->product_id),
            '{customer_name}' => get_user_meta($warranty->user_id, 'first_name', true),
            '{serial_number}' => $warranty->serial_number,
            '{old_status}' => $old_status,
            '{new_status}' => $new_status,
            '{site_name}' => get_bloginfo('name')
        );

        $subject = strtr($template['subject'], $variables);
        $message = strtr($template['message'], $variables);

        // ارسال به مشتری
        $customer_email = get_user_meta($warranty->user_id, 'billing_email', true);
        wp_mail($customer_email, $subject, $message);

        // اضافه کردن به داشبورد
        $this->add_dashboard_notification($warranty->user_id, 'warranty_status_change', $message);
    }
    /**
     * اطلاع‌رسانی تغییر وضعیت درخواست خدمات
     *
     * @param int $request_id شناسه درخواست
     * @param string $old_status وضعیت قبلی
     * @param string $new_status وضعیت جدید
     */
    public function notify_service_request_status_change($request_id, $old_status, $new_status) {
        if (!$this->notification_settings['service_request']['enabled']) {
            return;
        }

        global $wpdb;
        $request = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}asg_service_requests WHERE id = %d",
            $request_id
        ));

        if (!$request) {
            return;
        }

        $template = $this->default_templates['service_request_status'];
        
        $variables = array(
            '{request_id}' => $request_id,
            '{customer_name}' => get_user_meta($request->user_id, 'first_name', true),
            '{product_name}' => get_the_title($request->product_id),
            '{status}' => $new_status,
            '{description}' => $request->description,
            '{site_name}' => get_bloginfo('name')
        );

        $subject = strtr($template['subject'], $variables);
        $message = strtr($template['message'], $variables);

        // ارسال به مشتری
        if ($this->notification_settings['service_request']['notify_customer']) {
            $customer_email = get_user_meta($request->user_id, 'billing_email', true);
            
            if ($this->notification_settings['service_request']['notification_methods']['email']) {
                wp_mail($customer_email, $subject, $message);
            }

            if ($this->notification_settings['service_request']['notification_methods']['sms']) {
                $this->send_sms($customer_email, $message);
            }

            if ($this->notification_settings['service_request']['notification_methods']['dashboard']) {
                $this->add_dashboard_notification($request->user_id, 'service_request_status', $message);
            }
        }

        // ارسال به مدیر
        if ($this->notification_settings['service_request']['notify_admin']) {
            $admin_email = get_option('admin_email');
            wp_mail($admin_email, $subject, $message);
        }
    }

    /**
     * ارسال گزارش دوره‌ای
     */
    public function send_periodic_report() {
        if (!$this->notification_settings['periodic_report']['enabled']) {
            return;
        }

        // تولید گزارش
        $reports = ASG_Reports::instance();
        $current_date = current_time('mysql');
        $report_data = array(
            'warranty_status' => $reports->generate_warranty_status_report(),
            'expiring_warranties' => $reports->generate_expiring_warranties_report(),
            'service_requests' => $reports->generate_service_requests_report(),
            'revenue' => $reports->generate_revenue_report()
        );

        // آماده‌سازی پیام
        $template = $this->default_templates['periodic_report'];
        
        $variables = array(
            '{date}' => $this->utils->gregorian_to_jalali(date('Y-m-d')),
            '{active_warranties}' => $report_data['warranty_status']['summary']['active'],
            '{expiring_warranties}' => count($report_data['expiring_warranties']),
            '{open_requests}' => $report_data['service_requests']['summary']['pending'] + 
                               $report_data['service_requests']['summary']['in_progress'],
            '{site_name}' => get_bloginfo('name')
        );

        $subject = strtr($template['subject'], $variables);
        $message = strtr($template['message'], $variables);

        // ذخیره گزارش
        $reports->save_reports($report_data, $current_date);

        // ارسال به گیرندگان
        $recipients = $this->notification_settings['periodic_report']['recipients'];
        if (empty($recipients)) {
            $recipients = array(get_option('admin_email'));
        }

        foreach ($recipients as $recipient) {
            if ($this->notification_settings['periodic_report']['notification_methods']['email']) {
                wp_mail($recipient, $subject, $message, '', array(
                    WP_CONTENT_DIR . '/uploads/warranty-reports/latest.xlsx'
                ));
            }

            if ($this->notification_settings['periodic_report']['notification_methods']['dashboard']) {
                $user = get_user_by('email', $recipient);
                if ($user) {
                    $this->add_dashboard_notification($user->ID, 'periodic_report', $message);
                }
            }
        }
    }

    /**
     * ارسال هشدار سیستمی
     *
     * @param string $alert_type نوع هشدار
     * @param string $description توضیحات
     * @param string $priority اولویت (low, medium, high)
     */
    public function send_system_alert($alert_type, $description, $priority = 'medium') {
        if (!$this->notification_settings['system_alerts']['enabled']) {
            return;
        }

        // بررسی سطح اولویت
        $priority_levels = array('low' => 1, 'medium' => 2, 'high' => 3);
        $setting_level = $priority_levels[$this->notification_settings['system_alerts']['priority_level']];
        $alert_level = $priority_levels[$priority];

        if ($alert_level < $setting_level) {
            return;
        }

        $template = $this->default_templates['system_alert'];
        
        $variables = array(
            '{alert_type}' => $alert_type,
            '{description}' => $description,
            '{datetime}' => $this->utils->gregorian_to_jalali(current_time('mysql')),
            '{site_name}' => get_bloginfo('name')
        );

        $subject = strtr($template['subject'], $variables);
        $message = strtr($template['message'], $variables);

        // ارسال به گیرندگان
        $recipients = $this->notification_settings['system_alerts']['recipients'];
        if (empty($recipients)) {
            $recipients = array(get_option('admin_email'));
        }

        foreach ($recipients as $recipient) {
            if ($this->notification_settings['system_alerts']['notification_methods']['email']) {
                wp_mail($recipient, $subject, $message);
            }

            if ($this->notification_settings['system_alerts']['notification_methods']['dashboard']) {
                $user = get_user_by('email', $recipient);
                if ($user) {
                    $this->add_dashboard_notification($user->ID, 'system_alert', $message, $priority);
                }
            }
        }

        // ثبت در لاگ
        $this->utils->log_error($description, $priority, array(
            'alert_type' => $alert_type,
            'datetime' => current_time('mysql')
        ));
    }
    /**
     * افزودن اعلان به داشبورد کاربر
     *
     * @param int $user_id شناسه کاربر
     * @param string $type نوع اعلان
     * @param string $message متن پیام
     * @param string $priority اولویت (low, medium, high)
     * @return bool
     */
    private function add_dashboard_notification($user_id, $type, $message, $priority = 'medium') {
        global $wpdb;

        $result = $wpdb->insert(
            $wpdb->prefix . 'asg_notifications',
            array(
                'user_id' => $user_id,
                'type' => $type,
                'message' => $message,
                'priority' => $priority,
                'created_at' => current_time('mysql'),
                'read_at' => null
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s')
        );

        if ($result) {
            do_action('asg_notification_added', $user_id, $type, $message);
            return true;
        }

        return false;
    }

    /**
     * ارسال پیامک
     *
     * @param string $phone شماره موبایل
     * @param string $message متن پیام
     * @return bool
     */
    private function send_sms($phone, $message) {
        // بررسی تنظیمات SMS
        $sms_settings = get_option('asg_sms_settings');
        if (empty($sms_settings['api_key']) || empty($sms_settings['line_number'])) {
            $this->utils->log_error(
                'خطا در ارسال پیامک: تنظیمات SMS کامل نیست',
                'error',
                array('phone' => $phone)
            );
            return false;
        }

        // آماده‌سازی پارامترها
        $args = array(
            'body' => array(
                'apikey' => $sms_settings['api_key'],
                'linenumber' => $sms_settings['line_number'],
                'receptor' => $phone,
                'message' => $message
            )
        );

        // ارسال درخواست به API
        $response = wp_remote_post($sms_settings['api_url'], $args);

        if (is_wp_error($response)) {
            $this->utils->log_error(
                'خطا در ارسال پیامک: ' . $response->get_error_message(),
                'error',
                array('phone' => $phone)
            );
            return false;
        }

        $result = json_decode(wp_remote_retrieve_body($response), true);

        if (!isset($result['success']) || !$result['success']) {
            $this->utils->log_error(
                'خطا در ارسال پیامک: ' . ($result['message'] ?? 'خطای نامشخص'),
                'error',
                array(
                    'phone' => $phone,
                    'response' => $result
                )
            );
            return false;
        }

        return true;
    }

    /**
     * به‌روزرسانی تنظیمات اطلاع‌رسانی
     *
     * @param array $new_settings تنظیمات جدید
     * @return bool
     */
    public function update_settings($new_settings) {
        $this->notification_settings = wp_parse_args(
            $new_settings,
            $this->get_default_settings()
        );

        // به‌روزرسانی کرون جاب‌ها
        $this->setup_cron_jobs();

        return update_option('asg_notification_settings', $this->notification_settings);
    }

    /**
     * دریافت تنظیمات اطلاع‌رسانی
     *
     * @return array
     */
    public function get_settings() {
        return $this->notification_settings;
    }

    /**
     * دریافت قالب‌های پیش‌فرض
     *
     * @return array
     */
    public function get_default_templates() {
        return $this->default_templates;
    }

    /**
     * پاکسازی نشست در هنگام غیرفعال‌سازی افزونه
     */
    public static function deactivate() {
        wp_clear_scheduled_hook('asg_check_expiring_warranties');
        wp_clear_scheduled_hook('asg_send_periodic_report');
    }
}