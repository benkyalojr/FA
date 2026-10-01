(function () {
  'use strict';
  var html = document.documentElement;
  function read(key) { try { return localStorage.getItem(key); } catch (_) { return null; } }
  function save(key, value) { try { localStorage.setItem(key, value); } catch (_) {} }
  html.dataset.maTheme = read('ma-ui-theme') === 'dark' ? 'dark' : 'light';
  var desktop = window.matchMedia('(min-width: 761px)');
  html.dataset.maNav = desktop.matches && read('ma-ui-nav') === 'collapsed' ? 'collapsed' : 'expanded';
  function init() {
    var root = document.getElementById('ma-workspace');
    if (!root) return;
    var toggle = document.getElementById('ma-menu-toggle');
    var sidebar = document.getElementById('ma-sidebar');
    var scrim = root.querySelector('.ma-nav-scrim');
    var theme = document.getElementById('ma-theme-toggle');
    function sync() {
      var open = desktop.matches ? html.dataset.maNav !== 'collapsed' : root.classList.contains('ma-nav-open');
      if (toggle) toggle.setAttribute('aria-expanded', String(open));
      if (scrim) scrim.hidden = desktop.matches || !open;
    }
    function closeNav() { root.classList.remove('ma-nav-open'); sync(); if (toggle) toggle.focus(); }
    if (toggle) toggle.addEventListener('click', function () {
      if (desktop.matches) {
        html.dataset.maNav = html.dataset.maNav === 'collapsed' ? 'expanded' : 'collapsed';
        save('ma-ui-nav', html.dataset.maNav);
      } else {
        root.classList.toggle('ma-nav-open');
        if (root.classList.contains('ma-nav-open')) sidebar.querySelector('.ma-mobile-close').focus();
      }
      sync();
    });
    if (scrim) scrim.addEventListener('click', closeNav);
    var close = root.querySelector('.ma-mobile-close');
    if (close) close.addEventListener('click', closeNav);
    if (theme) {
      theme.setAttribute('aria-pressed', String(html.dataset.maTheme === 'dark'));
      theme.addEventListener('click', function () {
        html.dataset.maTheme = html.dataset.maTheme === 'dark' ? 'light' : 'dark';
        save('ma-ui-theme', html.dataset.maTheme);
        theme.setAttribute('aria-pressed', String(html.dataset.maTheme === 'dark'));
      });
    }
    var dropdowns = root.querySelectorAll('.ma-dropdown');
    dropdowns.forEach(function (details) {
      details.addEventListener('toggle', function () {
        if (details.open) dropdowns.forEach(function (other) { if (other !== details) other.open = false; });
      });
    });
    document.addEventListener('click', function (event) {
      dropdowns.forEach(function (details) { if (!details.contains(event.target)) details.open = false; });
    });
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        dropdowns.forEach(function (details) { if (details.open) { details.open = false; details.querySelector('summary').focus(); } });
        if (!desktop.matches && root.classList.contains('ma-nav-open')) closeNav();
      }
      if (event.key === 'Tab' && !desktop.matches && root.classList.contains('ma-nav-open')) {
        var items = Array.from(sidebar.querySelectorAll('a, button, summary')).filter(function (el) { return el.getClientRects().length; });
        var first = items[0], last = items[items.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
      }
    });
    desktop.addEventListener('change', function () {
      root.classList.remove('ma-nav-open');
      html.dataset.maNav = desktop.matches && read('ma-ui-nav') === 'collapsed' ? 'collapsed' : 'expanded';
      sync();
    });
    var period = root.querySelector('.ma-period-form select');
    if (period) period.addEventListener('change', function () { period.form.requestSubmit(); });
    sync();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
