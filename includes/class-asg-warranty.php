<?php
/**
 * کلاس اصلی مدیریت گارانتی
 *
 * @package After_Sales_Guarantee
 * @since 1.8
 * @author Arman MJ
 * @last_modified 2025-02-14
 */

if (!defined('ABSPATH')) {
    exit;
}

class ASG_Warranty {
    /**
     * نمونه singleton
     *
     * @var ASG_Warranty
     */
    private static $instance = null;

    /**
     * تنظیمات گارانتی
     *
     * @var array
     */
    private $warranty_settings;

    /**
     * نمونه کلاس ابزارها
     *
     * @var ASG_Utils
     */
    private $utils;

    /**
     * دریافت نمونه کلاس
     *
     * @return ASG_Warranty
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
        $this->warranty_settings = get_option('asg_warranty_settings', $this->get_default_settings());
        $this->utils = ASG_Utils::instance();

        // اضافه کردن متا باکس به محصولات
        add_action('add_meta_boxes', array($this, 'add_warranty_meta_box'));
        add_action('save_post_product', array($this, 'save_warranty_meta_box'));

        // اضافه کردن تب گارانتی به صفحه محصول
        add_filter('woocommerce_product_tabs', array($this, 'add_warranty_product_tab'));

        // اضافه کردن فیلدهای گارانتی به فرم سفارش
        add_action('woocommerce_checkout_after_order_notes', array($this, 'add_warranty_checkout_fields'));
        add_action('woocommerce_checkout_process', array($this, 'validate_warranty_checkout_fields'));
        add_action('woocommerce_checkout_update_order_meta', array($this, 'save_warranty_checkout_fields'));

        // ایجاد گارانتی بعد از تکمیل سفارش
        add_action('woocommerce_order_status_completed', array($this, 'create_warranty_after_order'));

        // اضافه کردن ستون گارانتی به لیست سفارشات
        add_filter('manage_edit-shop_order_columns', array($this, 'add_warranty_order_column'));
        add_action('manage_shop_order_posts_custom_column', array($this, 'display_warranty_order_column'), 10, 2);
    }

    /**
     * تنظیمات پیش‌فرض گارانتی
     *
     * @return array
     */
    private function get_default_settings() {
        return array(
            'default_warranty_duration' => 12, // ماه
            'warranty_types' => array(
                'standard' => array(
                    'title' => 'گارانتی استاندارد',
                    'duration' => 12,
                    'description' => 'گارانتی استاندارد محصول'
                ),
                'gold' => array(
                    'title' => 'گارانتی طلایی',
                    'duration' => 24,
                    'description' => 'گارانتی ویژه با پشتیبانی 24 ساعته'
                )
            ),
            'require_serial_number' => true,
            'require_invoice_number' => true,
            'auto_activate' => true,
            'notification_settings' => array(
                'enable_email' => true,
                'enable_sms' => true,
                'expiry_reminder_days' => array(30, 7, 1)
            )
        );
    }

    /**
     * افزودن متا باکس به محصولات
     */
    public function add_warranty_meta_box() {
        add_meta_box(
            'warranty_settings',
            'تنظیمات گارانتی',
            array($this, 'render_warranty_meta_box'),
            'product',
            'normal',
            'high'
        );
    }

    /**
     * نمایش متا باکس تنظیمات گارانتی
     *
     * @param WP_Post $post
     */
    public function render_warranty_meta_box($post) {
        // دریافت تنظیمات فعلی
        $warranty_type = get_post_meta($post->ID, '_warranty_type', true);
        $warranty_duration = get_post_meta($post->ID, '_warranty_duration', true);
        $warranty_price = get_post_meta($post->ID, '_warranty_price', true);
        
        wp_nonce_field('save_warranty_settings', 'warranty_settings_nonce');
        ?>
        <div class="warranty-settings-container">
            <p>
                <label for="warranty_type">نوع گارانتی:</label>
                <select name="warranty_type" id="warranty_type">
                    <?php foreach ($this->warranty_settings['warranty_types'] as $type => $settings): ?>
                        <option value="<?php echo esc_attr($type); ?>" <?php selected($warranty_type, $type); ?>>
                            <?php echo esc_html($settings['title']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>
            <p>
                <label for="warranty_duration">مدت گارانتی (ماه):</label>
                <input type="number" name="warranty_duration" id="warranty_duration" 
                       value="<?php echo esc_attr($warranty_duration); ?>" min="1" max="120">
            </p>
            <p>
                <label for="warranty_price">هزینه گارانتی:</label>
                <input type="number" name="warranty_price" id="warranty_price" 
                       value="<?php echo esc_attr($warranty_price); ?>" min="0" step="1000">
            </p>
        </div>
        <?php
    }

    /**
     * ذخیره تنظیمات گارانتی محصول
     *
     * @param int $post_id
     */
    public function save_warranty_meta_box($post_id) {
        if (!isset($_POST['warranty_settings_nonce']) || 
            !wp_verify_nonce($_POST['warranty_settings_nonce'], 'save_warranty_settings')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // ذخیره تنظیمات
        if (isset($_POST['warranty_type'])) {
            update_post_meta($post_id, '_warranty_type', sanitize_text_field($_POST['warranty_type']));
        }

        if (isset($_POST['warranty_duration'])) {
            update_post_meta($post_id, '_warranty_duration', absint($_POST['warranty_duration']));
        }

        if (isset($_POST['warranty_price'])) {
            update_post_meta($post_id, '_warranty_price', absint($_POST['warranty_price']));
        }
    }

    /**
     * افزودن تب گارانتی به صفحه محصول
     *
     * @param array $tabs
     * @return array
     */
    public function add_warranty_product_tab($tabs) {
        global $post;

        if ($this->product_has_warranty($post->ID)) {
            $tabs['warranty'] = array(
                'title' => 'گارانتی و خدمات',
                'priority' => 15,
                'callback' => array($this, 'render_warranty_product_tab')
            );
        }

        return $tabs;
    }

    /**
     * نمایش محتوای تب گارانتی
     */
    public function render_warranty_product_tab() {
        global $post;

        $warranty_type = get_post_meta($post->ID, '_warranty_type', true);
        $warranty_duration = get_post_meta($post->ID, '_warranty_duration', true);
        $warranty_settings = $this->warranty_settings['warranty_types'][$warranty_type];
        ?>
        <h2>شرایط گارانتی محصول</h2>
        <div class="warranty-info">
            <p><strong>نوع گارانتی:</strong> <?php echo esc_html($warranty_settings['title']); ?></p>
            <p><strong>مدت گارانتی:</strong> <?php echo esc_html($warranty_duration); ?> ماه</p>
            <p><strong>توضیحات:</strong> <?php echo esc_html($warranty_settings['description']); ?></p>
            
            <div class="warranty-terms">
                <h3>شرایط و ضوابط گارانتی</h3>
                <ul>
                    <li>گارانتی از تاریخ خرید محصول محاسبه می‌شود</li>
                    <li>برای فعال‌سازی گارانتی، ثبت محصول در سایت الزامی است</li>
                    <li>گارانتی شامل ایرادات ناشی از استفاده نادرست نمی‌شود</li>
                    <li>هزینه ارسال محصول به مرکز خدمات بر عهده مشتری است</li>
                </ul>
            </div>
        </div>
        <?php
    }

    /**
     * بررسی وجود گارانتی برای محصول
     *
     * @param int $product_id
     * @return bool
     */
    public function product_has_warranty($product_id) {
        $warranty_type = get_post_meta($product_id, '_warranty_type', true);
        return !empty($warranty_type);
    }

    /**
     * افزودن فیلدهای گارانتی به صفحه تسویه حساب
     *
     * @param WC_Checkout $checkout
     */
    public function add_warranty_checkout_fields($checkout) {
        $cart_has_warranty = false;
        
        foreach (WC()->cart->get_cart() as $cart_item) {
            if ($this->product_has_warranty($cart_item['product_id'])) {
                $cart_has_warranty = true;
                break;
            }
        }

        if ($cart_has_warranty) {
            echo '<div class="warranty-fields">';
            echo '<h3>' . __('اطلاعات گارانتی', 'warranty') . '</h3>';

            if ($this->warranty_settings['require_serial_number']) {
                woocommerce_form_field('warranty_serial_number', array(
                    'type' => 'text',
                    'class' => array('warranty-field'),
                    'label' => __('شماره سریال محصول', 'warranty'),
                    'required' => true
                ), $checkout->get_value('warranty_serial_number'));
            }

            if ($this->warranty_settings['require_invoice_number']) {
                woocommerce_form_field('warranty_invoice_number', array(
                    'type' => 'text',
                    'class' => array('warranty-field'),
                    'label' => __('شماره فاکتور', 'warranty'),
                    'required' => true
                ), $checkout->get_value('warranty_invoice_number'));
            }

            echo '</div>';
        }
    }

    /**
     * اعتبارسنجی فیلدهای گارانتی
     */
    public function validate_warranty_checkout_fields() {
        $cart_has_warranty = false;
        
        foreach (WC()->cart->get_cart() as $cart_item) {
            if ($this->product_has_warranty($cart_item['product_id'])) {
                $cart_has_warranty = true;
                break;
            }
        }

        if ($cart_has_warranty) {
            if ($this->warranty_settings['require_serial_number'] && empty($_POST['warranty_serial_number'])) {
                wc_add_notice(__('لطفاً شماره سریال محصول را وارد کنید.', 'warranty'), 'error');
            }

            if ($this->warranty_settings['require_invoice_number'] && empty($_POST['warranty_invoice_number'])) {
                wc_add_notice(__('لطفاً شماره فاکتور را وارد کنید.', 'warranty'), 'error');
            }
        }
    }

    /**
     * ذخیره اطلاعات گارانتی سفارش
     *
     * @param int $order_id
     */
    public function save_warranty_checkout_fields($order_id) {
        if (!empty($_POST['warranty_serial_number'])) {
            update_post_meta($order_id, '_warranty_serial_number', sanitize_text_field($_POST['warranty_serial_number']));
        }

        if (!empty($_POST['warranty_invoice_number'])) {
            update_post_meta($order_id, '_warranty_invoice_number', sanitize_text_field($_POST['warranty_invoice_number']));
        }
    }

    /**
     * ایجاد گارانتی بعد از تکمیل سفارش
     *
     * @param int $order_id
     */
    public function create_warranty_after_order($order_id) {
        $order = wc_get_order($order_id);
        
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            
            if ($this->product_has_warranty($product_id)) {
                $warranty_data = array(
                    'product_id' => $product_id,
                    'order_id' => $order_id,
                    'user_id' => $order->get_user_id(),
                    'warranty_type' => get_post_meta($product_id, '_warranty_type', true),
                    'warranty_duration' => get_post_meta($product_id, '_warranty_duration', true),
                    'serial_number' => get_post_meta($order_id, '_warranty_serial_number', true),
                    'invoice_number' => get_post_meta($order_id, '_warranty_invoice_number', true),
                    'start_date' => current_time('mysql'),
                    'status' => $this->warranty_settings['auto_activate'] ? 'active' : 'pending'
                );

                $this->create_warranty($warranty_data);
            }
        }
    }

    /**
     * ایجاد گارانتی جدید
     *
     * @param array $data
     * @return int|WP_Error
     */
    public function create_warranty($data) {
        global $wpdb;

        try {
            // بررسی داده‌های ضروری
            if (empty($data['product_id']) || empty($data['user_id'])) {
                throw new Exception('اطلاعات ناقص است');
            }

            // محاسبه تاریخ پایان گارانتی
            $end_date = date('Y-m-d H:i:s', strtotime($data['start_date'] . " +{$data['warranty_duration']} months"));

            // درج در دیتابیس
            $result = $wpdb->insert(
                $wpdb->prefix . 'asg_warranty_registrations',
                array(
                    'product_id' => $data['product_id'],
                    'order_id' => $data['order_id'],
                    'user_id' => $data['user_id'],
                    'warranty_type' => $data['warranty_type'],
                    'warranty_duration' => $data['warranty_duration'],
                    'serial_number' => $data['serial_number'],
                    'invoice_number' => $data['invoice_number'],
                    'start_date' => $data['start_date'],
                    'end_date' => $end_date,
                    'status' => $data['status'],
                    'created_at' => current_time('mysql')
                ),
                array('%d', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s')
            );

            if ($result === false) {
                throw new Exception('خطا در ثبت گارانتی');
            }

            $warranty_id = $wpdb->insert_id;

            // ارسال نوتیفیکیشن
            $this->send_warranty_notifications('new_warranty', $warranty_id);

            // ثبت لاگ
            $this->utils->log_error(
                'گارانتی جدید ایجاد شد',
                'info',
                array(
                    'warranty_id' => $warranty_id,
                    'product_id' => $data['product_id'],
                    'user_id' => $data['user_id']
                )
            );

            return $warranty_id;

        } catch (Exception $e) {
            return new WP_Error('warranty_creation_failed', $e->getMessage());
        }
    }

    /**
     * ارسال نوتیفیکیشن‌های گارانتی
     *
     * @param string $type
     * @param int $warranty_id
     */
    private function send_warranty_notifications($type, $warranty_id) {
        $warranty = $this->get_warranty($warranty_id);
        if (!$warranty) {
            return;
        }

        $user = get_user_by('id', $warranty->user_id);
        if (!$user) {
            return;
        }

        $product = wc_get_product($warranty->product_id);
        if (!$product) {
            return;
        }

        // تنظیم پیام‌ها
        $messages = array(
            'new_warranty' => array(
                'email' => array(
                    'subject' => 'گارانتی محصول شما فعال شد',
                    'message' => sprintf(
                        'گارانتی محصول %s با شماره سریال %s فعال شد. مدت اعتبار: %d ماه',
                        $product->get_name(),
                        $warranty->serial_number,
                        $warranty->warranty_duration
                    )
                ),
                'sms' => sprintf(
                    'گارانتی محصول %s فعال شد. کد رهگیری: %s',
                    $product->get_name(),
                    $warranty->serial_number
                )
            ),
            'expiry_reminder' => array(
                'email' => array(
                    'subject' => 'هشدار اتمام گارانتی',
                    'message' => sprintf(
                        'گارانتی محصول %s تا %s روز دیگر به پایان می‌رسد',
                        $product->get_name(),
                        human_time_diff(strtotime($warranty->end_date))
                    )
                ),
                'sms' => sprintf(
                    'گارانتی محصول %s رو به اتمام است. تاریخ پایان: %s',
                    $product->get_name(),
                    $this->utils->gregorian_to_jalali($warranty->end_date)
                )
            )
        );

        // ارسال ایمیل
        if ($this->warranty_settings['notification_settings']['enable_email']) {
            wp_mail(
                $user->user_email,
                $messages[$type]['email']['subject'],
                $messages[$type]['email']['message'],
                array('Content-Type: text/html; charset=UTF-8')
            );
        }

        // ارسال پیامک
        if ($this->warranty_settings['notification_settings']['enable_sms']) {
            $this->utils->send_sms(
                $user->user_login, // شماره موبایل در فیلد username ذخیره شده
                $messages[$type]['sms']
            );
        }
    }

    /**
     * دریافت اطلاعات گارانتی
     *
     * @param int $warranty_id
     * @return object|null
     */
    public function get_warranty($warranty_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}asg_warranty_registrations WHERE id = %d",
            $warranty_id
        ));
    }

    /**
     * به‌روزرسانی وضعیت گارانتی
     *
     * @param int $warranty_id
     * @param string $status
     * @return bool|WP_Error
     */
    public function update_warranty_status($warranty_id, $status) {
        global $wpdb;

        try {
            $result = $wpdb->update(
                $wpdb->prefix . 'asg_warranty_registrations',
                array('status' => $status),
                array('id' => $warranty_id),
                array('%s'),
                array('%d')
            );

            if ($result === false) {
                throw new Exception('خطا در به‌روزرسانی وضعیت گارانتی');
            }

            // ثبت لاگ
            $this->utils->log_error(
                'وضعیت گارانتی تغییر کرد',
                'info',
                array(
                    'warranty_id' => $warranty_id,
                    'new_status' => $status
                )
            );

            return true;

        } catch (Exception $e) {
            return new WP_Error('warranty_update_failed', $e->getMessage());
        }
    }

    /**
     * افزودن ستون گارانتی به لیست سفارشات
     *
     * @param array $columns
     * @return array
     */
    public function add_warranty_order_column($columns) {
        $new_columns = array();

        foreach ($columns as $key => $column) {
            $new_columns[$key] = $column;
            if ($key === 'order_status') {
                $new_columns['warranty_status'] = __('وضعیت گارانتی', 'warranty');
            }
        }

        return $new_columns;
    }

    /**
     * نمایش وضعیت گارانتی در لیست سفارشات
     *
     * @param string $column
     * @param int $post_id
     */
    public function display_warranty_order_column($column, $post_id) {
        if ($column === 'warranty_status') {
            global $wpdb;
            
            $warranties = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}asg_warranty_registrations WHERE order_id = %d",
                $post_id
            ));

            if ($warranties) {
                foreach ($warranties as $warranty) {
                    $product = wc_get_product($warranty->product_id);
                    if ($product) {
                        echo sprintf(
                            '<span class="warranty-status warranty-status-%s">%s - %s</span><br>',
                            esc_attr($warranty->status),
                            esc_html($product->get_name()),
                            esc_html($warranty->status)
                        );
                    }
                }
            } else {
                echo '<span class="warranty-status warranty-status-none">-</span>';
            }
        }
    }

    /**
     * به‌روزرسانی تنظیمات گارانتی
     *
     * @param array $new_settings
     * @return bool
     */
    public function update_warranty_settings($new_settings) {
        $this->warranty_settings = wp_parse_args($new_settings, $this->get_default_settings());
        return update_option('asg_warranty_settings', $this->warranty_settings);
    }
}