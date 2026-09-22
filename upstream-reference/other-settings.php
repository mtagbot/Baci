<?php
// File: other-settings.php
require_once __DIR__ . '/includes/auth.php';
require_admin();
require_once __DIR__ . '/includes/header.php';
?>
<div class="integrated-hub"><div class="card integrated-tabs"><a class="btn btn-primary" onclick="loadHub(this,'activity-logs.php?embedded=1')">لاگ فعالیت</a><a class="btn btn-warning" onclick="loadHub(this,'migration-updater.php?embedded=1')">مایگریشن</a><a class="btn btn-success" onclick="loadHub(this,'backups.php?embedded=1')">پشتیبان‌گیری</a><a class="btn btn-outline" onclick="loadHub(this,'api/docs.php?embedded=1')">مستندات API</a></div><iframe id="hubFrame" src="activity-logs.php?embedded=1"></iframe></div>
<script>function loadHub(a,u){document.getElementById('hubFrame').src=u;document.querySelectorAll('.integrated-tabs .btn').forEach(x=>x.classList.remove('btn-primary'));a.classList.add('btn-primary')}</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
