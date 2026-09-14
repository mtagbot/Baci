<?php
/**
 * Cron Queue Worker for Large Imports (cron/import-queue-worker.php)
 * Run CLI: php /path/to/student-report-system/cron/import-queue-worker.php
 */

if (php_sapi_name() !== 'cli' && !isset($_GET['token'])) {
    // Optional basic protection if executed via web
    die("This script must be run from command line or via cron job.");
}

require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/logger.php';

echo "Checking queue jobs...\n";

$jobs = DB::fetchAll("SELECT * FROM import_queue WHERE status = 'queued' ORDER BY id ASC LIMIT 5");

if (empty($jobs)) {
    echo "No queued jobs found.\n";
    exit;
}

foreach ($jobs as $job) {
    echo "Processing Job ID: {$job['id']} (Session ID: {$job['session_id']})...\n";
    DB::execute("UPDATE import_queue SET status = 'processing', progress = 10 WHERE id = ?", [$job['id']]);

    try {
        // Simulate background batch processing
        sleep(1);
        DB::execute("UPDATE import_queue SET progress = 50 WHERE id = ?", [$job['id']]);
        sleep(1);
        DB::execute("UPDATE import_queue SET status = 'completed', progress = 100, processed_at = NOW() WHERE id = ?", [$job['id']]);
        DB::execute("UPDATE import_sessions SET status = 'completed' WHERE id = ?", [$job['session_id']]);
        echo "Job ID {$job['id']} completed successfully.\n";
    } catch (Exception $e) {
        DB::execute("UPDATE import_queue SET status = 'failed', error_log = ? WHERE id = ?", [$e->getMessage(), $job['id']]);
        echo "Job ID {$job['id']} failed: " . $e->getMessage() . "\n";
    }
}
