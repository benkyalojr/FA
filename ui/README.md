# UI design system

Authentication, navigation, dashboards and the application shell use this layer.
They do not load FA's theme CSS or theme renderer. Existing transaction pages
keep their server handlers, form helpers and Ajax behavior.

- `design/tokens.css`: shared colors, fonts, spacing, control sizes, radii and motion.
- `config.php`: product name and description.
- `components/login.css`: responsive authentication layout using the shared tokens.
- `auth.inc` and `views/auth.php`: shared authentication layout and view data.
- `views/login.php`, `views/password_reset.php`, `views/auth_result.php`: forms and recovery results.
- `login.js`: password visibility, login cooldown feedback and full UI mode selection.
- `favicon.ico`, `favicon-*.png`, `apple-touch-icon.png`, `logo.png`: brand assets.
- `workspace.inc`: navigation and quick actions derived from enabled modules and
  the signed-in user's link permissions; local SVG icons.
- `renderer.php` and `views/workspace_header.php`: sidebar, top bar, user menu,
  shared page frame and footer. Module extension `render_index()` hooks remain available.
- `views/module.php`: permitted module actions, grouped into cards.
- `components/workspace.css`: responsive shell, dashboard and dark appearance.
- `components/forms.css`: compatibility styling for existing FA forms and tables.
- `workspace.js`: navigation, dropdowns, appearance and dashboard period controls.
  Appearance and desktop sidebar preferences persist in browser local storage.
- `dashboard.inc` and `views/dashboard.php`: permission-filtered live metrics,
  fiscal-year progress and accessible charts with exact-value tables.
- `reports.inc`, `views/reports.php` and `views/report_filters.php`: report
  categories, searchable cards and each report's actual parameter form.
- `components/reports.css` and `reports.js`: responsive report catalogue,
  cross-category search, filter-panel selection and keyboard focus handling.

The Report Centre uses `BoxReports` registrations, including company and extension
reports. Category links retain their `Class` IDs; forms keep `REP_ID`, `Rep{id}`
and ordered `PARAM_n` fields, so existing PDF/Excel and email handlers continue
to receive the same inputs. The existing report handlers enforce report-specific
permissions. Category access rules also filter the catalogue. Saved settings are
defaults; current edits take precedence on Ajax refresh. Without JavaScript,
category/report links and form submission continue through the normal page routes.
The report view sits inside the same workspace sidebar and top bar as other pages.
On desktop, compact categories and two columns of report cards sit beside a narrow
filter panel, with the print action above its fields. Smaller screens stack the
filters below the list.

Change primary colors, typography, spacing and radii in `design/tokens.css`.
Both light and dark palettes live there; dark overrides are scoped to the workspace.

The shared calendar lives in `date_picker.inc`, `date-picker.js` and
`components/date-picker.css`. `date_cells()` and `date_row()` emit date metadata;
the page header loads the component once. Known period field names are explicitly
paired in `ma_date_attributes()`, scoped to the same form. Reports pair adjacent
`DATEBEGIN*` and `DATEEND*` controls. Single and unrelated document dates stay separate.
Custom fields can opt in with `data-ma-date="true"` and, for a range start,
`data-ma-date-to="end_field_name"`. ISO-backed fields use `data-ma-date-format="iso"`.

Selections are drafts until Apply; Cancel/Escape discard them. Original named
inputs remain the submitted source of truth, including `PARAM_n` and CSRF tokens.
Both range values are committed before one active-field AJAX request. FA Behaviour
and DOM updates refresh the controls after AJAX. The picker supports typed dates,
six FA date formats, keyboard navigation and a single calendar on small screens.
Presets use the server date and actual configured fiscal periods (the previous
year is offered only when present). Jalali/Hijri installations keep FA's existing
conversion-aware calendar; disabling the calendar preference keeps manual entry.
Controls use SVG icons and plain ASCII punctuation instead of decorative glyphs.

Run `node tests/date_picker.cjs` for date arithmetic and format checks. The DOM
regression suite is `tests/date_picker_dom.cjs` and requires jsdom on `NODE_PATH`;
it checks draft/apply behavior, atomic updates, form isolation and AJAX replacement
without connecting to the database or opening a browser.
`config.php` sets the product identity; the company badge comes from the active
company. There is no simulated company switcher or placeholder module navigation.

`includes/main.inc`, `includes/page/header.inc`, `includes/page/footer.inc` and
`frontaccounting.php` connect this layer to FA. The old `admin/dashboard.php` URL
also opens the new dashboard. Legacy image assets are still used by FA form helpers.

Dashboard sales are net of credit notes, exclude tax, and convert to company
currency using transaction rates. Receivables account for allocations and
unallocated credits/payments; invoice counts include only outstanding invoices.
Record counts include active records. Bank/cash balances use the associated GL
accounts without double-counting accounts shared by bank records. Optional
employee and farmer data appears only when installed and permitted.
Analytics compare posted income with cost of sales and operating expenses.
Balance-sheet classes show cumulative balances to the cutoff; income and expense
classes use the selected period. All-time charts aggregate by year; other periods
aggregate by month. No demo figures or unsupported CRM/ticket metrics are shown.

`access/login.php` prepares the view. Authentication and permissions remain in
FA's session layer. The form keeps FA's field names, company selection, CSRF
generation and saved timeout request. Without JavaScript, it submits in fallback
mode. Recovery forms and success/failure screens use the same layout, while
the existing session handlers still perform delivery and session cleanup.
`access/auth_result.php` also renders logout and standalone failed-login screens.
Logout retains session revocation and audit logging, and clears the session even
if the confirmation view fails. Social
authentication and public registration are not configured and are not shown.

Inter is requested from Google Fonts, matching the supplied reference. System
fonts provide an offline fallback. The new UI adds no external JavaScript or icon
service; existing FA pages retain their current jQuery/Select2 dependencies.

Run `php tests/login_ui.php` to check rendered login states and form contracts
without connecting to a database or authenticating a real user.
Run `php tests/workspace_ui.php` to check permission filtering, dashboard queries,
currency conversion, allocations, fiscal dates and chart edge cases against an
isolated in-memory SQLite fixture. It does not write to company databases.
Run `php tests/reports_ui.php` to check catalogue rendering, parameter ordering,
form tokens, custom controls, Ajax responses, saved settings and category access
without generating reports or sending email.
