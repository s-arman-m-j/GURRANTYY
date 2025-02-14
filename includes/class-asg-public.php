<?php
/**
 * کلاس مدیریت بخش عمومی افزونه
 *
 * @package After_Sales_Guarantee
 * @since 1.8
 * @author Arman MJ
 * @last_modified 2025-02-14
 */

if (!defined('ABSPATH')) {
    exit;
}

class ASG_Public {
    /**
     * نمونه singleton
     *
     * @var ASG_Public
     */
    private static $instance = null;

    /**
     * نمونه‌های کلاس‌های مورد نیاز
     */
    private $warranty;
    private $utils;

    /**
     * دریافت نمونه کلاس
     *
     * @return ASG_Public
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

        // اضافه کردن شورت‌کدها
        add_shortcode('warranty_check', array($this, 'warranty_check_shortcode'));
        add_shortcode('warranty_register', array($this, 'warranty_register_shortcode'));
        add_shortcode('warranty_status', array($this, 'warranty_status_shortcode'));

        // اضافه کردن اکشن‌ها
        add_action('wp_enqueue_scripts', array($this, 'enqueue_public_assets'));
        add_action('wp_ajax_check_warranty', array($this, 'handle_warranty_check'));
        add_action('wp_ajax_nopriv_check_warranty', array($this, 'handle_warranty_check'));
        add_action('wp_ajax_register_warranty', array($this, 'handle_warranty_registration'));
        add_action('wp_ajax_nopriv_register_warranty', array($this, 'handle_warranty_registration'));

        // اضافه کردن تب گارانتی به حساب کاربری
        add_action('init', array($this, 'add_warranty_endpoint'));
        add_filter('woocommerce_account_menu_items', array($this, 'add_warranty_menu_item'));
        add_action('woocommerce_account_warranties_endpoint', array($this, 'warranty_account_content'));
    }

    /**
     * افزودن اسکریپت‌ها و استایل‌های عمومی
     */
    public function enqueue_public_assets() {
        // استایل‌ها
        wp_enqueue_style(
            'asg-public-styles',
            ASG_PLUGIN_URL . 'assets/css/public.css',
            array(),
            ASG_VERSION
        );

        // اسکریپت‌ها
        wp_enqueue_script(
            'asg-public-scripts',
            ASG_PLUGIN_URL . 'assets/js/public.js',
            array('jquery'),
            ASG_VERSION,
            true
        );

        wp_localize_script('asg-public-scripts', 'asgPublic', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('asg_public_nonce'),
            'messages' => array(
                'error' => 'خطایی رخ داد. لطفاً دوباره تلاش کنید.',
                'required' => 'لطفاً تمام فیلدهای الزامی را پر کنید.',
                'success' => 'عملیات با موفقیت انجام شد.'
            )
        ));
    }

    /**
     * شورت‌کد بررسی گارانتی
     *
     * @param array $atts
     * @return string
     */
    public function warranty_check_shortcode($atts) {
        ob_start();
        ?>
        <div class="warranty-check-form">
            <h3>بررسی وضعیت گارانتی</h3>
            <form id="warranty-check-form" method="post">
                <div class="form-row">
                    <label for="serial_number">شماره سریال محصول:</label>
                    <input type="text" id="serial_number" name="serial_number" required>
                </div>
                <div class="form-row">
                    <button type="submit" class="button">بررسی گارانتی</button>
                </div>
            </form>
            <div id="warranty-check-result" style="display: none;"></div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * شورت‌کد ثبت گارانتی
     *
     * @param array $atts
     * @return string
     */
    public function warranty_register_shortcode($atts) {
        if (!is_user_logged_in()) {
            return '<p>لطفاً برای ثبت گارانتی ابتدا وارد حساب کاربری خود شوید.</p>';
        }

        ob_start();
        ?>
        <div class="warranty-register-form">
            <h3>ثبت گارانتی جدید</h3>
            <form id="warranty-register-form" method="post">
                <div class="form-row">
                    <label for="product_id">محصول:</label>
                    <select id="product_id" name="product_id" required>
                        <option value="">انتخاب محصول</option>
                        <?php
                        $products = wc_get_products(array('status' => 'publish'));
                        foreach ($products as $product) {
                            if ($this->warranty->product_has_warranty($product->get_id())) {
                                printf(
                                    '<option value="%d">%s</option>',
                                    $product->get_id(),
                                    $product->get_name()
                                );
                            }
                        }
                        ?>
                    </select>
                </div>
                <div class="form-row">
                    <label for="serial_number">شماره سریال:</label>
                    <input type="text" id="serial_number" name="serial_number" required>
                </div>
                <div class="form-row">
                    <label for="purchase_date">تاریخ خرید:</label>
                    <input type="date" id="purchase_date" name="purchase_date" required>
                </div>
                <div class="form-row">
                    <button type="submit" class="button">ثبت گارانتی</button>
                </div>
            </form>
            <div id="warranty-register-result" style="display: none;"></div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * شورت‌کد نمایش وضعیت گارانتی
     *
     * @param array $atts
     * @return string
     */
    public function warranty_status_shortcode($atts) {
        $atts = shortcode_atts(array(
            'warranty_id' => 0
        ), $atts);

        if (!$atts['warranty_id']) {
            return '';
        }

        $warranty = $this->warranty->get_warranty($atts['warranty_id']);
        if (!$warranty) {
            return '<p>گارانتی مورد نظر یافت نشد.</p>';
        }

        ob_start();
        ?>
        <div class="warranty-status-container">
            <h3>وضعیت گارانتی</h3>
            <table class="warranty-details">
                <tr>
                    <th>شماره سریال:</th>
                    <td><?php echo esc_html($warranty->serial_number); ?></td>
                </tr>
                <tr>
                    <th>نوع گارانتی:</th>
                    <td><?php echo esc_html($warranty->warranty_type); ?></td>
                </tr>
                <tr>
                    <th>تاریخ شروع:</th>
                    <td><?php echo esc_html($this->utils->gregorian_to_jalali($warranty->start_date)); ?></td>
                </tr>
                <tr>
                    <th>تاریخ پایان:</th>
                    <td><?php echo esc_html($this->utils->gregorian_to_jalali($warranty->end_date)); ?></td>
                </tr>
                <tr>
                    <th>وضعیت:</th>
                    <td>
                        <span class="warranty-status status-<?php echo esc_attr($warranty->status); ?>">
                            <?php echo esc_html($warranty->status); ?>
                        </span>
                    </td>
                </tr>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * افزودن endpoint گارانتی به حساب کاربری
     */
    public function add_warranty_endpoint() {
        add_rewrite_endpoint('warranties', EP_ROOT | EP_PAGES);
    }

    /**
     * افزودن منوی گارانتی به حساب کاربری
     *
     * @param array $items
     * @return array
     */
    public function add_warranty_menu_item($items) {
        $new_items = array();
        
        foreach ($items as $key => $value) {
            $new_items[$key] = $value;
            if ($key === 'orders') {
                $new_items['warranties'] = 'گارانتی‌های من';
            }
        }
        
        return $new_items;
    }

    /**
     * نمایش محتوای صفحه گارانتی در حساب کاربری
     */
    public function warranty_account_content() {
        $user_id = get_current_user_id();
        
        // دریافت لیست گارانتی‌های کاربر
        global $wpdb;
        $warranties = $wpdb->get_results($wpdb->prepare("
            SELECT w.*, p.post_title as product_name
            FROM {$wpdb->prefix}asg_warranty_registrations w
            LEFT JOIN {$wpdb->posts} p ON w.product_id = p.ID
            WHERE w.user_id = %d
            ORDER BY w.created_at DESC
        ", $user_id));

        include ASG_PLUGIN_DIR . 'templates/public/my-warranties.php';
    }

    /**
     * پردازش بررسی گارانتی
     */
    public function handle_warranty_check() {
        check_ajax_referer('asg_public_nonce');

        $serial_number = sanitize_text_field($_POST['serial_number']);
        
        if (empty($serial_number)) {
            wp_send_json_error('لطفاً شماره سریال را وارد کنید.');
        }

        global $wpdb;
        $warranty = $wpdb->get_row($wpdb->prepare("
            SELECT w.*, p.post_title as product_name
            FROM {$wpdb->prefix}asg_warranty_registrations w
            LEFT JOIN {$wpdb->posts} p ON w.product_id = p.ID
            WHERE w.serial_number = %s
        ", $serial_number));

        if (!$warranty) {
            wp_send_json_error('گارانتی با این شماره سریال یافت نشد.');
        }

        $response = array(
            'product' => $warranty->product_name,
            'type' => $warranty->warranty_type,
            'start_date' => $this->utils->gregorian_to_jalali($warranty->start_date),
            'end_date' => $this->utils->gregorian_to_jalali($warranty->end_date),
            'status' => $warranty->status,
            'is_valid' => strtotime($warranty->end_date) > current_time('timestamp')
        );

        wp_send_json_success($response);
    }

    /**
     * پردازش ثبت گارانتی
     */
    public function handle_warranty_registration() {
        check_ajax_referer('asg_public_nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error('لطفاً ابتدا وارد حساب کاربری خود شوید.');
        }

        $product_id = absint($_POST['product_id']);
        $serial_number = sanitize_text_field($_POST['serial_number']);
        $purchase_date = sanitize_text_field($_POST['purchase_date']);

        if (!$product_id || !$serial_number || !$purchase_date) {
            wp_send_json_error('لطفاً تمام فیلدهای الزامی را پر کنید.');
        }

        // بررسی تکراری نبودن شماره سریال
        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) 
            FROM {$wpdb->prefix}asg_warranty_registrations 
            WHERE serial_number = %s
        ", $serial_number));

        if ($exists) {
            wp_send_json_error('این شماره سریال قبلاً ثبت شده است.');
        }

        // ایجاد گارانتی جدید
        $warranty_data = array(
            'product_id' => $product_id,
            'user_id' => get_current_user_id(),
            'serial_number' => $serial_number,
            'start_date' => $purchase_date,
            'warranty_type' => get_post_meta($product_id, '_warranty_type', true),
            'warranty_duration' => get_post_meta($product_id, '_warranty_duration', true),
            'status' => 'active'
        );

        $result = $this->warranty->create_warranty($warranty_data);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success('گارانتی با موفقیت ثبت شد.');
    }
}