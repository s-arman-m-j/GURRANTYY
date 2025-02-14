<?php defined('ABSPATH') || exit; ?>

<div class="asg-guarantee-details">
    <h2>جزئیات درخواست گارانتی #<?php echo $request->id; ?></h2>

    <div class="asg-guarantee-info">
        <div class="asg-info-row">
            <span class="asg-label">محصول:</span>
            <span class="asg-value"><?php echo esc_html($request->product_name); ?></span>
        </div>
        <div class="asg-info-row">
            <span class="asg-label">وضعیت:</span>
            <span class="asg-status asg-status-<?php echo esc_attr($request->status); ?>">
                <?php echo esc_html($this->get_status_label($request->status)); ?>
            </span>
        </div>
        <div class="asg-info-row">
            <span class="asg-label">تاریخ ثبت:</span>
            <span class="asg-value"><?php echo date_i18n('Y/m/d', strtotime($request->created_at)); ?></span>
        </div>
        <div class="asg-info-row">
            <span class="asg-label">شرح مشکل:</span>
            <div class="asg-value"><?php echo nl2br(esc_html($request->defect_description)); ?></div>
        </div>
    </div>

    <!-- بخش یادداشت‌ها -->
    <div class="asg-notes-section">
        <h3>یادداشت‌ها</h3>
        
        <div class="asg-add-note">
            <form id="asg-note-form">
                <input type="hidden" name="request_id" value="<?php echo $request->id; ?>">
                <textarea name="note" placeholder="یادداشت خود را وارد کنید..." required></textarea>
                <button type="submit" class="button">افزودن یادداشت</button>
            </form>
        </div>

        <div class="asg-notes-list">
            <?php if (!empty($notes)): ?>
                <?php foreach ($notes as $note): ?>
                    <div class="asg-note-item">
                        <div class="asg-note-content">
                            <?php echo nl2br(esc_html($note->note)); ?>
                        </div>
                        <div class="asg-note-meta">
                            <span class="asg-note-author">
                                <?php echo esc_html($note->user_name); ?>
                            </span>
                            <span class="asg-note-date">
                                <?php echo date_i18n('Y/m/d H:i', strtotime($note->created_at)); ?>
                            </span>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="asg-no-notes">هنوز یادداشتی ثبت نشده است.</p>
            <?php endif; ?>
        </div>
    </div>
</div>