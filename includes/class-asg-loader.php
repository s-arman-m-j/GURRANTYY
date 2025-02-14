<?php
/**
 * کلاس مدیریت بارگذاری ماژول‌های افزونه
 * 
 * @package After_Sales_Guarantee
 * @version 1.8
 * @since 1.8
 * @author Arman MJ
 */

if (!defined('ABSPATH')) {
    exit;
}

class ASG_Loader {
    /**
     * نمونه singleton
     */
    private static $instance = null;

    /**
     * ماژول‌های بارگذاری شده
     */
    private $loaded_modules = array();

    /**
     * صفحه فعلی
     */
    private $current_page = '';

    /**
     * دریافت نمونه کلاس
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
        add_action('init', array($this, 'determine_current_page'), 1);
        add_action('init', array($this, 'load_required_modules'), 5);
    }

    /**
     * تشخیص صفحه فعلی
     */
    public function determine_current_page() {
        if (is_admin()) {
            $page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';
            $this->current_page = $page;
        } else {
            global $wp;
            $this->current_page = home_url($wp->request);
        }
    }

    /**
     * بارگذاری ماژول‌های مورد نیاز
     */
    public function load_required_modules() {
        // کامپوننت‌های اصلی که همیشه باید لود شوند
        $this->load_module('security', 'ASG_Security');
        $this->load_module('db', 'ASG_DB');
        $this->load_module('performance', 'ASG_Performance');

        // بارگذاری بر اساس صفحه
        switch ($this->current_page) {
            case 'warranty-management':
                $this->load_module('api', 'ASG_API');
                $this->load_module('notifications', 'ASG_Notifications');
                break;

            case 'warranty-management-reports':
                $this->load_module('reports', 'ASG_Reports');
                break;

            default:
                if ($this->is_warranty_page()) {
                    $this->load_module('api', 'ASG_API');
                    $this->load_module('notifications', 'ASG_Notifications');
                }
                break;
        }

        // بهینه‌ساز assets همیشه در آخر لود شود
        $this->load_module('assets-optimizer', 'ASG_Assets_Optimizer');
    }

    /**
     * بارگذاری یک ماژول
     */
    private function load_module($module_name, $class_name) {
        if (!isset($this->loaded_modules[$module_name])) {
            $file = ASG_PLUGIN_DIR . 'includes/class-asg-' . $module_name . '.php';
            
            if (file_exists($file)) {
                require_once $file;
                if (class_exists($class_name)) {
                    $this->loaded_modules[$module_name] = new $class_name();
                }
            }
        }
        return isset($this->loaded_modules[$module_name]) ? $this->loaded_modules[$module_name] : null;
    }

    /**
     * بررسی صفحه گارانتی
     */
    private function is_warranty_page() {
        global $post;
        if (is_singular()) {
            return has_shortcode($post->post_content, 'warranty_form') ||
                   has_shortcode($post->post_content, 'warranty_requests');
        }
        return false;
    }

    /**
     * دریافت یک ماژول
     */
    public function get_module($module_name) {
        return isset($this->loaded_modules[$module_name]) ? $this->loaded_modules[$module_name] : null;
    }
}