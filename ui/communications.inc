<?php
// Communications workspace: delivery logs as tabs; templates, rules, recipient
// preferences and gateways are its settings screens (see ui/settings.inc).
require_once __DIR__ . '/finance.inc';

define('MA_COMMUNICATIONS_APP', 'communications');

function ma_communications_tabs()
{
    return array(
        'log'   => array(_('Delivery Log'), 'message', 'communications/inquiry/comm_log_inquiry.php', 'SA_COMMLOG'),
        'sms'   => array(_('SMS'), 'message', 'communications/inquiry/sms_log_inquiry.php', 'SA_COMMLOG'),
        'email' => array(_('Email'), 'message', 'communications/inquiry/email_log_inquiry.php', 'SA_COMMLOG'),
    );
}

function ma_communications_active_tab()
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    if (basename($script) === 'index.php') return (is_string($_GET['application'] ?? null) && $_GET['application'] === MA_COMMUNICATIONS_APP) ? 'log' : null;
    foreach (ma_communications_tabs() as $key => $tab)
        if (substr($script, -strlen('/'.$tab[2])) === '/'.$tab[2]) return $key;
    return null;
}

function ma_communications_new_links() { return array(); }
function ma_communications_reports_links() { return array(); }
function ma_communications_settings_links() { return array(); }
