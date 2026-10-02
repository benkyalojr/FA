function securityRightsCount(element) {
    var module = element.closest('.rights-module');
    var all = module.querySelectorAll('.rights-item input[type="checkbox"]');
    var checked = module.querySelectorAll('.rights-item input[type="checkbox"]:checked');
    module.querySelector('.rights-count').textContent = checked.length + ' / ' + all.length + ' selected';
}
function securityRightsSelect(button, selected) {
    var group = button.closest('.rights-group');
    group.querySelectorAll('.rights-item').forEach(function(label) {
        var checkbox = label.querySelector('input[type="checkbox"]');
        if (!label.hidden && checkbox && !checkbox.disabled) checkbox.checked = selected;
    });
    securityRightsCount(button);
}

// FA's generic button behaviour replaces onclick handlers, including after an
// AJAX role change. Delegate once so these local controls survive both paths.
if (!window.securityRightsSelectionInstalled) {
    window.securityRightsSelectionInstalled = true;
    document.addEventListener('click', function(event) {
        var button = event.target.closest('button[data-rights-select]');
        if (!button || !button.closest('.rights-workspace') || button.disabled) return;
        event.preventDefault();
        securityRightsSelect(button, button.getAttribute('data-rights-select') === 'all');
    });
}
// Search ignores case, spaces, hyphens and other punctuation, so "mpesa" finds "M-Pesa".
function securityRightsNormalize(text) {
    return String(text).toLowerCase().replace(/[^a-z0-9]+/g, '');
}

function securityRightsFilter(value) {
    var search = securityRightsNormalize(value);
    document.querySelectorAll('.rights-module').forEach(function(module) {
        var moduleMatch = securityRightsNormalize(module.dataset.module).indexOf(search) !== -1;
        var visible = 0;
        module.querySelectorAll('.rights-group').forEach(function(group) {
            var groupMatch = moduleMatch || securityRightsNormalize((group.querySelector('h4') || {}).textContent || '').indexOf(search) !== -1;
            var groupVisible = 0;
            group.querySelectorAll('.rights-item').forEach(function(label) {
                label.hidden = !groupMatch && securityRightsNormalize(label.textContent).indexOf(search) === -1;
                if (!label.hidden) groupVisible++;
            });
            group.hidden = !groupVisible;   // a group with nothing to show disappears too
            visible += groupVisible;
        });
        module.hidden = !visible;
    });
}
