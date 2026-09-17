<?php
require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/wp-load.php';
delete_transient('brandflow_mega_menu_html');
echo "Transient cleared.";
