<?php
// read-psi-key.php — читает psi_api_key из content/seo/gsc.sqlite (без вывода секрета наружу — только в stdout для пайпа).
$pdo = new PDO('sqlite:C:/Users/krs3d/.cline/data/workspaces/chat/calc_docs/content/seo/gsc.sqlite');
$st = $pdo->query("SELECT param_value FROM seo_settings WHERE param_name='psi_api_key'");
echo $st->fetchColumn();
