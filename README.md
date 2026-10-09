# AutoWay — Car Rental Management System

A PHP 8 / MySQL / Bootstrap 5 rental management system with admin, staff,
and customer roles. Built for a college final project — production-style
patterns (prepared statements, password hashing, role checks, DB
transactions on money-moving operations) without hiding the code behind
frameworks that would make it harder to explain in a defense.

All 14 phases are done. **Start here:** [Setup](#setup-instructions),
then [`TESTING.md`](TESTING.md) (automated tests, a manual checklist, and a
10-minute demo path) and [`SECURITY.md`](SECURITY.md) (how the data is
protected, the review results, and what to change for a real server).

## Why this is being built in phases

A system with this feature list — RBAC across 3 roles, vehicle/customer/
booking/payment/maintenance management, reports, a customer-facing booking
flow — is a genuinely large application. Handing over 60+ files in one pass
either means most of it is shallow/untested, or several "modules" are
UI-only with buttons that don't actually do anything — which is exactly
what you asked me not to build.

So this is being built the same way real project work gets scoped: one
solid, fully working layer at a time, each one runnable and checkable
before the next is added.

| Phase | Scope | Status |
|---|---|---|
| **1** | Architecture, folder structure, full DB schema + sample data, config | ✅ done |
| **2** | Auth (login/register/logout), sessions, RBAC guards, CSRF | ✅ done |
| **3** | Shared UI shell (sidebar, header, dark mode, design system) + Admin dashboard | ✅ done |
| **4** | Vehicle management (CRUD, images, availability) | ✅ done |
| **5** | Customer management + document uploads | ✅ done |
| **6** | Booking engine (search/filter, availability check, pricing, double-booking prevention) | ✅ done |
| **7** | Check-out / check-in workflow (rentals table, late fees, damage) | ✅ done |
| **8** | Payments + invoices/receipts | ✅ done |
| **9** | Maintenance module | ✅ done |
| **10** | Staff portal (reservations, checkout/checkin, customers) | ✅ done |
| **11** | Customer portal (browse, book, history, profile) | ✅ done |
| **12** | Reports + dashboards + charts | ✅ done |
| **13** | Landing page + final UI polish (responsive pass, empty/loading states) | ✅ done |
| **14** | Security hardening pass + testing checklist + test scenarios | ✅ done |

After Phase 14, a final review rechecked every file for anything that
would stop the system working on a real install. What it found and fixed
is under [Final review](#final-review-what-was-fixed).

## What's in the project

```
car_rental_system/
├── config/
│   ├── config.php          # app constants, upload paths, timezone
│   ├── settings.php        # Phase 13 — business settings: defaults + an admin's saved values
│   └── database.php        # PDO connection (singleton), DB time zone pinned to PHP's
├── includes/
│   ├── functions.php       # stateless helpers: money formatting, rental_days(),
│   │                       # reference number generation, flash messages
│   ├── auth.php            # Phase 2 — session bootstrap, password hashing, CSRF,
│   │                       # require_login()/require_role() guards
│   ├── header.php          # Phase 3 — <head>, topbar, flash messages (app shell)
│   ├── sidebar.php         # Phase 3 — role-aware nav; unbuilt pages render disabled
│   ├── footer.php          # Phase 3 — closes the shell, loads scripts
│   ├── auth_head.php       # Phase 3 — shared <head> for login/register (logged-out)
│   ├── dashboard_data.php  # Phase 3 — read-only queries behind the dashboards
│   ├── vehicles_data.php   # Phase 4 — vehicle CRUD, images, archive-guard queries
│   ├── vehicle_table_partial.php  # Phase 4 — shared row/gallery HTML (page + AJAX)
│   ├── customers_data.php  # Phase 5 — customer CRUD, verification rules, documents
│   ├── customer_partial.php       # Phase 5 — shared customer row + document list HTML
│   ├── bookings_data.php   # Phase 6 — the booking engine: availability, pricing, status changes
│   ├── rentals_data.php    # Phase 7 — check-out/check-in, late/fuel/damage fees, extensions
│   ├── payments_data.php   # Phase 8 — payments, deposit settlement, refunds, invoices
│   ├── maintenance_data.php       # Phase 9 — jobs, workshop status, service reminders
│   ├── settings_data.php   # Phase 13 — saving settings (validation, audit log)
│   ├── users_data.php      # Phase 13 — staff/admin accounts, suspend, password resets
│   ├── reports_data.php    # Phase 12 — report queries over a date range + CSV rows
│   ├── portal_data.php     # Phase 11 — customer portal: online requests, cancelling,
│   │                       #   payments sent online (and the desk's accept/reject), profile
│   ├── portal_partial.php  # Phase 11 — car cards, the customer's booking rows
│   ├── desk_data.php       # Phase 10 — staff desk: release/return queues, fleet board
│   ├── desk_partial.php    # Phase 10 — queue rows (dashboard + desk pages)
│   ├── views/              # Phase 10 — page bodies shared by admin/ and staff/
│   │                       #   (bookings, customers, maintenance)
│   ├── .htaccess           # Phase 10 — nothing in includes/ is reachable from a browser
│   ├── maintenance_partial.php    # Phase 9 — shared job row + service-due row HTML
│   └── booking_partial.php        # Phase 6 — shared booking row HTML
├── auth/
│   ├── login.php           # Phase 2 — username or email, bcrypt verify
│   ├── register.php        # Phase 2 — customer self-registration (transactional)
│   ├── logout.php          # Phase 2
│   └── account.php         # Phase 13 — admin/staff: own details and password
├── admin/
│   ├── dashboard.php       # Phase 3 — the real admin dashboard (live stats + charts)
│   ├── vehicles.php        # Phase 4 — vehicle management (list, add/edit, photos, archive)
│   ├── customers.php       # Phase 5 — customers: profile, verification, document review
│   ├── bookings.php        # Phase 6–8 — bookings: new booking, confirm/cancel/reschedule,
│                           #   release, receive back, extend, charges, payments, invoices
│   ├── payments.php        # Phase 8 — payments ledger, cash position, what needs settling
│   ├── maintenance.php     # Phase 9 — service due, schedule/start/complete jobs, log past work
│   ├── reports.php         # Phase 12 — revenue, utilization, bookings, customers, maintenance, owed now
│   ├── report_export.php   # Phase 12 — CSV download for any report table
│   ├── users.php           # Phase 13 — every login: add staff/admin, suspend, reset passwords
│   └── settings.php        # Phase 13 — business details, prices, booking and handover rules
├── staff/
│   ├── dashboard.php       # Phase 10 — "Today": pickups, returns, overdue, fleet board
│   ├── reservations.php    # Phase 10 — the bookings page, staff rules
│   ├── checkout.php        # Phase 10 — release queue: who's picking up, what's in the way
│   ├── checkin.php         # Phase 10 — return queue: every car out, overdue first
│   ├── customers.php       # Phase 10 — customers page, staff rules
│   └── maintenance.php     # Phase 10 — maintenance page
├── customer/
│   ├── dashboard.php       # Phase 11 — next booking, updates, account (Phase 3 originally)
│   ├── browse.php          # Phase 11 — the fleet, live availability and prices, booking requests
│   ├── my-bookings.php     # Phase 11 — their bookings: details, pay online, cancel, receipts
│   ├── payments.php        # Phase 11 — every receipt and invoice, online payments being checked
│   ├── profile.php         # Phase 11 — contact details and password
│   └── documents.php       # Phase 5 — customer uploads their own ID documents
├── api/
│   ├── vehicles.php        # Phase 4 — AJAX endpoint behind admin/vehicles.php
│   ├── customers.php       # Phase 5 — AJAX endpoint behind admin/customers.php (admin + staff)
│   ├── bookings.php        # Phase 6 — AJAX endpoint behind admin/bookings.php (admin + staff)
│   ├── maintenance.php     # Phase 9 — AJAX endpoint behind the maintenance page (admin + staff)
│   ├── users.php           # Phase 13 — AJAX endpoint behind admin/users.php (admin only)
│   ├── portal.php          # Phase 11 — AJAX endpoint for the customer portal (customers only)
│   └── document.php        # Phase 5 — the only way to open a document file (access-checked)
├── print/
│   ├── receipt.php         # Phase 8 — printable receipt for one payment
│   └── invoice.php         # Phase 8 — printable invoice (or statement while open)
├── .htaccess               # Phase 14 — no listings; .md/.sql/dotfiles denied; security headers
├── TESTING.md              # Phase 14 — how to run the tests, manual checklist, demo path
├── SECURITY.md             # Phase 14 — protections, review findings and fixes, production checklist
├── tests/
│   ├── run.php             # Phase 14 — `php tests/run.php`: every suite on a throwaway test database
│   └── *_test.php          # booking engine, handover, payments, maintenance, portal, reports, security
├── index.php               # Phase 13 — public front page (logged-in users go to their dashboard)
├── browse.php              # public car browser: dates, filters, prices; "Log in to book"
├── database/
│   ├── car_rental.sql      # full schema (16 tables) + sample data, ready to import
│   └── README.md           # table relationship notes and design decisions
├── admin/ staff/ customer/ api/   # each has a README noting what else
│                                  # lands there in upcoming phases
└── assets/
    ├── css/style.css              # design tokens, shell, tiles, tables, toasts, dark mode
    ├── css/print.css              # Phase 8 — receipts and invoices, laid out for paper
    ├── js/app.js                  # theme toggle, mobile sidebar, toast/confirm + shared formatters
    ├── js/dashboard-charts.js     # Phase 3 — revenue + fleet charts
    ├── js/datatable.js            # Phase 4 — reusable client-side search/filter/sort/pagination
    ├── js/vehicles.js             # Phase 4 — admin/vehicles.php page controller
    ├── js/customers.js            # Phase 5 — admin/customers.php page controller
    ├── js/bookings.js             # Phase 6 — admin/bookings.php page controller
    ├── js/maintenance.js          # Phase 9 — maintenance page controller
    ├── js/desk.js                 # Phase 10 — search/filters on the staff desk queues
    ├── js/users.js                # Phase 13 — users and staff page controller
    ├── js/reports.js              # Phase 12 — period picker + report charts
    ├── js/portal.js               # Phase 11 — browse + My bookings controller
    ├── js/portal-home.js          # Phase 11 — customer home ("mark read")
    ├── uploads/vehicles/          # public photos; .htaccess blocks script execution
    └── uploads/documents/         # private ID scans; .htaccess denies ALL direct access
```

## Design

Redesigned after the final review to give the app more life, with colors
and shapes taken from the coastal road of Calapan City, Oriental Mindoro. Almost all of it is in
`assets/css/style.css`; the PHP and JavaScript changed only to add the
palette menu, the login scene, and car-type icons.

- **Overpass for everything.** It's an open version of Highway Gothic,
  the typeface on road signs. Headings and big numbers use its heavy
  weights; money and counts use its tabular figures so columns line up.
  It's stored in `assets/vendor/fonts/` and works offline.
- **Four color palettes**, picked per browser from the palette button in
  the top bar (saved like the light/dark choice):

  | Palette | Sidebar | Actions | Highlight |
  |---|---|---|---|
  | Bay (default) | deep-sea ink `#0f3842` | bay teal `#0a7a6d` | mango `#f5a623` |
  | Pine | forest `#12352b` | green `#17785f` | gold `#e9b949` |
  | Jeepney | navy `#17224f` | royal blue `#2449c4` | sun yellow `#ffc629` |
  | Orchid | plum `#2b1946` | violet `#7a3ec0` | marigold `#f2b33d` |

  Each has a light and a dark version. **Status colors never change with
  the palette:** green = available or paid, amber = needs attention, blue =
  out or in progress, red = overdue or a problem, grey = neutral. They
  drive the badges, stat tiles, and charts alike.
- **A dark sidebar** in the palette's ink color, with a mango marker on
  the current page.
- **Stat tiles tinted by status.** Each tile carries its status color as a
  top bar and a light wash, so a row of numbers reads by color first.
- **Road details.** The dashed mango lane line runs under the brand, along
  the landing page's steps, and across the login scene. License plates
  show as white plate chips. The landing page's rate board is a green
  highway sign.
- **Login and sign-up** sit over a drawn scene of Mt. Halcon over Calapan
  Bay, a ferry crossing, and a winding road (`includes/road_scene.php`). It's inline SVG, so it
  follows the palette and costs no image download.
- **Cars without a photo** get a card colored by body type (sedan teal,
  SUV blue, van amber, pickup red, hatchback violet) with a matching icon.
  Upload a photo and it replaces the drawing.
- **Checked, not eyeballed:** every text/background pair in every palette
  and both themes meets WCAG AA contrast (4.5:1), keyboard focus is
  visible on the dark sidebar, hover lifts are off for people who ask for
  reduced motion, and no page scrolls sideways at 360, 390, or 768 px.

## What Phase 3 added

- **A shared app shell** (`includes/header.php`, `sidebar.php`, `footer.php`).
  Every logged-in page sets `$page_title` and `$active_nav`, includes the
  header, writes its content, and includes the footer. The sidebar is
  role-aware: admin, staff, and customer each see their own menu.
- **Sidebar links turn on by themselves.** An item whose page file doesn't
  exist yet is shown greyed out instead of as a dead link, and becomes a
  real link the moment a later phase adds that file. Nothing to edit here.
- **One color language.** Green = available/good, amber = attention,
  blue = in progress/rented, red = alert, grey = neutral. The same five
  colors drive the stat dots, status badges, and chart segments.
  (The look itself was redesigned after Phase 7 — see "Design" below.)
- **Light/dark mode.** Follows the system setting on first visit, then
  remembers the choice. Charts redraw in the new colors when you switch.
- **The real admin dashboard**, all from live database queries (nothing is
  hardcoded): fleet counts, active bookings, revenue today / this month /
  all time, a 7-day revenue chart, a fleet-status doughnut, recent
  payments, and vehicles due back with an overdue flag. The queries live
  in `includes/dashboard_data.php` so the staff dashboard (and the Phase 12
  reports) reuse them.
- **Revenue excludes security deposits.** A deposit is held, not earned.
  How refunds affect revenue is decided with the payments module (Phase 8).
- **Login/register restyled** to match the rest of the app.
- **Database clock pinned to PHP's.** The connection sets its time zone to
  the app's (Asia/Manila), so "revenue today" and "overdue" don't drift by
  hours depending on how the MySQL server is configured.

**Works offline.** Since the final review, Bootstrap, Bootstrap Icons,
Chart.js, and the Overpass font are stored in `assets/vendor/`, so
no page needs an internet connection.

## Latest changes

- **A quick tour for new users, and a full guide for everyone.**
  - The **quick tour** runs once, on a new user's first visit to their
    dashboard: a spotlight walks through the main parts of the screen
    (8 or 9 steps, different for admins, staff, and customers). Back,
    Next, Skip, and the keyboard all work (arrows, Esc); on phones the
    menu opens by itself for steps that point into it. Finishing or
    skipping is saved, so it never shows again for that person.
  - "New" means any account created from now on: customer sign-up,
    *Add customer*, and *Add staff or admin*. The five sample accounts are
    treated as existing users and skip it. To see it as a demo, create a
    new account, or click **Replay the quick tour** on the Guide page.
  - **Guide** is in every sidebar, under Help. It has step-by-step
    guides for everything that role does (13 for admins, 9 for staff, 7
    for customers), a contents list that follows you as you scroll, and
    the replay button. Fees, deposits, and limits in the text come from
    Settings, so the guide always matches the rules.
  - Files: `includes/guide.php` (tour steps and guide text),
    `auth/guide.php`, `assets/js/tour.js`, `assets/js/guide.js`,
    `api/tour.php`. New column `users.tour_completed_at`.
  - **Re-import `database/car_rental.sql`** for the new column. To keep
    your data instead, run this once in phpMyAdmin (SQL tab):
    `ALTER TABLE car_rental_db.users ADD COLUMN tour_completed_at DATETIME NULL; UPDATE car_rental_db.users SET tour_completed_at = NOW();`
    Until the column exists, the tour simply doesn't run.
- **New AutoWay logo**: a mango road-sign badge with "AW", used in the
  browser tab, the sidebar, the front page, login, browse, and on printed
  receipts and invoices. Files in `assets/img/`: `logo.svg` (the badge),
  `favicon.svg` and `favicon.ico` (tab-sized, bigger letters, no rim),
  and `apple-touch-icon.png`, `icon-192.png`, `icon-512.png` for phone
  home screens. The tab links carry `?v=autoway-1` so browsers drop any
  cached old icon; change that number if you edit the icon again.
- **Renamed to AutoWay Car Rentals**, now in Calapan City, Oriental
  Mindoro. The name comes from the Business name setting, so an admin can
  change it again under Settings without touching code.
- **`browse.php` opens for everyone.** Before, browsing cars needed a
  customer login: `browse.php` at the project root gave "Not Found",
  `customer/browse.php` sent visitors to the login page, and admins or
  staff back to their dashboard. Now:
  - anyone can open `http://localhost/car_rental_system/browse.php`, pick
    dates and filters, and see which cars are free and the full price
    (plates are never shown). It's a plain form that works without
    JavaScript, so every search has its own link;
  - **Log in to book** on a car sends a visitor to log in, then straight
    back to the customer browse page with the same search already run,
    where Book works;
  - a logged-in customer who opens `browse.php` lands on their own browse
    page; an admin or staff member who opens `customer/browse.php` lands
    on the public page instead of being turned away;
  - the front page links to it ("Browse cars", "Check your dates").
- Re-import `database/car_rental.sql`: the staff emails and business
  details changed with the new name.

## Final review: what was fixed

Every file was rechecked for anything that would make the system fail or
mislead on a real install. These were found and fixed:

| # | Problem | Why it mattered | Fix |
|---|---|---|---|
| 1 | Bootstrap, icons, charts, and the font loaded from CDNs | With no internet (common at a defense), every page lost its layout, icons, and charts, and modals didn't open | All of them are now in `assets/vendor/`; the Content-Security-Policy allows only this site |
| 2 | `BASE_URL` was fixed to `http://localhost/car_rental_system/` | A renamed folder, another port, or opening it from another device broke every link, redirect, and the session cookie | Worked out from the request automatically. `BASE_URL_OVERRIDE` in `config/config.php` forces a value if you need one |
| 3 | The MySQL port was fixed at the default | XAMPP installs that moved MySQL to 3307 couldn't connect | A `PORT` constant in `config/database.php` |
| 4 | Sample bookings had fixed September 2026 dates | Imported any later, the CR-V was "overdue" by weeks with a huge late fee, Nadia's booking was in the past, and Today/Release/Receive were empty | Sample dates are relative to the day you import: the CR-V is a few hours overdue, Nadia picks up tomorrow at 10:00, maintenance is due in days |
| 5 | Sample customers were "verified" with no documents on file | Editing one of them un-verified them | Both have an approved license on file (sample images included) |
| 6 | Staff and admin had no way to see notifications | Online booking requests and payment reports were only noticed by chance | A bell with an unread count on every page, and a Notifications page that opens the booking or customer each one is about |
| 7 | The "online payment to check" filter matched nothing | Staff couldn't find payments waiting for review | Fixed; the filter now matches it |
| 8 | Cancelling a *pending* request at the desk could charge a fee | Requests never confirmed should cancel free, as they do online | Pending cancels free everywhere |
| 9 | Rule text (24-hour free cancellation, early release window) was written into the pages | Changing it in Settings left the pages saying the old values | The pages show the current settings |
| 10 | A "Reserved" vehicle status existed but nothing used it | Setting it by hand hid a car for no reason | Removed; bookings decide availability |
| 11 | Starting a maintenance job reset an "Unavailable" car to "Maintenance" | Finishing the job then put a car back on the lot that an admin had taken out | An unavailable car stays unavailable |
| 12 | `APP_ENV` defaulted to development | A copied install showed PHP errors to visitors | Defaults to production. Set `AUTOWAY_ENV=development` (or edit `config/config.php`) to see errors while you work |
| 13 | Phone layout: cramped tables, a long "Log out" button, names breaking mid-word, empty tables cut off | Hard to use on a phone | Wide tables scroll inside their panel, the logout is an icon, names stay on one line, empty tables fit the screen |
| 14 | No customer guidance before a first booking; no license fields mentioned | New customers didn't know they had to be verified | A "Before your first booking" checklist on the customer home page |
| 15 | No favicon; the test runner broke on paths with spaces | Small, but visible | Added an icon; the runner passes arguments safely |

**Re-import `database/car_rental.sql`** after updating: the sample data
changed and `notifications` gained a `target` column.

## What Phase 14 added

- **An independent security review** of every page and endpoint. No
  high-severity issues were found. All 13 medium and low findings are
  fixed and tested; the table is in [`SECURITY.md`](SECURITY.md). In short:
  - private files (the SQL dump, config, includes, notes) are now refused
    over HTTP, and folder listings are off;
  - security headers on every response: a Content-Security-Policy, no
    framing (clickjacking), nosniff, and a referrer policy;
  - login throttling per username and address, and per address; unknown
    usernames take as long as wrong passwords;
  - logout is a POST with the CSRF token;
  - the session cookie is scoped to the app and Secure on HTTPS;
  - a password change or reset signs out every other session;
  - sign-ups are rate-limited per address and fully validated;
  - two race conditions closed (the pending-request limit and a
    cancellation racing a confirmation);
  - logged-in pages aren't cached by the browser;
  - a warning for anyone still using the sample password;
  - an `APP_ENV` switch so production hides PHP errors from visitors.
- **Tested under real Apache** (the `.htaccess` rules only apply there),
  including a browser pass over every page with the CSP on: no blocked
  resources, no script errors.
- **A test suite you can run yourself**: `php tests/run.php` runs 431
  checks (9 suites) in about two seconds against a separate test database. It
  covers the booking engine, handover, money, maintenance, the customer
  portal, reports, and the security rules.
- **[`TESTING.md`](TESTING.md)**: a manual checklist of scenarios with
  expected results, for each role and module, and a 10-minute demo path
  for your defense.
- **[`SECURITY.md`](SECURITY.md)**: who can do what, how each threat is
  handled and where in the code, known limits, and a checklist for moving
  off XAMPP (HTTPS, production mode, a real database user, backups).
- The sample Ford Ranger job now ends two days after you import the
  database, so the demo looks the same on any date.
- **Re-import `database/car_rental.sql`**: there's a new column,
  `users.session_version`.

## What Phase 13 added

- **A public front page** (the project root). Visitors see today's
  board: every car, its daily rate, and whether it's on the lot, out on
  rental, or in for service, straight from the database (no plates or
  customer details). Below it: how renting works in four steps, the
  rules in plain words (deposit, cancelling, late returns, fuel, longer
  rentals, who can rent), and where to find the desk. Every number comes
  from the settings, so the page never disagrees with what the booking
  engine charges. Logged-in users still go straight to their dashboard,
  and the login and sign-up pages link back to it.
- **Settings** (admin): business details printed on paperwork (name,
  address, phone, email, TIN, GCash number, bank account), pricing
  (deposit, long-rental discount, the admin and staff discount limits),
  booking rules (longest booking, how far ahead, turnaround gap, online
  notice, unconfirmed-request limit, free-cancellation window, fees),
  handover rules (early release, late grace, late fee, fuel charge), and
  maintenance reminders.
  - Each setting is checked against its own range, and against related
    ones (staff can't be allowed more discount than admins).
    Nothing is saved if anything is wrong.
  - Values are stored in the new `settings` table and loaded once per
    request into the same constants the code already used, so no rule
    changed. A value set back to its default removes the override; a
    stored value that no longer fits falls back to the default.
  - Changes apply from now on. Existing bookings keep their price and
    deposit. Every change is logged as "old → new", and the page shows
    which settings differ from the default and who changed them.
  - **Restore defaults** for each group.
- **Users and staff** (admin): every login in one list, filterable by
  role and status.
  - Add a staff or admin account, edit contact details, change a
    staff/admin role, suspend or reactivate any login (a suspended user
    is signed out on their next click), and set a new password (a
    customer is notified when that happens).
  - Guards: nobody can change their own role or suspend themselves, and
    there's always at least one active admin. A customer login can't be
    turned into a staff account.
- **My account** for admin and staff (click your name in the sidebar):
  contact details and password. Customers keep their Profile page.
- **Polish:** no menu item is "not built yet" any more. A submit button
  can ask for confirmation (`data-confirm`). Dark-mode tweaks for the new
  pages. The phone-width check now covers the front page and every new
  page.
- **Re-import `database/car_rental.sql`**: there's a new table.

## What Phase 12 added

- **Reports** (admin sidebar → Reports), for a period you pick: this
  month (default), last month, last 30 / 90 days, this year, last year,
  or any custom range up to two years.
  - **Headline numbers**: revenue, rental days sold, fleet utilization,
    bookings made (how many online, and the cancelled/no-show rate).
  - **Revenue over time**: by day for ranges up to two months, by month
    beyond, with a breakdown underneath: rental fees, other charges,
    deposits kept, money in, refunds out, deposits taken and returned.
  - **Bookings made, desk vs online**: a stacked chart, plus where those
    bookings stand now (by status), their average length and value.
  - **Vehicles**: rentals, utilization (time out on rental ÷ the part of
    the period that has already happened), revenue, maintenance cost,
    and revenue less maintenance. Archived cars appear only if they had
    activity in the period.
  - **Top customers** by revenue, and **maintenance by type of work**.
  - **Owed right now** (regardless of the period): what customers owe,
    what's owed back to them, and deposits held.
- **One definition of money everywhere.** Revenue uses the same rule as
  the dashboard and the payments ledger (earned charges + deposits kept −
  refunds of payments), so a month here matches the same month there.
  The tests check that daily buckets, vehicles, and customers each add
  up to the period total, and that utilization is exact on a known
  3-days-in-30 rental (10%).
- **CSV export** for every table (revenue, bookings, vehicles, customers,
  maintenance, owed now), with the period in the filename. Amounts are
  plain numbers so spreadsheets can sum them, the file has a UTF-8 BOM so
  Excel shows names correctly, and text that starts like a formula
  (`=`, `+`, `-`, `@`) is defused. Every export is logged.
- **Chart colors**: two chart tokens (`--chart-1`, `--chart-2`) for light
  and dark mode, checked with a color-vision-deficiency validator so desk
  vs online stay distinguishable. The charts redraw when the theme is
  switched.
- No database changes in this phase.

## What Phase 11 added

- **A customer portal** (log in as `customer.liam` or `customer.nadia`):
  - **Browse vehicles**: the fleet with photos and specs. Pick dates and
    filters to see which cars are free and the full price (long-rental
    discount and the refundable deposit included). Plate numbers aren't
    shown to customers.
  - **Booking requests**: the same engine as the desk (row lock, the same
    availability and customer checks), with three online-only rules:
    pickups need **3 hours' notice**, a request stays **pending** until
    staff confirm it, and a customer can have at most **3 unconfirmed
    requests** at once. The desk is notified of every request.
  - **My bookings**: each booking explains where it stands (waiting for
    confirmation, confirmed with what's left to pay, out with the return
    time, overdue). It shows the price breakdown, receipts (printable),
    the invoice once issued, and two actions:
    - **Cancel**: free while pending, and free until 24 hours before
      pickup once confirmed. After that the cancellation fee applies,
      shown before they confirm. The desk is told, to settle any deposit.
    - **I sent a payment**: the customer sends money by GCash or bank
      transfer, then enters the amount, reference, and date. It counts
      as paid only once staff check it (see below).
  - **Payments**: every receipt and invoice across their bookings, plus
    online payments being checked or not confirmed (with the reason).
  - **Profile**: contact details and password. Once verified, the
    license, ID, and birth date are locked (they're what the desk
    checked); changing them goes through the desk.
  - **Home**: their current or next booking, updates (booking
    confirmations, receipts, document reviews, with "mark read"), and
    account status with what to do next if they can't book yet.
- **Checking online payments (desk side).** A booking with an online
  payment waiting shows *online payment to check* in the list, a filter
  finds them all, and staff Today and the admin Payments page list them.
  In the booking window staff **Accept** (choosing what it's for, in case
  the deposit was already paid at the desk), which records a normal
  payment with a receipt, or **Reject** with a reason the customer sees.
  A reference already used on a payment can't be sent again.
- **New table `payment_submissions`**: what the customer reported and what
  the desk decided; it links to the payment once accepted. Nothing in it
  counts as money until then, so the Phase 8 money rules are unchanged.
- `in_transaction()` now joins an outer transaction instead of starting a
  second one, so accepting a submission and recording its payment commit
  or roll back together (tested by making the payment step fail).
- **Re-import `database/car_rental.sql`**: there's a new table.

## What Phase 10 added

- **A staff portal** (log in as `staff.maria`). Staff get their own pages
  under `staff/`, built on the same page bodies as the admin pages
  (`includes/views/`), so a fix to the bookings page fixes it for both.
  - **Today** (the staff home): pickups today (and how many are ready to
    release now), returns due, overdue rentals, bookings waiting to be
    confirmed, plus a fleet board showing where every car is: on the lot,
    out until when, overdue, or in the workshop.
  - **Release a vehicle**: today's and tomorrow's pickups, late ones
    flagged. Each row lists what's in the way of handing over the keys:
    not confirmed yet, customer not verified or blocked, license expiring,
    the car still out on an earlier rental, or the car in the workshop.
    **Release** opens the booking with the handover form already open.
  - **Receive a return**: every car out, most overdue first, with what's
    still unpaid. **Receive** opens the return form, with charges
    previewed as before.
  - **Reservations, Customers, Maintenance**: the same pages admins use.
- **What staff can't do** (enforced by the API, not just hidden buttons):
  - give an extra discount above **10%** of the base amount (admins: 30%;
    a setting under Admin → Settings). A bigger discount an
    admin already gave is kept when staff reschedule the booking, but staff
    can't raise it.
  - **unblock** a customer (staff can block one; lifting a block is an
    admin decision) or **delete** a customer document (they can reject it).
  - void payments or invoices, open the payments ledger, or manage
    vehicles (unchanged from earlier phases).
- **Links follow the role.** Pages, receipts, and scripts no longer
  hard-code `admin/`: a booking link opens `staff/reservations.php` for
  staff and `admin/bookings.php` for admins (`portal_url()` in PHP,
  `AutoWay.link()` in JS).
- **`includes/` is closed to the browser** (`includes/.htaccess`), and a
  shared page body opened directly returns 404.
- No database changes in this phase. If you're upgrading from Phase 9,
  there's nothing to re-import.

## What Phase 9 added

- **A Maintenance page** (sidebar → Maintenance) with four numbers: cars
  in the workshop, jobs starting in the next 14 days, services due or
  overdue, and what maintenance cost this month / this year.
- **Service reminders by date *and* mileage.** Every completed job can
  set the next service date and/or the odometer reading it's due at.
  A car shows up under **Service due** when either is passed (overdue)
  or within 14 days / 500 km (due soon) — e.g. the seeded Vios is 1,000 km
  past its oil change. One click schedules the job, pre-filled.
- **A job's life:** *scheduled* → *in progress* → *completed*, or
  *cancelled* (scheduled only, with a reason kept on record).
  - **Schedule** picks the car, type of work, and the days it's needed.
    A car with bookings on those days can't be scheduled — the booking
    references are listed so you know what to move.
  - **Start** puts the car in the workshop (vehicle status *maintenance*)
    with a planned end date. It's refused if the car is out on a rental.
  - **Change planned end** when the shop takes longer; new days are
    checked against bookings the same way.
  - **Complete** records the finish date, final cost, shop, odometer
    (raises the car's mileage, can't go backwards), next-service date/km,
    and what was done. The car goes back to *available* unless it has
    another job in progress.
  - **Log past service** records work already done (e.g. before the
    system existed) as a completed job, without touching bookings.
- **Check-in's "needs maintenance"** (Phase 7) now opens a real
  in-progress job for the next 2 days, so it shows up here to complete.
- **The car's status follows its jobs.** *Maintenance* can't be set or
  cleared from the vehicle form any more, and a car with open jobs can't
  be archived.
- **Design change: maintenance blocks dates, not the car.** Before, a car
  marked *maintenance* couldn't be booked for any date. Now a job blocks
  bookings only for its own days, so a car in the shop until Thursday can
  be booked from Friday. If it's still in the shop *past* its planned end,
  it's blocked until someone completes the job or moves the end date.
  *Unavailable* still blocks the car entirely.
- **Same row lock as bookings.** Scheduling, starting, and extending a job
  lock the car's row (`SELECT … FOR UPDATE`) like booking creation does,
  so a booking and a job can't both grab the same day. Tested with
  concurrent requests and an artificial delay: without the lock both got
  in; with it, never.
- Every change is written to `system_logs`; staff can use the same API
  (their page arrives with the Phase 10 staff portal).

## What Phase 8 added

- **Recording payments** from the booking window: amount, what it's for
  (deposit, rental, late fee, damage, other charges), and how it was paid
  (cash, card, GCash, bank transfer). Non-cash payments need their
  reference number, and a reference already on another receipt is
  caught. A payment can't exceed the balance due, and the deposit can't
  be over-collected. Every payment gets a sequential receipt number
  (`OR-2026-000004`) and a printable receipt. The customer is notified.
- **The deposit is a liability, not revenue.** While the car is out the
  deposit is held. When a booking closes, **Settle** does exactly two
  things: it hands back what's owed to the customer (a refund), and it
  keeps the rest of the deposit against what they owed ("deposit kept").
  Keeping deposit money moves no cash, but that's the moment it counts as
  revenue. Overpayments come back as a separate refund. The settle panel
  shows the split before anything is saved.
- **Revenue follows that rule everywhere:** rental and charge payments +
  deposit kept − refunds of payments. Receiving or returning a deposit
  never moves revenue. Tested by checking that, after every kind of
  transaction, **cash in − cash out = revenue + deposits held**, to the
  centavo.
- **Cancellation fees.** Free until 24 hours before pickup; after that,
  one day at the booked rate (never more than the rental itself). A
  no-show always costs one day. The fee is stored on the booking when it
  happens, and staff can waive a late fee (the waiver is logged). The
  cancel panel shows the fee before you confirm.
- **Invoices**, issued once a booking is closed: sequential numbers
  (`INV-2026-000001`), totals frozen at issue, and marked paid or unpaid
  as payments come in. To correct one, an admin voids it with a reason
  (it stays on record, stamped VOID) and issues a new one. Charges can't
  be added under an issued invoice. Before a booking closes, the same
  page prints as a **statement** marked "not final".
- **Printable receipts and invoices** (`print/`) with business details from
  Admin → Settings (Phase 13). They're laid out for paper and "save as PDF". Only
  admin/staff, or the customer the booking belongs to, can open them;
  anyone else gets "not found".
- **Payments page** (`admin/payments.php`) with these sections:
  - Cash collected today and this month, refunds, and deposits held.
  - A **Needs attention** list: balances to collect, refunds to make,
    deposits to settle.
  - A searchable ledger of every receipt, with filters for money in,
    refunds, deposit kept, voided, method, and date.
- **Corrections, not deletions.** An admin can void a mistaken payment
  with a reason; it stays on the ledger, struck through. Payments that a
  settlement already used are protected until the settlement rows are
  voided first.
- **Also fixed:** the dashboard's "Recent payments" listed voided rows
  and showed refunds as money in. It now shows real cash movements only,
  with refunds as money out and each amount linked to its receipt.

**Database change:** `payments` gained receipt numbers, `refund_of`,
notes, void tracking, and check constraints; `invoices` gained issue/void
tracking (a booking can now have a voided invoice plus its replacement);
`bookings` gained `cancellation_fee`. Re-import `database/car_rental.sql`
(this resets the sample data).

## What Phase 7 added

- **Releasing a car (check-out)** from a booking's detail window. Allowed
  for a confirmed booking from 2 hours before pickup until its return
  time. Staff record the odometer (it can't go below the car's last
  recorded mileage), the fuel level, and the condition, walked through
  with the customer. They also tick that they checked the physical
  license. It's refused if the car hasn't come back from a previous
  rental or is marked maintenance/unavailable. The customer's
  eligibility is checked once more. The booking becomes **active**, the
  car **rented**, and an unpaid balance is flagged before the keys go out.
- **Receiving it back (check-in)**, with a live preview of every charge
  while staff fill in the form. The preview and the saved result come
  from the same function, so they always match.
  - **Late fee:** the first hour late is free. After that, every started
    hour costs 15% of the daily rate, but never more than one day's rate
    per 24 hours late.
  - **Fuel:** ₱25 for each 1% of the tank missing.
  - **Damage:** entered by staff, and notes are required.
  - **Other charges** (cleaning, extra mileage, traffic violation, other)
    are stored in `penalties`. Late and damage can't be added by hand,
    so nothing gets charged twice.

  The car's mileage is updated. Ticking "needs maintenance" puts the car
  in maintenance, opens an in-progress maintenance job (Phase 9), and warns about
  upcoming bookings that need moving.
- **The deposit is settled properly.** Before the car comes back, the
  deposit is part of what's owed. Once the rental is completed it's owed
  back, so "amount due" becomes the rental plus extra charges. Overpaying
  shows up as **refund due** instead of disappearing. One function
  (`booking_amount_due()`) decides this for every screen and for
  `payment_status`. Phase 8 records the actual payments and refunds.
- **Extending a rental** that's out: live price preview at the same rate
  (crossing 7 days applies the long-rental discount), and it's refused
  if it would run into the car's next booking or its turnaround.
- **Adding a charge later**, for example a traffic ticket that arrives
  after the car is back.
- **Handover record** in the booking detail: who released and received
  the car, when, odometer, fuel, km driven, condition notes, and every
  charge itemized.
- **Fixes found along the way:**
  - A car received in the same minute it was released was refused as
    "returned before release". Release time had seconds and the form
    doesn't. Both are now stored to the minute, and the database's own
    check constraint caught it too.
  - A Phase 6 style rule was squashing every input in the booking panels
    to half width.
  - Sample bookings showed "created" after "confirmed", depending on the
    day you imported the file.
  - The vehicles page can no longer set "rented" by hand (or clear it
    while the car is out), since that's now driven by check-out/check-in.
  - Unexpected server errors in the bookings API now come back as a JSON
    message instead of a PHP error page.

**Database change:** `rentals` gained condition notes, a fuel fee, and
check constraints (fuel 0–100, odometer can't go backwards, return after
release). Re-import `database/car_rental.sql` (this resets the sample data).

## What Phase 6 added

- **The booking engine** (`includes/bookings_data.php`) — one file with
  every booking rule, used by the admin page now and by the staff portal
  (Phase 10) and customer booking flow (Phase 11) later.
- **Availability.** A car is free for a window when it isn't archived or
  marked maintenance/unavailable, has no scheduled maintenance that day,
  isn't out on an overdue rental, and has no overlapping pending,
  confirmed, or active booking. Overlap includes a **2-hour turnaround**
  on both sides for cleaning and inspection.
- **Pricing, all on the server.** The daily rate is locked into the
  booking when it's made. Every started 24 hours is a day. 7+ days get
  10% off automatically, staff can add up to 30% more, and a refundable
  ₱2,000 deposit is added. Each part is itemized and rounded so they
  always add up to the total. All of these numbers are settings an admin
  can change under Admin → Settings (Phase 13).
- **No double-booking, proven.** Creating or rescheduling locks the car's
  row (`SELECT … FOR UPDATE`) before checking availability, inside the
  same transaction as the insert. Tested both ways: with the check
  deliberately slowed down, 6 simultaneous requests booked one car **6
  times** without the lock and **once** with it. Through the real web
  endpoint, 8 parallel requests from 8 sessions gave exactly 1 booking and
  7 "already booked" messages.
- **Who can book.** Only verified customers with an active login and a
  driver's license valid through the *return* date. The same checks run
  again when a booking is confirmed or rescheduled, in case something
  changed in between.
- **Lifecycle.** Pending → confirmed (records who and when) → cancelled
  (a reason is required and shown to the customer) or no-show (only once
  the pickup time has passed). Rescheduling keeps the quoted rate on the
  same car and re-prices on a different one, then shows the old and new
  totals. "Active" and "completed" are set by check-out/check-in in
  Phase 7. Every change notifies the customer and goes to `system_logs`.
- **Admin bookings page** (`admin/bookings.php`): live tiles (waiting to
  confirm, pickups today, out now, overdue), a list with search and
  status / date / payment filters, a three-step new-booking flow
  (customer and dates → available cars with prices → review, extra
  discount, confirm now or leave pending), and a detail window with the
  itemized price, amount paid, balance, history, and the actions allowed
  for that booking's state.
- **Smaller things:** each booking has a customer-facing reference
  (`BK-261003-A1B2C3`), a customer's Bookings tab links straight to each
  booking, and vehicle types now read "SUV" instead of "Suv".

**Database change:** `bookings` gained a reference, confirmation and
cancellation columns, and an index for the availability check.
Re-import `database/car_rental.sql` (this resets the sample data).

## What Phase 5 added

- **Customer management** (`admin/customers.php`) — a searchable,
  filterable list (status, "has documents to review", license valid /
  expired / not on file), live counts at the top, and one modal per
  customer with three tabs: **Profile** (edit details, license, ID),
  **Documents** (review queue), and **Bookings** (counts, total paid,
  most recent five). Staff can also register a walk-in customer at the
  desk with a temporary password.
- **Two statuses, kept separate on purpose.** `users.status` decides
  whether someone can log in at all; `customers.account_status`
  (unverified / verified / blocked) decides whether they may rent. This
  module manages the second one.
- **Verification has real rules**, checked on the server and explained
  in the UI before anyone clicks: a customer can only be marked verified
  with a license number on file, a license that hasn't expired, and at
  least one document that a person actually reviewed and approved. If an
  edit later breaks that (say, an expiry date that's already past), the
  customer is automatically moved back to unverified and you're told why.
  A verified customer's last approved document can't be rejected or
  deleted; un-verify them first.
- **Document review**: approve, or reject with a reason (required, since
  the customer sees it and needs to know what to fix). Every decision
  records who reviewed it and when, notifies the customer, and is written
  to `system_logs`.
- **Customers upload their own documents** (`customer/documents.php`, new
  "My documents" menu item): they see each file's status and any
  rejection reason, and can remove anything not yet approved. New uploads
  notify every admin and staff member. Uploads pause while an account is
  blocked, and at most 10 can wait for review at once.
- **ID scans are private.** The documents folder's `.htaccess` now denies
  *all* direct access (in Phase 4 it only blocked scripts, so a file
  could have been opened by anyone who guessed its URL). Files are served
  only by `api/document.php`, which lets in admin/staff, or the customer
  who owns the file. Anyone else gets the same "not found" whether the
  file exists or not. Accepted types are JPEG, PNG, WebP, and PDF, checked
  from the file's bytes; the original filename is kept for display only.
- **Shared pieces added along the way**: the CSRF token as a
  `<meta name="csrf-token">` tag in every page (for AJAX), one password
  policy used by both registration and staff-created accounts,
  `AutoWay.statusClass()` in `app.js` mirroring PHP's
  `status_badge_class()` exactly (the vehicles page had its own copy that
  coloured unknown statuses green instead of grey), and readable labels
  for enum values ("Driver's license", not "drivers_license").

**Database change:** the `documents` table gained columns (original
filename, type, size, uploader, review note, review time). Re-import
`database/car_rental.sql` in phpMyAdmin; it drops and recreates the
database, so this also resets the sample data.

## What Phase 4 added

- **Vehicle management** (`admin/vehicles.php`) — add, edit, and archive
  vehicles, with search, status/type filters, column sorting, and pagination all handled
  client-side by a small reusable table controller
  (`assets/js/datatable.js`) now available to every future module.
- **Archiving is soft-delete, and it's guarded.** A vehicle with a
  pending, confirmed, or active booking can't be archived — the booking
  would lose its vehicle. Archived vehicles keep their booking/payment
  history intact and can be restored.
- **Photo upload, for real.** The server never trusts the browser's claimed
  file type or the original filename: it reads the file's actual bytes
  (`finfo`), only accepts genuine JPEG/PNG/WebP, and saves it under a
  random generated name. The first photo becomes the primary photo
  automatically; deleting the primary promotes the next one. Both upload
  folders also ship with a `.htaccess` that refuses to execute any script
  file, as a second layer of defense if that validation were ever bypassed.
- **One template for the table, used twice.** The initial page load and
  every AJAX response after an add/edit/archive/restore render the exact
  same PHP function (`includes/vehicle_table_partial.php`) — there's no
  separate copy of the row markup living in JavaScript to drift out of sync.
- **Toasts and a real confirm dialog**, added to `app.js` as
  `AutoWay.toast()` / `AutoWay.confirm()` — shared infrastructure every
  later module (customers, bookings, ...) reuses instead of rolling its own.
- **A found-and-fixed inconsistency**: `status_badge_class()` (written in
  Phase 1) still returned old Bootstrap classes (`bg-success`, etc.) from
  before the Phase 3 design system existed, and nothing had called it yet
  to notice. Rewrote it to return the current `.status-*` classes, with
  the same five-color mapping already used by the fleet chart, and checked
  it against every status value in the schema (27 of them).

## What Phase 2 added

- **Login** (`auth/login.php`) — by username or email, bcrypt password
  verification, a generic "incorrect username/email or password" message
  either way (doesn't let someone probe which usernames exist), and a
  clear message if the account is suspended/deactivated.
- **Registration** (`auth/register.php`) — customer accounts only (matches
  the spec: admin/staff accounts are provisioned by an admin, not
  self-served). Validates username format, email, password strength, and
  that passwords match; the `users` + `customers` inserts happen in one DB
  transaction so a failure can't leave a user row with no customer profile.
- **Sessions** — a custom session name, `httponly` + `SameSite=Lax`
  cookies, `session.use_strict_mode`, an idle timeout separate from the
  cookie's own lifetime, and session ID regeneration on login (session
  fixation protection).
- **Live re-validation, not just at login** — every request re-checks the
  logged-in user's status and role against the database. Suspend someone's
  account while they're mid-session and their very next click logs them
  out, instead of waiting until they'd next try to log in.
- **CSRF tokens** on both forms, timing-safe compared with `hash_equals()`.
- **`require_role('admin')` / `require_role('admin', 'staff')`** — the
  guard every protected page from here on starts with. Redirects to login
  (remembering where you were headed, so you land back there after
  logging in) or to your own dashboard with a "you don't have access to
  that page" message if you're logged in as the wrong role.
- **Audit logging** — every login, failed login, logout, and registration
  writes a row to `system_logs`.

All of this was actually run end-to-end against a live MySQL instance and
PHP server while building it — login, wrong password, CSRF rejection,
RBAC blocking the wrong role, the post-login redirect-back, registration,
duplicate-registration rejection, logout, session-fixation, and the
mid-session suspension check all pass.

## Setup instructions

1. Copy the `car_rental_system` folder into your XAMPP `htdocs` directory.
2. Start **Apache** and **MySQL** in the XAMPP control panel.
3. Open **phpMyAdmin** → Import → choose `database/car_rental.sql` → Go.
   This creates the `car_rental_db` database with all 16 tables and sample
   vehicles/customers/bookings.
4. Open `config/database.php` — if your MySQL root user has a password
   (some setups do), set it in the `PASS` constant. If MySQL runs on a
   port other than 3306, set `PORT`.
5. Nothing to change for the folder name or address: the app works out
   its own URL. (To force one, set `BASE_URL_OVERRIDE` in
   `config/config.php`.) The app runs in production mode by default;
   for PHP error messages while developing, set `APP_ENV` to
   `'development'` there.
6. Visit `http://localhost/car_rental_system/` in a browser. You'll see
   the public front page; log in from there.
7. Optional: run the tests with `C:\xampp\php\php.exe tests\run.php` from
   the project folder (see [`TESTING.md`](TESTING.md)).

Before putting it on a real server, go through the checklist at the end of
[`SECURITY.md`](SECURITY.md).

## Sample accounts (seeded, real bcrypt hashes — these logins work as-is)

| Role | Username | Password |
|---|---|---|
| Admin | `admin` | `Password123!` |
| Staff | `staff.maria` | `Password123!` |
| Staff | `staff.jon` | `Password123!` |
| Customer | `customer.liam` | `Password123!` |
| Customer | `customer.nadia` | `Password123!` |

Everyone who logs in with the sample password is reminded to change it.
Try logging in as each role, then try visiting `admin/dashboard.php`
while logged in as `customer.liam`: you'll be sent back to the customer
dashboard with an access-denied message instead of seeing the admin page.

## Where it could go next

The project is complete for its scope. Natural extensions, if you want
them:

- a real payment gateway (PayMongo, for example) in place of reported payments;
- email or SMS notifications;
- password reset by email link;
- moving the few inline scripts into files so the CSP can drop
  `'unsafe-inline'`.
