(function () {
  'use strict';
  var root = document.getElementById('ma-login');
  if (!root) return;
  var form = root.querySelector('form');
  if (!form) return;
  var password = form.querySelector('[name="password"]');
  var toggle = form.querySelector('[data-password-toggle]');
  var submit = form.querySelector('[type="submit"]');
  if (toggle && password) {
    toggle.hidden = false;
    toggle.addEventListener('click', function () {
      var show = password.type === 'password';
      password.type = show ? 'text' : 'password';
      toggle.setAttribute('aria-pressed', String(show));
      toggle.setAttribute('aria-label', show ? toggle.dataset.hideLabel : toggle.dataset.showLabel);
    });
  }
  form.addEventListener('submit', function (event) {
    if (submit && submit.disabled) {
      event.preventDefault();
      return;
    }
    // Use a normal POST, including SubmitUser and any saved timeout request.
    // A no-JavaScript submission leaves this at zero for FA's fallback mode.
    form.querySelector('[name="ui_mode"]').value = '1';
  });
  if (submit && submit.disabled && root.dataset.retrySeconds) {
    window.setTimeout(function () {
      submit.disabled = false;
      var notice = root.querySelector('#log_msg');
      if (notice) {
        notice.classList.remove('ma-notice--error');
        notice.textContent = root.dataset.retryMessage;
      }
    }, Number(root.dataset.retrySeconds) * 1000);
  }
  // Avoid opening the keyboard or scrolling past the introduction on phones.
  if (window.matchMedia('(min-width: 761px)').matches) {
    var initial = root.dataset.timeout === 'true' ? password : form.querySelector('[autocomplete="username"]');
    if (initial) initial.focus({ preventScroll: true });
  }
})();
