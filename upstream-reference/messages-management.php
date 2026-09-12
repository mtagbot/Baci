<?php
// File: messages-management.php
require_once __DIR__ . '/includes/auth.php';
require_permission('send_sms');
require_once __DIR__ . '/includes/header.php';
?>
<div class="integrated-hub"><div class="card integrated-tabs"><a class="btn btn-primary" onclick="loadHub(this,'notifications.php?embedded=1')">اعلان‌ها</a><a class="btn btn-success" onclick="loadHub(this,'sms-panel.php?embedded=1')">سامانه پیامکی</a></div><iframe id="hubFrame" src="notifications.php?embedded=1"></iframe></div>
<script>function loadHub(a,u){document.getElementById('hubFrame').src=u;document.querySelectorAll('.integrated-tabs .btn').forEach(x=>x.classList.remove('btn-primary'));a.classList.add('btn-primary')}</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
