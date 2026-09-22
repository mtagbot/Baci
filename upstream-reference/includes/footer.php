<?php if (is_admin_logged_in() || is_student_logged_in()): ?>
    </main>
</div>
<?php else: ?>
    </main>
<?php endif; ?>

<footer class="py-4 px-6 border-t border-color bg-card text-center text-xs text-muted mt-auto">
    <span>سیستم مدیریت کارنامه دانش‌آموزی &bull; نگارش <?php echo clean(get_setting('system_version', '2.5.0')); ?></span>
    <span class="mx-2">|</span>
    <span>توسعه‌یافته با PHP خالص و MySQL</span>
</footer>

<script src="assets/vendor/chart.umd.min.js"></script>
<script src="assets/js/main.js"></script>
</body>
</html>
