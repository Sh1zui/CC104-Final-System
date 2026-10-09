# Security in AutoWay

This file covers how the app protects its data, what the Phase 14 review
found and how each finding was fixed, what's still a known limit, and what
to change before running it anywhere other than your own XAMPP.

## Who can do what

| | Visitor | Customer | Staff | Admin |
|---|---|---|---|---|
| Front page, browse cars and prices (no plates), register, log in | ✓ | ✓ | ✓ | ✓ |
| Browse, book online, own bookings/receipts/profile | | ✓ own only | | |
| Bookings, handover, payments, customers, maintenance | | | ✓ | ✓ |
| Discount above 10%, void payments/invoices, unblock customers, delete documents | | | | ✓ |
| Vehicles, payments ledger, reports, users, settings | | | | ✓ |

Every rule above is checked **on the server** (`require_role()` on pages,
a role check at the top of every `api/` endpoint, ownership checks in
`includes/portal_data.php`). Hidden buttons are only a convenience.

## How the data is protected

| Threat | Protection | Where |
|---|---|---|
| SQL injection | Every query is a prepared statement with real (not emulated) parameters. Dynamic parts are fixed strings or integers. | `config/database.php`, all `includes/*_data.php` |
| Cross-site scripting | All PHP output goes through `e()`. JavaScript escapes server data, quotes included, before inserting it into the page. JSON in pages uses the `JSON_HEX_*` flags. A Content-Security-Policy allows scripts, styles, fonts, and connections from this site only (every library is stored in `assets/vendor/`). | `includes/functions.php`, `assets/js/app.js`, `includes/auth.php` |
| Cross-site request forgery | One secret token per session, required on every form post and API call (including logout and marking the quick tour done), compared in constant time. | `verify_csrf()` in `includes/auth.php` |
| Clickjacking | `X-Frame-Options: DENY` and CSP `frame-ancestors 'none'`. | `includes/auth.php`, `.htaccess` |
| Password guessing | bcrypt hashes. A 5-failure limit per username and address, and 20 per address, each over 15 minutes. A different address can still log in, so an attacker can't lock out the real admin. Unknown usernames take as long as wrong passwords. | `auth/login.php`, `login_wait_minutes()` |
| Session theft or fixation | HttpOnly, SameSite=Lax cookie, scoped to the app's folder and Secure on HTTPS. The ID is regenerated at login, the session ends after 2 hours idle, and role and status are rechecked on every request. Changing or resetting a password signs out every other session. | `includes/auth.php` |
| One customer reading another's data | Every booking, receipt, invoice, document, and online payment is checked against the logged-in customer. "Not yours" and "doesn't exist" give the same answer. | `includes/portal_data.php`, `can_view_*()` |
| Dangerous uploads | The file type is detected from the content, not the name, and the extension comes from that type. 5 MB limit, random file names. ID documents sit in a folder Apache refuses to serve and are only reachable through an access-checked script. The vehicle-photo folder can't run scripts. | `includes/customers_data.php`, `api/document.php`, `assets/uploads/*/.htaccess` |
| Exposed files | `.htaccess` denies `config/`, `includes/`, `database/`, `tests/`, every `.md` and `.sql` file, and dotfiles, and turns off folder listings. | `.htaccess` files |
| Money tampering | Amounts are validated and capped, and the database has CHECK constraints. Bookings, payments, settlement, and maintenance lock the row they change, so two people can't double-book a car or double-spend a balance. Nothing is deleted: voids keep a reason. | `includes/bookings_data.php`, `includes/payments_data.php` |
| Spreadsheet formula injection | CSV cells starting with `= + - @`, tab, or CR get a leading apostrophe. | `csv_safe()` in `includes/reports_data.php` |
| Leaking errors | In production mode, PHP errors go to the log, not the page. API errors are always a plain JSON message. | `config/config.php` |
| Who did what | Logins, failures, money, settings, and account changes go to `system_logs` with the user and address. | `log_action()` |

## Phase 14 review: findings and fixes

An independent review read every endpoint. It found no high-severity
issues. Everything it raised is fixed, and each fix has a test
(`tests/security_test.php` and the HTTP checks behind `TESTING.md`).

| # | Finding | Fix |
|---|---|---|
| M1 | The SQL dump, READMEs, and folder listings were downloadable over HTTP | Deny rules in a root `.htaccess` and in `database/`, `config/`, and `tests/`; `Options -Indexes` |
| M2 | PHP errors were shown on the page | `APP_ENV` switch; production hides errors and logs them |
| M3 | No limit on password guessing; timing revealed which usernames exist | Throttle per username and address and per address; a dummy bcrypt check for unknown users |
| M4 | No security headers | CSP, X-Frame-Options, nosniff, Referrer-Policy, and Permissions-Policy on every response |
| L1 | The JS escape function didn't escape quotes | It now escapes `& < > " '` |
| L2 | Logout was a GET (another site could log you out) | POST with the CSRF token |
| L3 | Session cookie sent to the whole host; not Secure on HTTPS | Cookie path = the app folder; Secure when HTTPS; renamed to `autoway_session` |
| L4 | A password change didn't end other sessions | `users.session_version`, checked on every request |
| L5 | Unlimited self-registration; long input caused a database error | 5 sign-ups per address per hour; length and format checks |
| L6 | Parallel requests could pass the 3-pending-request limit | The customer row is locked while counting (tested with 6 parallel sessions: exactly 3 got through) |
| L7 | A cancellation could be free if staff confirmed at the same moment | The waiver is decided after the booking is locked |
| L8 | A duplicate payment reference in a race gave a generic error | Caught and explained |
| — | The CSRF token was accepted in the URL on one endpoint; booking notes' visibility was unclear; logged-in pages could be cached | POST only; the notes field says the customer sees it; `Cache-Control: no-store` behind the login |

## Final review

The last pass removed every request to another site (Bootstrap, icons,
Chart.js, and the font are now served from `assets/vendor/`), so the CSP
is `'self'` only, and made production mode the default so a copied
install never shows PHP errors to visitors.

## Known limits

These are deliberate for a XAMPP project. Say so if asked.

- **No real payment gateway.** Online payments are reported by the
  customer and checked by staff before they count.
- **No email.** Notifications appear in the app; password resets are done
  by an admin.
- **The CSP allows inline scripts** (a few small ones set the theme before
  the page paints). Inline scripts could be moved to files with nonces
  later.
- **No two-factor login.**
- **Login throttling counts by IP address.** Many users behind one shared
  connection (a school network) share the per-address limit of 20.

## Before running it anywhere but your own computer

1. **HTTPS.** Get a certificate. Links follow the address the site is
   reached on, and the session cookie becomes Secure automatically. Behind
   a proxy that ends HTTPS, set `BASE_URL_OVERRIDE` to the `https://…`
   address in `config/config.php`.
2. **Production mode.** It's the default. Check that nobody left
   `APP_ENV` as `'development'` in `config/config.php` or
   `AUTOWAY_ENV=development` on the server.
3. **Database account.** Create a MySQL user with a strong password and
   only the rights it needs on `car_rental_db` (SELECT, INSERT, UPDATE,
   DELETE). Put it in `config/database.php` instead of `root` with no
   password.
4. **Sample data.** Don't import the sample accounts, or change every
   password straight away. The app warns anyone who logs in with
   `Password123!`.
5. **Apache.** Keep `AllowOverride All` (or copy the `.htaccess` rules into
   the site config) and `mod_headers` on. Check that
   `https://your-site/car_rental_system/database/car_rental.sql` gives 403.
6. **Uploads folder.** Make `assets/uploads/` writable by the web server
   only, and keep it out of anything you publish.
7. **Backups.** Run `mysqldump car_rental_db > backup.sql` daily, and copy
   `assets/uploads/` with it. The documents folder holds customers' ID
   scans, so store backups somewhere private.
8. **Updates.** Keep PHP and MySQL/MariaDB on supported versions.
