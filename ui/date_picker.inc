<?php
// Shared date-field metadata. Pair only known period fields, never arbitrary dates.
function ma_date_attributes($name, $peer = null)
{
    $pairs = array('TransAfterDate'=>'TransToDate', 'TransFromDate'=>'TransToDate',
        'FromDate'=>'ToDate', 'AfterDate'=>'BeforeDate', 'OrdersAfterDate'=>'OrdersToDate',
        'DeliveryAfterDate'=>'DeliveryToDate', 'from'=>'to', 'from_date'=>'to_date',
        'date_from'=>'date_to', 'show_from'=>'show_to', 'week_of'=>'week_to',
        'receive_begin'=>'receive_end', 'proposed_start_date'=>'proposed_end_date');
    $peer = $peer ?? ($pairs[$name] ?? '');
    return ' data-ma-date="true"'.($peer !== '' ? ' data-ma-date-to="'.htmlspecialchars($peer, ENT_QUOTES, 'UTF-8').'"' : '');
}

function ma_date_picker_assets()
{
    global $SysPrefs, $path_to_root, $tmonths;
    static $loaded = false;
    // Keep FA's conversion-aware calendar for Jalali and Hijri installations.
    if ($loaded || !user_use_date_picker() || in_array((int)$SysPrefs->date_system, array(1, 2), true)) return;
    $loaded = true;
    require_once $path_to_root.'/includes/date_functions.inc';
    require_once $path_to_root.'/admin/db/fiscalyears_db.inc';
    // The installer (and login) run before any company database is connected:
    // the calendar then simply has no financial-year shortcuts.
    $year = false;
    $previous = false;
    try {
        $year = get_current_fiscalyear();
        if ($year) {
            $result = db_query('SELECT begin, end FROM '.TB_PREF.'fiscal_year WHERE end < '.db_escape($year['begin']).' ORDER BY end DESC LIMIT 1', 'Could not read previous financial year');
            $previous = db_fetch($result);
        }
    } catch (Throwable $e) {
        $year = $previous = false;
    }
    $labels = array();
    foreach (array('Date range','Choose date','From','To','Apply range','Apply date','Cancel','Today','Yesterday',
        'Last 7 Days','Last 30 Days','This Month','Last Month','Last 3 Months','Last 6 Months','Last 8 Months',
        'This Year','This Financial Year','Previous Financial Year','Financial Year to Date','Custom Range',
        'Previous month','Next month','Month','Year','Select a start and end date.', 'Choose an end date.',
        'Selection ready. Apply to confirm.', 'Enter valid dates.', 'End date must be on or after start date.',
        'Clear dates', 'Clear date', 'Select a date.') as $label) $labels[$label] = _($label);
    $config = array('format'=>user_date_format(), 'separator'=>$SysPrefs->dateseps[user_date_sep()],
        'shortMonths'=>array_values(array_slice($tmonths, 1, 12)), 'today'=>date('Y-m-d'),
        'weekStart'=>(int)$SysPrefs->date_system === 3 ? 6 : (in_array(user_date_format(), array(0,3)) ? 0 : 1),
        'months'=>array(_('January'),_('February'),_('March'),_('April'),_('May'),_('June'),_('July'),_('August'),_('September'),_('October'),_('November'),_('December')),
        'weekdays'=>array(_('Su'),_('Mo'),_('Tu'),_('We'),_('Th'),_('Fr'),_('Sa')), 'labels'=>$labels,
        'fiscal'=>$year ? array($year['begin'],$year['end']) : null,
        'previousFiscal'=>$previous ? array($previous['begin'],$previous['end']) : null);
    // FA translations may use a legacy encoding; JSON must contain valid UTF-8.
    $encoding = $_SESSION['language']->encoding ?? 'UTF-8';
    array_walk_recursive($config, static function (&$value) use ($encoding) {
        if (is_string($value) && !preg_match('//u', $value))
            $value = iconv($encoding, 'UTF-8', $value);
    });
    // tmonths is also used by FA's existing date-entry keyboard shortcuts.
    add_js_source('window.maDateConfig='.json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE).';window.tmonths=[""].concat(window.maDateConfig.shortMonths);');
    add_css_file($path_to_root.'/ui/components/date-picker.css?v='.filemtime($path_to_root.'/ui/components/date-picker.css'));
    add_js_ufile($path_to_root.'/ui/date-picker.js?v='.filemtime($path_to_root.'/ui/date-picker.js'));
}
