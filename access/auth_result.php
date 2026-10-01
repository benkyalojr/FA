<?php
// Shared result screens. Session handlers own authentication and cleanup.
if (!isset($path_to_root, $auth_result)
    || !in_array($auth_result, array('logout', 'login_failed', 'reset_sent', 'reset_failed'), true)
    || isset($_GET['path_to_root']) || isset($_POST['path_to_root'])) {
    http_response_code(404);
    exit;
}
include $path_to_root . '/ui/auth.inc';
$auth_view = 'auth_result';
$result_url = $path_to_root . '/index.php';
$result_label = _('Back to sign in');
switch ($auth_result) {
    case 'logout':
        $auth_heading = _("You're signed out");
        $auth_subtitle = sprintf(_('Thank you for using %s. Sign in again whenever you are ready to continue.'), $identity['name']);
        $result_label = _('Sign in again');
        break;
    case 'login_failed':
        $auth_heading = _('Unable to sign in');
        $auth_subtitle = _('The username or password is incorrect. Please try again or contact your system administrator if you need access.');
        $auth_error = true;
        $result_label = _('Try again');
        break;
    case 'reset_sent':
        $auth_heading = _('Check your messages');
        $auth_subtitle = _('A temporary password has been sent to your registered phone and email. Use it to sign in and choose a new password.');
        break;
    case 'reset_failed':
        $auth_heading = _('Unable to reset password');
        $auth_subtitle = _('Please check your account details and company, then try again. Contact your system administrator if you need help.');
        $auth_error = true;
        $result_url .= '?reset=1';
        $result_label = _('Try again');
        break;
}
$title = $auth_heading . ' | ' . $identity['name'];
include $path_to_root . '/ui/views/auth.php';
