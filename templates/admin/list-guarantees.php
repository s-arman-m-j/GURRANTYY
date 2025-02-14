<?php
// تنظیمات پیجینیشن
$current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$per_page = 20;

// دریافت فیلترها
$filters = array(
    'user_id' => isset($_GET['filter_user']) ? intval($_GET['filter_user']) : '',
    'product_id' => isset($_GET['filter_product']) ? intval($_GET['filter_product']) : '',
    'status' => isset($_GET['filter_status']) ? sanitize_text_field($_GET['filter_status']) : ''
);

// دریافت داده‌ها با پیجینیشن
$requests = $this->db->get_requests($filters, $current_page, $per_page);
$total_items = $this->db->count_total_requests($filters);
$total_pages = ceil($total_items / $per_page);

// نمایش لیست
?>
<div class="wrap">
    <h1>لیست درخواست‌های گارانتی</h1>
    
    <!-- فرم فیلترها -->
    <div class="asg-filters">
        <form method="get" action="">
            <input type="hidden" name="page" value="asg-guarantee">
            <!-- فیلدهای فیلتر -->
            <input type="submit" value="اعمال فیلتر" class="button">
        </form>
    </div>

    <!-- جدول نمایش داده‌ها -->
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th>شناسه</th>
                <th>محصول</th>
                <th>مشتری</th>
                <th>وضعیت</th>
                <th>تاریخ</th>
                <th>عملیات</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($requests as $request): ?>
                <tr>
                    <td><?php echo $request->id; ?></td>
                    <td><?php echo esc_html($request->product_name); ?></td>
                    <td><?php echo esc_html($request->user_name); ?></td>
                    <td><?php echo esc_html($request->status); ?></td>
                    <td><?php echo esc_html($request->created_at); ?></td>
                    <td>
                        <a href="<?php echo admin_url('admin.php?page=asg-edit-guarantee&id=' . $request->id); ?>" 
                           class="button">ویرایش</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- پیجینیشن -->
    <?php if ($total_pages > 1): ?>
        <div class="tablenav">
            <div class="tablenav-pages">
                <?php
                echo paginate_links(array(
                    'base' => add_query_arg('paged', '%#%'),
                    'format' => '',
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                    'total' => $total_pages,
                    'current' => $current_page,
                    'type' => 'list'
                ));
                ?>
            </div>
        </div>
    <?php endif; ?>
</div>