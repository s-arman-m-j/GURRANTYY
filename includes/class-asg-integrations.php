<?php
/**
 * کلاس مدیریت یکپارچه‌سازی‌های افزونه
 *
 * @package After_Sales_Guarantee
 * @since 1.8
 * @author Arman MJ
 * @last_modified 2025-02-14
 */

if (!defined('ABSPATH')) {
    exit;
}

class ASG_Integrations {
    /**
     * نمونه singleton
     *
     * @var ASG_Integrations
     */
    private static $instance = null;

    /**
     * نمونه‌های کلاس‌های مورد نیاز
     */
    private $warranty;
    private $utils;

    /**
     * تنظیمات یکپارچه‌سازی
     *
     * @var array
     */
    private $integration_settings;

    /**
     * دریافت نمونه کلاس
     *
     * @return ASG_Integrations
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
        $this->integration_settings = get_option('asg_integration_settings', $this->get_default_settings());

        // یکپارچه‌سازی با CRM
        if ($this->integration_settings['crm']['enabled']) {
            add_action('asg_warranty_created', array($this, 'sync_warranty_to_crm'), 10, 2);
            add_action('asg_warranty_updated', array($this, 'update_warranty_in_crm'), 10, 2);
        }

        // یکپارچه‌سازی با سیستم تیکتینگ
        if ($this->integration_settings['ticketing']['enabled']) {
            add_action('asg_warranty_service_requested', array($this, 'create_service_ticket'), 10, 3);
            add_action('asg_warranty_ticket_updated', array($this, 'update_ticket_status'), 10, 3);
        }

        // یکپارچه‌سازی با پیامک
        if ($this->integration_settings['sms']['enabled']) {
            add_action('asg_warranty_status_changed', array($this, 'send_status_sms'), 10, 3);
            add_action('asg_warranty_expiring_soon', array($this, 'send_expiry_reminder_sms'), 10, 2);
        }

        // یکپارچه‌سازی با سیستم حسابداری
        if ($this->integration_settings['accounting']['enabled']) {
            add_action('asg_warranty_payment_received', array($this, 'sync_payment_to_accounting'), 10, 3);
        }
    }

    /**
     * تنظیمات پیش‌فرض یکپارچه‌سازی
     *
     * @return array
     */
    private function get_default_settings() {
        return array(
            'crm' => array(
                'enabled' => false,
                'api_url' => '',
                'api_key' => '',
                'sync_fields' => array(
                    'customer' => true,
                    'product' => true,
                    'warranty' => true
                )
            ),
            'ticketing' => array(
                'enabled' => false,
                'api_url' => '',
                'api_key' => '',
                'default_priority' => 'medium',
                'auto_assign' => true
            ),
            'sms' => array(
                'enabled' => false,
                'provider' => 'kavenegar',
                'api_key' => '',
                'templates' => array(
                    'warranty_created' => '',
                    'warranty_expired' => '',
                    'service_requested' => ''
                )
            ),
            'accounting' => array(
                'enabled' => false,
                'api_url' => '',
                'api_key' => '',
                'auto_invoice' => true,
                'default_tax_rate' => 9
            )
        );
    }

    /**
     * همگام‌سازی گارانتی با CRM
     *
     * @param int $warranty_id
     * @param array $warranty_data
     */
    public function sync_warranty_to_crm($warranty_id, $warranty_data) {
        try {
            $crm_api_url = $this->integration_settings['crm']['api_url'];
            $crm_api_key = $this->integration_settings['crm']['api_key'];

            // آماده‌سازی داده‌ها برای CRM
            $crm_data = array(
                'warranty_id' => $warranty_id,
                'customer' => array(
                    'id' => $warranty_data['user_id'],
                    'name' => get_user_meta($warranty_data['user_id'], 'first_name', true) . ' ' . 
                            get_user_meta($warranty_data['user_id'], 'last_name', true),
                    'email' => get_userdata($warranty_data['user_id'])->user_email
                ),
                'product' => array(
                    'id' => $warranty_data['product_id'],
                    'name' => get_the_title($warranty_data['product_id']),
                    'serial_number' => $warranty_data['serial_number']
                ),
                'warranty' => array(
                    'type' => $warranty_data['warranty_type'],
                    'start_date' => $warranty_data['start_date'],
                    'end_date' => $warranty_data['end_date'],
                    'status' => $warranty_data['status']
                )
            );

            // ارسال به CRM
            $response = wp_remote_post($crm_api_url . '/warranties', array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $crm_api_key,
                    'Content-Type' => 'application/json'
                ),
                'body' => wp_json_encode($crm_data)
            ));

            if (is_wp_error($response)) {
                throw new Exception($response->get_error_message());
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            
            if (!empty($body['id'])) {
                update_post_meta($warranty_id, '_crm_warranty_id', $body['id']);
            }

        } catch (Exception $e) {
            $this->utils->log_error(
                'خطا در همگام‌سازی با CRM',
                'error',
                array(
                    'warranty_id' => $warranty_id,
                    'error' => $e->getMessage()
                )
            );
        }
    }

    /**
     * به‌روزرسانی گارانتی در CRM
     *
     * @param int $warranty_id
     * @param array $warranty_data
     */
    public function update_warranty_in_crm($warranty_id, $warranty_data) {
        try {
            $crm_warranty_id = get_post_meta($warranty_id, '_crm_warranty_id', true);
            if (!$crm_warranty_id) {
                return;
            }

            $crm_api_url = $this->integration_settings['crm']['api_url'];
            $crm_api_key = $this->integration_settings['crm']['api_key'];

            // ارسال به‌روزرسانی به CRM
            $response = wp_remote_request($crm_api_url . '/warranties/' . $crm_warranty_id, array(
                'method' => 'PUT',
                'headers' => array(
                    'Authorization' => 'Bearer ' . $crm_api_key,
                    'Content-Type' => 'application/json'
                ),
                'body' => wp_json_encode(array(
                    'status' => $warranty_data['status'],
                    'updated_at' => current_time('mysql')
                ))
            ));

            if (is_wp_error($response)) {
                throw new Exception($response->get_error_message());
            }

        } catch (Exception $e) {
            $this->utils->log_error(
                'خطا در به‌روزرسانی CRM',
                'error',
                array(
                    'warranty_id' => $warranty_id,
                    'error' => $e->getMessage()
                )
            );
        }
    }

    /**
     * ایجاد تیکت خدمات
     *
     * @param int $warranty_id
     * @param int $user_id
     * @param array $service_data
     */
    public function create_service_ticket($warranty_id, $user_id, $service_data) {
        try {
            $ticketing_api_url = $this->integration_settings['ticketing']['api_url'];
            $ticketing_api_key = $this->integration_settings['ticketing']['api_key'];

            // آماده‌سازی داده‌های تیکت
            $ticket_data = array(
                'warranty_id' => $warranty_id,
                'customer_id' => $user_id,
                'subject' => $service_data['subject'],
                'description' => $service_data['description'],
                'priority' => $this->integration_settings['ticketing']['default_priority'],
                'auto_assign' => $this->integration_settings['ticketing']['auto_assign']
            );

            // ایجاد تیکت
            $response = wp_remote_post($ticketing_api_url . '/tickets', array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $ticketing_api_key,
                    'Content-Type' => 'application/json'
                ),
                'body' => wp_json_encode($ticket_data)
            ));

            if (is_wp_error($response)) {
                throw new Exception($response->get_error_message());
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            
            if (!empty($body['id'])) {
                update_post_meta($warranty_id, '_service_ticket_id', $body['id']);
            }

        } catch (Exception $e) {
            $this->utils->log_error(
                'خطا در ایجاد تیکت خدمات',
                'error',
                array(
                    'warranty_id' => $warranty_id,
                    'error' => $e->getMessage()
                )
            );
        }
    }

    /**
     * به‌روزرسانی وضعیت تیکت
     *
     * @param int $warranty_id
     * @param int $ticket_id
     * @param string $status
     */
    public function update_ticket_status($warranty_id, $ticket_id, $status) {
        try {
            $ticketing_api_url = $this->integration_settings['ticketing']['api_url'];
            $ticketing_api_key = $this->integration_settings['ticketing']['api_key'];

            // به‌روزرسانی وضعیت تیکت
            $response = wp_remote_request($ticketing_api_url . '/tickets/' . $ticket_id, array(
                'method' => 'PUT',
                'headers' => array(
                    'Authorization' => 'Bearer ' . $ticketing_api_key,
                    'Content-Type' => 'application/json'
                ),
                'body' => wp_json_encode(array(
                    'status' => $status
                ))
            ));

            if (is_wp_error($response)) {
                throw new Exception($response->get_error_message());
            }

        } catch (Exception $e) {
            $this->utils->log_error(
                'خطا در به‌روزرسانی وضعیت تیکت',
                'error',
                array(
                    'warranty_id' => $warranty_id,
                    'ticket_id' => $ticket_id,
                    'error' => $e->getMessage()
                )
            );
        }
    }

    /**
     * ارسال پیامک وضعیت
     *
     * @param int $warranty_id
     * @param string $status
     * @param array $warranty_data
     */
    public function send_status_sms($warranty_id, $status, $warranty_data) {
        try {
            $mobile = get_user_meta($warranty_data['user_id'], 'billing_phone', true);
            if (!$mobile) {
                return;
            }

            $template = '';
            switch ($status) {
                case 'active':
                    $template = $this->integration_settings['sms']['templates']['warranty_created'];
                    break;
                case 'expired':
                    $template = $this->integration_settings['sms']['templates']['warranty_expired'];
                    break;
            }

            if ($template) {
                // جایگزینی متغیرها در قالب
                $message = str_replace(
                    array('{product_name}', '{serial_number}', '{end_date}'),
                    array(
                        get_the_title($warranty_data['product_id']),
                        $warranty_data['serial_number'],
                        $this->utils->gregorian_to_jalali($warranty_data['end_date'])
                    ),
                    $template
                );

                $this->utils->send_sms($mobile, $message);
            }

        } catch (Exception $e) {
            $this->utils->log_error(
                'خطا در ارسال پیامک وضعیت',
                'error',
                array(
                    'warranty_id' => $warranty_id,
                    'error' => $e->getMessage()
                )
            );
        }
    }

    /**
     * ارسال پیامک یادآوری انقضا
     *
     * @param int $warranty_id
     * @param array $warranty_data
     */
    public function send_expiry_reminder_sms($warranty_id, $warranty_data) {
        try {
            $mobile = get_user_meta($warranty_data['user_id'], 'billing_phone', true);
            if (!$mobile) {
                return;
            }

            $days_left = floor((strtotime($warranty_data['end_date']) - time()) / DAY_IN_SECONDS);
            
            $message = sprintf(
                'مشتری گرامی، گارانتی محصول %s با شماره سریال %s تا %d روز دیگر منقضی می‌شود.',
                get_the_title($warranty_data['product_id']),
                $warranty_data['serial_number'],
                $days_left
            );

            $this->utils->send_sms($mobile, $message);

        } catch (Exception $e) {
            $this->utils->log_error(
                'خطا در ارسال پیامک یادآوری',
                'error',
                array(
                    'warranty_id' => $warranty_id,
                    'error' => $e->getMessage()
                )
            );
        }
    }

    /**
     * همگام‌سازی پرداخت با سیستم حسابداری
     *
     * @param int $warranty_id
     * @param array $payment_data
     * @param array $warranty_data
     */
    public function sync_payment_to_accounting($warranty_id, $payment_data, $warranty_data) {
        try {
            $accounting_api_url = $this->integration_settings['accounting']['api_url'];
            $accounting_api_key = $this->integration_settings['accounting']['api_key'];

            // محاسبه مالیات
            $tax_amount = 0;
            if ($this->integration_settings['accounting']['auto_invoice']) {
                $tax_rate = $this->integration_settings['accounting']['default_tax_rate'];
                $tax_amount = ($payment_data['amount'] * $tax_rate) / 100;
            }

            // آماده‌سازی داده‌های مالی
            $invoice_data = array(
                'warranty_id' => $warranty_id,
                'customer_id' => $warranty_data['user_id'],
                'amount' => $payment_data['amount'],
                'tax_amount' => $tax_amount,
                'total_amount' => $payment_data['amount'] + $tax_amount,
                'payment_method' => $payment_data['method'],
                'transaction_id' => $payment_data['transaction_id'],
                'date' => current_time('mysql'),
                'description' => sprintf(
                    'هزینه گارانتی محصول %s - شماره سریال: %s',
                    get_the_title($warranty_data['product_id']),
                    $warranty_data['serial_number']
                )
            );

            // ارسال به سیستم حسابداری
            $response = wp_remote_post($accounting_api_url . '/invoices', array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $accounting_api_key,
                    'Content-Type' => 'application/json'
                ),
                'body' => wp_json_encode($invoice_data)
            ));

            if (is_wp_error($response)) {
                throw new Exception($response->get_error_message());
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            
            if (!empty($body['id'])) {
                update_post_meta($warranty_id, '_accounting_invoice_id', $body['id']);
            }

        } catch (Exception $e) {
            $this->utils->log_error(
                'خطا در همگام‌سازی با سیستم حسابداری',
                'error',
                array(
                    'warranty_id' => $warranty_id,
                    'error' => $e->getMessage()
                )
            );
        }
    }

    /**
     * به‌روزرسانی تنظیمات یکپارچه‌سازی
     *
     * @param array $new_settings
     * @return bool
     */
    public function update_integration_settings($new_settings) {
        $this->integration_settings = wp_parse_args($new_settings, $this->get_default_settings());
        return update_option('asg_integration_settings', $this->integration_settings);
    }

    /**
     * بررسی وضعیت اتصال به سرویس‌ها
     *
     * @return array
     */
    public function check_connections() {
        $status = array();

        // بررسی اتصال به CRM
        if ($this->integration_settings['crm']['enabled']) {
            $status['crm'] = $this->test_crm_connection();
        }

        // بررسی اتصال به سیستم تیکتینگ
        if ($this->integration_settings['ticketing']['enabled']) {
            $status['ticketing'] = $this->test_ticketing_connection();
        }

        // بررسی اتصال به سرویس پیامک
        if ($this->integration_settings['sms']['enabled']) {
            $status['sms'] = $this->test_sms_connection();
        }

        // بررسی اتصال به سیستم حسابداری
        if ($this->integration_settings['accounting']['enabled']) {
            $status['accounting'] = $this->test_accounting_connection();
        }

        return $status;
    }

    /**
     * تست اتصال به CRM
     *
     * @return bool
     */
    private function test_crm_connection() {
        try {
            $response = wp_remote_get($this->integration_settings['crm']['api_url'] . '/test', array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->integration_settings['crm']['api_key']
                )
            ));

            return !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200;

        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * تست اتصال به سیستم تیکتینگ
     *
     * @return bool
     */
    private function test_ticketing_connection() {
        try {
            $response = wp_remote_get($this->integration_settings['ticketing']['api_url'] . '/test', array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->integration_settings['ticketing']['api_key']
                )
            ));

            return !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200;

        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * تست اتصال به سرویس پیامک
     *
     * @return bool
     */
    private function test_sms_connection() {
        try {
            // بررسی اعتبار API کلید
            $response = wp_remote_get('https://api.kavenegar.com/v1/' . $this->integration_settings['sms']['api_key'] . '/account/info.json');

            return !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200;

        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * تست اتصال به سیستم حسابداری
     *
     * @return bool
     */
    private function test_accounting_connection() {
        try {
            $response = wp_remote_get($this->integration_settings['accounting']['api_url'] . '/test', array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->integration_settings['accounting']['api_key']
                )
            ));

            return !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200;

        } catch (Exception $e) {
            return false;
        }
    }
}