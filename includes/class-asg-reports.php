<?php
/**
 * کلاس مدیریت گزارشات افزونه
 * 
 * @package After_Sales_Guarantee
 * @since 1.8
 * @author s-arman-m-j
 * @last_modified 2025-02-14 10:38:39
 */

if (!defined('ABSPATH')) {
    exit;
}

class ASG_Reports {
    /**
     * نمونه singleton
     *
     * @var ASG_Reports
     */
    private static $instance = null;

    /**
     * نمونه‌های کلاس‌های مورد نیاز
     */
    private $warranty;
    private $utils;

    /**
     * تنظیمات گزارش‌گیری
     *
     * @var array
     */
    private $report_settings;

    /**
     * دریافت نمونه کلاس
     *
     * @return ASG_Reports
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
        $this->report_settings = get_option('asg_report_settings', $this->get_default_settings());

        // اضافه کردن کرون جاب برای گزارشات خودکار
        if ($this->report_settings['auto_reports']['enabled']) {
            add_action('asg_generate_auto_reports', array($this, 'generate_scheduled_reports'));
            if (!wp_next_scheduled('asg_generate_auto_reports')) {
                wp_schedule_event(time(), 'daily', 'asg_generate_auto_reports');
            }
        }
    }

    /**
     * تنظیمات پیش‌فرض گزارش‌گیری
     *
     * @return array
     */
    private function get_default_settings() {
        return array(
            'auto_reports' => array(
                'enabled' => true,
                'frequency' => 'daily',
                'recipients' => array(),
                'types' => array(
                    'warranty_status' => true,
                    'expiring_warranties' => true,
                    'service_requests' => true,
                    'revenue' => true
                )
            ),
            'export_settings' => array(
                'format' => 'xlsx',
                'include_headers' => true,
                'date_format' => 'Y/m/d'
            ),
            'chart_settings' => array(
                'type' => 'line',
                'colors' => array(
                    'primary' => '#2271b1',
                    'secondary' => '#72aee6',
                    'tertiary' => '#1d2327'
                )
            )
        );
    }

    /**
     * تولید گزارش وضعیت گارانتی‌ها
     *
     * @param string $start_date
     * @param string $end_date
     * @return array
     */
    public function generate_warranty_status_report($start_date = '', $end_date = '') {
        global $wpdb;

        $where = '';
        if ($start_date && $end_date) {
            $where = $wpdb->prepare(
                "WHERE created_at BETWEEN %s AND %s",
                $start_date,
                $end_date
            );
        }

        // آمار کلی
        $stats = array(
            'total' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}asg_warranty_registrations $where"),
            'active' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}asg_warranty_registrations WHERE status = 'active' $where"),
            'expired' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}asg_warranty_registrations WHERE status = 'expired' $where"),
            'pending' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}asg_warranty_registrations WHERE status = 'pending' $where")
        );

        // آمار به تفکیک محصول
        $product_stats = $wpdb->get_results("
            SELECT 
                p.ID as product_id,
                p.post_title as product_name,
                COUNT(*) as total_warranties,
                SUM(CASE WHEN w.status = 'active' THEN 1 ELSE 0 END) as active_warranties,
                SUM(CASE WHEN w.status = 'expired' THEN 1 ELSE 0 END) as expired_warranties
            FROM {$wpdb->prefix}asg_warranty_registrations w
            LEFT JOIN {$wpdb->posts} p ON w.product_id = p.ID
            $where
            GROUP BY p.ID
            ORDER BY total_warranties DESC
        ");

        // آمار به تفکیک نوع گارانتی
        $type_stats = $wpdb->get_results("
            SELECT 
                warranty_type,
                COUNT(*) as total,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) as expired
            FROM {$wpdb->prefix}asg_warranty_registrations
            $where
            GROUP BY warranty_type
        ");

        // روند ثبت گارانتی
        $trend = $wpdb->get_results("
            SELECT 
                DATE_FORMAT(created_at, '%Y-%m') as month,
                COUNT(*) as count
            FROM {$wpdb->prefix}asg_warranty_registrations
            $where
            GROUP BY month
            ORDER BY month ASC
        ");

        return array(
            'summary' => $stats,
            'products' => $product_stats,
            'types' => $type_stats,
            'trend' => $trend
        );
    }
/**
     * تولید گزارش گارانتی‌های رو به انقضا
     *
     * @param int $days
     * @return array
     */
    public function generate_expiring_warranties_report($days = 30) {
        global $wpdb;

        $expiry_date = date('Y-m-d', strtotime("+$days days"));

        return $wpdb->get_results($wpdb->prepare("
            SELECT 
                w.*,
                p.post_title as product_name,
                u.display_name as customer_name,
                um.meta_value as customer_phone
            FROM {$wpdb->prefix}asg_warranty_registrations w
            LEFT JOIN {$wpdb->posts} p ON w.product_id = p.ID
            LEFT JOIN {$wpdb->users} u ON w.user_id = u.ID
            LEFT JOIN {$wpdb->usermeta} um ON u.ID = um.user_id AND um.meta_key = 'billing_phone'
            WHERE w.status = 'active'
            AND w.end_date <= %s
            ORDER BY w.end_date ASC
        ", $expiry_date));
    }

    /**
     * تولید گزارش درخواست‌های خدمات
     *
     * @param string $start_date
     * @param string $end_date
     * @return array
     */
    public function generate_service_requests_report($start_date = '', $end_date = '') {
        global $wpdb;

        $where = '';
        if ($start_date && $end_date) {
            $where = $wpdb->prepare(
                "WHERE sr.created_at BETWEEN %s AND %s",
                $start_date,
                $end_date
            );
        }

        // آمار کلی
        $stats = array(
            'total' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}asg_service_requests sr $where"),
            'pending' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}asg_service_requests sr WHERE status = 'pending' $where"),
            'in_progress' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}asg_service_requests sr WHERE status = 'in_progress' $where"),
            'completed' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}asg_service_requests sr WHERE status = 'completed' $where")
        );

        // جزئیات درخواست‌ها
        $requests = $wpdb->get_results("
            SELECT 
                sr.*,
                p.post_title as product_name,
                u.display_name as customer_name
            FROM {$wpdb->prefix}asg_service_requests sr
            LEFT JOIN {$wpdb->prefix}asg_warranty_registrations w ON sr.warranty_id = w.id
            LEFT JOIN {$wpdb->posts} p ON w.product_id = p.ID
            LEFT JOIN {$wpdb->users} u ON w.user_id = u.ID
            $where
            ORDER BY sr.created_at DESC
        ");

        return array(
            'summary' => $stats,
            'requests' => $requests
        );
    }

    /**
     * تولید گزارش درآمد
     *
     * @param string $start_date
     * @param string $end_date
     * @return array
     */
    public function generate_revenue_report($start_date = '', $end_date = '') {
        global $wpdb;

        $where = '';
        if ($start_date && $end_date) {
            $where = $wpdb->prepare(
                "WHERE p.created_at BETWEEN %s AND %s",
                $start_date,
                $end_date
            );
        }

        // آمار کلی
        $stats = array(
            'total_revenue' => $wpdb->get_var("
                SELECT SUM(amount) 
                FROM {$wpdb->prefix}asg_payments p 
                $where
            "),
            'total_transactions' => $wpdb->get_var("
                SELECT COUNT(*) 
                FROM {$wpdb->prefix}asg_payments p 
                $where
            ")
        );

        // درآمد به تفکیک نوع گارانتی
        $revenue_by_type = $wpdb->get_results("
            SELECT 
                w.warranty_type,
                COUNT(*) as count,
                SUM(p.amount) as revenue
            FROM {$wpdb->prefix}asg_payments p
            LEFT JOIN {$wpdb->prefix}asg_warranty_registrations w ON p.warranty_id = w.id
            $where
            GROUP BY w.warranty_type
        ");

        // درآمد ماهانه
        $monthly_revenue = $wpdb->get_results("
            SELECT 
                DATE_FORMAT(p.created_at, '%Y-%m') as month,
                COUNT(*) as transactions,
                SUM(p.amount) as revenue
            FROM {$wpdb->prefix}asg_payments p
            $where
            GROUP BY month
            ORDER BY month ASC
        ");

        return array(
            'summary' => $stats,
            'by_type' => $revenue_by_type,
            'monthly' => $monthly_revenue
        );
    }

    /**
     * تولید گزارشات زمان‌بندی شده
     */
    public function generate_scheduled_reports() {
        $date = current_time('mysql');
        $reports = array();

        // تولید گزارشات فعال شده
        if ($this->report_settings['auto_reports']['types']['warranty_status']) {
            $reports['warranty_status'] = $this->generate_warranty_status_report();
        }

        if ($this->report_settings['auto_reports']['types']['expiring_warranties']) {
            $reports['expiring_warranties'] = $this->generate_expiring_warranties_report();
        }

        if ($this->report_settings['auto_reports']['types']['service_requests']) {
            $reports['service_requests'] = $this->generate_service_requests_report();
        }

        if ($this->report_settings['auto_reports']['types']['revenue']) {
            $reports['revenue'] = $this->generate_revenue_report();
        }

        // ارسال گزارشات به گیرندگان
        foreach ($this->report_settings['auto_reports']['recipients'] as $recipient) {
            $this->send_report_email($recipient, $reports);
        }

        // ذخیره گزارشات
        $this->save_reports($reports, $date);
    }
    /**
     * ارسال ایمیل گزارش
     *
     * @param string $recipient
     * @param array $reports
     */
    private function send_report_email($recipient, $reports) {
        $subject = 'گزارش خودکار سیستم گارانتی - ' . wp_date('Y-m-d');
        
        ob_start();
        include ASG_PLUGIN_DIR . 'templates/emails/auto-report.php';
        $message = ob_get_clean();

        wp_mail($recipient, $subject, $message, array('Content-Type: text/html; charset=UTF-8'));
    }

    /**
     * ذخیره گزارشات
     *
     * @param array $reports
     * @param string $date
     */
    private function save_reports($reports, $date) {
        $filename = 'warranty-reports-' . date('Y-m-d-His') . '.' . $this->report_settings['export_settings']['format'];
        $export_dir = WP_CONTENT_DIR . '/uploads/warranty-reports/';

        if (!file_exists($export_dir)) {
            wp_mkdir_p($export_dir);
        }

        if ($this->report_settings['export_settings']['format'] === 'xlsx') {
            $this->export_to_excel($reports, $export_dir . $filename);
        } else {
            $this->export_to_csv($reports, $export_dir . $filename);
        }
    }

    /**
     * به‌روزرسانی تنظیمات گزارش‌گیری
     *
     * @param array $new_settings
     * @return bool
     */
    public function update_report_settings($new_settings) {
        $this->report_settings = wp_parse_args($new_settings, $this->get_default_settings());
        return update_option('asg_report_settings', $this->report_settings);
    }

    /**
     * اکسپورت گزارشات به فرمت Excel
     *
     * @param array $reports
     * @param string $filepath
     * @return bool
     */
    private function export_to_excel($reports, $filepath) {
        try {
            require_once ASG_PLUGIN_DIR . 'includes/libs/phpspreadsheet/autoload.php';

            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            
            // صفحه گزارش وضعیت گارانتی
            if (isset($reports['warranty_status'])) {
                $sheet = $spreadsheet->getActiveSheet();
                $sheet->setTitle('وضعیت گارانتی');

                // خلاصه آماری
                $sheet->setCellValue('A1', 'خلاصه آماری');
                $sheet->setCellValue('A2', 'کل گارانتی‌ها');
                $sheet->setCellValue('B2', $reports['warranty_status']['summary']['total']);
                $sheet->setCellValue('A3', 'گارانتی‌های فعال');
                $sheet->setCellValue('B3', $reports['warranty_status']['summary']['active']);
                $sheet->setCellValue('A4', 'گارانتی‌های منقضی');
                $sheet->setCellValue('B4', $reports['warranty_status']['summary']['expired']);

                // آمار محصولات
                $sheet->setCellValue('A6', 'آمار محصولات');
                $sheet->setCellValue('A7', 'نام محصول');
                $sheet->setCellValue('B7', 'تعداد کل');
                $sheet->setCellValue('C7', 'فعال');
                $sheet->setCellValue('D7', 'منقضی');

                $row = 8;
                foreach ($reports['warranty_status']['products'] as $product) {
                    $sheet->setCellValue('A' . $row, $product->product_name);
                    $sheet->setCellValue('B' . $row, $product->total_warranties);
                    $sheet->setCellValue('C' . $row, $product->active_warranties);
                    $sheet->setCellValue('D' . $row, $product->expired_warranties);
                    $row++;
                }
            }

            // صفحه گارانتی‌های رو به انقضا
            if (isset($reports['expiring_warranties'])) {
                $sheet = $spreadsheet->createSheet();
                $sheet->setTitle('گارانتی‌های رو به انقضا');
                
                $sheet->setCellValue('A1', 'نام مشتری');
                $sheet->setCellValue('B1', 'محصول');
                $sheet->setCellValue('C1', 'شماره سریال');
                $sheet->setCellValue('D1', 'تاریخ انقضا');
                $sheet->setCellValue('E1', 'شماره تماس');

                $row = 2;
                foreach ($reports['expiring_warranties'] as $warranty) {
                    $sheet->setCellValue('A' . $row, $warranty->customer_name);
                    $sheet->setCellValue('B' . $row, $warranty->product_name);
                    $sheet->setCellValue('C' . $row, $warranty->serial_number);
                    $sheet->setCellValue('D' . $row, $this->utils->gregorian_to_jalali($warranty->end_date));
                    $sheet->setCellValue('E' . $row, $warranty->customer_phone);
                    $row++;
                }
            }

            // صفحه درخواست‌های خدمات
            if (isset($reports['service_requests'])) {
                $sheet = $spreadsheet->createSheet();
                $sheet->setTitle('درخواست‌های خدمات');

                // خلاصه آماری
                $sheet->setCellValue('A1', 'خلاصه آماری');
                $sheet->setCellValue('A2', 'کل درخواست‌ها');
                $sheet->setCellValue('B2', $reports['service_requests']['summary']['total']);
                $sheet->setCellValue('A3', 'در انتظار');
                $sheet->setCellValue('B3', $reports['service_requests']['summary']['pending']);
                $sheet->setCellValue('A4', 'در حال پیگیری');
                $sheet->setCellValue('B4', $reports['service_requests']['summary']['in_progress']);
                $sheet->setCellValue('A5', 'تکمیل شده');
                $sheet->setCellValue('B5', $reports['service_requests']['summary']['completed']);

                // لیست درخواست‌ها
                $sheet->setCellValue('A7', 'مشتری');
                $sheet->setCellValue('B7', 'محصول');
                $sheet->setCellValue('C7', 'تاریخ');
                $sheet->setCellValue('D7', 'وضعیت');

                $row = 8;
                foreach ($reports['service_requests']['requests'] as $request) {
                    $sheet->setCellValue('A' . $row, $request->customer_name);
                    $sheet->setCellValue('B' . $row, $request->product_name);
                    $sheet->setCellValue('C' . $row, $this->utils->gregorian_to_jalali($request->created_at));
                    $sheet->setCellValue('D' . $row, $request->status);
                    $row++;
                }
            }
            // صفحه گزارش درآمد
            if (isset($reports['revenue'])) {
                $sheet = $spreadsheet->createSheet();
                $sheet->setTitle('گزارش درآمد');

                // خلاصه آماری
                $sheet->setCellValue('A1', 'خلاصه آماری');
                $sheet->setCellValue('A2', 'کل درآمد');
                $sheet->setCellValue('B2', number_format($reports['revenue']['summary']['total_revenue']));
                $sheet->setCellValue('A3', 'تعداد تراکنش‌ها');
                $sheet->setCellValue('B3', $reports['revenue']['summary']['total_transactions']);

                // درآمد به تفکیک نوع گارانتی
                $sheet->setCellValue('A5', 'درآمد به تفکیک نوع گارانتی');
                $sheet->setCellValue('A6', 'نوع گارانتی');
                $sheet->setCellValue('B6', 'تعداد');
                $sheet->setCellValue('C6', 'درآمد');

                $row = 7;
                foreach ($reports['revenue']['by_type'] as $type) {
                    $sheet->setCellValue('A' . $row, $type->warranty_type);
                    $sheet->setCellValue('B' . $row, $type->count);
                    $sheet->setCellValue('C' . $row, number_format($type->revenue));
                    $row++;
                }

                // درآمد ماهانه
                $sheet->setCellValue('A' . ($row + 2), 'درآمد ماهانه');
                $sheet->setCellValue('A' . ($row + 3), 'ماه');
                $sheet->setCellValue('B' . ($row + 3), 'تعداد تراکنش');
                $sheet->setCellValue('C' . ($row + 3), 'درآمد');

                $row += 4;
                foreach ($reports['revenue']['monthly'] as $month) {
                    $sheet->setCellValue('A' . $row, $month->month);
                    $sheet->setCellValue('B' . $row, $month->transactions);
                    $sheet->setCellValue('C' . $row, number_format($month->revenue));
                    $row++;
                }
            }

            // تنظیم سبک‌ها
            foreach ($spreadsheet->getAllSheets() as $sheet) {
                // تنظیم عرض ستون‌ها
                foreach (range('A', 'E') as $column) {
                    $sheet->getColumnDimension($column)->setAutoSize(true);
                }

                // تنظیم سبک هدرها
                $sheet->getStyle('A1:E1')->getFont()->setBold(true);
                $sheet->getStyle('A1:E1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID);
                $sheet->getStyle('A1:E1')->getFill()->getStartColor()->setRGB('CCCCCC');

                // تنظیم راست به چپ
                $sheet->setRightToLeft(true);
            }

            // ذخیره فایل
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save($filepath);

            return true;

        } catch (Exception $e) {
            $this->utils->log_error(
                'خطا در اکسپورت گزارش به اکسل',
                'error',
                array('error' => $e->getMessage())
            );
            return false;
        }
    }

    /**
     * اکسپورت گزارشات به فرمت CSV
     *
     * @param array $reports
     * @param string $filepath
     * @return bool
     */
    private function export_to_csv($reports, $filepath) {
        try {
            $fp = fopen($filepath, 'w');

            // تنظیم UTF-8 BOM برای پشتیبانی از فارسی
            fprintf($fp, chr(0xEF).chr(0xBB).chr(0xBF));

            // گزارش وضعیت گارانتی
            if (isset($reports['warranty_status'])) {
                fputcsv($fp, array('گزارش وضعیت گارانتی'));
                fputcsv($fp, array('خلاصه آماری'));
                fputcsv($fp, array('کل گارانتی‌ها', $reports['warranty_status']['summary']['total']));
                fputcsv($fp, array('گارانتی‌های فعال', $reports['warranty_status']['summary']['active']));
                fputcsv($fp, array('گارانتی‌های منقضی', $reports['warranty_status']['summary']['expired']));
                
                fputcsv($fp, array(''));
                fputcsv($fp, array('آمار محصولات'));
                fputcsv($fp, array('نام محصول', 'تعداد کل', 'فعال', 'منقضی'));
                
                foreach ($reports['warranty_status']['products'] as $product) {
                    fputcsv($fp, array(
                        $product->product_name,
                        $product->total_warranties,
                        $product->active_warranties,
                        $product->expired_warranties
                    ));
                }
                
                fputcsv($fp, array(''));
            }

            // گزارش گارانتی‌های رو به انقضا
            if (isset($reports['expiring_warranties'])) {
                fputcsv($fp, array('گزارش گارانتی‌های رو به انقضا'));
                fputcsv($fp, array('نام مشتری', 'محصول', 'شماره سریال', 'تاریخ انقضا', 'شماره تماس'));
                
                foreach ($reports['expiring_warranties'] as $warranty) {
                    fputcsv($fp, array(
                        $warranty->customer_name,
                        $warranty->product_name,
                        $warranty->serial_number,
                        $this->utils->gregorian_to_jalali($warranty->end_date),
                        $warranty->customer_phone
                    ));
                }
                
                fputcsv($fp, array(''));
            }

            // گزارش درخواست‌های خدمات
            if (isset($reports['service_requests'])) {
                fputcsv($fp, array('گزارش درخواست‌های خدمات'));
                fputcsv($fp, array('خلاصه آماری'));
                fputcsv($fp, array('کل درخواست‌ها', $reports['service_requests']['summary']['total']));
                fputcsv($fp, array('در انتظار', $reports['service_requests']['summary']['pending']));
                fputcsv($fp, array('در حال پیگیری', $reports['service_requests']['summary']['in_progress']));
                fputcsv($fp, array('تکمیل شده', $reports['service_requests']['summary']['completed']));
                
                fputcsv($fp, array(''));
                fputcsv($fp, array('لیست درخواست‌ها'));
                fputcsv($fp, array('مشتری', 'محصول', 'تاریخ', 'وضعیت'));
                
                foreach ($reports['service_requests']['requests'] as $request) {
                    fputcsv($fp, array(
                        $request->customer_name,
                        $request->product_name,
                        $this->utils->gregorian_to_jalali($request->created_at),
                        $request->status
                    ));
                }
                
                fputcsv($fp, array(''));
            }

            // گزارش درآمد
            if (isset($reports['revenue'])) {
                fputcsv($fp, array('گزارش درآمد'));
                fputcsv($fp, array('خلاصه آماری'));
                fputcsv($fp, array('کل درآمد', number_format($reports['revenue']['summary']['total_revenue'])));
                fputcsv($fp, array('تعداد تراکنش‌ها', $reports['revenue']['summary']['total_transactions']));
                
                fputcsv($fp, array(''));
                fputcsv($fp, array('درآمد به تفکیک نوع گارانتی'));
                fputcsv($fp, array('نوع گارانتی', 'تعداد', 'درآمد'));
                
                foreach ($reports['revenue']['by_type'] as $type) {
                    fputcsv($fp, array(
                        $type->warranty_type,
                        $type->count,
                        number_format($type->revenue)
                    ));
                }
                
                fputcsv($fp, array(''));
                fputcsv($fp, array('درآمد ماهانه'));
                fputcsv($fp, array('ماه', 'تعداد تراکنش', 'درآمد'));
                
                foreach ($reports['revenue']['monthly'] as $month) {
                    fputcsv($fp, array(
                        $month->month,
                        $month->transactions,
                        number_format($month->revenue)
                    ));
                }
            }

            fclose($fp);
            return true;

        } catch (Exception $e) {
            $this->utils->log_error(
                'خطا در اکسپورت گزارش به CSV',
                'error',
                array('error' => $e->getMessage())
            );
            return false;
        }
    }
}