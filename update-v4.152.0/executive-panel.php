<?php
// File: executive-panel.php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/school_roles.php';
ensure_school_roles_schema();
if (!current_teacher_is_executive()) redirect('admin-login.php?tab=teacher');
require_once __DIR__ . '/includes/header.php';
?>
<div class="integrated-hub">
  <div class="card integrated-tabs">
    <a class="btn btn-primary" onclick="loadHub(this,'students.php?embedded=1')">مدیریت دانش‌آموزان</a>
    <a class="btn btn-success" onclick="loadHub(this,'exams.php?embedded=1')">امتحانات حضوری</a>
    <a class="btn btn-success" onclick="loadHub(this,'online-exams.php?embedded=1')" style="background:#4f46e5;color:white;"><svg data-ui-icon="science" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M8 2h8m-6 0v7L3 21h18L14 9V2M7 15h10"/></svg> آزمون‌های آنلاین</a>
    <a class="btn btn-outline" onclick="loadHub(this,'exam-question-bank.php?embedded=1')">اختصاص طراحی آزمون</a>
    <a class="btn btn-warning" onclick="loadHub(this,'reports.php?embedded=1')">مدیریت کارنامه‌ها</a>
    <a class="btn btn-outline" onclick="loadHub(this,'classes.php?tab=classes&embedded=1')">کلاس‌ها و دروس</a>
  </div>
  <iframe id="hubFrame" src="students.php?embedded=1"></iframe>
</div>
<script>function loadHub(a,u){document.getElementById('hubFrame').src=u;document.querySelectorAll('.integrated-tabs .btn').forEach(x=>x.classList.remove('btn-primary'));a.classList.add('btn-primary')}</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
