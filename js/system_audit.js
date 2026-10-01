(function () {
    // Delegation survives the framework replacing the filter form via AJAX.
    if (window.jamiiAuditLiveInstalled) return;
    window.jamiiAuditLiveInstalled = true;
    var filterTimer;
    // Filter controls are plain selects, not FA's submit-on-change widgets.
    // Use the search button so changing a filter also resets the older-page cursor.
    function filterChanged(event) {
        var field = event.target;
        if (!field.form || field.form.name !== 'audit_filters' ||
            !field.matches('select, input:not([type=hidden]):not([type=submit]):not([type=button])')) return;
        if (field.name === 'party_type') {
            var party = field.form.querySelector('[name=party_id]');
            party.value = '';
        }
        window.clearTimeout(filterTimer);
        filterTimer = window.setTimeout(function () {
            var button = field.form.querySelector('[name=search]');
            if (button && document.contains(button)) button.click();
        }, 250);
    }
    // Select2 emits jQuery change events; native-only listeners miss these.
    function bindFilters() {
        if (window.jQuery) window.jQuery(document).on('change.jamiiAudit', 'form[name=audit_filters] select, form[name=audit_filters] input', filterChanged);
        else document.addEventListener('change', filterChanged);
    }
    // Framework scripts are emitted before the jQuery/Select2 libraries.
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bindFilters);
    else bindFilters();
    document.addEventListener('submit', function (event) {
        if (event.target.name === 'audit_filters') window.clearTimeout(filterTimer);
    });
    document.addEventListener('click', function (event) {
        var button = event.target.closest('#audit-live');
        if (!button) return;
        event.preventDefault();
        var field = button.form.querySelector('[name=live]');
        field.value = field.value === '1' ? '0' : '1';
        button.textContent = field.value === '1' ? 'Pause live updates' : 'Start live updates';
        document.getElementById('audit-live-status').textContent = field.value === '1' ? 'Refreshes every 15 seconds' : '';
    });
    window.setInterval(function () {
        var button = document.getElementById('audit-live');
        if (!button || document.hidden) return;
        var field = button.form.querySelector('[name=live]');
        if (field.value === '1') button.form.querySelector('[name=search]').click();
    }, 15000);
})();
