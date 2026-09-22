<?php
/**
 * Export Helper Functions (includes/export.php)
 */

if (!function_exists('export_report_link_excel')) {
    function export_report_link_excel($report_id) {
        return "export-excel.php?id=" . (int)$report_id;
    }
}

if (!function_exists('export_report_link_pdf')) {
    function export_report_link_pdf($report_id) {
        return "export-pdf.php?id=" . (int)$report_id;
    }
}
