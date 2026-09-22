<?php
/**
 * Single-File Project Code Bundle Exporter (export-project-bundle.php)
 * Packages all code files into a single JSON/Markdown file uploadable to Arena.ai Agent Mode chat!
 */

require_once __DIR__ . '/includes/auth.php';

if (!is_admin_logged_in()) {
    die("غیرمجاز (Unauthorized)");
}

$bundle = [
    'version' => '2.7.0',
    'created_at' => date('Y-m-d H:i:s'),
    'description' => 'Complete single-file bundle of student-report-system source code ready for upload to Arena.ai chat input.',
    'files' => []
];

$rootPath = realpath(__DIR__);
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rootPath), RecursiveIteratorIterator::LEAVES_ONLY);

foreach ($files as $name => $file) {
    if (!$file->isDir()) {
        $filePath = $file->getRealPath();
        $relativePath = substr($filePath, strlen($rootPath) + 1);
        $relativePath = str_replace('\\', '/', $relativePath);

        // Exclude vendor binary files, uploads, and backups to keep JSON clean & attachable to chat
        if (strpos($relativePath, 'vendor/') === false && strpos($relativePath, 'backups/') === false && strpos($relativePath, 'uploads/') === false && strpos($relativePath, '.git') === false) {
            $content = file_get_contents($filePath);
            $bundle['files'][$relativePath] = $content;
        }
    }
}

$jsonOutput = json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
$filename = 'PROJECT-CODE-BUNDLE-v2.7.0.json';

header('Content-Type: application/json; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($jsonOutput));
echo $jsonOutput;
exit;
