/* Repeat score is a visual fill helper only; it is intentionally not submitted. */
(function () {
    'use strict';

    document.addEventListener('input', function (event) {
        const repeat = event.target;
        if (!repeat || repeat.id !== 'drmRepeatScore') return;

        const raw = String(repeat.value || '').trim();
        if (raw === '') return;
        const score = Number(raw);
        if (!Number.isFinite(score) || score < 0 || score > 21) return;

        const form = repeat.closest('form');
        const table = form && form.querySelector('#disciplineReportTable');
        if (!table) return;

        table.querySelectorAll('.drm-score:not(:disabled)').forEach(function (field) {
            field.value = raw;
        });
    });
})();
