<?php

/*
|--------------------------------------------------------------------------
| Dashboard reactive cards
|--------------------------------------------------------------------------
| Sincronizeaza cardurile vizibile din dashboard cu perioada selectata.
| Nu modifica layout-ul desktop; completeaza datele reale pe baza DB si
| actualizeaza cardurile/charts dupa render.
|--------------------------------------------------------------------------
*/

if (!function_exists('pz_dash_rx_table_exists')) {
    function pz_dash_rx_table_exists(PDO $pdo, string $table): bool
    {
        try {
            $s = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
            $s->execute([$table]);
            return (int)$s->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('pz_dash_rx_column_exists')) {
    function pz_dash_rx_column_exists(PDO $pdo, string $table, string $column): bool
    {
        try {
            $s = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
            $s->execute([$table, $column]);
            return (int)$s->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('pz_dash_rx_rows')) {
    function pz_dash_rx_rows(PDO $pdo, string $sql, array $params = []): array
    {
        try {
            $s = $pdo->prepare($sql);
            $s->execute($params);
            return $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('dashboard reactive cards: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('pz_dash_rx_money')) {
    function pz_dash_rx_money($amount): string
    {
        return number_format((float)$amount, 0, ',', '.');
    }
}

if (!function_exists('pz_dash_rx_time')) {
    function pz_dash_rx_time(?string $time): string
    {
        return $time ? substr((string)$time, 0, 5) : '--:--';
    }
}

if (!function_exists('pz_dash_rx_month_short')) {
    function pz_dash_rx_month_short(string $monthNumber): string
    {
        $labels = ['01'=>'Ian','02'=>'Feb','03'=>'Mar','04'=>'Apr','05'=>'Mai','06'=>'Iun','07'=>'Iul','08'=>'Aug','09'=>'Sep','10'=>'Oct','11'=>'Nov','12'=>'Dec'];
        return $labels[$monthNumber] ?? $monthNumber;
    }
}

if (!function_exists('pz_dash_rx_period_buckets')) {
    function pz_dash_rx_period_buckets(string $start, string $end): array
    {
        $startTs = strtotime($start);
        $endTs = strtotime($end);
        $days = max(1, (int)floor(($endTs - $startTs) / 86400) + 1);
        $buckets = [];

        if ($days <= 1) {
            return [[
                'key' => $start,
                'label' => 'Azi',
                'start' => $start,
                'end' => $end,
            ]];
        }

        if ($days <= 31) {
            $cursor = $startTs;
            while ($cursor <= $endTs) {
                $d = date('Y-m-d', $cursor);
                $buckets[] = [
                    'key' => $d,
                    'label' => date('d.m', $cursor),
                    'start' => $d,
                    'end' => $d,
                ];
                $cursor = strtotime('+1 day', $cursor);
            }
            return $buckets;
        }

        if ($days <= 100) {
            $cursor = $startTs;
            while ($cursor <= $endTs) {
                $bucketStart = date('Y-m-d', $cursor);
                $bucketEndTs = min($endTs, strtotime('+6 days', $cursor));
                $bucketEnd = date('Y-m-d', $bucketEndTs);
                $buckets[] = [
                    'key' => $bucketStart . ':' . $bucketEnd,
                    'label' => date('d.m', $cursor),
                    'start' => $bucketStart,
                    'end' => $bucketEnd,
                ];
                $cursor = strtotime('+7 days', $cursor);
            }
            return $buckets;
        }

        $cursor = strtotime(date('Y-m-01', $startTs));
        $endMonth = strtotime(date('Y-m-01', $endTs));
        while ($cursor <= $endMonth) {
            $monthStart = max($startTs, $cursor);
            $monthEnd = min($endTs, strtotime(date('Y-m-t', $cursor)));
            $buckets[] = [
                'key' => date('Y-m', $cursor),
                'label' => pz_dash_rx_month_short(date('m', $cursor)),
                'start' => date('Y-m-d', $monthStart),
                'end' => date('Y-m-d', $monthEnd),
            ];
            $cursor = strtotime('+1 month', $cursor);
        }

        return $buckets;
    }
}

if (!function_exists('pz_dash_rx_chart_series')) {
    function pz_dash_rx_chart_series(PDO $pdo, string $start, string $end): array
    {
        $labels = [];
        $issued = [];
        $paid = [];
        $buckets = pz_dash_rx_period_buckets($start, $end);
        $hasInvoices = pz_dash_rx_table_exists($pdo, 'smartbill_invoices');
        $hasPayments = pz_dash_rx_table_exists($pdo, 'smartbill_invoice_payments');
        $hasSourceType = $hasInvoices && pz_dash_rx_column_exists($pdo, 'smartbill_invoices', 'source_type');
        $sourceWhere = $hasSourceType ? " AND (source_type IS NULL OR source_type <> 'receipt')" : "";

        foreach ($buckets as $bucket) {
            $labels[] = $bucket['label'];
            $issuedAmount = 0.0;
            $paidAmount = 0.0;

            if ($hasInvoices) {
                $rows = pz_dash_rx_rows($pdo, "
                    SELECT COALESCE(SUM(gross_amount), 0) AS total
                    FROM smartbill_invoices
                    WHERE invoice_date BETWEEN ? AND ?
                      AND TRIM(COALESCE(smartbill_number, '')) <> '' {$sourceWhere}
                ", [$bucket['start'], $bucket['end']]);
                $issuedAmount = (float)($rows[0]['total'] ?? 0);
            }

            if ($hasPayments) {
                $rows = pz_dash_rx_rows($pdo, "
                    SELECT COALESCE(SUM(amount), 0) AS total
                    FROM smartbill_invoice_payments
                    WHERE payment_date BETWEEN ? AND ?
                      AND COALESCE(smartbill_status, '') NOT IN ('error', 'deleted')
                ", [$bucket['start'], $bucket['end']]);
                $paidAmount = (float)($rows[0]['total'] ?? 0);
            }

            $issued[] = round($issuedAmount, 2);
            $paid[] = round($paidAmount, 2);
        }

        return [
            'labels' => $labels,
            'issued' => $issued,
            'paid' => $paid,
        ];
    }
}

if (!function_exists('pz_dash_rx_invoice_status')) {
    function pz_dash_rx_invoice_status(PDO $pdo, string $start, string $end, string $today): array
    {
        $out = [
            'count' => 0,
            'gross' => 0.0,
            'paid_count' => 0,
            'pending_count' => 0,
            'overdue_count' => 0,
            'paid_amount' => 0.0,
            'pending_amount' => 0.0,
            'overdue_amount' => 0.0,
            'paid_pct_amount' => 0,
            'pending_pct_amount' => 0,
            'overdue_pct_amount' => 0,
        ];

        if (!pz_dash_rx_table_exists($pdo, 'smartbill_invoices')) {
            return $out;
        }

        $hasPayments = pz_dash_rx_table_exists($pdo, 'smartbill_invoice_payments');
        $hasDueDate = pz_dash_rx_column_exists($pdo, 'smartbill_invoices', 'due_date');
        $hasSourceType = pz_dash_rx_column_exists($pdo, 'smartbill_invoices', 'source_type');
        $sourceWhere = $hasSourceType ? " AND (i.source_type IS NULL OR i.source_type <> 'receipt')" : "";
        $paymentJoin = $hasPayments
            ? "LEFT JOIN (SELECT smartbill_invoice_id, SUM(amount) AS paid FROM smartbill_invoice_payments WHERE COALESCE(smartbill_status, '') NOT IN ('error', 'deleted') GROUP BY smartbill_invoice_id) p ON p.smartbill_invoice_id = i.id"
            : "";
        $paidExpr = $hasPayments ? "COALESCE(p.paid, 0)" : "0";
        $dueExpr = $hasDueDate ? "i.due_date" : "NULL AS due_date";

        $rows = pz_dash_rx_rows($pdo, "
            SELECT i.id, i.gross_amount, {$dueExpr}, {$paidExpr} AS paid
            FROM smartbill_invoices i {$paymentJoin}
            WHERE i.invoice_date BETWEEN ? AND ?
              AND TRIM(COALESCE(i.smartbill_number, '')) <> '' {$sourceWhere}
        ", [$start, $end]);

        foreach ($rows as $r) {
            $gross = max(0.0, (float)($r['gross_amount'] ?? 0));
            $paid = max(0.0, (float)($r['paid'] ?? 0));
            $paidCapped = min($gross, $paid);
            $remaining = max(0.0, $gross - $paidCapped);
            $dueDate = (string)($r['due_date'] ?? '');
            $isPaid = $gross <= 0.01 || $remaining <= 0.01;
            $isOverdue = !$isPaid && $dueDate !== '' && $dueDate < $today;

            $out['count']++;
            $out['gross'] += $gross;
            $out['paid_amount'] += $paidCapped;

            if ($isPaid) {
                $out['paid_count']++;
            } elseif ($isOverdue) {
                $out['overdue_count']++;
                $out['overdue_amount'] += $remaining;
            } else {
                $out['pending_count']++;
                $out['pending_amount'] += $remaining;
            }
        }

        if ($out['gross'] > 0.01) {
            $out['paid_pct_amount'] = (int)round(($out['paid_amount'] / $out['gross']) * 100);
            $out['pending_pct_amount'] = (int)round(($out['pending_amount'] / $out['gross']) * 100);
            $out['overdue_pct_amount'] = max(0, 100 - $out['paid_pct_amount'] - $out['pending_pct_amount']);
        }

        return $out;
    }
}

if (!function_exists('pz_dash_rx_appointments')) {
    function pz_dash_rx_appointments(PDO $pdo, string $start, string $end): array
    {
        $out = [
            'total' => 0,
            'completed' => 0,
            'pending' => 0,
            'pct' => 0,
            'rows' => [],
        ];

        if (!pz_dash_rx_table_exists($pdo, 'appointments')) {
            return $out;
        }

        $summary = pz_dash_rx_rows($pdo, "
            SELECT COUNT(*) AS total,
                   SUM(CASE WHEN status = 'finalizata' THEN 1 ELSE 0 END) AS completed
            FROM appointments
            WHERE appointment_date BETWEEN ? AND ?
              AND status != 'anulata'
        ", [$start, $end]);

        $out['total'] = (int)($summary[0]['total'] ?? 0);
        $out['completed'] = (int)($summary[0]['completed'] ?? 0);
        $out['pending'] = max(0, $out['total'] - $out['completed']);
        $out['pct'] = $out['total'] > 0 ? (int)round(($out['completed'] / $out['total']) * 100) : 0;

        $rows = pz_dash_rx_rows($pdo, "
            SELECT a.id, a.appointment_date, a.start_time, a.service_type, a.status,
                   c.name AS client_name, tm.name AS team_name
            FROM appointments a
            LEFT JOIN clients c ON c.id = a.client_id
            LEFT JOIN team_members tm ON tm.id = a.team_member_id
            WHERE a.appointment_date BETWEEN ? AND ?
              AND a.status != 'anulata'
            ORDER BY a.appointment_date ASC, a.start_time ASC, a.id ASC
            LIMIT 6
        ", [$start, $end]);

        foreach ($rows as $r) {
            $status = (string)($r['status'] ?? '');
            $statusCls = 'pending';
            $statusLbl = '·';
            if ($status === 'finalizata') {
                $statusCls = 'done';
                $statusLbl = 'gata';
            } elseif ($status === 'in_lucru' || $status === 'inceput') {
                $statusCls = 'in-progress';
                $statusLbl = 'curs';
            }

            $date = (string)($r['appointment_date'] ?? '');
            $time = pz_dash_rx_time($r['start_time'] ?? null);
            $out['rows'][] = [
                'time' => $start === $end ? $time : (date('d.m', strtotime($date)) . ' · ' . $time),
                'client' => trim((string)($r['client_name'] ?? '')) ?: 'Programare',
                'team' => trim((string)($r['team_name'] ?? '')),
                'status_class' => $statusCls,
                'status_label' => $statusLbl,
            ];
        }

        return $out;
    }
}

if (!function_exists('pz_dash_rx_data')) {
    function pz_dash_rx_data(): array
    {
        global $pdo, $finStart, $finEnd, $finLabel, $periodFin;

        if (!($pdo instanceof PDO)) {
            return ['enabled' => false];
        }

        $start = (string)($finStart ?? date('Y-m-01'));
        $end = (string)($finEnd ?? date('Y-m-t'));
        $label = (string)($finLabel ?? 'Luna curentă');
        $period = (string)($periodFin ?? 'month');
        $today = date('Y-m-d');

        $appointments = pz_dash_rx_appointments($pdo, $start, $end);
        $invoiceStatus = pz_dash_rx_invoice_status($pdo, $start, $end, $today);
        $chart = pz_dash_rx_chart_series($pdo, $start, $end);

        return [
            'enabled' => true,
            'period' => [
                'key' => $period,
                'label' => $label,
                'label_lower' => mb_strtolower($label, 'UTF-8'),
                'start' => $start,
                'end' => $end,
            ],
            'appointments' => $appointments,
            'invoices' => $invoiceStatus,
            'chart' => $chart,
            'money' => [
                'gross' => pz_dash_rx_money($invoiceStatus['gross']),
                'paid' => pz_dash_rx_money($invoiceStatus['paid_amount']),
                'pending' => pz_dash_rx_money($invoiceStatus['pending_amount']),
                'overdue' => pz_dash_rx_money($invoiceStatus['overdue_amount']),
            ],
        ];
    }
}

if (!function_exists('pz_dash_rx_assets')) {
    function pz_dash_rx_assets(): string
    {
        $data = pz_dash_rx_data();
        if (empty($data['enabled'])) {
            return '';
        }

        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        return <<<HTML
<script>
window.PZ_DASH_REACTIVE = {$json};
(function () {
    var data = window.PZ_DASH_REACTIVE || {};
    if (!data.enabled) return;

    function text(el, value) {
        if (el) el.textContent = value;
    }

    function findKpiByLabel(labelStart) {
        var cards = Array.prototype.slice.call(document.querySelectorAll('.pz-kpi'));
        return cards.find(function (card) {
            var label = card.querySelector('.pz-kpi-label');
            return label && label.textContent.trim().indexOf(labelStart) === 0;
        }) || null;
    }

    function findInfoCardBySmallTitle(titleStart) {
        var cards = Array.prototype.slice.call(document.querySelectorAll('.pz-card'));
        return cards.find(function (card) {
            var title = card.querySelector('.pz-card-title-sm');
            return title && title.textContent.trim().indexOf(titleStart) === 0;
        }) || null;
    }

    function updateInvoiceKpi() {
        var card = findKpiByLabel('Facturi emise');
        if (!card || !data.invoices) return;
        var badge = card.querySelector('.pz-kpi-badge');
        var value = card.querySelector('.pz-kpi-value');
        var bar = card.querySelector('.pz-kpi-bar');

        if (badge) badge.innerHTML = '<i class="ti ti-file-invoice" aria-hidden="true"></i>' + Number(data.invoices.count || 0);
        if (value) value.innerHTML = Number(data.invoices.paid_pct_amount || 0) + '<span class="unit">% încasate</span>';
        if (bar) {
            bar.innerHTML = '';
            var paid = document.createElement('span');
            paid.style.width = Math.max(0, Number(data.invoices.paid_pct_amount || 0)) + '%';
            paid.style.background = 'var(--pz-gr)';
            bar.appendChild(paid);

            var pending = document.createElement('span');
            pending.style.width = Math.max(0, Number(data.invoices.pending_pct_amount || 0)) + '%';
            pending.style.background = 'var(--pz-or)';
            bar.appendChild(pending);

            var overdue = document.createElement('span');
            overdue.style.width = Math.max(0, Number(data.invoices.overdue_pct_amount || 0)) + '%';
            overdue.style.background = 'var(--pz-re)';
            bar.appendChild(overdue);
        }
    }

    function updateAppointmentsKpi() {
        var card = findKpiByLabel('Programări');
        if (!card || !data.appointments || !data.period) return;
        var label = card.querySelector('.pz-kpi-label');
        var badge = card.querySelector('.pz-kpi-badge');
        var value = card.querySelector('.pz-kpi-value');
        var barSpan = card.querySelector('.pz-kpi-bar > span');
        var name = data.period.key === 'today' ? 'Programări azi' : ('Programări ' + data.period.label_lower);

        text(label, name);
        if (badge) badge.innerHTML = '<i class="ti ti-calendar" aria-hidden="true"></i>' + Number(data.appointments.pct || 0) + '%';
        if (value) value.innerHTML = Number(data.appointments.completed || 0) + '<span class="unit">/ ' + Number(data.appointments.total || 0) + ' finalizate</span>';
        if (barSpan) barSpan.style.width = Math.max(0, Number(data.appointments.pct || 0)) + '%';
    }

    function updateAppointmentsCard() {
        var card = findInfoCardBySmallTitle('Programări');
        if (!card || !data.appointments || !data.period) return;
        var smallTitle = card.querySelector('.pz-card-title-sm');
        var title = card.querySelector('.pz-card-title');
        var list = card.querySelector('.pz-appt-list');
        var name = data.period.key === 'today' ? 'Programări astăzi' : ('Programări ' + data.period.label_lower);

        text(smallTitle, name);
        text(title, Number(data.appointments.completed || 0) + ' finalizate · ' + Number(data.appointments.pending || 0) + ' rămase');

        if (!list) return;
        list.innerHTML = '';
        var rows = data.appointments.rows || [];
        if (!rows.length) {
            var empty = document.createElement('div');
            empty.className = 'pz-appt-empty';
            empty.textContent = 'Nu există programări în perioada selectată.';
            list.appendChild(empty);
            return;
        }

        rows.forEach(function (row) {
            var item = document.createElement('div');
            item.className = 'pz-appt-row' + (row.status_class === 'in-progress' ? ' active' : '');

            var time = document.createElement('div');
            time.className = 'pz-appt-time';
            time.textContent = row.time || '--:--';
            item.appendChild(time);

            var info = document.createElement('div');
            info.className = 'pz-appt-info';
            var client = document.createElement('p');
            client.className = 'name';
            client.textContent = row.client || 'Programare';
            info.appendChild(client);

            if (row.team) {
                var team = document.createElement('p');
                team.className = 'tech';
                team.textContent = row.team;
                info.appendChild(team);
            }
            item.appendChild(info);

            var status = document.createElement('span');
            status.className = 'pz-appt-status ' + (row.status_class || 'pending');
            status.textContent = row.status_label || '·';
            item.appendChild(status);

            list.appendChild(item);
        });
    }

    function recreateRevenueChart() {
        var canvas = document.getElementById('pzRevenueChart');
        if (!canvas || !window.Chart || !data.chart) return;
        var card = findInfoCardBySmallTitle('Venituri și încasări');
        if (card && data.period) {
            var title = card.querySelector('.pz-card-title');
            text(title, data.period.label);
        }

        var old = Chart.getChart(canvas);
        if (old) old.destroy();

        new Chart(canvas, {
            type: 'line',
            data: {
                labels: data.chart.labels || [],
                datasets: [
                    {
                        label: 'Venituri',
                        data: data.chart.issued || [],
                        borderColor: '#2563EB',
                        backgroundColor: 'rgba(37, 99, 235, 0.08)',
                        tension: 0.35,
                        fill: true,
                        borderWidth: 2,
                        pointRadius: 3,
                        pointHoverRadius: 5,
                        pointBackgroundColor: '#2563EB'
                    },
                    {
                        label: 'Încasări',
                        data: data.chart.paid || [],
                        borderColor: '#166534',
                        backgroundColor: 'rgba(22, 101, 52, 0.08)',
                        tension: 0.35,
                        fill: true,
                        borderWidth: 2,
                        borderDash: [5, 3],
                        pointRadius: 3,
                        pointHoverRadius: 5,
                        pointBackgroundColor: '#166534'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                return ctx.dataset.label + ': ' + Number(ctx.parsed.y || 0).toLocaleString('ro-RO') + ' lei';
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        grid: { color: 'rgba(0,0,0,0.06)', drawBorder: false },
                        ticks: {
                            color: '#64748B',
                            font: { size: 10 },
                            callback: function (v) { return Math.round(Number(v || 0) / 1000) + 'k'; }
                        }
                    },
                    x: { grid: { display: false }, ticks: { color: '#64748B', font: { size: 10 } } }
                }
            }
        });
    }

    function recreateStatusChart() {
        var canvas = document.getElementById('pzStatusChart');
        if (!canvas || !window.Chart || !data.invoices) return;
        var paid = Number(data.invoices.paid_count || 0);
        var pending = Number(data.invoices.pending_count || 0);
        var overdue = Number(data.invoices.overdue_count || 0);
        var total = paid + pending + overdue;
        var old = Chart.getChart(canvas);
        if (old) old.destroy();

        var card = findInfoCardBySmallTitle('Status facturi');
        if (card) {
            var title = card.querySelector('.pz-card-title');
            if (data.period) text(title, data.period.label);
            var values = card.querySelectorAll('.pz-donut-legend .value');
            if (values[0]) values[0].textContent = paid;
            if (values[1]) values[1].textContent = pending;
            if (values[2]) values[2].textContent = overdue;
        }

        if (total <= 0) return;

        new Chart(canvas, {
            type: 'doughnut',
            data: {
                labels: ['Încasate', 'În termen', 'Restante'],
                datasets: [{
                    data: [paid, pending, overdue],
                    backgroundColor: ['#166534', '#9A3412', '#991B1B'],
                    borderWidth: 0,
                    spacing: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '72%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                var pct = total > 0 ? Math.round((Number(ctx.parsed || 0) / total) * 100) : 0;
                                return ctx.label + ': ' + ctx.parsed + ' (' + pct + '%)';
                            }
                        }
                    }
                }
            }
        });
    }

    function run() {
        updateInvoiceKpi();
        updateAppointmentsKpi();
        updateAppointmentsCard();
        recreateRevenueChart();
        recreateStatusChart();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', run);
    } else {
        run();
    }
})();
</script>
HTML;
    }
}

if (basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'dashboard.php' && !defined('PZ_DASHBOARD_REACTIVE_CARDS_ASSETS')) {
    define('PZ_DASHBOARD_REACTIVE_CARDS_ASSETS', true);

    ob_start(static function (string $html): string {
        if (stripos($html, 'class="pz-kpi"') === false) {
            return $html;
        }

        $assets = pz_dash_rx_assets();
        if ($assets === '') {
            return $html;
        }

        if (stripos($html, '</body>') !== false) {
            return str_ireplace('</body>', $assets . "\n</body>", $html);
        }

        return $html . $assets;
    });
}
