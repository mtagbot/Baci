<?php
// File: migration-academic-year-normalizer.php - Normalize all academic_year to unified format YYYY/YYYY
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/academic_year_helpers.php';
require_admin();

echo "<h2>🔄 نرمال‌سازی سال تحصیلی به فرمت جامع (1405/1406)</h2><pre style='background:#f5f5f5;padding:15px;border-radius:8px;'>";

echo "Current default year (before): ".get_setting('current_academic_year','')."\n";
$unifiedCurrent = unify_academic_year(get_setting('current_academic_year',''));
echo "Unified: $unifiedCurrent\n\n";

try {
    ensure_academic_years_unified_schema();
    echo "✅ ensure_academic_years_unified_schema executed\n";
} catch (Exception $e) {
    echo "❌ Error in ensure: ".$e->getMessage()."\n";
}

$tables = [
    'academic_years' => 'year_name',
    'students' => 'academic_year',
    'reports' => 'academic_year',
    'classes' => 'academic_year',
    'class_schedules' => 'academic_year',
    'exam_schedules' => 'academic_year',
    'exam_student_seating' => 'academic_year',
    'online_exams' => 'academic_year',
    'online_question_categories' => 'academic_year',
    'teachers' => 'academic_year',
    'grade_entry_permissions' => 'academic_year',
    'report_locks' => 'academic_year',
];

$totalFixed = 0;
foreach ($tables as $table=>$col) {
    echo "\n--- Table: $table ---\n";
    try {
        $rows = DB::fetchAll("SELECT id, `$col` as ay FROM `$table` WHERE `$col` IS NOT NULL AND `$col`<>'' LIMIT 2000");
        $fixedInTable = 0;
        foreach ($rows as $r) {
            $raw = $r['ay'] ?? '';
            $unified = unify_academic_year($raw);
            if ($unified !== '' && $unified !== $raw) {
                DB::execute("UPDATE `$table` SET `$col`=? WHERE id=?", [$unified, $r['id']]);
                $fixedInTable++;
                $totalFixed++;
                echo "  Fixed ID {$r['id']}: '$raw' => '$unified'\n";
            }
        }
        if ($fixedInTable==0) echo "  No changes needed (already unified)\n";
        else echo "  Fixed $fixedInTable rows\n";
    } catch (Exception $e) {
        echo "  Error or table not exists: ".$e->getMessage()."\n";
    }
}

echo "\n\n=== Summary ===\n";
echo "Total rows fixed: $totalFixed\n";
echo "Current default year (after): ".get_setting('current_academic_year','')." -> ".get_current_academic_year()."\n";

echo "\n\nUnified years list:\n";
try {
    $all = get_all_academic_years_unified();
    foreach ($all as $y) echo "- $y\n";
} catch (Exception $e) { echo "Error: ".$e->getMessage()."\n"; }

echo "</pre>";
echo "<p><a href='academic-years.php'>رفتن به مدیریت سال‌های تحصیلی</a> | <a href='online-exams.php'>آزمون‌های آنلاین</a></p>";
?>
