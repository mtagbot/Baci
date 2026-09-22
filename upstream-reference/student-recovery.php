<?php
// File: student-recovery.php
require_once __DIR__ . '/includes/auth.php';
require_permission('import_data');
require_once __DIR__ . '/includes/header.php';
?>
<div class="integrated-hub"><div class="card integrated-tabs"><a class="btn btn-primary" onclick="loadHub(this,'import-students.php?embedded=1')">ایمپورت دانش‌آموزان</a><a class="btn btn-success" onclick="loadHub(this,'import-photos.php?embedded=1')">آپلود ZIP تصاویر</a></div><iframe id="hubFrame" src="import-students.php?embedded=1"></iframe></div>
<script>function loadHub(a,u){document.getElementById('hubFrame').src=u;document.querySelectorAll('.integrated-tabs .btn').forEach(x=>x.classList.remove('btn-primary'));a.classList.add('btn-primary')}</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
