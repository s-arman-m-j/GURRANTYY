<?php
/**
 * کلاس مدیریت REST API افزونه
 *
 * @package After_Sales_Guarantee
 * @since 1.8
 * @author Arman MJ
 * @last_modified 2025-02-14
 */

if (!defined('ABSPATH')) {
    exit;
}

class ASG_API {
    /**
     * نمونه singleton
     *
     * @var ASG_API
     */
    private static $instance = null;

    /**
     * نمونه‌های کلاس‌های مورد نیاز
     */
    private $warranty;
    private $utils;

    /**
     * نسخه API
     *
     * @var string
     */
    private $api_version = 'v1';

    /**
     * دریافت نمونه کلاس
     *
     * @return ASG_API
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

        // ثبت route های API
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    /**
     * ثبت route های API
     */
    public function register_routes() {
        $namespace = 'asg/v1';

        // بررسی وضعیت گارانتی
        register_rest_route($namespace, '/warranty/check', array(
            'methods' => 'GET',
            'callback' => array($this, 'check_warranty'),
            'permission_callback' => '__return_true',
            'args' => array(
                'serial_number' => array(
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                )
            )
        ));

        // ثبت گارانتی جدید
        register_rest_route($namespace, '/warranty/register', array(
            'methods' => 'POST',
            'callback' => array($this, 'register_warranty'),
            'permission_callback' => array($this, 'check_api_auth'),
            'args' => array(
                'product_id' => array(
                    'required' => true,
                    'type' => 'integer'
                ),
                'serial_number' => array(
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'user_id' => array(
                    'required' => true,
                    'type' => 'integer'
                )
            )
        ));

        // به‌روزرسانی وضعیت گارانتی
        register_rest_route($namespace, '/warranty/update/(?P<id>\d+)', array(
            'methods' => 'PUT',
            'callback' => array($this, 'update_warranty'),
            'permission_callback' => array($this, 'check_api_auth'),
            'args' => array(
                'status' => array(
                    'required' => true,
                    'type' => 'string',
                    'enum' => array('active', 'expired', 'revoked', 'pending')
                )
            )
        ));

        // دریافت گزارش
        register_rest_route($namespace, '/reports/(?P<type>[a-zA-Z]+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_report'),
            'permission_callback' => array($this, 'check_api_auth'),
            'args' => array(
                'start_date' => array(
                    'type' => 'string',
                    'format' => 'date'
                ),
                'end_date' => array(
                    'type' => 'string',
                    'format' => 'date'
                )
            )
        ));
    }

    /**
     * بررسی احراز هویت API
     *
     * @param WP_REST_Request $request
     * @return bool|WP_Error
     */
    public function check_api_auth($request) {
        $auth_header = $request->get_header('Authorization');
        
        if (!$auth_header || strpos($auth_header, 'Bearer ') !== 0) {
            return new WP_Error(
                'rest_forbidden',
                'دسترسی غیرمجاز',
                array('status' => 401)
            );
        }

        $token = str_replace('Bearer ', '', $auth_header);
        
        // بررسی اعتبار توکن
        if (!$this->validate_api_token($token)) {
            return new WP_Error(
                'rest_forbidden',
                'توکن نامعتبر است',
                array('status' => 401)
            );
        }

        return true;
    }

    /**
     * اعتبارسنجی توکن API
     *
     * @param string $token
     * @return bool
     */
    private function validate_api_token($token) {
        $valid_tokens = get_option('asg_api_tokens', array());
        return in_array($token, $valid_tokens);
    }

    /**
     * بررسی وضعیت گارانتی
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function check_warranty($request) {
        global $wpdb;

        $serial_number = $request->get_param('serial_number');
        
        $warranty = $wpdb->get_row($wpdb->prepare("
            SELECT w.*, p.post_title as product_name
            FROM {$wpdb->prefix}asg_warranty_registrations w
            LEFT JOIN {$wpdb->posts} p ON w.product_id = p.ID
            WHERE w.serial_number = %s
        ", $serial_number));

        if (!$warranty) {
            return new WP_Error(
                'warranty_not_found',
                'گارانتی با این شماره سریال یافت نشد',
                array('status' => 404)
            );
        }

        return rest_ensure_response(array(
            'id' => $warranty->id,
            'product' => array(
                'id' => $warranty->product_id,
                'name' => $warranty->product_name
            ),
            'serial_number' => $warranty->serial_number,
            'warranty_type' => $warranty->warranty_type,
            'start_date' => $warranty->start_date,
            'end_date' => $warranty->end_date,
            'status' => $warranty->status,
            'is_valid' => strtotime($warranty->end_date) > current_time('timestamp')
        ));
    }

    /**
     * ثبت گارانتی جدید
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function register_warranty($request) {
        $warranty_data = array(
            'product_id' => $request->get_param('product_id'),
            'user_id' => $request->get_param('user_id'),
            'serial_number' => $request->get_param('serial_number'),
            'start_date' => current_time('mysql'),
            'warranty_type' => get_post_meta($request->get_param('product_id'), '_warranty_type', true),
            'warranty_duration' => get_post_meta($request->get_param('product_id'), '_warranty_duration', true),
            'status' => 'active'
        );

        $result = $this->warranty->create_warranty($warranty_data);

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response(array(
            'id' => $result,
            'message' => 'گارانتی با موفقیت ثبت شد'
        ));
    }

    /**
     * به‌روزرسانی وضعیت گارانتی
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function update_warranty($request) {
        $warranty_id = $request->get_param('id');
        $status = $request->get_param('status');

        $result = $this->warranty->update_warranty_status($warranty_id, $status);

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response(array(
            'id' => $warranty_id,
            'status' => $status,
            'message' => 'وضعیت گارانتی با موفقیت به‌روزرسانی شد'
        ));
    }

    /**
     * دریافت گزارش
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_report($request) {
        $type = $request->get_param('type');
        $start_date = $request->get_param('start_date');
        $end_date = $request->get_param('end_date');

        switch ($type) {
            case 'summary':
                $data = $this->get_summary_report($start_date, $end_date);
                break;
            
            case 'products':
                $data = $this->get_products_report($start_date, $end_date);
                break;
            
            case 'warranty_types':
                $data = $this->get_warranty_types_report($start_date, $end_date);
                break;
            
            default:
                return new WP_Error(
                    'invalid_report_type',
                    'نوع گزارش نامعتبر است',
                    array('status' => 400)
                );
        }

        return rest_ensure_response($data);
    }

    /**
     * دریافت گزارش خلاصه
     *
     * @param string $start_date
     * @param string $end_date
     * @return array
     */
    private function get_summary_report($start_date, $end_date) {
        global $wpdb;

        $where = '';
        if ($start_date && $end_date) {
            $where = $wpdb->prepare(
                "WHERE created_at BETWEEN %s AND %s",
                $start_date,
                $end_date
            );
        }

        return array(
            'total_warranties' => $wpdb->get_var("
                SELECT COUNT(*) 
                FROM {$wpdb->prefix}asg_warranty_registrations 
                $where
            "),
            'active_warranties' => $wpdb->get_var("
                SELECT COUNT(*) 
                FROM {$wpdb->prefix}asg_warranty_registrations 
                WHERE status = 'active' $where
            "),
            'expired_warranties' => $wpdb->get_var("
                SELECT COUNT(*) 
                FROM {$wpdb->prefix}asg_warranty_registrations 
                WHERE status = 'expired' $where
            ")
        );
    }

    /**
     * دریافت گزارش محصولات
     *
     * @param string $start_date
     * @param string $end_date
     * @return array
     */
    private function get_products_report($start_date, $end_date) {
        global $wpdb;

        $where = '';
        if ($start_date && $end_date) {
            $where = $wpdb->prepare(
                "WHERE w.created_at BETWEEN %s AND %s",
                $start_date,
                $end_date
            );
        }

        return $wpdb->get_results("
            SELECT 
                p.ID as product_id,
                p.post_title as product_name,
                COUNT(*) as total_warranties,
                COUNT(CASE WHEN w.status = 'active' THEN 1 END) as active_warranties
            FROM {$wpdb->prefix}asg_warranty_registrations w
            LEFT JOIN {$wpdb->posts} p ON w.product_id = p.ID
            $where
            GROUP BY p.ID
            ORDER BY total_warranties DESC
        ");
    }

    /**
     * دریافت گزارش انواع گارانتی
     *
     * @param string $start_date
     * @param string $end_date
     * @return array
     */
    private function get_warranty_types_report($start_date, $end_date) {
        global $wpdb;

        $where = '';
        if ($start_date && $end_date) {
            $where = $wpdb->prepare(
                "WHERE created_at BETWEEN %s AND %s",
                $start_date,
                $end_date
            );
        }

        return $wpdb->get_results("
            SELECT 
                warranty_type,
                COUNT(*) as total,
                COUNT(CASE WHEN status = 'active' THEN 1 END) as active,
                COUNT(CASE WHEN status = 'expired' THEN 1 END) as expired
            FROM {$wpdb->prefix}asg_warranty_registrations
            $where
            GROUP BY warranty_type
        ");
    }
}