jQuery(document).ready(function($) {
    if ($('#asgStatusChart').length) {
        $.get(ajaxurl, {
            action: 'asg_get_stats',
            nonce: asg_params.nonce
        }, function(response) {
            if (response.success) {
                const data = response.data;
                
                // نمودار وضعیت‌ها
                new Chart($('#asgStatusChart'), {
                    type: 'pie',
                    data: {
                        labels: data.status_labels,
                        datasets: [{
                            data: data.status_counts,
                            backgroundColor: [
                                '#FF6384',
                                '#36A2EB',
                                '#FFCE56',
                                '#4BC0C0'
                            ]
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: {
                                position: 'right'
                            }
                        }
                    }
                });

                // نمودار ماهانه
                new Chart($('#asgMonthlyChart'), {
                    type: 'line',
                    data: {
                        labels: data.monthly_labels,
                        datasets: [{
                            label: 'تعداد درخواست‌ها',
                            data: data.monthly_counts,
                            borderColor: '#36A2EB',
                            tension: 0.1
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: {
                                position: 'top'
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true
                            }
                        }
                    }
                });
            }
        });
    }
});