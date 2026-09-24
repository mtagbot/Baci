<?php /* v4.60.0 fix: teachers were missing from this condition, so for the
   teacher panel the wrapping <div class="flex flex-1"> was never closed and
   the footer rendered INSIDE the flex row (stuck to the left on desktop).
   Teachers get a sidebar + main exactly like admin/student, so they must
   close both tags too. */
if (is_admin_logged_in() || is_student_logged_in() || (function_exists('is_teacher_logged_in') && is_teacher_logged_in())): ?>
    </main>
</div>
<?php else: ?>
    </main>
<?php endif; ?>

<footer class="py-4 px-6 border-t border-color bg-card text-center text-xs text-muted mt-auto">
    <span>طراحی و توسعه : معاونت فناوری متوسطه اول</span>
</footer>

<script src="assets/vendor/chart.umd.min.js"></script>
<script src="assets/js/main.js"></script>
<script src="assets/js/ui-modern.js"></script><!-- v4.30.0: UX enhancements -->

</body>
</html>
