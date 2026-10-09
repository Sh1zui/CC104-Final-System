<?php
require_once __DIR__ . '/../includes/auth.php';

$user = require_role('staff');
$page_title = 'Reservations';
$active_nav = 'reservations';

require __DIR__ . '/../includes/views/bookings_view.php';
