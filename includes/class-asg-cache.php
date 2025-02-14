<?php
if (!defined('ABSPATH')) {
    exit;
}

class ASG_Cache {
    private $cache_group = 'asg_cache';
    private $cache_time = 3600; // یک ساعت
    private $user_cache_group;

    public function __construct() {
        // تنظیم گروه کش برای هر کاربر
        $this->user_cache_group = $this->cache_group . '_' . get_current_user_id();
        
        // پاک کردن کش در هنگام به‌روزرسانی گارانتی
        add_action('asg_after_guarantee_update', array($this, 'clear_guarantee_cache'));
        add_action('asg_after_guarantee_delete', array($this, 'clear_guarantee_cache'));
        add_action('asg_after_note_add', array($this, 'clear_notes_cache'));
    }

    /**
     * دریافت مقدار از کش
     */
    public function get($key, $user_specific = false) {
        $group = $user_specific ? $this->user_cache_group : $this->cache_group;
        return wp_cache_get($key, $group);
    }

    /**
     * ذخیره مقدار در کش
     */
    public function set($key, $value, $user_specific = false) {
        $group = $user_specific ? $this->user_cache_group : $this->cache_group;
        return wp_cache_set($key, $value, $group, $this->cache_time);
    }

    /**
     * حذف یک مقدار از کش
     */
    public function delete($key, $user_specific = false) {
        $group = $user_specific ? $this->user_cache_group : $this->cache_group;
        return wp_cache_delete($key, $group);
    }

    /**
     * پاک کردن کش گارانتی
     */
    public function clear_guarantee_cache($guarantee_id = null) {
        if ($guarantee_id) {
            $this->delete('guarantee_' . $guarantee_id);
            $this->delete('guarantee_notes_' . $guarantee_id);
        } else {
            // پاک کردن تمام کش‌های مرتبط با گارانتی
            wp_cache_flush();
        }
    }

    /**
     * پاک کردن کش یادداشت‌ها
     */
    public function clear_notes_cache($request_id) {
        $this->delete('guarantee_notes_' . $request_id);
    }

    /**
     * کش کردن نتایج جستجو
     */
    public function get_search_results($type, $query, $args = array()) {
        $cache_key = 'search_' . $type . '_' . md5($query . serialize($args));
        $results = $this->get($cache_key, true);

        if ($results === false) {
            switch ($type) {
                case 'products':
                    $results = $this->search_products($query, $args);
                    break;
                case 'users':
                    $results = $this->search_users($query, $args);
                    break;
            }
            $this->set($cache_key, $results, true);
        }

        return $results;
    }

    /**
     * جستجوی محصولات
     */
    private function search_products($query, $args = array()) {
        $products = wc_get_products(array(
            'status' => 'publish',
            'limit' => isset($args['limit']) ? $args['limit'] : 10,
            's' => $query
        ));

        $results = array();
        foreach ($products as $product) {
            $results[] = array(
                'id' => $product->get_id(),
                'text' => $product->get_name(),
                'price' => $product->get_price(),
                'sku' => $product->get_sku()
            );
        }

        return $results;
    }

    /**
     * جستجوی کاربران
     */
    private function search_users($query, $args = array()) {
        $users = get_users(array(
            'search' => '*' . $query . '*',
            'number' => isset($args['limit']) ? $args['limit'] : 10,
            'role' => isset($args['role']) ? $args['role'] : ''
        ));

        $results = array();
        foreach ($users as $user) {
            $results[] = array(
                'id' => $user->ID,
                'text' => $user->display_name,
                'email' => $user->user_email
            );
        }

        return $results;
    }
}