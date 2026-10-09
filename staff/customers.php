<?php
require_once __DIR__ . '/../includes/auth.php';

$user = require_role('staff');
$page_title = 'Customers';
$active_nav = 'customers';

require __DIR__ . '/../includes/views/customers_view.php';
