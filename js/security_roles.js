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
function securityRightsFilter(value) {
    var search = value.toLowerCase().trim();
    document.querySelectorAll('.rights-module').forEach(function(module) {
        var moduleMatch = module.dataset.module.toLowerCase().indexOf(search) !== -1;
        var visible = 0;
        module.querySelectorAll('.rights-item').forEach(function(label) {
            label.hidden = !moduleMatch && label.textContent.toLowerCase().indexOf(search) === -1;
            if (!label.hidden) visible++;
        });
        module.hidden = !visible;

    });
}
