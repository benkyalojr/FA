(function () {
  'use strict';
  function init() {
    var root = document.getElementById('ma-reports');
    if (!root) return;
    var search = root.querySelector('#ma-report-search');
    var clear = root.querySelector('#ma-report-clear');
    var count = root.querySelector('#ma-report-count');
    var empty = root.querySelector('#ma-report-empty');
    var form = root.querySelector('#rep_form');
    var categories = Array.from(root.querySelectorAll('.ma-report-category'));
    var cards = Array.from(root.querySelectorAll('.ma-report-card'));
    var active = root.dataset.category;
    var selected = root.querySelector('.ma-report-card[aria-current]');
    var wanted = selected ? selected.dataset.report : '';
    var pending = false;
    function url(link) { try { history.replaceState(null, '', link.href); } catch (_) {} }
    function render() {
      var query = search.value.trim().toLocaleLowerCase();
      var total = 0;
      root.classList.toggle('ma-reports-searching', !!query);
      clear.hidden = !query;
      cards.forEach(function (card) {
        card.hidden = query ? !card.textContent.toLocaleLowerCase().includes(query) : card.dataset.category !== active;
        if (!card.hidden) total++;
      });
      categories.forEach(function (link) {
        if (!query && link.dataset.category === active) link.setAttribute('aria-current', 'page');
        else link.removeAttribute('aria-current');
      });
      var category = categories.find(function (link) { return link.dataset.category === active; });
      if (count) count.textContent = query ? root.dataset.matches.replace('%d', total) : (category ? category.dataset.label + ' / ' : '') + root.dataset.count.replace('%d', total);
      if (empty) empty.hidden = total !== 0;
    }
    function close(restore) {
      wanted = '';
      if (form) form.replaceChildren();
      cards.forEach(function (card) { card.removeAttribute('aria-current'); });
      if (restore && selected && !selected.hidden) selected.focus();
    }
    window.maReportLoaded = function () {
      var panel = form.querySelector('.ma-report-filters');
      cards.forEach(function (card) { card.removeAttribute('aria-busy'); });
      var opening = pending;
      pending = false;
      if (!panel || panel.dataset.report !== wanted) { form.replaceChildren(); return; }
      if (opening) {
        var heading = panel.querySelector('h2');
        // FA restores focus after applying Ajax commands; keep it on the new heading.
        window._focus = heading.id;
        heading.focus({ preventScroll: true });
        heading.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'nearest' });
      }
    };
    root.addEventListener('click', function (event) {
      if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
      var category = event.target.closest('.ma-report-category');
      if (category) {
        // A normal navigation cancels an in-flight selection safely.
        if (pending) return;
        event.preventDefault();
        active = category.dataset.category;
        search.value = '';
        close(false);
        render();
        url(category);
        return;
      }
      var card = event.target.closest('.ma-report-card');
      if (card) {
        if (pending || !window.JsHttpRequest) return;
        event.preventDefault();
        close(false);
        selected = card;
        active = card.dataset.category;
        wanted = card.dataset.report;
        card.setAttribute('aria-current', 'true');
        card.setAttribute('aria-busy', 'true');
        pending = true;
        url(card);
        if (window.save_focus) save_focus(card);
        JsHttpRequest.request(card, null);
        return;
      }
      var closer = event.target.closest('.ma-report-close');
      if (closer) { event.preventDefault(); close(true); url(closer); }
    });
    search.addEventListener('input', function () { close(false); render(); });
    clear.addEventListener('click', function () { search.value = ''; render(); search.focus(); });
    root.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && event.target.closest('.ma-report-filters')) {
        var closer = form.querySelector('.ma-report-close');
        close(true);
        if (closer) url(closer);
      }
    });
    render();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
