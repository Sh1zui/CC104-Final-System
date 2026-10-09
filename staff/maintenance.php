<?php
require_once __DIR__ . '/../includes/auth.php';

$user = require_role('staff');
$page_title = 'Maintenance';
$active_nav = 'maintenance';

require __DIR__ . '/../includes/views/maintenance_view.php';
