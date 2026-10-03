/* Point of sale screen. Talks to pos_api.php (catalog, quote, checkout, settle) and sales/view/mpesa_stk.php. */
function posInit() {
  var root = document.getElementById('pos');
  if (!root) return;
  var cfg = JSON.parse(root.getAttribute('data-config'));
  var $ = function (id) { return document.getElementById(id); };
  var fmt = new Intl.NumberFormat('en-KE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  var money = function (n) { return cfg.currency + ' ' + fmt.format(n || 0); };
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };

  var state = { customer: cfg.walkin, items: [], byId: {}, tax_included: 1, negative: false, blocked: 0, cart: {}, category: '', quote: null,
    method: null, invoice: null, due: 0, busy: false, poll: null };
  var heldKey = 'ma_pos_held_' + (cfg.register.id || 0);
  var toastTimer, quoteTimer, quoteSeq = 0, searchTimer;

  function toast(msg) { var t = $('pos-toast'); t.textContent = msg; t.hidden = false; clearTimeout(toastTimer); toastTimer = setTimeout(function () { t.hidden = true; }, 3500); }
  function post(url, data) {
    var body = new URLSearchParams(); body.set('_token', cfg.token);
    Object.keys(data).forEach(function (k) { body.set(k, data[k]); });
    return fetch(url, { method: 'POST', credentials: 'same-origin', body: body }).then(function (r) { return r.json().catch(function () { return { error: 'Unexpected response (' + r.status + ')' }; }); });
  }
  function get(url) { return fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json().catch(function () { return { error: 'Unexpected response (' + r.status + ')' }; }); }); }

  // ------------------------------------------------------------ catalog
  function loadCatalog() {
    if (!state.customer) { $('pos-products').innerHTML = '<div class="pos-empty">' + esc('Choose a customer to load prices.') + '</div>'; return; }
    get(cfg.api + '?action=catalog&customer=' + state.customer.id).then(function (d) {
      if (d.error) { $('pos-products').innerHTML = '<div class="pos-empty">' + esc(d.error) + '</div>'; return; }
      state.items = d.items; state.tax_included = d.tax_included; state.negative = d.negative; state.blocked = d.blocked;
      state.byId = {}; d.items.forEach(function (i) { state.byId[i.id] = i; });
      Object.keys(state.cart).forEach(function (id) { if (!state.byId[id]) delete state.cart[id]; });
      renderCategories(); renderProducts(); renderCart();
      if (d.blocked) toast('This customer is on hold: invoices are not allowed.');
    });
  }
  function renderCategories() {
    var cats = [''].concat(state.items.map(function (i) { return i.cat; }).filter(function (c, n, a) { return a.indexOf(c) === n; }));
    if (cats.indexOf(state.category) < 0) state.category = '';
    $('pos-categories').innerHTML = cats.map(function (c) { return '<button type="button" class="pos-category' + (c === state.category ? ' active' : '') + '" data-cat="' + esc(c) + '">' + esc(c || 'All products') + '</button>'; }).join('');
  }
  function tile(i) {
    var out = i.stock !== null && i.stock <= 0, initials = i.name.replace(/[^A-Za-z0-9 ]/g, '').split(' ').slice(0, 2).map(function (w) { return w.charAt(0); }).join('').toUpperCase();
    var visual = i.img ? '<img src="' + esc(i.img) + '" alt="" loading="lazy">' : '<span class="pos-initials" aria-hidden="true">' + esc(initials) + '</span>';
    var badge = i.stock === null ? '' : '<span class="pos-stock' + (out ? ' out' : '') + '">' + (out ? 'Out of stock' : (+i.stock) + ' in stock') + '</span>';
    return '<button type="button" class="pos-product" data-id="' + esc(i.id) + '"' + (i.price <= 0 ? ' disabled' : '') + ' aria-label="Add ' + esc(i.name) + '"><div class="pos-visual">' + badge + visual + '</div>'
      + '<div class="pos-info"><div class="pos-code">' + esc(i.id) + ' &middot; ' + esc(i.cat) + '</div><div class="pos-name">' + esc(i.name) + '</div>'
      + '<div class="pos-price"><span>' + (i.price > 0 ? money(i.price) : 'No price') + '</span><span class="pos-plus">+</span></div></div></button>';
  }
  function renderProducts() {
    var q = $('pos-search').value.toLowerCase().trim();
    var list = state.items.filter(function (i) { return (!state.category || i.cat === state.category) && (!q || (i.name + ' ' + i.id + ' ' + i.codes.join(' ')).toLowerCase().indexOf(q) >= 0); });
    $('pos-count').textContent = list.length + ' products';
    $('pos-products').innerHTML = list.slice(0, 120).map(tile).join('') + (list.length > 120 ? '<div class="pos-empty">Showing 120 of ' + list.length + '. Search to narrow down.</div>' : '') || '<div class="pos-empty">No products found.</div>';
  }

  // ------------------------------------------------------------ cart
  function count() { return Object.keys(state.cart).reduce(function (s, id) { return s + state.cart[id]; }, 0); }
  function raw() { return Object.keys(state.cart).reduce(function (s, id) { return s + state.byId[id].price * state.cart[id]; }, 0); }
  function discount() { var d = parseFloat($('pos-discount').value) || 0; return Math.max(0, Math.min(d, raw())); }
  function add(id, qty) {
    var it = state.byId[id]; if (!it || it.price <= 0) return;
    var q = (state.cart[id] || 0) + (qty || 1);
    if (it.stock !== null && !state.negative && q > it.stock) { toast('Only ' + (+it.stock) + ' ' + esc(it.name) + ' in stock'); q = Math.max(0, Math.min(q, it.stock)); if (!q) return; }
    state.cart[id] = q; renderCart();
  }
  function setQty(id, q) {
    var it = state.byId[id];
    if (!(q > 0)) { delete state.cart[id]; }
    else { if (it.stock !== null && !state.negative && q > it.stock) { toast('Only ' + (+it.stock) + ' in stock'); q = it.stock; } state.cart[id] = q; }
    renderCart();
  }
  function renderCart() {
    var ids = Object.keys(state.cart), n = count();
    $('pos-items').textContent = n + (n === 1 ? ' item' : ' items');
    $('pos-lines').innerHTML = ids.map(function (id) {
      var it = state.byId[id], q = state.cart[id];
      return '<div class="pos-line"><div class="pos-line-top"><span>' + esc(it.name) + '</span><button type="button" class="pos-remove" data-remove="' + esc(id) + '" aria-label="Remove ' + esc(it.name) + '">&times;</button></div>'
        + '<div class="pos-code">' + esc(id) + ' &middot; ' + money(it.price) + ' / ' + esc(it.units) + '</div><div class="pos-line-bottom"><div class="pos-qty"><button type="button" data-minus="' + esc(id) + '" aria-label="Decrease">&minus;</button>'
        + '<input type="number" min="0" step="any" value="' + (+q) + '" data-qty="' + esc(id) + '" aria-label="Quantity"><button type="button" data-plus="' + esc(id) + '" aria-label="Increase">+</button></div><strong>' + money(it.price * q) + '</strong></div></div>';
    }).join('') || '<div class="pos-empty">Your cart is empty<br><small>Select a product to start an order.</small></div>';
    var d = discount(), r = raw(), t = Math.max(0, r - d);
    $('pos-subtotal').textContent = money(r); $('pos-total').textContent = $('pos-pay-total').textContent = money(t);
    $('pos-tax').textContent = '...';
    $('pos-subtotal-label').textContent = state.tax_included ? 'Subtotal (including tax)' : 'Subtotal';
    $('pos-tax-label').textContent = state.tax_included ? 'Tax included' : 'Tax added';
    $('pos-pay').disabled = $('pos-hold').disabled = !ids.length;
    state.quote = null; state.quoteError = null; clearTimeout(quoteTimer);
    if (ids.length) quoteTimer = setTimeout(requestQuote, 250); else { $('pos-tax').textContent = money(0); }
  }
  function lines() { return JSON.stringify(Object.keys(state.cart).map(function (id) { return { id: id, qty: state.cart[id] }; })); }
  function requestQuote() {
    var seq = ++quoteSeq;
    post(cfg.api, { action: 'quote', customer: state.customer.id, lines: lines(), discount: discount() }).then(function (q) {
      if (seq !== quoteSeq) return;
      if (q.error) { state.quoteError = q.error; $('pos-tax').textContent = '-'; toast(q.error); return; }
      state.quoteError = null; state.quote = q;
      $('pos-tax').textContent = money(q.tax); $('pos-total').textContent = $('pos-pay-total').textContent = money(q.total);
      $('pos-subtotal').textContent = money(q.items + discount());
    }).catch(function (e) { if (seq === quoteSeq) { state.quoteError = 'Could not calculate totals: ' + e.message; $('pos-tax').textContent = '-'; toast(state.quoteError); } });
  }
  function resetOrder() { state.cart = {}; $('pos-discount').value = 0; renderCart(); }

  // ------------------------------------------------------------ customer
  function setCustomer(c) { state.customer = c; $('pos-customer').value = c ? c.name : ''; $('pos-customer-list').hidden = true; resetOrderKeepCart(); }
  function resetOrderKeepCart() { loadCatalog(); }
  $('pos-customer').addEventListener('input', function () {
    var q = this.value.trim(); clearTimeout(searchTimer);
    if (q.length < 2) { $('pos-customer-list').hidden = true; return; }
    searchTimer = setTimeout(function () {
      get(cfg.api + '?action=customers&q=' + encodeURIComponent(q)).then(function (d) {
        var box = $('pos-customer-list');
        box.innerHTML = (d.customers || []).map(function (c) { return '<button type="button" data-cust="' + c.id + '" data-name="' + esc(c.name) + '">' + esc(c.name) + '</button>'; }).join('') || '<span>No customers found</span>';
        box.hidden = false;
      });
    }, 200);
  });
  $('pos-customer-list').addEventListener('click', function (e) {
    var b = e.target.closest('[data-cust]'); if (!b) return;
    setCustomer({ id: +b.getAttribute('data-cust'), name: b.getAttribute('data-name') });
    if (cfg.canSetWalkin && !cfg.walkin && confirm('Use ' + b.getAttribute('data-name') + ' as the default walk-in customer?'))
      post(cfg.api, { action: 'walkin', customer: b.getAttribute('data-cust') }).then(function (r) { if (r.ok) { cfg.walkin = state.customer; toast('Walk-in customer saved.'); } });
  });
  $('pos-walkin').addEventListener('click', function () { if (cfg.walkin) setCustomer(cfg.walkin); else toast('Search for a customer and pick one; you will be asked to save it as the walk-in default.'); });

  // ------------------------------------------------------------ events
  $('pos-categories').addEventListener('click', function (e) { var b = e.target.closest('[data-cat]'); if (!b) return; state.category = b.getAttribute('data-cat'); renderCategories(); renderProducts(); });
  $('pos-products').addEventListener('click', function (e) { var b = e.target.closest('[data-id]'); if (b) add(b.getAttribute('data-id'), 1); });
  $('pos-lines').addEventListener('click', function (e) {
    var t = e.target.closest('button'); if (!t) return;
    if (t.hasAttribute('data-remove')) setQty(t.getAttribute('data-remove'), 0);
    else if (t.hasAttribute('data-minus')) setQty(t.getAttribute('data-minus'), (state.cart[t.getAttribute('data-minus')] || 0) - 1);
    else if (t.hasAttribute('data-plus')) add(t.getAttribute('data-plus'), 1);
  });
  $('pos-lines').addEventListener('change', function (e) { if (e.target.hasAttribute('data-qty')) setQty(e.target.getAttribute('data-qty'), parseFloat(e.target.value)); });
  $('pos-search').addEventListener('input', renderProducts);
  $('pos-search').addEventListener('keydown', function (e) {
    if (e.key !== 'Enter') return;
    var v = this.value.trim().toLowerCase(), hit = state.items.filter(function (i) { return i.id.toLowerCase() === v || i.codes.some(function (c) { return c.toLowerCase() === v; }); })[0];
    if (hit) { add(hit.id, 1); this.value = ''; renderProducts(); }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === '/' && !/^(INPUT|SELECT|TEXTAREA)$/.test(document.activeElement.tagName) && !$('pos-checkout').open) { e.preventDefault(); $('pos-search').focus(); }
  });
  $('pos-discount').addEventListener('input', function () { if (+this.value < 0) this.value = 0; if (+this.value > raw()) this.value = raw().toFixed(2); renderCart(); });
  $('pos-clear').addEventListener('click', function () { if (count() && confirm('Clear all items from this order?')) resetOrder(); });
  $('pos-new').addEventListener('click', function () { if (count() && !confirm('Discard this order and start a new one?')) return; resetOrder(); if (cfg.walkin) setCustomer(cfg.walkin); });

  // held orders live in this browser so they survive a refresh
  function heldList() { try { return JSON.parse(localStorage.getItem(heldKey) || '[]'); } catch (e) { return []; } }
  function saveHeld(l) { try { localStorage.setItem(heldKey, JSON.stringify(l)); } catch (e) { toast('Held orders cannot be saved in this browser.'); } $('pos-held-count').textContent = l.length; }
  $('pos-hold').addEventListener('click', function () { var l = heldList(); l.push({ cart: state.cart, discount: $('pos-discount').value, customer: state.customer, at: Date.now() }); saveHeld(l); resetOrder(); toast('Order held.'); });
  $('pos-held').addEventListener('click', function () {
    var l = heldList(); if (!l.length) return toast('No held orders.'); if (count()) return toast('Hold or clear the current order first.');
    var h = l.shift(); saveHeld(l); state.cart = h.cart; $('pos-discount').value = h.discount;
    if (h.customer && (!state.customer || h.customer.id !== state.customer.id)) { state.customer = h.customer; $('pos-customer').value = h.customer.name; loadCatalog(); } else renderCart();
    toast('Oldest held order resumed.');
  });

  // ------------------------------------------------------------ checkout
  var dlg = $('pos-checkout');
  function total() { return state.invoice ? state.due : (state.quote ? state.quote.total : Math.max(0, raw() - discount())); }
  function groups() {
    var g = [];
    cfg.methods.forEach(function (m) { if (m.key === 'bank') { var b = g.filter(function (x) { return x.key === 'bank'; })[0]; if (!b) g.push({ key: 'bank', label: 'Bank / Card', accounts: [m] }); else b.accounts.push(m); } else g.push({ key: m.key, label: m.label, accounts: [m] }); });
    return g;
  }
  function showMethods() {
    var g = groups(); if (state.invoice && state.settleOnly) g = g.filter(function (x) { return x.key !== 'mpesa'; });
    if (!g.length) { $('pos-methods').innerHTML = ''; setNotice(cfg.text.noMethods, 'err'); $('pos-complete').disabled = true; return; }
    $('pos-methods').innerHTML = g.map(function (x) { return '<button type="button" class="pos-btn" data-method="' + x.key + '">' + esc(x.label) + '</button>'; }).join('');
    chooseMethod(g.some(function (x) { return x.key === state.method; }) ? state.method : g[0].key);
  }
  function setNotice(text, cls) { var n = $('pos-notice'); n.textContent = text || ''; n.className = 'pos-notice' + (cls ? ' ' + cls : ''); n.hidden = !text; }
  function chooseMethod(key) {
    state.method = key;
    [].forEach.call($('pos-methods').children, function (b) { b.classList.toggle('active', b.getAttribute('data-method') === key); });
    var accounts = cfg.methods.filter(function (m) { return m.key === key; });
    $('pos-f-account').hidden = !(key === 'bank' && accounts.length > 1);
    $('pos-account').innerHTML = accounts.map(function (a) { return '<option value="' + a.id + '">' + esc(a.label) + '</option>'; }).join('');
    $('pos-f-ref').hidden = key !== 'bank'; $('pos-f-phone').hidden = key !== 'mpesa'; $('pos-f-cash').hidden = key !== 'cash';
    if (key === 'cash') { $('pos-received').value = total().toFixed(2); quick(); }
    setNotice(key === 'mpesa' ? 'An M-Pesa prompt is sent to the customer\'s phone. The sale completes when they enter their PIN.' : '', '');
    paymentState();
  }
  function quick() {
    var t = total(), opts = [t]; [100, 500, 1000, 5000].forEach(function (s) { var r = Math.ceil(t / s) * s; if (r > t && opts.indexOf(r) < 0) opts.push(r); });
    $('pos-quick').innerHTML = opts.slice(0, 4).map(function (v, n) { return '<button type="button" class="pos-btn" data-amt="' + v.toFixed(2) + '">' + (n === 0 ? 'Exact' : fmt.format(v)) + '</button>'; }).join('');
  }
  function paymentState() {
    var ok = true;
    if (state.method === 'cash') { var r = parseFloat($('pos-received').value) || 0; ok = r + 0.005 >= total(); $('pos-change').textContent = money(Math.max(0, r - total())); }
    if (state.method === 'bank') ok = $('pos-ref').value.trim() !== '';
    if (state.method === 'mpesa') ok = $('pos-phone').value.replace(/\D/g, '').length >= 9;
    $('pos-complete').disabled = state.busy || !ok;
  }
  $('pos-methods').addEventListener('click', function (e) { var b = e.target.closest('[data-method]'); if (b) chooseMethod(b.getAttribute('data-method')); });
  $('pos-quick').addEventListener('click', function (e) { var b = e.target.closest('[data-amt]'); if (b) { $('pos-received').value = b.getAttribute('data-amt'); paymentState(); } });
  ['pos-received', 'pos-ref', 'pos-phone'].forEach(function (id) { $(id).addEventListener('input', paymentState); });

  function openCheckout() {
    if (!state.customer) return toast('Choose a customer first.');
    if (!state.quote) return toast(state.quoteError || 'Totals are still being calculated. Try again in a moment.');
    if (state.blocked) return toast('This customer is on hold: invoices are not allowed.');
    state.invoice = null; state.settleOnly = false; stopPoll();
    $('pos-dialog-title').textContent = 'Payment'; $('pos-dialog-customer').textContent = state.customer.name; $('pos-due').textContent = money(state.quote.total);
    $('pos-pay-fields').hidden = false; $('pos-complete').hidden = false; $('pos-print').hidden = true; $('pos-cancel').textContent = 'Cancel'; $('pos-complete').textContent = 'Complete sale';
    $('pos-ref').value = ''; $('pos-phone').value = '';
    showMethods(); dlg.showModal();
  }
  $('pos-pay').addEventListener('click', openCheckout);
  $('pos-cancel').addEventListener('click', function () { dlg.close(); });
  // however the dialog closes (button or Esc), a posted sale starts a fresh order so it cannot be charged twice
  dlg.addEventListener('close', function () {
    stopPoll();
    if (state.done) { state.done = false; state.invoice = null; resetOrder(); if (cfg.walkin) setCustomer(cfg.walkin); else loadCatalog(); }
  });

  function accountId() { var a = cfg.methods.filter(function (m) { return m.key === state.method; }); return a.length > 1 ? $('pos-account').value : (a[0] ? a[0].id : 0); }
  function complete() {
    if ($('pos-complete').disabled) return;
    state.busy = true; $('pos-complete').disabled = true; setNotice('Posting...', '');
    if (state.invoice && state.method === 'mpesa') { state.busy = false; startMpesa(); return; }
    var data = { method: state.method, account: accountId(), reference: $('pos-ref').value.trim(), tendered: $('pos-received').value };
    var req;
    if (state.invoice) { data.action = 'settle'; data.trans_no = state.invoice.no; req = post(cfg.api, data); }
    else { data.action = 'checkout'; data.customer = state.customer.id; data.lines = lines(); data.discount = discount(); req = post(cfg.api, data); }
    req.then(function (r) {
      state.busy = false;
      if (r.error) { setNotice(r.error, 'err'); paymentState(); return; }
      if (r.invoice_no && !state.invoice) state.invoice = { no: r.invoice_no, ref: r.reference };
      state.due = r.total || state.due;
      if (r.warning) { state.settleOnly = true; state.done = true; setNotice(r.warning, 'err'); showMethods(); return; }
      if (r.pending && state.method === 'mpesa') { state.done = true; startMpesa(); return; }
      finish(r);
    }).catch(function (e) { state.busy = false; setNotice('Network problem: ' + e.message, 'err'); paymentState(); });
  }
  $('pos-complete').addEventListener('click', complete);

  function finish(r) {
    state.done = true; stopPoll();
    $('pos-dialog-title').textContent = 'Sale completed';
    $('pos-pay-fields').hidden = true; $('pos-complete').hidden = true; $('pos-print').hidden = false; $('pos-cancel').textContent = 'Start next order';
    var ch = r && r.change > 0 ? ' Change due: ' + money(r.change) + '.' : '';
    $('pos-due').textContent = money(state.due);
    setNotice('Invoice ' + (state.invoice ? state.invoice.ref : '') + ' paid.' + ch, 'ok');
    state.receiptUrl = cfg.receipt + '?no=' + state.invoice.no + (r && r.tendered ? '&tendered=' + r.tendered : '') + '&autoprint=1';
    toast('Sale completed');
  }
  $('pos-print').addEventListener('click', function () { if (state.receiptUrl) window.open(state.receiptUrl, '_blank', 'width=420,height=640'); });

  // ------------------------------------------------------------ M-Pesa
  function stopPoll() { if (state.poll) { clearInterval(state.poll.timer); state.poll = null; } }
  function startMpesa() {
    $('pos-pay-fields').hidden = true; $('pos-complete').hidden = true; $('pos-cancel').textContent = 'Close';
    setNotice('Sending the M-Pesa request...', '');
    post(cfg.mpesaApi, { trans_no: state.invoice.no, phone: $('pos-phone').value, amount: state.due.toFixed(2) }).then(function (r) {
      if (r.error) return mpesaFailed(r.error);
      setNotice('Waiting for the customer to enter their M-Pesa PIN... ' + (r.message || ''), '');
      var started = Date.now();
      state.poll = { timer: setInterval(function () {
        get(cfg.mpesaApi + '?poll=' + r.tx).then(function (s) {
          if (!state.poll) return;
          if (s.status === 'posted') { finish({ total: state.due }); setNotice('M-Pesa receipt ' + (s.receipt || '') + ' received. Invoice ' + state.invoice.ref + ' paid.', 'ok'); }
          else if (s.status === 'failed' || s.status === 'cancelled') mpesaFailed(s.message || 'The customer did not complete the payment.');
          else if (Date.now() - started > 100000) mpesaFailed('No answer from the customer yet. If they did pay, it will be matched automatically.');
        });
      }, 3000) };
    }).catch(function (e) { mpesaFailed('Network problem: ' + e.message); });
  }
  function mpesaFailed(msg) {
    stopPoll(); state.settleOnly = false;
    setNotice(msg + ' Invoice ' + state.invoice.ref + ' is posted and unpaid. Try again or take another payment method.', 'err');
    $('pos-pay-fields').hidden = false; $('pos-complete').hidden = false; $('pos-complete').textContent = 'Take payment'; $('pos-cancel').textContent = 'Leave unpaid';
    showMethods(); setNotice(msg + ' Invoice ' + state.invoice.ref + ' is posted and unpaid. Try again or choose another way to pay.', 'err');
  }

  // ------------------------------------------------------------ start
  $('pos-held-count').textContent = heldList().length;
  if (state.customer) $('pos-customer').value = state.customer.name;
  renderCart();
  if (state.customer) loadCatalog();
  else { $('pos-products').innerHTML = '<div class="pos-empty">Search for a customer on the right to load prices. An administrator can save one as the default walk-in customer.</div>'; }
  if (!cfg.methods.length) toast(cfg.text.noMethods);
}
// The script is queued in <head>, so wait for the page before looking for #pos.
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', posInit); else posInit();
