<?php

declare(strict_types=1);

/**
 * accounts/gst_report_export.php
 * Excel export for the GST Report (accounts/gst_report.php). No
 * spreadsheet library is bundled with this project (see composer.json)
 * and none can be installed in this environment, so — same lightweight
 * approach commonly used for simple "Excel export" features in plain
 * PHP — this streams an HTML table with an .xls filename and Excel
 * MIME type; Excel opens it directly as a normal, formatted workbook.
 * Mirrors the on-screen/print report's GST Entered / Non-GST
 * separation exactly (see split_gst_invoices() in includes/billing.php)
 * so auditors get the same clearly separated sections in the download.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
require_menu_access('accounts');

$availableYears = financial_years_with_data();
$financialYear = $_GET['fy'] ?? financial_year_for(date('Y-m-d'));
if (!in_array($financialYear, $availableYears, true)) {
    $financialYear = financial_year_for(date('Y-m-d'));
}

['start' => $startDate, 'end' => $endDate] = financial_year_bounds($financialYear);

$allGstInvoices = gst_invoices_for_period($startDate, $endDate);
['gst' => $gstInvoices, 'non_gst' => $nonGstInvoices] = split_gst_invoices($allGstInvoices);
$gstTotals = totals_for_invoice_group($gstInvoices);
$nonGstTotals = totals_for_invoice_group($nonGstInvoices);

$filename = 'GST_Report_FY' . str_replace('-', '_', $financialYear) . '.xls';

header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: private, max-age=0, must-revalidate');
header('X-Content-Type-Options: nosniff');

$fmt = static fn (float $n): string => number_format($n, 2, '.', '');
$h = static fn (?string $v): string => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');

echo "\xEF\xBB\xBF"; // UTF-8 BOM so ₹ and company name render correctly in Excel
?>
<html>
<head><meta charset="UTF-8"></head>
<body>
<table border="1">
    <tr><td colspan="9" style="font-size:14pt;font-weight:bold;"><?= $h(company_name()) ?> — GST Report</td></tr>
    <tr><td colspan="9">Financial Year <?= $h($financialYear) ?> (<?= $h(date('d M Y', strtotime($startDate))) ?> to <?= $h(date('d M Y', strtotime($endDate))) ?>)</td></tr>
    <?php if (company_gstin()): ?><tr><td colspan="9">GSTIN: <?= $h(company_gstin()) ?></td></tr><?php endif; ?>
    <tr><td colspan="9"></td></tr>

    <tr><td colspan="9" style="font-weight:bold;background:#d9ead3;">GST ENTERED RECORDS (<?= count($gstInvoices) ?>)</td></tr>
    <tr style="font-weight:bold;background:#f3f3f3;">
        <td>Invoice #</td><td>GST No.</td><td>Date</td><td>Client</td>
        <td>Taxable</td><td>CGST</td><td>SGST</td><td>IGST</td><td>Total</td>
    </tr>
    <?php if (empty($gstInvoices)): ?>
        <tr><td colspan="9">No GST invoices issued in this period.</td></tr>
    <?php endif; ?>
    <?php foreach ($gstInvoices as $inv): ?>
        <tr>
            <td><?= $h($inv['invoice_number']) ?></td>
            <td><?= $h($inv['client_gstin'] ?? '') ?></td>
            <td><?= $h(date('d-M-Y', strtotime($inv['invoice_date']))) ?></td>
            <td><?= $h($inv['client_name'] ?? '') ?></td>
            <td><?= $fmt((float) $inv['taxable_amount']) ?></td>
            <td><?= $fmt((float) $inv['cgst_amount']) ?></td>
            <td><?= $fmt((float) $inv['sgst_amount']) ?></td>
            <td><?= $fmt((float) $inv['igst_amount']) ?></td>
            <td><?= $fmt((float) $inv['total_amount']) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!empty($gstInvoices)): ?>
        <tr style="font-weight:bold;">
            <td colspan="4">GST Total</td>
            <td><?= $fmt($gstTotals['taxable_amount']) ?></td>
            <td><?= $fmt($gstTotals['cgst_amount']) ?></td>
            <td><?= $fmt($gstTotals['sgst_amount']) ?></td>
            <td><?= $fmt($gstTotals['igst_amount']) ?></td>
            <td><?= $fmt($gstTotals['total_amount']) ?></td>
        </tr>
    <?php endif; ?>

    <tr><td colspan="9"></td></tr>
    <tr><td colspan="9"></td></tr>

    <tr><td colspan="9" style="font-weight:bold;background:#f4cccc;">NON-GST RECORDS (<?= count($nonGstInvoices) ?>)</td></tr>
    <tr style="font-weight:bold;background:#f3f3f3;">
        <td>Invoice #</td><td>Date</td><td>Client</td><td>Amount</td>
    </tr>
    <?php if (empty($nonGstInvoices)): ?>
        <tr><td colspan="4">No non-GST invoices issued in this period.</td></tr>
    <?php endif; ?>
    <?php foreach ($nonGstInvoices as $inv): ?>
        <tr>
            <td><?= $h($inv['invoice_number']) ?></td>
            <td><?= $h(date('d-M-Y', strtotime($inv['invoice_date']))) ?></td>
            <td><?= $h($inv['client_name'] ?? '') ?></td>
            <td><?= $fmt((float) $inv['total_amount']) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!empty($nonGstInvoices)): ?>
        <tr style="font-weight:bold;">
            <td colspan="3">Non-GST Total</td>
            <td><?= $fmt($nonGstTotals['total_amount']) ?></td>
        </tr>
    <?php endif; ?>
</table>
</body>
</html>
