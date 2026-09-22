<?php
// File: reports-management.php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/school_roles.php';
ensure_school_roles_schema();
if (!current_teacher_is_executive()) require_permission('manage_reports');
require_once __DIR__ . '/includes/header.php';
?>
<div class="integrated-hub"><div class="card integrated-tabs"><a class="btn btn-primary" onclick="loadHub(this,'reports.php?embedded=1')">کارنامه‌ها</a><a class="btn btn-success" onclick="loadHub(this,'import.php?embedded=1')">ایمپورت Wizard</a><a class="btn btn-warning" onclick="loadHub(this,'analytics.php?embedded=1')">تحلیل و مقایسه</a><a class="btn btn-outline" onclick="loadHub(this,'grade-entry-management.php?embedded=1')">مدیریت ثبت نمره</a><a class="btn btn-primary" onclick="loadHub(this,'report-broadcast.php?embedded=1')">اعلام سراسری</a></div><iframe id="hubFrame" src="reports.php?embedded=1"></iframe></div>
<script>function loadHub(a,u){document.getElementById('hubFrame').src=u;document.querySelectorAll('.integrated-tabs .btn').forEach(x=>x.classList.remove('btn-primary'));a.classList.add('btn-primary')}</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
