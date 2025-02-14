<?php
/**
 * کلاس بهینه‌سازی asset های افزونه
 *
 * @package After_Sales_Guarantee
 * @since 1.8
 * @author Arman MJ
 * @last_modified 2025-02-14
 */

if (!defined('ABSPATH')) {
    exit;
}

class ASG_Assets_Optimizer {
    /**
     * نمونه singleton
     *
     * @var ASG_Assets_Optimizer
     */
    private static $instance = null;

    /**
     * تنظیمات بهینه‌سازی
     *
     * @var array
     */
    private $optimization_settings;

    /**
     * مسیر دایرکتوری کش
     *
     * @var string
     */
    private $cache_dir;

    /**
     * دریافت نمونه کلاس
     *
     * @return ASG_Assets_Optimizer
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
        $this->optimization_settings = get_option('asg_optimization_settings', $this->get_default_settings());
        $this->cache_dir = WP_CONTENT_DIR . '/cache/asg-assets';

        // ایجاد دایرکتوری کش اگر وجود ندارد
        if (!file_exists($this->cache_dir)) {
            wp_mkdir_p($this->cache_dir);
        }

        // اضافه کردن هوک‌ها
        add_action('wp_enqueue_scripts', array($this, 'optimize_front_assets'), 999);
        add_action('admin_enqueue_scripts', array($this, 'optimize_admin_assets'), 999);
        add_action('wp_footer', array($this, 'defer_javascript'), 999);
        add_filter('style_loader_tag', array($this, 'add_preload_to_styles'), 10, 4);
        
        // پاکسازی کش به صورت دوره‌ای
        add_action('asg_clear_assets_cache', array($this, 'clear_cache'));
        if (!wp_next_scheduled('asg_clear_assets_cache')) {
            wp_schedule_event(time(), 'daily', 'asg_clear_assets_cache');
        }
    }

    /**
     * تنظیمات پیش‌فرض بهینه‌سازی
     *
     * @return array
     */
    private function get_default_settings() {
        return array(
            'minify_css' => true,
            'minify_js' => true,
            'combine_css' => true,
            'combine_js' => true,
            'defer_js' => true,
            'preload_css' => true,
            'exclude_files' => array(),
            'cache_time' => 86400 // 24 ساعت
        );
    }

    /**
     * بهینه‌سازی asset های قسمت کاربری
     */
    public function optimize_front_assets() {
        if (!is_admin()) {
            global $wp_styles, $wp_scripts;

            // بهینه‌سازی CSS
            if ($this->optimization_settings['minify_css'] || $this->optimization_settings['combine_css']) {
                $this->optimize_styles($wp_styles, 'front');
            }

            // بهینه‌سازی JavaScript
            if ($this->optimization_settings['minify_js'] || $this->optimization_settings['combine_js']) {
                $this->optimize_scripts($wp_scripts, 'front');
            }
        }
    }

    /**
     * بهینه‌سازی asset های قسمت مدیریت
     */
    public function optimize_admin_assets() {
        if (is_admin()) {
            global $wp_styles, $wp_scripts;

            // بهینه‌سازی CSS
            if ($this->optimization_settings['minify_css'] || $this->optimization_settings['combine_css']) {
                $this->optimize_styles($wp_styles, 'admin');
            }

            // بهینه‌سازی JavaScript
            if ($this->optimization_settings['minify_js'] || $this->optimization_settings['combine_js']) {
                $this->optimize_scripts($wp_scripts, 'admin');
            }
        }
    }

    /**
     * بهینه‌سازی استایل‌ها
     *
     * @param WP_Styles $wp_styles
     * @param string $context
     */
    private function optimize_styles($wp_styles, $context) {
        $handles = array();
        $excluded = $this->optimization_settings['exclude_files'];

        // جمع‌آوری فایل‌های CSS
        foreach ($wp_styles->queue as $handle) {
            if (!in_array($handle, $excluded)) {
                $handles[] = $handle;
            }
        }

        if (empty($handles)) {
            return;
        }

        // ایجاد نام فایل کش
        $cache_key = md5(implode('', $handles) . $context . ASG_VERSION);
        $cache_file = $this->cache_dir . "/styles-{$cache_key}.css";

        // بررسی کش
        if (!file_exists($cache_file) || (time() - filemtime($cache_file) > $this->optimization_settings['cache_time'])) {
            $content = '';

            foreach ($handles as $handle) {
                $src = $wp_styles->registered[$handle]->src;
                if (strpos($src, '//') === 0) {
                    $src = 'https:' . $src;
                }
                
                // دریافت محتوای CSS
                $file_content = $this->get_file_content($src);
                
                if ($file_content) {
                    // بهینه‌سازی CSS
                    if ($this->optimization_settings['minify_css']) {
                        $file_content = $this->minify_css($file_content);
                    }
                    
                    $content .= "/* {$handle} */\n" . $file_content . "\n";
                }
            }

            // ذخیره در کش
            file_put_contents($cache_file, $content);
        }

        // حذف فایل‌های اصلی و اضافه کردن فایل بهینه شده
        foreach ($handles as $handle) {
            wp_dequeue_style($handle);
        }

        wp_enqueue_style(
            'asg-optimized-' . $context,
            content_url('cache/asg-assets/styles-' . $cache_key . '.css'),
            array(),
            ASG_VERSION
        );
    }

    /**
     * بهینه‌سازی اسکریپت‌ها
     *
     * @param WP_Scripts $wp_scripts
     * @param string $context
     */
    private function optimize_scripts($wp_scripts, $context) {
        $handles = array();
        $excluded = $this->optimization_settings['exclude_files'];

        // جمع‌آوری فایل‌های JavaScript
        foreach ($wp_scripts->queue as $handle) {
            if (!in_array($handle, $excluded)) {
                $handles[] = $handle;
            }
        }

        if (empty($handles)) {
            return;
        }

        // ایجاد نام فایل کش
        $cache_key = md5(implode('', $handles) . $context . ASG_VERSION);
        $cache_file = $this->cache_dir . "/scripts-{$cache_key}.js";

        // بررسی کش
        if (!file_exists($cache_file) || (time() - filemtime($cache_file) > $this->optimization_settings['cache_time'])) {
            $content = '';

            foreach ($handles as $handle) {
                $src = $wp_scripts->registered[$handle]->src;
                if (strpos($src, '//') === 0) {
                    $src = 'https:' . $src;
                }
                
                // دریافت محتوای JavaScript
                $file_content = $this->get_file_content($src);
                
                if ($file_content) {
                    // بهینه‌سازی JavaScript
                    if ($this->optimization_settings['minify_js']) {
                        $file_content = $this->minify_js($file_content);
                    }
                    
                    $content .= "/* {$handle} */\n" . $file_content . "\n";
                }
            }

            // ذخیره در کش
            file_put_contents($cache_file, $content);
        }

        // حذف فایل‌های اصلی و اضافه کردن فایل بهینه شده
        foreach ($handles as $handle) {
            wp_dequeue_script($handle);
        }

        wp_enqueue_script(
            'asg-optimized-' . $context,
            content_url('cache/asg-assets/scripts-' . $cache_key . '.js'),
            array(),
            ASG_VERSION,
            $this->optimization_settings['defer_js']
        );
    }

    /**
     * اضافه کردن defer به اسکریپت‌ها
     */
    public function defer_javascript() {
        if ($this->optimization_settings['defer_js']) {
            ?>
            <script type="text/javascript">
                function asgDeferJS(url) {
                    var script = document.createElement('script');
                    script.src = url;
                    document.body.appendChild(script);
                }
                window.addEventListener('load', function() {
                    var scripts = document.getElementsByTagName('script');
                    for (var i = 0; i < scripts.length; i++) {
                        if (scripts[i].getAttribute('data-defer') === 'true') {
                            asgDeferJS(scripts[i].getAttribute('src'));
                            scripts[i].parentNode.removeChild(scripts[i]);
                        }
                    }
                });
            </script>
            <?php
        }
    }

    /**
     * اضافه کردن preload به استایل‌ها
     *
     * @param string $html
     * @param string $handle
     * @param string $href
     * @param string $media
     * @return string
     */
    public function add_preload_to_styles($html, $handle, $href, $media) {
        if ($this->optimization_settings['preload_css'] && strpos($handle, 'asg-') === 0) {
            $html = "<link rel='preload' as='style' href='$href' media='$media' onload=\"this.onload=null;this.rel='stylesheet'\">
                    <noscript>$html</noscript>";
        }
        return $html;
    }

    /**
     * دریافت محتوای فایل
     *
     * @param string $url
     * @return string|bool
     */
    private function get_file_content($url) {
        if (strpos($url, content_url()) !== false) {
            $path = str_replace(content_url(), WP_CONTENT_DIR, $url);
            return file_exists($path) ? file_get_contents($path) : false;
        }

        $response = wp_remote_get($url);
        return (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) ? 
                wp_remote_retrieve_body($response) : false;
    }

    /**
     * بهینه‌سازی CSS
     *
     * @param string $css
     * @return string
     */
    private function minify_css($css) {
        // حذف کامنت‌ها
        $css = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $css);
        
        // حذف فضاهای خالی
        $css = str_replace(array("\r\n", "\r", "\n", "\t"), '', $css);
        $css = preg_replace('/\s+/', ' ', $css);
        
        // حذف فضاهای اضافی
        $css = str_replace(array(' {', '{ '), '{', $css);
        $css = str_replace(array(' }', '} '), '}', $css);
        $css = str_replace(array('; ', ' ;'), ';', $css);
        $css = str_replace(array(': ', ' :'), ':', $css);
        $css = str_replace(array(', ', ' ,'), ',', $css);
        
        return trim($css);
    }

    /**
     * بهینه‌سازی JavaScript
     *
     * @param string $js
     * @return string
     */
    private function minify_js($js) {
        // از JShrink استفاده می‌کنیم
        if (!class_exists('JShrink\Minifier')) {
            require_once ASG_PLUGIN_DIR . 'includes/libs/jshrink/Minifier.php';
        }

        try {
            return \JShrink\Minifier::minify($js);
        } catch (Exception $e) {
            error_log('ASG JS Minification Error: ' . $e->getMessage());
            return $js;
        }
    }

    /**
     * پاکسازی کش
     */
    public function clear_cache() {
        $files = glob($this->cache_dir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /**
     * به‌روزرسانی تنظیمات بهینه‌سازی
     *
     * @param array $new_settings
     * @return bool
     */
    public function update_optimization_settings($new_settings) {
        $this->optimization_settings = wp_parse_args($new_settings, $this->get_default_settings());
        $this->clear_cache(); // پاکسازی کش قدیمی
        return update_option('asg_optimization_settings', $this->optimization_settings);
    }
}