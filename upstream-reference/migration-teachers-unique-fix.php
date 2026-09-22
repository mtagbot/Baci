<?php
// File: migration-teachers-unique-fix.php - Fix teachers national_id unique to allow same teacher in multiple years
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/academic_year_helpers.php';
require_admin();

echo "<h2>🔧 فیکس کلید یکتای دبیران - اجازه یک دبیر در چند سال تحصیلی</h2><pre style='background:#f5f5f5;padding:15px;border-radius:8px;'>";

echo "Before fix:\n";
try {
    $indexes = DB::fetchAll("SHOW INDEX FROM teachers");
    foreach ($indexes as $idx) {
        echo "- Index: {$idx['Key_name']} Column: {$idx['Column_name']} Unique: ".($idx['Non_unique']==0?'YES':'NO')."\n";
    }
} catch (Exception $e) { echo "Error SHOW INDEX: ".$e->getMessage()."\n"; }

echo "\nAttempting to drop old unique key national_id...\n";
try { DB::execute("ALTER TABLE teachers DROP INDEX national_id"); echo "✅ Dropped index national_id\n"; } catch (Exception $e) { echo "⚠️ Drop national_id failed (may not exist): ".$e->getMessage()."\n"; }
try { DB::execute("ALTER TABLE teachers DROP INDEX `national_id`"); echo "✅ Dropped\n"; } catch (Exception $e) {}
try { DB::execute("ALTER TABLE teachers DROP KEY national_id"); echo "✅ Dropped KEY\n"; } catch (Exception $e) {}

echo "\nAdding composite unique key (national_id, academic_year)...\n";
try {
    DB::execute("ALTER TABLE teachers ADD UNIQUE KEY uniq_nid_year (national_id, academic_year)");
    echo "✅ Added uniq_nid_year (national_id, academic_year)\n";
} catch (Exception $e) {
    echo "⚠️ Add composite failed: ".$e->getMessage()."\n";
    // Check if there are duplicates that prevent adding unique
    echo "\nChecking for duplicates that prevent unique...\n";
    try {
        $dups = DB::fetchAll("SELECT national_id, academic_year, COUNT(*) c FROM teachers GROUP BY national_id, academic_year HAVING c>1");
        if (!empty($dups)) {
            echo "Found duplicate (national_id, academic_year):\n";
            foreach ($dups as $d) {
                echo "- {$d['national_id']} / {$d['academic_year']} : {$d['c']} rows\n";
                // Keep latest, delete others
                $rows = DB::fetchAll("SELECT id FROM teachers WHERE national_id=? AND academic_year=? ORDER BY id DESC", [$d['national_id'], $d['academic_year']]);
                $keep = array_shift($rows);
                foreach ($rows as $r) {
                    DB::execute("DELETE FROM teachers WHERE id=?", [$r['id']]);
                    echo "  Deleted duplicate ID {$r['id']}, kept {$keep['id']}\n";
                }
            }
            // Try again
            DB::execute("ALTER TABLE teachers ADD UNIQUE KEY uniq_nid_year (national_id, academic_year)");
            echo "✅ Added after cleaning duplicates\n";
        } else {
            echo "No duplicate (national_id, academic_year) found, but add failed - maybe old unique still exists?\n";
            // Try to list indexes again
            $indexes = DB::fetchAll("SHOW INDEX FROM teachers");
            foreach ($indexes as $idx) {
                echo "- Index: {$idx['Key_name']} Column: {$idx['Column_name']} Unique: ".($idx['Non_unique']==0?'YES':'NO')."\n";
            }
        }
    } catch (Exception $e2) {
        echo "❌ Second attempt failed: ".$e2->getMessage()."\n";
    }
}

echo "\nAfter fix:\n";
try {
    $indexes = DB::fetchAll("SHOW INDEX FROM teachers");
    foreach ($indexes as $idx) {
        echo "- Index: {$idx['Key_name']} Column: {$idx['Column_name']} Unique: ".($idx['Non_unique']==0?'YES':'NO')."\n";
    }
} catch (Exception $e) { echo "Error: ".$e->getMessage()."\n"; }

echo "\n\nTest transfer of teacher to other year should now work without Duplicate entry error.\n";
echo "Example: If teacher with national_id 5560755680 exists in 1404/1405, you can now create same national_id in 1405/1406\n";

echo "</pre>";
echo "<p><a href='import-teachers.php?tab=list'>رفتن به مدیریت دبیران</a> | <a href='academic-years.php'>سال‌های تحصیلی</a></p>";
?>
