<?php defined('ABSPATH') || exit; ?>

<div class="asg-guarantee-requests">
    <h2>درخواست‌های گارانتی</h2>

    <?php
    // کش کردن لیست درخواست‌ها
    $user_id = get_current_user_id();
    $page = get_query_var('paged') ? get_query_var('paged') : 1;
    $per_page = 10;
    $cache_key = 'asg_requests_user_' . $user_id . '_page_' . $page;
    
    $cached_data = wp_cache_get($cache_key);
    
    if (false === $cached_data) {
        global $wpdb;
        
        // محاسبه تعداد کل صفحات
        $total_items = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}asg_guarantee_requests WHERE user_id = %d",
            $user_id
        ));
        $total_pages = ceil($total_items / $per_page);
        
        // دریافت درخواست‌ها با پیجینیشن
        $requests = $wpdb->get_results($wpdb->prepare(
            "SELECT r.*, p.post_title as product_name 
            FROM {$wpdb->prefix}asg_guarantee_requests r
            LEFT JOIN {$wpdb->posts} p ON r.product_id = p.ID
            WHERE r.user_id = %d
            ORDER BY r.created_at DESC
            LIMIT %d OFFSET %d",
            $user_id,
            $per_page,
            ($page - 1) * $per_page
        ));
        
        $cached_data = array(
            'requests' => $requests,
            'total_pages' => $total_pages
        );
        
        // ذخیره در کش برای 5 دقیقه
        wp_cache_set($cache_key, $cached_data, '', 300);
    }
    
    $requests = $cached_data['requests'];
    $total_pages = $cached_data['total_pages'];
    ?>

    <?php if (!empty($requests)): ?>
        <div class="asg-guarantee-list">
            <?php foreach ($requests as $request): ?>
                <div class="asg-guarantee-item" data-id="<?php echo esc_attr($request->id); ?>">
                    <div class="asg-guarantee-header">
                        <h3>
                            محصول: <?php echo esc_html($request->product_name); ?>
                            <span class="asg-guarantee-number">#<?php echo esc_html($request->id); ?></span>
                        </h3>
                        <span class="asg-guarantee-status asg-status-<?php echo esc_attr($request->status); ?>">
                            <?php 
                            // کش کردن برچسب‌های وضعیت
                            $status_label = wp_cache_get('asg_status_label_' . $request->status);
                            if (false === $status_label) {
                                $status_label = esc_html($this->get_status_label($request->status));
                                wp_cache_set('asg_status_label_' . $request->status, $status_label, '', 3600);
                            }
                            echo $status_label;
                            ?>
                        </span>
                    </div>
                    <div class="asg-guarantee-content">
                        <p class="asg-guarantee-date">
                            تاریخ ثبت: <time datetime="<?php echo esc_attr($request->created_at); ?>">
                                <?php echo date_i18n('Y/m/d', strtotime($request->created_at)); ?>
                            </time>
                        </p>
                        <div class="asg-guarantee-actions">
                            <a href="<?php echo esc_url(wc_get_account_endpoint_url('view-guarantee') . $request->id); ?>" 
                               class="button view-details"
                               data-guarantee-id="<?php echo esc_attr($request->id); ?>">
                                مشاهده جزئیات
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($total_pages > 1): ?>
            <div class="asg-pagination">
                <?php
                // بهینه‌سازی پیجینیشن
                echo paginate_links(array(
                    'base' => add_query_arg('paged', '%#%'),
                    'format' => '',
                    'current' => $page,
                    'total' => $total_pages,
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                    'type' => 'list',
                    'mid_size' => 2,
                    'end_size' => 1
                ));
                ?>
            </div>
        <?php endif; ?>

        <!-- اضافه کردن CSS بهینه شده -->
        <style>
            .asg-guarantee-requests {
                max-width: 1200px;
                margin: 0 auto;
                padding: 20px;
            }
            .asg-guarantee-list {
                display: grid;
                gap: 20px;
                margin: 20px 0;
            }
            .asg-guarantee-item {
                background: #fff;
                border-radius: 8px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                transition: transform 0.2s ease;
            }
            .asg-guarantee-item:hover {
                transform: translateY(-2px);
            }
            .asg-guarantee-header {
                padding: 15px;
                border-bottom: 1px solid #eee;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            .asg-guarantee-content {
                padding: 15px;
            }
            .asg-guarantee-status {
                padding: 5px 10px;
                border-radius: 4px;
                font-size: 0.9em;
                font-weight: bold;
            }
            .asg-status-pending { background: #fff3cd; color: #856404; }
            .asg-status-approved { background: #d4edda; color: #155724; }
            .asg-status-rejected { background: #f8d7da; color: #721c24; }
            .asg-pagination {
                margin-top: 20px;
                text-align: center;
            }
            .asg-pagination ul {
                display: inline-flex;
                gap: 5px;
                list-style: none;
                padding: 0;
            }
            .asg-pagination a,
            .asg-pagination span {
                padding: 8px 12px;
                border-radius: 4px;
                background: #f8f9fa;
                color: #007bff;
                text-decoration: none;
            }
            .asg-pagination .current {
                background: #007bff;
                color: #fff;
            }
            @media (max-width: 768px) {
                .asg-guarantee-header {
                    flex-direction: column;
                    text-align: center;
                    gap: 10px;
                }
                .asg-guarantee-item {
                    margin: 10px 0;
                }
            }
        </style>

        <!-- اضافه کردن JavaScript بهینه شده -->
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            // تابع لود تنبل تصاویر
            function lazyLoadImages() {
                const images = document.querySelectorAll('img[data-src]');
                images.forEach(img => {
                    if (img.getBoundingClientRect().top < window.innerHeight + 100) {
                        img.src = img.dataset.src;
                        img.removeAttribute('data-src');
                    }
                });
            }

            // اجرای لود تنبل در اسکرول
            let timeout;
            window.addEventListener('scroll', () => {
                if (timeout) {
                    window.cancelAnimationFrame(timeout);
                }
                timeout = window.requestAnimationFrame(lazyLoadImages);
            });

            // اجرای اولیه لود تنبل
            lazyLoadImages();
        });
        </script>

    <?php else: ?>
        <p class="asg-no-requests">هیچ درخواست گارانتی ثبت نشده است.</p>
    <?php endif; ?>
</div>