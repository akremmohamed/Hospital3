/**
 * Shared Laboratory module UI helpers
 */
(function (window) {
    'use strict';

    function initHighContrastToggle() {
        var btn = document.getElementById('labContrastToggle');
        if (!btn) return;
        var key = 'lab_high_contrast';
        if (localStorage.getItem(key) === '1') {
            document.body.classList.add('lab-high-contrast');
        }
        btn.addEventListener('click', function () {
            document.body.classList.toggle('lab-high-contrast');
            localStorage.setItem(key, document.body.classList.contains('lab-high-contrast') ? '1' : '0');
        });
    }

    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function priorityBadge(priority) {
        var p = (priority || 'routine').toLowerCase();
        var cls = p === 'stat' ? 'lab-badge-stat' : (p === 'urgent' ? 'lab-badge-urgent' : 'lab-badge-routine');
        return '<span class="lab-badge ' + cls + '">' + escapeHtml(p) + '</span>';
    }

    function validateNumericRange(input, low, high) {
        var val = parseFloat(input.value);
        if (isNaN(val)) return { abnormal: false, critical: false };
        var abnormal = false;
        var critical = false;
        if (low !== null && low !== '' && !isNaN(parseFloat(low)) && val < parseFloat(low)) abnormal = true;
        if (high !== null && high !== '' && !isNaN(parseFloat(high)) && val > parseFloat(high)) abnormal = true;
        if (low !== null && low !== '' && !isNaN(parseFloat(low)) && val < parseFloat(low) * 0.5) critical = true;
        if (high !== null && high !== '' && !isNaN(parseFloat(high)) && val > parseFloat(high) * 1.5) critical = true;
        return { abnormal: abnormal, critical: critical };
    }

    function bindResultValidation(container) {
        if (!container) return;
        container.querySelectorAll('[data-lab-result-input]').forEach(function (input) {
            input.addEventListener('input', function () {
                var row = input.closest('.lab-result-row');
                if (!row) return;
                var low = input.dataset.refLow;
                var high = input.dataset.refHigh;
                var flags = validateNumericRange(input, low, high);
                row.classList.toggle('abnormal', flags.abnormal);
                row.classList.toggle('critical', flags.critical);
                var warn = row.querySelector('[data-range-warning]');
                if (warn) {
                    if (flags.critical) {
                        warn.textContent = 'CRITICAL — verify and add mandatory notes';
                        warn.style.display = 'block';
                    } else if (flags.abnormal) {
                        warn.textContent = 'Out of reference range';
                        warn.style.display = 'block';
                    } else {
                        warn.style.display = 'none';
                    }
                }
                var critToggle = row.querySelector('[data-critical-toggle]');
                var critNotes = row.querySelector('[data-critical-notes]');
                if (critToggle && critNotes) {
                    critToggle.checked = flags.critical || critToggle.checked;
                    critNotes.style.display = critToggle.checked ? 'block' : 'none';
                }
            });
        });
        container.querySelectorAll('[data-critical-toggle]').forEach(function (toggle) {
            toggle.addEventListener('change', function () {
                var row = toggle.closest('.lab-result-row');
                var notes = row && row.querySelector('[data-critical-notes]');
                if (notes) notes.style.display = toggle.checked ? 'block' : 'none';
                if (row) row.classList.toggle('critical', toggle.checked);
            });
        });
    }

    function generateBarcode(prefix) {
        prefix = prefix || 'LAB';
        return prefix + '-' + Date.now().toString(36).toUpperCase() + '-' + Math.random().toString(36).substr(2, 5).toUpperCase();
    }

    document.addEventListener('DOMContentLoaded', initHighContrastToggle);

    window.LabUI = {
        escapeHtml: escapeHtml,
        priorityBadge: priorityBadge,
        validateNumericRange: validateNumericRange,
        bindResultValidation: bindResultValidation,
        generateBarcode: generateBarcode,
        initHighContrastToggle: initHighContrastToggle
    };
})(window);
