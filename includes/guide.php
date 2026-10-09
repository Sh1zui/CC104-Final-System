<?php
/**
 * Help for people new to AutoWay.
 *
 * 1. The quick tour: a short spotlight walkthrough of the screen, shown once
 *    on a new user's first dashboard visit (users.tour_completed_at is
 *    NULL). Seeded and older accounts have a date there and skip it.
 *    Anyone can replay it from the Guide page (?tour=1 on the dashboard).
 * 2. The full guide (auth/guide.php): step-by-step guides for every
 *    task that role does, with a contents list to jump around.
 *
 * Both are written per role. Numbers in the text (deposit, fees, hours)
 * come from the live settings, so the help never disagrees with the rules.
 * Targets are CSS selectors; the sidebar links carry data-nav="<key>".
 */

require_once __DIR__ . '/functions.php';

/** True when this user hasn't seen the quick tour yet. */
function tour_pending(int $user_id): bool
{
    try {
        $stmt = Database::getConnection()->prepare('SELECT tour_completed_at IS NULL FROM users WHERE user_id = ?');
        $stmt->execute([$user_id]);
        return (bool) $stmt->fetchColumn();
    } catch (PDOException $e) {
        // Database imported before the guide existed (no column yet):
        // skip the tour rather than break the page. Re-import to enable it.
        return false;
    }
}

function mark_tour_done(int $user_id): void
{
    Database::getConnection()
        ->prepare('UPDATE users SET tour_completed_at = NOW() WHERE user_id = ? AND tour_completed_at IS NULL')
        ->execute([$user_id]);
}

/** The quick tour for a role: [{target, title, body}], target null = centered. */
function tour_steps(string $role, string $first_name): array
{
    $bell = ['target' => '.notif-btn', 'title' => 'Notifications',
             'body' => 'The bell shows a count when something needs you. Click it to see the list; each one opens the booking or customer it\'s about.'];
    $look = ['target' => '#palette-btn', 'title' => 'Make it yours',
             'body' => 'Pick one of four color palettes here, and switch between light and dark with the moon next to it. Your choice is remembered on this browser.'];
    $help = ['target' => '[data-nav="guide"]', 'title' => 'Help is always here',
             'body' => 'Guide walks you through everything you can do, step by step, and has a button to replay this tour.'];

    if ($role === 'admin') {
        return [
            ['target' => null, 'title' => 'Welcome to ' . APP_NAME . ', ' . $first_name,
             'body' => 'This 1-minute tour shows where everything is. You can skip it now and replay it later from Guide in the sidebar.'],
            ['target' => '.stat-grid', 'title' => 'The fleet at a glance',
             'body' => 'These tiles are live: cars available, out, and in the workshop, plus revenue. Colors mean the same thing everywhere: green available, blue out, amber needs attention, red overdue.'],
            ['target' => '[data-nav="vehicles"]', 'title' => 'Vehicles',
             'body' => 'Add cars, upload photos, set daily rates, and archive cars you sell. Maintenance, right below, schedules services so a car isn\'t booked while it\'s in the shop.'],
            ['target' => '[data-nav="bookings"]', 'title' => 'Bookings',
             'body' => 'Every reservation: create one at the desk, confirm online requests, release the car, receive it back, and settle the money.'],
            ['target' => '[data-nav="reports"]', 'title' => 'Reports',
             'body' => 'Revenue, utilization, and bookings for any period, with CSV downloads for your spreadsheets.'],
            ['target' => '[data-nav="settings"]', 'title' => 'Settings and users',
             'body' => 'Settings holds the business details and the rental rules (deposit, fees, discounts). Users and staff, above it, adds accounts for your team.'],
            $bell, $look, $help,
        ];
    }
    if ($role === 'staff') {
        return [
            ['target' => null, 'title' => 'Welcome to the desk, ' . $first_name,
             'body' => 'This 1-minute tour shows where your daily work lives. You can skip it now and replay it later from Guide in the sidebar.'],
            ['target' => '.stat-grid', 'title' => 'Today',
             'body' => 'Pickups, returns, overdue cars, and requests waiting for you. Click a tile to open that list.'],
            ['target' => '[data-nav="reservations"]', 'title' => 'Reservations',
             'body' => 'Create bookings for walk-in customers, confirm online requests, take payments, and print receipts.'],
            ['target' => '[data-nav="checkout"]', 'title' => 'Release a vehicle',
             'body' => 'Cars leaving today and tomorrow. Check the license, note the odometer and fuel, and hand over the keys.'],
            ['target' => '[data-nav="checkin"]', 'title' => 'Receive a return',
             'body' => 'Cars coming back, overdue first. Late, fuel, and damage charges are worked out for you before you save.'],
            ['target' => '[data-nav="customers"]', 'title' => 'Customers',
             'body' => 'Check uploaded licenses and verify new customers so they can book.'],
            $bell, $look, $help,
        ];
    }
    return [
        ['target' => null, 'title' => 'Welcome to ' . APP_NAME . ', ' . $first_name,
         'body' => 'This 1-minute tour shows how renting works here. You can skip it now and replay it later from Guide in the sidebar.'],
        ['target' => '[data-nav="documents"]', 'title' => 'First, your driver\'s license',
         'body' => 'Upload a clear photo of your license in My documents. The desk checks it once, and then you can book any time.'],
        ['target' => '[data-nav="browse"]', 'title' => 'Browse vehicles',
         'body' => 'Pick your dates to see which cars are free and the full price, then request the one you want. Pickups need at least '
             . ONLINE_BOOKING_LEAD_HOURS . ' hours\' notice.'],
        ['target' => '[data-nav="bookings"]', 'title' => 'My bookings',
         'body' => 'Follow each request from "waiting for the desk" to confirmed. Pay, cancel, or print receipts from here.'],
        ['target' => '[data-nav="payments"]', 'title' => 'Payments',
         'body' => 'Every receipt and invoice in one place. Paid by GCash or bank transfer? Tell us on the booking, and the desk checks it.'],
        $bell, $look, $help,
    ];
}

/**
 * The full guide for a role: sections of [id, title, icon, intro, steps[], tip].
 * Steps are plain text; **bold** marks a button or page name.
 */
function guide_sections(string $role): array
{
    $deposit = money(SECURITY_DEPOSIT);
    $s = [];

    $s[] = ['id' => 'getting-around', 'title' => 'Getting around', 'icon' => 'bi-compass',
        'intro' => 'Everything starts from the sidebar on the left. On a phone, tap the menu button at the top left to open it.',
        'steps' => [
            'The **sidebar** lists every page you can use. The page you\'re on has a mango marker.',
            'The **bell** at the top right shows notifications. A number means something new; click one to open what it\'s about.',
            'The **palette** button changes the colors, and the **moon** switches light and dark mode. Both are remembered on this browser.',
            'Your name at the bottom of the sidebar opens your account, where you can change your password.',
            'Click **Log out** at the top right when you\'re done, especially on a shared computer.',
        ],
        'tip' => 'Lost? Come back to this page from **Guide** in the sidebar, or replay the quick tour.'];

    if ($role === 'customer') {
        $s[] = ['id' => 'verify', 'title' => 'Get verified', 'icon' => 'bi-person-vcard',
            'intro' => 'Before your first booking, the desk checks your driver\'s license once.',
            'steps' => [
                'Open **My documents** and choose **Driver\'s license** as the document type.',
                'Pick a clear photo or scan (JPG, PNG, WebP, or PDF, up to 5 MB) with all four corners showing, and click **Upload**.',
                'It shows as "pending" until the desk reviews it. If it\'s rejected, the reason is shown; upload a better photo.',
                'Once the desk verifies you, your account shows "verified" and you can book.',
            ],
            'tip' => 'Anyone ' . MIN_RENTER_AGE . ' or older with a license that doesn\'t expire before the car is due back can rent.'];
        $s[] = ['id' => 'book', 'title' => 'Book a car', 'icon' => 'bi-car-front',
            'intro' => 'You see the full price before you ask for a car.',
            'steps' => [
                'Open **Browse vehicles** and pick your pickup and return date and time. Pickups need at least ' . ONLINE_BOOKING_LEAD_HOURS . ' hours\' notice.',
                'Filter by type, transmission, or seats if you like, then click **Check availability**.',
                'Each free car shows the total for your dates. Click **Book**, check the price breakdown, add a note for the desk if needed, and click **Request booking**.',
                'Your request appears in **My bookings** as waiting for the desk. You get a notification when it\'s confirmed.',
            ],
            'tip' => 'Rentals of ' . LONG_RENTAL_MIN_DAYS . ' days or more get ' . LONG_RENTAL_DISCOUNT_PCT . '% off automatically. You can have up to '
                . MAX_PENDING_ONLINE_BOOKINGS . ' requests waiting at a time.'];
        $s[] = ['id' => 'pay', 'title' => 'Pay', 'icon' => 'bi-wallet2',
            'intro' => 'Pay at the desk, or by GCash or bank transfer from your account.',
            'steps' => [
                'Open the booking in **My bookings**. The balance and what\'s due are shown.',
                'Paid online? Click **I sent a payment**, enter the amount, the reference number from GCash or your bank, and the date, then **Send**.',
                'It shows "being checked" until the desk matches it. Then a receipt is issued and you get a notification.',
                'All receipts and invoices are under **Payments**. Open one to print or save it as a PDF.',
            ],
            'tip' => 'The ' . $deposit . ' deposit is refundable. Anything owed at return (late hours, fuel, damage) comes out of it first, and the rest comes back to you.'];
        $s[] = ['id' => 'cancel', 'title' => 'Change or cancel', 'icon' => 'bi-x-circle',
            'intro' => 'Plans change. Here\'s what it costs.',
            'steps' => [
                'Open the booking in **My bookings** and click **Cancel booking**. The fee, if any, is shown before you confirm.',
                'A request still waiting for the desk cancels free.',
                'A confirmed booking cancels free up to ' . FREE_CANCELLATION_HOURS . ' hours before pickup. After that, it costs ' . CANCELLATION_FEE_DAYS
                    . ' day' . (CANCELLATION_FEE_DAYS === 1 ? '' : 's') . ' of the rental.',
                'To change dates, call the desk at ' . BUSINESS_PHONE . '.',
            ],
            'tip' => null];
        $s[] = ['id' => 'pickup-return', 'title' => 'Pick up and return', 'icon' => 'bi-key',
            'intro' => 'The handover takes a few minutes at the desk.',
            'steps' => [
                'Bring your driver\'s license. The desk checks it against your account.',
                'Together you note the odometer and the fuel level, and any existing scratches.',
                'Bring the car back by the return time with the same fuel level. The first ' . LATE_GRACE_MINUTES . ' minutes late are free; after that each started hour costs '
                    . LATE_FEE_HOURLY_PCT . '% of the daily rate.',
                'The desk settles the deposit: charges come out, and the rest is refunded to you.',
            ],
            'tip' => 'A short tank is charged at ' . money(FUEL_CHARGE_PER_PERCENT) . ' for each 1% missing.'];
        $s[] = ['id' => 'profile', 'title' => 'Your profile', 'icon' => 'bi-gear',
            'intro' => 'Keep your details current so the desk can reach you.',
            'steps' => [
                'Open **Profile** to change your name, email, mobile number, and address, then **Save details**.',
                'License fields lock once you\'re verified. To change them, upload the new license in **My documents** and ask the desk.',
                'Change your password at the bottom. Other browsers where you\'re logged in are signed out.',
            ],
            'tip' => null];
        return $s;
    }

    // Staff and admin share the desk work.
    $is_admin = $role === 'admin';
    $bookings = $is_admin ? 'Bookings' : 'Reservations';
    $s[] = ['id' => 'daily', 'title' => $is_admin ? 'Your dashboard' : 'Your day at the desk', 'icon' => $is_admin ? 'bi-speedometer2' : 'bi-sun',
        'intro' => $is_admin ? 'The dashboard shows the whole business at a glance.' : 'Start each shift on **Today**.',
        'steps' => $is_admin ? [
            'The tiles count cars available, out, and in maintenance, plus active bookings and revenue (deposits are held, not earned, so they\'re not counted).',
            'The 7-day chart shows money earned each day; the fleet ring shows where the cars are right now.',
            '**Recent payments** and **Vehicles due back** list what needs a look, with overdue cars marked in red.',
        ] : [
            'The tiles count today\'s pickups, cars due back, overdue cars, and online requests waiting for you. Click a tile to open that list.',
            '**Pickups today** and **Due back** list the cars to hand over and receive, each with a button to do it.',
            '**Fleet right now** shows where every car is and its next pickup.',
        ],
        'tip' => 'Colors mean the same everywhere: green available or paid, blue out, amber needs attention, red overdue.'];
    $s[] = ['id' => 'new-booking', 'title' => 'Create a booking at the desk', 'icon' => 'bi-calendar-plus',
        'intro' => 'For walk-in and phone customers.',
        'steps' => [
            'Open **' . $bookings . '** and click **New booking**.',
            'Choose the customer (only verified customers can book; add a new one from **Customers** first), then the pickup and return times.',
            'Click **Find available vehicles**. Only cars free for those dates are listed, with the price for each.',
            'Pick a car. Add an extra discount if needed (staff up to ' . STAFF_MAX_DISCOUNT_PCT . '%, admins up to ' . MAX_MANUAL_DISCOUNT_PCT . '%), check the total, and click **Create booking**.',
            'Record the deposit right away with **Record payment** so the car is held.',
        ],
        'tip' => 'Two bookings of the same car need a ' . TURNAROUND_HOURS . '-hour gap for cleaning; the search already allows for it.'];
    $s[] = ['id' => 'online-requests', 'title' => 'Confirm online requests', 'icon' => 'bi-inbox',
        'intro' => 'Customers request cars online; you confirm them.',
        'steps' => [
            'The bell shows a new request. Click it, or filter **' . $bookings . '** by "Pending".',
            'Open the booking and check the customer and dates.',
            'Click **Confirm booking**. The customer gets a notification.',
            'If a customer reports a GCash or bank payment, the booking shows "online payment to check". Match the reference with your records, then **Accept** (a receipt is issued) or **Reject** with a reason.',
        ],
        'tip' => null];
    $s[] = ['id' => 'release', 'title' => 'Release a car', 'icon' => 'bi-box-arrow-right',
        'intro' => 'Hand the keys over from **' . ($is_admin ? 'Bookings' : 'Release a vehicle') . '**.',
        'steps' => [
            ($is_admin ? 'Open the booking. ' : 'Find the booking in **Release a vehicle** and click **Release**. ') . 'A car can be released up to ' . EARLY_CHECKOUT_MINUTES . ' minutes before pickup.',
            'Check the driver\'s license and tick the box to say you did.',
            'Enter the odometer reading and fuel level, and note any existing damage.',
            'Click **Release car**. The car shows as out on rental.',
        ],
        'tip' => 'A booking with money still due shows the balance first; take it with **Record payment**.'];
    $s[] = ['id' => 'return', 'title' => 'Receive a car back', 'icon' => 'bi-box-arrow-in-left',
        'intro' => 'Overdue cars are listed first in **' . ($is_admin ? 'Bookings' : 'Receive a return') . '**.',
        'steps' => [
            'Open the booking and click **Receive car back**.',
            'Enter the odometer and fuel level. Late hours and a fuel shortfall are charged automatically and shown before you save.',
            'Add a damage charge or other charge if needed, and tick **Needs maintenance before it\'s rented again** if something is wrong; a maintenance job opens.',
            'Click **Receive car and close rental**.',
            'Click **Settle**: the plan shows what comes out of the deposit and what is refunded. Confirm it, then **Issue invoice** to print.',
        ],
        'tip' => 'The first ' . LATE_GRACE_MINUTES . ' minutes late are free. After that each started hour costs ' . LATE_FEE_HOURLY_PCT . '% of the daily rate, never more than a full day per day late.'];
    $s[] = ['id' => 'payments', 'title' => 'Take payments', 'icon' => 'bi-receipt',
        'intro' => 'Every peso goes through a booking, and every payment gets a receipt.',
        'steps' => [
            'Open the booking and click **Record payment**.',
            'Choose what it\'s for (deposit or rental), the amount, and the method. GCash, card, and bank transfers need a reference number.',
            'Save. A receipt number is issued; print it from the payment row.',
            $is_admin ? 'A wrong payment can be voided with **Void** and a reason. It stays on record, struck through.' : 'A wrong payment can only be voided by an admin; ask one.',
        ],
        'tip' => 'The ' . $deposit . ' deposit is held, not earned. It\'s settled at return.'];
    $s[] = ['id' => 'customers', 'title' => 'Verify customers', 'icon' => 'bi-people',
        'intro' => 'A customer can book only after you check their license.',
        'steps' => [
            'Open **Customers**. "Documents to review" counts customers waiting on you.',
            'Open the customer and view the uploaded license. **Approve** it, or **Reject** it with a reason the customer will see.',
            'Fill in the license number and expiry date, then click **Verify customer**.',
            'To add a walk-in customer, click **Add customer**; they get a login too.',
        ],
        'tip' => $is_admin ? 'You can block a customer and unblock them later. Staff can block but not unblock.' : 'You can block a customer who shouldn\'t rent; only an admin can unblock.'];
    $s[] = ['id' => 'maintenance', 'title' => 'Maintenance', 'icon' => 'bi-tools',
        'intro' => 'Keep cars out of bookings while they\'re in the shop.',
        'steps' => [
            'Open **Maintenance**. **Service due** lists cars past their service date or mileage.',
            'Click **Schedule a job**, pick the car, the work, and the days. Days that clash with a booking are refused, naming the booking.',
            'When the work starts, click **Start now**: the car can\'t be booked until the job ends.',
            'When it\'s done, click **Complete job** with the cost and the next service date or mileage.',
        ],
        'tip' => 'Use **Log past service** to record work done before AutoWay, so reminders start from the right place.'];

    if ($is_admin) {
        $s[] = ['id' => 'vehicles', 'title' => 'Manage vehicles', 'icon' => 'bi-car-front',
            'intro' => 'The fleet customers see comes from here.',
            'steps' => [
                'Open **Vehicles** and click **Add vehicle**. Enter the brand, model, plate, type, seats, and daily rate, then **Save vehicle**.',
                'Open the car again to upload photos. The first photo is the one customers see; you can change it.',
                'Set a car to "unavailable" to hide it from bookings without deleting anything.',
                'Sold a car? **Archive** it. Its history stays for reports.',
            ],
            'tip' => null];
        $s[] = ['id' => 'reports', 'title' => 'Reports', 'icon' => 'bi-bar-chart-line',
            'intro' => 'See how the business is doing for any period.',
            'steps' => [
                'Open **Reports**, choose a period (this month, last month, or a custom range), and click **Show**.',
                'Read revenue, rental days sold, fleet utilization, and bookings; each table breaks it down by day, car, or customer.',
                'Click **CSV** on any table to download it for Excel or Google Sheets.',
            ],
            'tip' => 'Revenue here matches the Payments page for the same period.'];
        $s[] = ['id' => 'users', 'title' => 'Users and staff', 'icon' => 'bi-person-gear',
            'intro' => 'Give each person their own login.',
            'steps' => [
                'Open **Users and staff** and click **Add staff or admin**. They see the quick tour the first time they log in.',
                'Suspend an account to block it at once; it\'s signed out on its next click.',
                'Reset a forgotten password with **Set password**. Their other sessions end.',
            ],
            'tip' => 'There is always at least one active admin, and you can\'t suspend yourself or change your own role.'];
        $s[] = ['id' => 'settings', 'title' => 'Settings', 'icon' => 'bi-sliders',
            'intro' => 'Change the business details and the rental rules without touching code.',
            'steps' => [
                'Open **Settings**. **Business** holds the name, address, phone, and payment details printed on receipts.',
                'The other groups hold the rules: deposit, discounts, booking limits, handover, and late fees.',
                'Change a value and click **Save settings**. New bookings use it right away; existing bookings keep their price.',
                '**Restore defaults** puts a group back the way it came.',
            ],
            'tip' => 'Every change is recorded in the activity log with who made it.'];
    }
    return $s;
}

/** **bold** in guide text → <strong>, after escaping. */
function guide_text(string $text): string
{
    return preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', e($text));
}
