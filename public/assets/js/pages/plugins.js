/**
 * OwnPay Admin — Plugins Page JS
 * Card-grid tab filtering + hover micro-interactions.
 */
(function () {
    'use strict';

    // Tab filtering
    document.querySelectorAll('.op-tab').forEach(function (tab) {
        tab.addEventListener('click', function () {
            document.querySelectorAll('.op-tab').forEach(function (t) { t.classList.remove('active'); });
            tab.classList.add('active');
            var filter = tab.dataset.tab;
            document.querySelectorAll('.plugin-row').forEach(function (card) {
                if (filter === 'all') {
                    card.style.display = '';
                } else if (filter === 'active') {
                    card.style.display = card.dataset.status === 'active' ? '' : 'none';
                } else if (filter === 'inactive') {
                    card.style.display = card.dataset.status !== 'active' ? '' : 'none';
                }
            });
        });
    });
}());
