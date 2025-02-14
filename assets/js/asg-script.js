jQuery(document).ready(function($) {
    let guaranteeCount = 2; // شمارنده گارانتی‌ها

    // فعال‌سازی Select2 برای فیلد مشتری
    $('#user_id').select2({
        minimumInputLength: 3, // حداقل 3 حرف برای جستجو
        ajax: {
            url: ajaxurl, // آدرس Ajax در وردپرس
            dataType: 'json',
            delay: 250,
            data: function(params) {
                return {
                    action: 'asg_search_users', // اکشن Ajax
                    search: params.term // عبارت جستجو
                };
            },
            processResults: function(data) {
                return {
                    results: data
                };
            },
            cache: true
        }
    });

    // فعال‌سازی Select2 برای فیلد محصولات (گارانتی‌های ۱ و ۲)
    for (let i = 1; i <= 2; i++) {
        $('#product_id_' + i).select2({
            minimumInputLength: 3, // حداقل 3 حرف برای جستجو
            ajax: {
                url: ajaxurl, // آدرس Ajax در وردپرس
                dataType: 'json',
                delay: 250,
                data: function(params) {
                    return {
                        action: 'asg_search_products', // اکشن Ajax
                        search: params.term // عبارت جستجو
                    };
                },
                processResults: function(data) {
                    return {
                        results: data
                    };
                },
                cache: true
            }
        });

        // آپلود عکس برای گارانتی‌های ۱ و ۲
        $('#upload_image_' + i).click(function(e) {
            e.preventDefault();
            var image = wp.media({
                title: 'آپلود عکس',
                multiple: false
            }).open()
            .on('select', function() {
                var uploaded_image = image.state().get('selection').first();
                var image_url = uploaded_image.toJSON().id;
                $('#image_url_' + i).val(image_url);

                // نمایش عکس آپلود شده
                var image_html = '<img src="' + uploaded_image.toJSON().url + '" style="max-width: 200px; height: auto;">';
                $('#image_url_' + i).after(image_html);
            });
        });
    }

    // افزودن گارانتی جدید با کلیک روی دکمه +
    $('#asg-add-guarantee').click(function() {
        if (guaranteeCount < 10) {
            guaranteeCount++;
            const newGuarantee = `
                <div class="asg-card asg-guarantee-card" id="asg-guarantee-${guaranteeCount}">
                    <div class="asg-card-header">
                        گارانتی شماره ${guaranteeCount}
                        <button type="button" class="asg-remove-guarantee" data-guarantee-id="${guaranteeCount}">حذف</button>
                    </div>
                    <div class="asg-card-body">
                        <div class="asg-form-group">
                            <label for="product_id_${guaranteeCount}">محصول:</label>
                            <select name="product_id_${guaranteeCount}" id="product_id_${guaranteeCount}"></select>
                        </div>
                        <div class="asg-form-group">
                            <label for="tamin_user_id_${guaranteeCount}">تامین‌کننده (tamin):</label>
                            <select name="tamin_user_id_${guaranteeCount}" id="tamin_user_id_${guaranteeCount}">
                                ${$('#tamin_user_id_1').html()} // کپی گزینه‌های تامین‌کننده
                            </select>
                        </div>
                        <div class="asg-form-group">
                            <label for="defect_description_${guaranteeCount}">نقص کالا:</label>
                            <textarea name="defect_description_${guaranteeCount}" id="defect_description_${guaranteeCount}" rows="5" cols="40" required></textarea>
                        </div>
                        <div class="asg-form-group">
                            <label for="expert_comment_${guaranteeCount}">نظر کارشناس:</label>
                            <textarea name="expert_comment_${guaranteeCount}" id="expert_comment_${guaranteeCount}" rows="5" cols="40"></textarea>
                        </div>
                        <div class="asg-form-group">
                            <label for="status_${guaranteeCount}">وضعیت:</label>
                            <select name="status_${guaranteeCount}" id="status_${guaranteeCount}" required>
                                ${$('#status_1').html()} // کپی گزینه‌های وضعیت
                            </select>
                        </div>
                        <div class="asg-form-group">
                            <label for="receipt_day_${guaranteeCount}">روز:</label>
                            <select name="receipt_day_${guaranteeCount}" id="receipt_day_${guaranteeCount}" required>
                                ${Array.from({ length: 31 }, (_, i) => `<option value="${i + 1}">${i + 1}</option>`).join('')}
                            </select>
                        </div>
                        <div class="asg-form-group">
                            <label for="receipt_year_${guaranteeCount}">سال:</label>
                            <select name="receipt_year_${guaranteeCount}" id="receipt_year_${guaranteeCount}" required>
                                ${Array.from({ length: 8 }, (_, i) => {
                                    const year = 1403 + i;
                                    const selected = year === 1403 ? 'selected' : '';
                                    return `<option value="${year}" ${selected}>${year}</option>`;
                                }).join('')}
                            </select>
                        </div>
                        <div class="asg-form-group">
                            <label for="image_url_${guaranteeCount}">عکس:</label>
                            <input type="text" name="image_id_${guaranteeCount}" id="image_url_${guaranteeCount}" class="regular-text">
                            <input type="button" value="آپلود عکس" class="button" id="upload_image_${guaranteeCount}">
                        </div>
                    </div>
                </div>
            `;
            $(newGuarantee).insertBefore('#asg-add-guarantee');

            // فعال‌سازی Select2 برای فیلد محصول جدید
            $('#product_id_' + guaranteeCount).select2({
                minimumInputLength: 3,
                ajax: {
                    url: ajaxurl,
                    dataType: 'json',
                    delay: 250,
                    data: function(params) {
                        return {
                            action: 'asg_search_products',
                            search: params.term
                        };
                    },
                    processResults: function(data) {
                        return {
                            results: data
                        };
                    },
                    cache: true
                }
            });

            // فعال‌سازی آپلود عکس برای گارانتی جدید
            $('#upload_image_' + guaranteeCount).click(function(e) {
                e.preventDefault();
                var image = wp.media({
                    title: 'آپلود عکس',
                    multiple: false
                }).open()
                .on('select', function() {
                    var uploaded_image = image.state().get('selection').first();
                    var image_url = uploaded_image.toJSON().id;
                    $('#image_url_' + guaranteeCount).val(image_url);

                    // نمایش عکس آپلود شده
                    var image_html = '<img src="' + uploaded_image.toJSON().url + '" style="max-width: 200px; height: auto;">';
                    $('#image_url_' + guaranteeCount).after(image_html);
                });
            });

            // حذف گارانتی با کلیک روی دکمه حذف
            $(`.asg-remove-guarantee[data-guarantee-id="${guaranteeCount}"]`).click(function() {
                $(`#asg-guarantee-${guaranteeCount}`).remove();
                guaranteeCount--; // کاهش شمارنده
            });
        } else {
            alert('حداکثر ۱۰ گارانتی می‌توانید ثبت کنید.');
        }
    });

    // حذف گارانتی‌های پیش‌فرض (۱ و ۲)
    $(document).on('click', '.asg-remove-guarantee', function() {
        const guaranteeId = $(this).data('guarantee-id');
        $(`#asg-guarantee-${guaranteeId}`).remove();
        guaranteeCount--; // کاهش شمارنده
    });
});
// فعال‌سازی Select2 برای فیلتر مشتری در گزارشات
$('#filter_customer').select2({
    ajax: {
        url: ajaxurl,
        dataType: 'json',
        delay: 250,
        data: function(params) {
            return {
                action: 'asg_search_users',
                search: params.term,
                page: params.page || 1
            };
        },
        processResults: function(data, params) {
            params.page = params.page || 1;
            return {
                results: data,
                pagination: {
                    more: (params.page * 30) < data.total_count
                }
            };
        },
        cache: true
    },
    minimumInputLength: 2,
    placeholder: 'جستجوی مشتری...',
    language: {
        noResults: function() {
            return "مشتری یافت نشد";
        },
        searching: function() {
            return "در حال جستجو...";
        }
    }
});

<?php
// قبل از جدول اصلی
echo '<div class="asg-charts">';
echo '<div class="asg-chart-container">';
echo '<canvas id="statusChart"></canvas>';
echo '</div>';
echo '</div>';

// اضافه کردن Chart.js
wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js', array(), '3.7.0', true);

// اضافه کردن اسکریپت نمودار
add_action('admin_footer', function() use ($status_counts) {
    ?>
    <script>
    jQuery(document).ready(function($) {
        var ctx = document.getElementById('statusChart').getContext('2d');
        new Chart(ctx, {
            type: 'pie',
            data: {
                labels: <?php echo json_encode(array_keys($status_counts)); ?>,
                datasets: [{
                    data: <?php echo json_encode(array_values($status_counts)); ?>,
                    backgroundColor: [
                        '#2271b1',
                        '#00a32a',
                        '#dba617',
                        '#d63638'
                    ]
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'right'
                    },
                    title: {
                        display: true,
                        text: 'توزیع وضعیت‌های گارانتی'
                    }
                }
            }
        });
    });
    </script>
    <?php
});