<?php
/**
 * Project ZIP Exporter (download-zip.php)
 * Creates on-the-fly or serves ready ZIP archive of the complete system.
 */

require_once __DIR__ . '/includes/auth.php';

// Allow super admin or authorized users to download ZIP
if (!is_admin_logged_in()) {
    die("غیرمجاز (Unauthorized)");
}

$zipPath = __DIR__ . '/backups/student-report-system.zip';

// Create zip using ZipArchive if available
if (class_exists('ZipArchive')) {
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
        $rootPath = realpath(__DIR__);
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rootPath), RecursiveIteratorIterator::LEAVES_ONLY);
        foreach ($files as $name => $file) {
            if (!$file->isDir()) {
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen($rootPath) + 1);
                // Exclude backups folder large zips or lock files if desired
                if (strpos($relativePath, 'backups/student-report-system.zip') === false) {
                    $zip->addFile($filePath, $relativePath);
                }
            }
        }
        $zip->close();
    }
}

if (file_exists($zipPath)) {
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="student-report-system-v2.5.0.zip"');
    header('Content-Length: ' . filesize($zipPath));
    readfile($zipPath);
    exit;
} else {
    die("خطا در ایجاد یا دریافت فایل ZIP پروژه.");
}
