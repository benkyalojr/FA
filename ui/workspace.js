(function () {
  'use strict';
  var html = document.documentElement;
  function read(key) { try { return localStorage.getItem(key); } catch (_) { return null; } }
  function save(key, value) { try { localStorage.setItem(key, value); } catch (_) {} }
  html.dataset.maTheme = read('ma-ui-theme') === 'dark' ? 'dark' : 'light';
  var desktop = window.matchMedia('(min-width: 761px)');
  html.dataset.maNav = desktop.matches && read('ma-ui-nav') === 'collapsed' ? 'collapsed' : 'expanded';
  // Esc closes an open modal form (its close link returns to the list).
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    var close = document.querySelector('.ma-modal .ma-modal-close');
    if (close) close.click();
  });
  // Document modal: a click on a document number (sales order, delivery, invoice,
  // credit, payment) opens a summary overlay instead of a popup window.
  var DOC_VIEW = /sales\/view\/view_(invoice|credit|receipt|dispatch|sales_order)\.php/;
  var docModal = null, docSeq = 0;
  function closeDoc() { if (docModal) { docModal.remove(); docModal = null; } }
  function docEl(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text) e.textContent = text; return e; }
  function openDoc(url, original) {
    var seq = ++docSeq;
    if (!docModal) {
      docModal = docEl('div', 'ma-modal ma-doc-modal');
      docModal.setAttribute('role', 'dialog'); docModal.setAttribute('aria-modal', 'true');
      var backdrop = docEl('div', 'ma-modal-backdrop'), panel = docEl('div', 'ma-modal-panel'), head = docEl('div', 'ma-modal-head');
      var title = docEl('h2'), tools = docEl('div', 'ma-modal-tools'), expand = docEl('button', '', '\u2922'), close = docEl('button', 'ma-modal-close', '\u00d7');
      expand.type = close.type = 'button'; expand.setAttribute('aria-label', 'Expand'); close.setAttribute('aria-label', 'Close');
      expand.addEventListener('click', function () { docModal.classList.toggle('expanded'); });
      close.addEventListener('click', closeDoc); backdrop.addEventListener('click', closeDoc);
      tools.appendChild(expand); tools.appendChild(close); head.appendChild(title); head.appendChild(tools);
      var body = docEl('div', 'ma-modal-body'); panel.appendChild(head); panel.appendChild(body);
      docModal.appendChild(backdrop); docModal.appendChild(panel); document.body.appendChild(docModal);
    }
    docModal.setAttribute('data-doc-url', url);
    docModal.setAttribute('data-share-url', url.split('?')[0].replace('doc_modal.php', 'doc_share.php'));
    var bodyEl = docModal.querySelector('.ma-modal-body'), titleEl = docModal.querySelector('h2');
    bodyEl.textContent = ''; bodyEl.appendChild(docEl('div', 'ma-doc-loading', 'Loading\u2026'));
    fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } }).then(function (r) {
      return r.text().then(function (t) { return { ok: r.ok, text: t, url: r.url }; });
    }).then(function (res) {
      if (seq !== docSeq || !docModal) return;
      if (res.url.indexOf('doc_modal.php') < 0) { window.location.href = original; return; } // session expired: let FA show the login
      var tpl = document.createElement('div'); tpl.innerHTML = res.text; // server-rendered, escaped HTML
      var meta = tpl.querySelector('template[data-title]');
      titleEl.textContent = meta ? meta.getAttribute('data-title') : '';
      bodyEl.textContent = ''; while (tpl.firstChild) bodyEl.appendChild(tpl.firstChild);
    }).catch(function () { if (seq === docSeq) { closeDoc(); window.open(original, '_blank'); } });
  }
  // M-Pesa STK Push from the invoice modal: send the request, then watch for the customer's answer.
  function mpesaRequest(btn) {
    var doc = btn.closest('.ma-doc'), box = doc.querySelector('.ma-doc-mpesa'), msg = box.querySelector('.ma-doc-mpesa-msg');
    var base = docModal.getAttribute('data-share-url').replace('doc_share.php', 'mpesa_stk.php'), body = new URLSearchParams();
    body.set('trans_no', doc.getAttribute('data-doc-no')); body.set('_token', doc.getAttribute('data-doc-token'));
    body.set('phone', box.querySelector('[data-mpesa-phone]').value); body.set('amount', box.querySelector('[data-mpesa-amount]').value);
    function say(text, cls) { msg.textContent = text; msg.className = 'ma-doc-mpesa-msg' + (cls ? ' ' + cls : ''); }
    btn.disabled = true; say('Sending the request\u2026');
    fetch(base, { method: 'POST', credentials: 'same-origin', body: body, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json(); }).then(function (res) {
        if (res.error) { btn.disabled = false; say(res.error, 'err'); return; }
        say((res.message || 'Request sent.') + ' Waiting for the customer to enter their PIN\u2026');
        var tries = 0, timer = setInterval(function () {
          tries++;
          fetch(base + '?poll=' + encodeURIComponent(res.tx), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (st) {
            if (st.status === 'posted') { clearInterval(timer); say('Paid. M-Pesa receipt ' + (st.receipt || '') + '. The payment has been recorded.', 'ok'); setTimeout(function () { openDoc(docModal.getAttribute('data-doc-url')); }, 1800); }
            else if (st.status === 'failed' || st.status === 'cancelled') { clearInterval(timer); btn.disabled = false; say(st.status === 'cancelled' ? 'The customer cancelled the request.' : 'The payment was not completed' + (st.message ? ': ' + st.message : '.'), 'err'); }
            else if (st.status === 'review') { clearInterval(timer); say('Paid (receipt ' + (st.receipt || '') + ') but it needs review before it is posted. See M-Pesa > Needs Review.', 'err'); }
            else if (tries >= 40) { clearInterval(timer); btn.disabled = false; say('No answer yet. If the customer paid, it appears in M-Pesa > Transactions shortly.', 'err'); }
          }).catch(function () {});
        }, 3000);
      }).catch(function () { btn.disabled = false; say('Could not send the request. Please try again.', 'err'); });
  }
  // Public share link of an invoice: create (or fetch) it, show it, or withdraw it.
  function shareDoc(btn, action) {
    var doc = btn.closest('.ma-doc'), box = doc.querySelector('.ma-doc-share'), body = new URLSearchParams();
    body.set('trans_type', doc.getAttribute('data-doc-type')); body.set('trans_no', doc.getAttribute('data-doc-no'));
    body.set('_token', doc.getAttribute('data-doc-token')); body.set('action', action);
    box.hidden = false; box.textContent = '';
    box.appendChild(docEl('span', 'ma-doc-share-note', 'Working\u2026'));
    fetch(docModal.getAttribute('data-share-url'), {
      method: 'POST', credentials: 'same-origin', body: body, headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function (r) { return r.json(); }).then(function (res) {
      box.textContent = '';
      if (res.error) { box.appendChild(docEl('span', 'ma-doc-share-note err', res.error)); return; }
      if (!res.url) { box.appendChild(docEl('span', 'ma-doc-share-note', 'Sharing stopped. The old link no longer works.')); return; }
      var inp = docEl('input'); inp.readOnly = true; inp.value = res.url; inp.setAttribute('aria-label', 'Public link');
      var copy = docEl('button', 'ma-doc-btn primary', 'Copy'), stop = docEl('button', 'ma-doc-btn danger', 'Stop sharing');
      copy.type = stop.type = 'button'; copy.setAttribute('data-doc-copyurl', ''); stop.setAttribute('data-doc-unshare', '');
      box.appendChild(docEl('span', 'ma-doc-share-note', 'Anyone with this link can view this invoice (read-only), without signing in.'));
      var row = docEl('div', 'ma-doc-share-row'); row.appendChild(inp); row.appendChild(copy); row.appendChild(stop); box.appendChild(row);
      inp.select();
    }).catch(function () { box.textContent = ''; box.appendChild(docEl('span', 'ma-doc-share-note err', 'Could not create the link. Please try again.')); });
  }
  document.addEventListener('click', function (e) {
    if (e.defaultPrevented || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey) return;
    var t = e.target.closest ? e.target : e.target.parentNode;
    var tab = t.closest('[data-doc-tab]');
    if (tab) {
      var doc = tab.closest('.ma-doc');
      doc.querySelectorAll('[data-doc-tab]').forEach(function (b) { var on = b === tab; b.classList.toggle('active', on); b.setAttribute('aria-selected', String(on)); });
      doc.querySelectorAll('[data-doc-panel]').forEach(function (p) { p.hidden = p.getAttribute('data-doc-panel') !== tab.getAttribute('data-doc-tab'); });
      return;
    }
    var shareBtn = t.closest('[data-doc-share]');
    if (shareBtn) { shareDoc(shareBtn, 'share'); return; }
    var mpToggle = t.closest('[data-doc-mpesa-toggle]');
    if (mpToggle) { var mp = mpToggle.closest('.ma-doc').querySelector('.ma-doc-mpesa'); mp.hidden = !mp.hidden; if (!mp.hidden) mp.querySelector('[data-mpesa-phone]').focus(); return; }
    var mpSend = t.closest('[data-mpesa-send]');
    if (mpSend) { mpesaRequest(mpSend); return; }
    var stopBtn = t.closest('[data-doc-unshare]');
    if (stopBtn) { shareDoc(stopBtn, 'revoke'); return; }
    var copyShare = t.closest('[data-doc-copyurl]');
    if (copyShare) {
      var inp = copyShare.parentNode.querySelector('input'); inp.select();
      var ok = function () { copyShare.textContent = 'Copied'; setTimeout(function () { copyShare.textContent = 'Copy'; }, 1400); };
      if (navigator.clipboard) navigator.clipboard.writeText(inp.value).then(ok, function () { document.execCommand('copy'); ok(); });
      else { document.execCommand('copy'); ok(); }
      return;
    }
    var copy = t.closest('[data-doc-copy]');
    if (copy) {
      var link = new URL(copy.getAttribute('data-doc-copy'), window.location.href).href;
      var done = function () { var old = copy.lastChild.textContent; copy.lastChild.textContent = 'Copied'; setTimeout(function () { copy.lastChild.textContent = old; }, 1400); };
      if (navigator.clipboard) navigator.clipboard.writeText(link).then(done, function () {}); 
      return;
    }
    var a = t.closest('a[href]');
    if (!a || !DOC_VIEW.test(a.getAttribute('href'))) return;
    var u = new URL(a.href, window.location.href), no = u.searchParams.get('trans_no'), type = u.searchParams.get('trans_type');
    if (!no) return;
    if (!type) type = { invoice: 10, credit: 11, receipt: 12, dispatch: 13, sales_order: 30 }[DOC_VIEW.exec(a.getAttribute('href'))[1]];
    e.preventDefault(); e.stopPropagation();
    openDoc(u.href.replace(/sales\/view\/view_[a-z_]+\.php.*$/, 'sales/view/doc_modal.php') + '?trans_type=' + encodeURIComponent(type) + '&trans_no=' + encodeURIComponent(no), a.href);
  }, true);
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
