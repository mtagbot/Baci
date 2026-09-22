<?php
// File: courses-management.php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/school_roles.php';
ensure_school_roles_schema();
if (!current_teacher_is_executive()) require_permission('manage_classes');
require_once __DIR__ . '/includes/header.php';
?>
<div class="integrated-hub"><div class="card integrated-tabs"><a class="btn btn-primary" onclick="loadHub(this,'classes.php?embedded=1')">کلاس‌ها و دروس</a><a class="btn btn-success" onclick="loadHub(this,'import-teachers.php?tab=list&embedded=1')">دبیران</a><a class="btn btn-warning" onclick="loadHub(this,'import-schedule.php?embedded=1')">برنامه هفتگی</a><a class="btn btn-outline" onclick="loadHub(this,'academic-years.php?embedded=1')">سال‌های تحصیلی</a></div><iframe id="hubFrame" src="classes.php?embedded=1"></iframe></div>
<script>function loadHub(a,u){document.getElementById('hubFrame').src=u;document.querySelectorAll('.integrated-tabs .btn').forEach(x=>x.classList.remove('btn-primary'));a.classList.add('btn-primary')}</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
