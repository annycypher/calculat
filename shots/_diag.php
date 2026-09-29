<?php
$r = 'C:/Users/krs3d/.cline/data/workspaces/chat/calc_docs';
require $r . '/admin-panel-x7k2/inc/config.php';
require $r . '/admin-panel-x7k2/inc/auth.php';
require $r . '/admin-panel-x7k2/inc/ui.php';
require $r . '/admin-panel-x7k2/inc/content.php';
require $r . '/admin-panel-x7k2/inc/meta.php';
require $r . '/admin-panel-x7k2/inc/deploy.php';
echo 'подключения прошли' . PHP_EOL;
var_dump(function_exists('ftpDeploy'), function_exists('deploy_changes_count'));
