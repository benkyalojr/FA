/**********************************************************************
    Copyright (C) FrontAccounting, LLC.
	Released under the terms of the GNU General Public License, GPL,
	as published by the Free Software Foundation, either version 3
	of the License, or (at your option) any later version.
    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
    See the License here <http://www.gnu.org/licenses/gpl-3.0.html>.
***********************************************************************/
/**********************************************************************
  Progressively enhances item/customer/supplier combos with server search,
  and combos already scoped to a fixed option list (customer branches, and
  anything else marked data-select2-local) with search over those existing
  options. Applied by Behaviour, so it re-runs after every Ajax partial reload.
***********************************************************************/
function fa_select2_init(sel) {
	if (typeof jQuery === 'undefined' || sel.classList.contains('select2-hidden-accessible'))
		return;

	var $sel = jQuery(sel);
	var relId = sel.getAttribute('rel');

	if (relId) {
		// hide FA's own search text box; Select2 provides its own
		var box = document.getElementById(relId);
		if (box) box.style.display = 'none';
	}

	// hide the popup search magnifier icon, now redundant
	var host = sel.closest('span') || sel;
	var sib = host.nextElementSibling;
	while (sib) {
		if (sib.tagName === 'IMG') { sib.style.display = 'none'; break; }
		sib = sib.nextElementSibling;
	}

	var options = {
		// 'resolve' reads the *original* select's rendered width, but that
		// select normally holds just the one currently-selected option, so
		// the box would size to whatever that option's text happened to be
		// instead of a stable width. Match FA's own `select { max-width }`.
		width: '230px',
		minimumInputLength: 0,
		allowClear: false,
		dropdownAutoWidth: true
	};

	// Some combos (customer branches, and anything else PHP marks
	// data-select2-local) already contain just the options the caller wants
	// - search that fixed list locally instead of hitting the server.
	if (!sel.hasAttribute('data-select2-local')) {
		var specialOption = sel.querySelector('option[data-select2-static]');
		options.ajax = {
			url: sel.getAttribute('data-select2-url'),
			dataType: 'json',
			delay: 250,
			data: function(params) {
				return {
					entity: sel.getAttribute('data-select2-entity'),
                    route_id: sel.getAttribute('data-select2-route') || '',
                    active: sel.getAttribute('data-select2-active') || '',
					account_type: sel.getAttribute('data-select2-account-type') || '',
					term: params.term || '',
					type: sel.getAttribute('data-select2-type') || '',
					parent: sel.getAttribute('data-select2-parent') || ''
				};
			},
			processResults: function(data, params) {
				// Preserve page-specific choices such as All Customers, including
				// their original value (an empty string means all customers).
				if (specialOption && (!params.page || params.page === 1)) {
					data.results = [{id: specialOption.value, text: specialOption.text}].concat(
						jQuery.grep(data.results, function(result) {
							return String(result.id) !== specialOption.value;
						})
					);
				}
				return data;
			},
			cache: true
		};
	}

	// Select2 already triggers change on the original select, including FA's
	// onchange handler. Dispatching it again would submit the selection twice.
	$sel.select2(options);
}

Behaviour.register({
	// Not an attribute selector: FA's Behaviour.js only captures \w+ for
	// attribute names, which breaks on hyphenated names like
	// data-select2-entity. Match on the plain "fa-select2" class instead;
	// the data-select2-* parameters are still read via getAttribute() below.
	'select.fa-select2': fa_select2_init
});
