<?php
/**
 * Report Card View Alias (view-report.php)
 */
require_once __DIR__ . '/includes/functions.php';
$id = (int)($_GET['id'] ?? 0);
redirect("report-view.php?id=" . $id);
