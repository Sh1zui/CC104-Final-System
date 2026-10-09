<?php
/**
 * CSV download for one report table (Phase 12), same range rules as
 * admin/reports.php. Plain numbers (no peso sign or thousands separators)
 * so spreadsheets can add them up; text cells that look like formulas are
 * defused (csv_safe()).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/reports_data.php';

$user = require_role('admin');

$report = (string) ($_GET['report'] ?? '');
$range = report_range($_GET);
$data = report_csv_rows($report, $range);
if ($data === null) {
    http_response_code(404);
    exit('Unknown report.');
}
[$header, $rows] = $data;

log_action((int) $user['user_id'], 'report_export', "Exported $report report, " . $range['label']);

$name = $report === 'receivables'
    ? 'autoway-receivables-' . date('Y-m-d') . '.csv'
    : 'autoway-' . $report . '-' . $range['from']->format('Y-m-d') . '-to-' . $range['to']->format('Y-m-d') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM, so Excel reads the UTF-8 (names with ñ, the ₱ in notes) correctly
fputcsv($out, $header, ',', '"', '');
foreach ($rows as $row) {
    fputcsv($out, array_map('csv_safe', $row), ',', '"', '');
}
fclose($out);
