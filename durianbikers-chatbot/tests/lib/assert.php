<?php
$GLOBALS['t_pass'] = 0; $GLOBALS['t_fail'] = 0;
function t_ok($cond, $name) {
    if ($cond) { $GLOBALS['t_pass']++; echo "PASS: $name\n"; }
    else { $GLOBALS['t_fail']++; echo "FAIL: $name\n"; }
}
function t_eq($a, $b, $name) { t_ok($a === $b, $name . " (got " . var_export($a, true) . ")"); }
function t_done() {
    echo "\n{$GLOBALS['t_pass']} passed, {$GLOBALS['t_fail']} failed\n";
    exit($GLOBALS['t_fail'] > 0 ? 1 : 0);
}
