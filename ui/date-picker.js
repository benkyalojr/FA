/* Shared Gregorian date picker. Keep submitted FA dates in the user's format. */
(function (global) {
  'use strict';
  function dateMath(config) {
    function date(y, m, d) { return new Date(y, m, d, 12); }
    function iso(d) { return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0'); }
    function valid(y, m, d) {
      var result = date(y,m-1,d);
      return y >= 1000 && y <= 9999 && result.getFullYear() === y && result.getMonth() === m-1 && result.getDate() === d ? result : null;
    }
    function fromISO(value) {
      var p = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value || '');
      return p ? valid(+p[1],+p[2],+p[3]) : null;
    }
    function parse(value) {
      value = (value || '').trim();
      if (!value) return null;
      var parts = value.split(config.separator), order = config.format % 3;
      if (parts.length === 1 && config.format < 3 && /^\d{8}$/.test(value))
        parts = order === 2 ? [value.slice(0,4),value.slice(4,6),value.slice(6,8)] : [value.slice(0,2),value.slice(2,4),value.slice(4,8)];
      if (parts.length !== 3) return null;
      var y = order === 2 ? parts[0] : parts[2], m = order === 0 ? parts[0] : parts[1], d = order === 0 ? parts[1] : parts[2];
      if (order === 1) d = parts[0];
      if (!/^\d{4}$/.test(y) || !/^\d{1,2}$/.test(d)) return null;
      if (config.format >= 3) m = config.shortMonths.findIndex(function (name) { return name.toLocaleLowerCase() === m.toLocaleLowerCase(); }) + 1;
      else if (!/^\d{1,2}$/.test(m)) return null;
      return valid(+y,+m,+d);
    }
    function format(d) {
      var y = d.getFullYear(), m = config.format < 3 ? String(d.getMonth()+1).padStart(2,'0') : config.shortMonths[d.getMonth()];
      var day = config.format < 3 ? String(d.getDate()).padStart(2,'0') : d.getDate();
      return (config.format % 3 === 0 ? [m,day,y] : config.format % 3 === 1 ? [day,m,y] : [y,m,day]).join(config.separator);
    }
    function add(d, n) { return date(d.getFullYear(),d.getMonth(),d.getDate()+n); }
    function month(d, n) { return date(d.getFullYear(),d.getMonth()+(n || 0),1); }
    function shift(d, n) { var first = month(d,n); return date(first.getFullYear(),first.getMonth(),Math.min(d.getDate(),date(first.getFullYear(),first.getMonth()+1,0).getDate())); }
    function presets() {
      var today = fromISO(config.today), list = {
        'Today':[today,today], 'Yesterday':[add(today,-1),add(today,-1)],
        'Last 7 Days':[add(today,-6),today], 'Last 30 Days':[add(today,-29),today],
        'This Month':[month(today),today], 'Last Month':[month(today,-1),add(month(today),-1)],
        'Last 3 Months':[add(shift(today,-3),1),today], 'Last 6 Months':[add(shift(today,-6),1),today],
        'Last 8 Months':[add(shift(today,-8),1),today], 'This Year':[date(today.getFullYear(),0,1),today]
      };
      if (config.fiscal) {
        var from = fromISO(config.fiscal[0]), to = fromISO(config.fiscal[1]);
        if (from && to && from <= to) {
          list['This Financial Year'] = [from,to];
          if (today >= from) list['Financial Year to Date'] = [from, today < to ? today : to];
        }
      }
      if (config.previousFiscal) {
        var previous = config.previousFiscal.map(fromISO);
        if (previous[0] && previous[1]) list['Previous Financial Year'] = previous;
      }
      return list;
    }
    return { date:date, iso:iso, fromISO:fromISO, parse:parse, format:format, add:add, month:month, shift:shift, presets:presets };
  }
  if (typeof module !== 'undefined' && module.exports) { module.exports = dateMath; return; }
  function init() {
    var config = global.maDateConfig;
    if (!config || !global.HTMLDialogElement) return;
    var math = dateMath(config), today = math.fromISO(config.today), picker = null, state = null, sequence = 0;
    var bindings = new WeakMap(), ranges = new Set();
    function t(key) { return config.labels[key] || key; }
    function el(tag, cls, text) { var node = document.createElement(tag); if (cls) node.className = cls; if (text !== undefined) node.textContent = text; return node; }
    function button(text, cls, action) { var node = el('button',cls,text); node.type = 'button'; node.addEventListener('click',action); return node; }
    function calendarIcon() { var icon = el('span','ma-date-icon'); icon.setAttribute('aria-hidden','true'); icon.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4m8-4v4"/></svg>'; return icon; }
    function controlIcon(path) { var icon = el('span','ma-date-icon'); icon.setAttribute('aria-hidden','true'); icon.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="'+path+'"/></svg>'; return icon; }
    function fieldContainer(input) { return input.closest('.ma-report-field, [data-ma-date-cell]') || input.parentElement; }
    function readField(input) { return input.dataset.maDateFormat === 'iso' ? math.fromISO(input.value) : math.parse(input.value); }
    function labelFor(input) {
      return Array.from((input.form || document).querySelectorAll('label,[data-ma-date-label]')).find(function (label) {
        return label.dataset.maDateLabel === input.name || (input.id && label.htmlFor === input.id);
      });
    }
    function fieldLinks(input) { return Array.from(fieldContainer(input).querySelectorAll('a[href^="javascript:date_picker"]')); }
    function caption(binding) {
      var value = binding.inputs.map(function (input) { var d = readField(input); return d ? math.format(d) : input.value.trim(); }).join(' - ');
      if (!binding.inputs.some(function (input) { return input.value.trim(); })) value = t('Date range');
      if (binding.text.textContent !== value) binding.text.textContent = value;
      binding.trigger.setAttribute('aria-label',t('Date range')+': '+value);
    }
    function enhance() {
      if (state && state.inputs.some(function (input) { return !input.isConnected; })) close(false);
      document.querySelectorAll('input[type="date"][data-ma-date]').forEach(function (input) { input.dataset.maDateFormat = 'iso'; input.type = 'text'; });
      document.querySelectorAll('input[data-ma-date],input.date').forEach(function (input) {
        if (bindings.has(input) || input.disabled || input.readOnly) return;
        var peer = input.dataset.maDateTo && input.form && input.form.elements.namedItem(input.dataset.maDateTo);
        if (peer && peer.tagName === 'INPUT' && !peer.disabled && !peer.readOnly && !bindings.has(peer)) {
          var inputs = [input,peer], trigger = button('', 'ma-date-range-trigger', function () { open(inputs,trigger); });
          trigger.id = 'ma-range-' + (++sequence);
          trigger.setAttribute('aria-haspopup','dialog'); trigger.setAttribute('aria-expanded','false');
          var text = el('span'); trigger.append(text,calendarIcon());
          var first = fieldContainer(input), last = fieldContainer(peer), firstLabel = labelFor(input), lastLabel = labelFor(peer);
          first.insertBefore(trigger,first.firstChild);
          inputs.forEach(function (field) { field.hidden = true; fieldLinks(field).forEach(function (link) { link.hidden = true; }); });
          first.classList.add('ma-date-range-field');
          if (firstLabel) { firstLabel.textContent = t('Date range'); if (firstLabel.tagName === 'LABEL') firstLabel.htmlFor = trigger.id; }
          if (last !== first) {
            last.hidden = true;
            if (lastLabel) lastLabel.hidden = true;
            var row = last.closest('tr');
            if (row && row !== first.closest('tr') && row.cells.length === 2 && row.contains(lastLabel)) row.hidden = true;
          }
          var binding = {inputs:inputs,trigger:trigger,text:text};
          inputs.forEach(function (field) { bindings.set(field,binding); field.addEventListener('input',function () { caption(binding); }); });
          ranges.add(binding); caption(binding);
        }
      });
      document.querySelectorAll('input[data-ma-date],input.date').forEach(function (input) {
        if (bindings.has(input) || input.disabled || input.readOnly) return;
        var trigger = button('', 'ma-date-single-trigger', function () { open([input],trigger); });
        trigger.append(calendarIcon()); trigger.setAttribute('aria-label',t('Choose date'));
        trigger.setAttribute('aria-haspopup','dialog'); trigger.setAttribute('aria-expanded','false');
        fieldLinks(input).forEach(function (link) { link.hidden = true; });
        input.insertAdjacentElement('afterend',trigger);
        input.classList.add('ma-date-input');
        bindings.set(input,{inputs:[input],trigger:trigger});
        input.addEventListener('keydown',function (event) { if (event.altKey && event.key === 'ArrowDown') { event.preventDefault(); open([input],trigger); } });
      });
      ranges.forEach(function (binding) { if (!binding.trigger.isConnected) ranges.delete(binding); else caption(binding); });
    }
    function close(restore) {
      if (!state) return;
      var trigger = state.trigger;
      trigger.setAttribute('aria-expanded','false');
      state = null;
      picker.close();
      if (restore && trigger.isConnected) trigger.focus({preventScroll:true});
    }
    function place() {
      if (!state) return;
      var rect = state.trigger.getBoundingClientRect(), width = picker.offsetWidth;
      var left = Math.max(12,Math.min(rect.right-width,window.innerWidth-width-12));
      var top = rect.bottom + 8;
      if (top + picker.offsetHeight > window.innerHeight-12) top = Math.max(12,rect.top-picker.offsetHeight-8);
      picker.style.left = left + 'px'; picker.style.top = top + 'px';
    }
    function hint(message) { picker.querySelector('.ma-date-hint').textContent = t(message); }
    function readTyped() {
      var fromText = picker.querySelector('[data-draft="0"]').value.trim();
      var toControl = picker.querySelector('[data-draft="1"]'), toText = toControl ? toControl.value.trim() : fromText;
      var from = math.parse(fromText), to = math.parse(toText);
      if (!from || !to) { hint('Enter valid dates.'); return false; }
      if (to < from) { hint('End date must be on or after start date.'); return false; }
      state.start = from; state.end = to; state.selecting = false;
      return true;
    }
    function syncDraft() {
      picker.querySelector('[data-draft="0"]').value = math.format(state.start);
      var to = picker.querySelector('[data-draft="1"]'); if (to) to.value = math.format(state.end);
    }
    function commit(clear) {
      if (!clear && state.selecting) { hint('Choose an end date.'); return; }
      if (!clear && !readTyped()) return;
      var inputs = state.inputs.slice(), range = inputs.length === 2;
      var dates = [state.start,state.end], changed = [];
      inputs.forEach(function (input,i) {
        var value = clear ? '' : input.dataset.maDateFormat === 'iso' ? math.iso(dates[i]) : math.format(dates[i]);
        if (input.value !== value) changed.push(input);
        input.value = value;
        if (input.getAttribute('aspect') === 'cdate') input.style.color = value === math.format(today) ? '' : 'var(--color-danger)';
      });
      // Both values are set before any listeners or AJAX requests run.
      close(true);
      changed.forEach(function (input) { input.dispatchEvent(new Event('input',{bubbles:true})); input.dispatchEvent(new Event('change',{bubbles:true})); });
      var active = changed.find(function (input) { return input.classList.contains('active'); });
      changed.forEach(function (input) { input.setAttribute('_last_val',input.value); });
      if (active && global.JsHttpRequest) JsHttpRequest.request('_'+active.name+'_changed',active.form);
      if (changed.length) inputs[0].dispatchEvent(new CustomEvent(range ? 'daterangechange' : 'datechange',{bubbles:true,detail:{from:clear ? '' : math.iso(dates[0]),to:clear ? '' : math.iso(dates[range ? 1 : 0])}}));
      enhance();
    }
    function choose(d) {
      state.preset = 'Custom Range';
      if (state.inputs.length === 1) { state.start = state.end = d; state.selecting = false; }
      else if (!state.selecting) { state.start = state.end = d; state.selecting = true; }
      else { state.end = d < state.start ? state.start : d; state.start = d < state.start ? d : state.start; state.selecting = false; }
      syncDraft(); draw(); hint(state.selecting ? 'Choose an end date.' : 'Selection ready. Apply to confirm.');
      focusDay(d);
    }
    function focusDay(d) {
      var candidates = Array.from(picker.querySelectorAll('[data-day="'+math.iso(d)+'"]'));
      var target = candidates.find(function (item) { return !item.classList.contains('outside'); }) || candidates[0];
      if (target) target.focus({preventScroll:true});
    }
    function navigate(delta) { state.view = math.month(state.view,delta); draw(); }
    function draw() {
      var nav = picker.querySelector('.ma-date-presets'); nav.replaceChildren();
      var range = state.inputs.length === 2;
      var presets = range ? math.presets() : {'Today':[today,today]};
      Object.keys(presets).concat(range ? ['Custom Range'] : []).forEach(function (key) {
        var b = button(t(key),'ma-date-preset',function () {
          state.preset = key; state.selecting = false;
          if (presets[key]) { state.start = presets[key][0]; state.end = presets[key][1]; state.view = math.month(state.start); syncDraft(); }
          draw(); hint(presets[key] ? 'Selection ready. Apply to confirm.' : 'Select a start and end date.');
          picker.querySelector('[data-preset="'+key+'"]').focus({preventScroll:true});
        });
        b.dataset.preset = key; b.setAttribute('aria-pressed',String(state.preset === key)); nav.append(b);
      });
      var calendars = picker.querySelector('.ma-date-calendars'); calendars.replaceChildren();
      picker.querySelector('.ma-date-jump select').value = state.view.getMonth();
      picker.querySelector('.ma-date-jump input').value = state.view.getFullYear();
      var count = range && !window.matchMedia('(max-width:620px)').matches ? 2 : 1;
      for (var offset = 0; offset < count; offset++) {
        var month = math.month(state.view,offset), panel = el('section','ma-date-month'), head = el('div','ma-date-month-head');
        var prev = button('','ma-date-arrow',function () { navigate(-1); picker.querySelector('.ma-date-prev').focus(); });
        prev.append(controlIcon('m15 6-6 6 6 6'));
        prev.classList.add('ma-date-prev'); prev.setAttribute('aria-label',t('Previous month')); prev.disabled = state.view.getFullYear() <= 1000 && state.view.getMonth() === 0;
        var next = button('','ma-date-arrow',function () { navigate(1); picker.querySelector('.ma-date-next').focus(); });
        next.append(controlIcon('m9 6 6 6-6 6'));
        next.classList.add('ma-date-next'); next.setAttribute('aria-label',t('Next month')); next.disabled = state.view.getFullYear() >= 9999 && state.view.getMonth() >= 11-(count-1);
        var title = el('strong','',config.months[month.getMonth()]+' '+month.getFullYear());
        head.append(offset === 0 ? prev : el('span','ma-date-spacer'),title,offset === count-1 ? next : el('span','ma-date-spacer'));
        panel.append(head);
        var days = el('div','ma-date-days');
        for (var w = 0; w < 7; w++) days.append(el('span','ma-date-weekday',config.weekdays[(w+config.weekStart)%7]));
        var first = math.add(month,-((month.getDay()-config.weekStart+7)%7));
        for (var i = 0; i < 42; i++) {
          var d = math.add(first,i), day = button(String(d.getDate()),'ma-date-day',choose.bind(null,d));
          day.dataset.day = math.iso(d);
          day.classList.toggle('outside',d.getMonth() !== month.getMonth());
          day.classList.toggle('between',d > state.start && d < state.end);
          day.classList.toggle('edge',+d === +state.start || +d === +state.end);
          day.setAttribute('aria-label',config.months[d.getMonth()]+' '+d.getDate()+', '+d.getFullYear());
          day.setAttribute('aria-pressed',String(d >= state.start && d <= state.end));
          if (+d === +today) day.setAttribute('aria-current','date');
          day.tabIndex = +d === +state.start || (state.start.getMonth() !== month.getMonth() && d.getDate() === 1) ? 0 : -1;
          day.disabled = d.getFullYear() < 1000 || d.getFullYear() > 9999;
          days.append(day);
        }
        panel.append(days); calendars.append(panel);
      }
      place();
    }
    function open(inputs, trigger) {
      if (state) close(false);
      if (!inputs.length || inputs.some(function (input) { return input.disabled || input.readOnly; })) return;
      var from = readField(inputs[0]) || today, to = inputs[1] ? readField(inputs[1]) || from : from;
      state = {inputs:inputs,trigger:trigger,start:from,end:to,view:math.month(from),selecting:false,preset:''};
      if (!picker) {
        picker = el('dialog','ma-date-picker'); picker.id = 'ma-date-picker'; document.body.append(picker);
        picker.setAttribute('aria-labelledby','ma-date-title');
        picker.addEventListener('cancel',function (event) { event.preventDefault(); close(true); });
        picker.addEventListener('click',function (event) { if (event.target === picker) { var r = picker.getBoundingClientRect(); if (event.clientX < r.left || event.clientX > r.right || event.clientY < r.top || event.clientY > r.bottom) close(true); } });
        picker.addEventListener('keydown',function (event) {
          var current = event.target.dataset.day;
          if (!current) return;
          var d = math.fromISO(current), next;
          if (event.key === 'ArrowLeft') next = math.add(d,-1);
          if (event.key === 'ArrowRight') next = math.add(d,1);
          if (event.key === 'ArrowUp') next = math.add(d,-7);
          if (event.key === 'ArrowDown') next = math.add(d,7);
          if (event.key === 'Home') next = math.add(d,-((d.getDay()-config.weekStart+7)%7));
          if (event.key === 'End') next = math.add(d,6-((d.getDay()-config.weekStart+7)%7));
          if (event.key === 'PageUp') next = math.shift(d,event.shiftKey ? -12 : -1);
          if (event.key === 'PageDown') next = math.shift(d,event.shiftKey ? 12 : 1);
          if (next && next.getFullYear() >= 1000 && next.getFullYear() <= 9999) { event.preventDefault(); state.view = math.month(next); draw(); focusDay(next); }
        });
      }
      picker.replaceChildren(); picker.classList.toggle('ma-date-picker-single',inputs.length === 1);
      var header = el('div','ma-date-header'), title = el('h2','',t(inputs.length === 2 ? 'Date range' : 'Choose date')); title.id = 'ma-date-title';
      header.append(title,button('','ma-date-close',function () { close(true); })); header.lastChild.append(controlIcon('m6 6 12 12M6 18 18 6')); header.lastChild.setAttribute('aria-label',t('Cancel')); picker.append(header);
      var nav = el('nav','ma-date-presets'); nav.setAttribute('aria-label',t('Date range')); picker.append(nav);
      var main = el('div','ma-date-main'), jump = el('div','ma-date-jump');
      var monthSelect = el('select'); monthSelect.setAttribute('aria-label',t('Month'));
      config.months.forEach(function (name,i) { var option = el('option','',name); option.value = i; monthSelect.append(option); }); monthSelect.value = state.view.getMonth();
      var yearInput = el('input'); yearInput.type = 'number'; yearInput.min = '1000'; yearInput.max = '9999'; yearInput.value = state.view.getFullYear(); yearInput.setAttribute('aria-label',t('Year'));
      function jumpTo() { var year = Number(yearInput.value); if (Number.isInteger(year) && year >= 1000 && year <= 9999) { state.view = math.date(year,+monthSelect.value,1); draw(); } }
      monthSelect.addEventListener('change',jumpTo); yearInput.addEventListener('change',jumpTo); jump.append(monthSelect,yearInput); main.append(jump,el('div','ma-date-calendars'));
      var typed = el('div','ma-date-typed');
      inputs.forEach(function (input,i) {
        var label = el('label','',t(inputs.length === 1 ? 'Choose date' : i ? 'To' : 'From')), draft = el('input');
        draft.type = 'text'; draft.dataset.draft = i; draft.autocomplete = 'off'; draft.value = readField(input) ? math.format(readField(input)) : input.value; draft.placeholder = math.format(today);
        draft.addEventListener('change',function () { if (readTyped()) { state.view = math.month(state.start); state.preset = ''; draw(); hint('Selection ready. Apply to confirm.'); } });
        label.append(draft); typed.append(label);
      });
      main.append(typed); picker.append(main);
      var footer = el('div','ma-date-footer'), message = el('span','ma-date-hint',t(inputs.length === 2 ? 'Select a start and end date.' : 'Select a date.')); message.setAttribute('role','status');
      var actions = el('div','ma-date-actions');
      if (inputs.every(function (input) { return !input.required; })) actions.append(button(t(inputs.length === 2 ? 'Clear dates' : 'Clear date'),'ma-date-clear',function () { commit(true); }));
      actions.append(button(t('Cancel'),'ma-date-cancel',function () { close(true); }),button(t(inputs.length === 2 ? 'Apply range' : 'Apply date'),'ma-date-apply',function () { commit(false); }));
      footer.append(message,actions); picker.append(footer);
      trigger.setAttribute('aria-expanded','true'); trigger.setAttribute('aria-controls',picker.id);
      draw(); picker.showModal(); place();
      picker.querySelector('[data-draft="0"]').focus({preventScroll:true});
    }
    global.maDatePicker = {refresh:enhance,open:function (input) { var binding = bindings.get(input); open(binding ? binding.inputs : [input],binding ? binding.trigger : input); }};
    global.date_picker = function (input) { if (input) global.maDatePicker.open(input); };
    global.hideCC = function () { close(true); };
    enhance();
    // FA reapplies Behaviour after AJAX, including updates to input values only.
    if (global.Behaviour) Behaviour.register({'body':enhance});
    var queued = false;
    new MutationObserver(function (records) {
      if (!records.some(function (record) { return !picker || !picker.contains(record.target); }) || queued) return;
      queued = true; queueMicrotask(function () { queued = false; enhance(); });
    }).observe(document.body,{childList:true,subtree:true});
    window.addEventListener('resize',function () { if (state) draw(); });
    document.addEventListener('reset',function () { setTimeout(enhance,0); });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded',init); else init();
})(typeof window === 'undefined' ? globalThis : window);
