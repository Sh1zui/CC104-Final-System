/*
 * Public browse page (browse.php). The search is a plain GET form, so this
 * file only adds conveniences: a return time that follows the pickup
 * (one day later by default), and "Check dates" on a card jumping to the
 * pickup field.
 */
(function () {
    'use strict';

    var pickup = document.getElementById('s-pickup');
    var ret = document.getElementById('s-return');
    var grid = document.getElementById('car-grid');
    if (!pickup || !ret) return;

    function pad(n) { return String(n).padStart(2, '0'); }

    pickup.addEventListener('change', function () {
        if (!pickup.value) return;
        ret.min = pickup.value;
        if (!ret.value || ret.value <= pickup.value) {
            var d = new Date(pickup.value);
            d.setDate(d.getDate() + 1);
            ret.value = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
        }
    });

    if (grid) {
        grid.addEventListener('click', function (e) {
            if (!e.target.closest('[data-pick-dates]')) return;
            pickup.scrollIntoView({ behavior: 'smooth', block: 'center' });
            pickup.focus();
            if (pickup.showPicker) { try { pickup.showPicker(); } catch (err) { /* not allowed everywhere */ } }
        });
    }
})();
