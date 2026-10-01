<?php
// Link-level menu permissions are not part of this codebase; the shared UI
// only needs URL normalisation and a "no extra right required" answer.
if (!function_exists('menu_rights_normalize')) {
    function menu_rights_normalize($url)
    {
        $parts = parse_url($url);
        $path = ltrim($parts['path'] ?? '', './');
        parse_str($parts['query'] ?? '', $query);
        ksort($query);
        return $path.($query ? '?'.http_build_query($query) : '');
    }
}
if (!function_exists('menu_right_for_link')) {
    function menu_right_for_link($url) { return null; }
}
