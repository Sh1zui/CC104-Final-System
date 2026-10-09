-- =====================================================================
-- Car Rental Management System — Database Schema
-- Engine: MySQL 8+ or MariaDB 10.4+ (XAMPP), InnoDB, utf8mb4
-- Import this whole file via phpMyAdmin (or `mysql -u root -p < car_rental.sql`)
-- =====================================================================

-- The sample data below uses NOW()/CURDATE(), so read them on the app's
-- clock (Asia/Manila, set in config/config.php), whatever the server's is.
SET time_zone = '+08:00';

DROP DATABASE IF EXISTS car_rental_db;
CREATE DATABASE car_rental_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE car_rental_db;

-- ---------------------------------------------------------------------
-- 1. ROLES — admin / staff / customer. Kept as a table (not an ENUM)
--    so new roles can be added later without touching table structure.
-- ---------------------------------------------------------------------
CREATE TABLE roles (
    role_id     TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_name   VARCHAR(30) NOT NULL UNIQUE,
    description VARCHAR(255)
) ENGINE=InnoDB;

INSERT INTO roles (role_name, description) VALUES
('admin',    'Full system access — vehicles, staff, customers, reports, settings'),
('staff',    'Front-desk operations — reservations, check-out/in, payments'),
('customer', 'Self-service rental customer');

-- ---------------------------------------------------------------------
-- 2. USERS — one row per login, regardless of role. Customer-specific
--    fields live in `customers`, linked 1:1 by user_id.
-- ---------------------------------------------------------------------
CREATE TABLE users (
    user_id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_id        TINYINT UNSIGNED NOT NULL,
    username       VARCHAR(50)  NOT NULL UNIQUE,
    email          VARCHAR(120) NOT NULL UNIQUE,
    password_hash  VARCHAR(255) NOT NULL,
    full_name      VARCHAR(120) NOT NULL,
    phone          VARCHAR(20),
    status         ENUM('active','suspended','deactivated') NOT NULL DEFAULT 'active',
    last_login_at  DATETIME NULL,
    session_version INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'bumped on password change/reset: ends other sessions',
    tour_completed_at DATETIME NULL COMMENT 'NULL = new user: the quick tour runs on their next dashboard visit',
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(role_id)
) ENGINE=InnoDB;

CREATE INDEX idx_users_role ON users(role_id);

-- ---------------------------------------------------------------------
-- 3. CUSTOMERS — extra profile data only customers need.
-- ---------------------------------------------------------------------
CREATE TABLE customers (
    customer_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id          INT UNSIGNED NOT NULL UNIQUE,
    date_of_birth    DATE,
    address_line     VARCHAR(255),
    city             VARCHAR(100),
    license_number   VARCHAR(50),
    license_expiry   DATE,
    id_type          ENUM('national_id','passport','drivers_license') DEFAULT 'drivers_license',
    id_number        VARCHAR(50),
    account_status   ENUM('unverified','verified','blocked') NOT NULL DEFAULT 'unverified',
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 4. VEHICLES
-- ---------------------------------------------------------------------
CREATE TABLE vehicles (
    vehicle_id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    brand            VARCHAR(60)  NOT NULL,
    model            VARCHAR(60)  NOT NULL,
    year             SMALLINT UNSIGNED NOT NULL,
    plate_number     VARCHAR(20)  NOT NULL UNIQUE,
    vehicle_type     ENUM('sedan','suv','hatchback','van','pickup','luxury','motorcycle') NOT NULL,
    transmission     ENUM('automatic','manual') NOT NULL,
    fuel_type        ENUM('gasoline','diesel','hybrid','electric') NOT NULL,
    seating_capacity TINYINT UNSIGNED NOT NULL,
    color            VARCHAR(30),
    mileage_km       INT UNSIGNED DEFAULT 0,
    daily_rate       DECIMAL(10,2) NOT NULL,
    status           ENUM('available','reserved','rented','maintenance','unavailable') NOT NULL DEFAULT 'available',
    description      TEXT,
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at       TIMESTAMP NULL DEFAULT NULL COMMENT 'soft delete — keeps history intact for past bookings'
) ENGINE=InnoDB;

CREATE INDEX idx_vehicles_status ON vehicles(status);
CREATE INDEX idx_vehicles_type ON vehicles(vehicle_type);

CREATE TABLE vehicle_images (
    image_id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vehicle_id   INT UNSIGNED NOT NULL,
    image_path   VARCHAR(255) NOT NULL,
    is_primary   TINYINT(1) NOT NULL DEFAULT 0,
    uploaded_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (vehicle_id) REFERENCES vehicles(vehicle_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 5. BOOKINGS — the reservation itself (dates, pricing, status).
--    A booking becomes a "rental" once the vehicle is physically
--    released (see `rentals` below) — this separation lets us track
--    a reservation that's confirmed but not yet picked up.
-- ---------------------------------------------------------------------
CREATE TABLE bookings (
    booking_id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_reference   VARCHAR(20)  NOT NULL UNIQUE COMMENT 'shown to customers, e.g. BK-261003-A1B2C3',
    customer_id         INT UNSIGNED NOT NULL,
    vehicle_id          INT UNSIGNED NOT NULL,
    pickup_datetime     DATETIME NOT NULL,
    return_datetime     DATETIME NOT NULL,
    rental_days         INT UNSIGNED NOT NULL COMMENT 'ceil(hours/24), calculated at booking time',
    daily_rate_snapshot DECIMAL(10,2) NOT NULL COMMENT 'rate locked in at booking time',
    base_amount         DECIMAL(10,2) NOT NULL,
    discount_amount     DECIMAL(10,2) NOT NULL DEFAULT 0,
    deposit_amount      DECIMAL(10,2) NOT NULL DEFAULT 0,
    total_amount        DECIMAL(10,2) NOT NULL,
    booking_status      ENUM('pending','confirmed','active','completed','cancelled','no_show') NOT NULL DEFAULT 'pending',
    payment_status      ENUM('pending','partial','paid','refunded') NOT NULL DEFAULT 'pending',
    notes               VARCHAR(500),
    created_by          INT UNSIGNED NULL COMMENT 'user_id — NULL if customer self-booked',
    confirmed_by        INT UNSIGNED NULL,
    confirmed_at        DATETIME NULL,
    cancelled_by        INT UNSIGNED NULL COMMENT 'NULL + cancelled_at set = cancelled by the customer',
    cancelled_at        DATETIME NULL,
    cancellation_reason VARCHAR(255) NULL,
    cancellation_fee    DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'set when cancelled/no-show; what the customer owes then',
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id)  REFERENCES customers(customer_id),
    FOREIGN KEY (vehicle_id)   REFERENCES vehicles(vehicle_id),
    FOREIGN KEY (created_by)   REFERENCES users(user_id),
    FOREIGN KEY (confirmed_by) REFERENCES users(user_id),
    FOREIGN KEY (cancelled_by) REFERENCES users(user_id),
    -- the availability check filters on exactly these columns
    INDEX idx_bookings_overlap (vehicle_id, booking_status, pickup_datetime, return_datetime),
    CHECK (return_datetime > pickup_datetime),
    CHECK (total_amount >= 0 AND discount_amount >= 0 AND deposit_amount >= 0 AND cancellation_fee >= 0)
) ENGINE=InnoDB;

CREATE INDEX idx_bookings_vehicle_dates ON bookings(vehicle_id, pickup_datetime, return_datetime);
CREATE INDEX idx_bookings_customer ON bookings(customer_id);
CREATE INDEX idx_bookings_status ON bookings(booking_status);

-- ---------------------------------------------------------------------
-- 6. RENTALS — the physical check-out/check-in event for a booking.
--    One row is created when the car is released (check-out) and
--    completed when it comes back (check-in). The three automatic
--    charges live here; anything staff add by hand goes in `penalties`.
-- ---------------------------------------------------------------------
CREATE TABLE rentals (
    rental_id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id          INT UNSIGNED NOT NULL UNIQUE,
    released_by         INT UNSIGNED NOT NULL COMMENT 'staff/admin user_id who handed over the vehicle',
    released_at         DATETIME NOT NULL,
    odometer_out        INT UNSIGNED NOT NULL,
    fuel_level_out      TINYINT UNSIGNED NOT NULL COMMENT 'percent, 0-100',
    condition_notes_out VARCHAR(500) NULL COMMENT 'existing scratches etc., noted with the customer at handover',
    returned_by         INT UNSIGNED NULL COMMENT 'staff/admin user_id who received the vehicle back',
    returned_at         DATETIME NULL,
    odometer_in         INT UNSIGNED NULL,
    fuel_level_in       TINYINT UNSIGNED NULL,
    late_hours          INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'hours charged; 0 if within the grace period',
    late_fee            DECIMAL(10,2) NOT NULL DEFAULT 0,
    fuel_fee            DECIMAL(10,2) NOT NULL DEFAULT 0,
    damage_fee          DECIMAL(10,2) NOT NULL DEFAULT 0,
    damage_notes        VARCHAR(500),
    FOREIGN KEY (booking_id)  REFERENCES bookings(booking_id) ON DELETE CASCADE,
    FOREIGN KEY (released_by) REFERENCES users(user_id),
    FOREIGN KEY (returned_by) REFERENCES users(user_id),
    CHECK (fuel_level_out <= 100 AND (fuel_level_in IS NULL OR fuel_level_in <= 100)),
    CHECK (odometer_in IS NULL OR odometer_in >= odometer_out),
    CHECK (returned_at IS NULL OR returned_at >= released_at)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 7. PAYMENTS — every money movement tied to a booking.
--    Money in: deposit, rental_fee, late_fee, damage_fee, additional_service.
--    Money out: refund (refund_of says whether it returns the deposit or
--    an overpayment). deposit_applied moves no cash: it records the part
--    of a held deposit kept to cover what the customer owed, which is the
--    moment that money becomes revenue. See database/README.md.
-- ---------------------------------------------------------------------
CREATE TABLE payments (
    payment_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id      INT UNSIGNED NOT NULL,
    receipt_number  VARCHAR(30)  NOT NULL UNIQUE COMMENT 'OR-YYYY-NNNNNN, sequential',
    amount          DECIMAL(10,2) NOT NULL,
    payment_type    ENUM('deposit','rental_fee','late_fee','damage_fee','additional_service','refund','deposit_applied') NOT NULL,
    refund_of       ENUM('deposit','payment') NULL COMMENT 'refunds only',
    payment_method  ENUM('cash','card','gcash','bank_transfer') NULL COMMENT 'NULL only for deposit_applied (no cash moves)',
    transaction_ref VARCHAR(80) UNIQUE COMMENT 'card/GCash/bank reference; required for non-cash',
    status          ENUM('pending','completed','failed','refunded') NOT NULL DEFAULT 'completed' COMMENT 'failed = voided by an admin',
    notes           VARCHAR(255) NULL,
    recorded_by     INT UNSIGNED NOT NULL COMMENT 'staff/admin who recorded it',
    paid_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    voided_by       INT UNSIGNED NULL,
    voided_at       DATETIME NULL,
    FOREIGN KEY (booking_id)   REFERENCES bookings(booking_id),
    FOREIGN KEY (recorded_by)  REFERENCES users(user_id),
    FOREIGN KEY (voided_by)    REFERENCES users(user_id),
    INDEX idx_payments_paid_at (paid_at),
    CHECK (amount > 0),
    CHECK ((payment_type = 'refund') = (refund_of IS NOT NULL)),
    CHECK (payment_method IS NOT NULL OR payment_type = 'deposit_applied')
) ENGINE=InnoDB;

CREATE INDEX idx_payments_booking ON payments(booking_id);

-- ---------------------------------------------------------------------
-- 7b. PAYMENT SUBMISSIONS — a customer says "I sent ₱X by GCash / bank
--    transfer, reference Y" (Phase 11). Nothing counts as paid until a
--    staff member checks the reference and accepts it; accepting records a
--    normal payment (with a receipt) and links it here. Rejections keep the
--    reason so the customer sees why.
-- ---------------------------------------------------------------------
CREATE TABLE payment_submissions (
    submission_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id      INT UNSIGNED NOT NULL,
    submitted_by    INT UNSIGNED NOT NULL COMMENT 'the customer''s user_id',
    amount          DECIMAL(10,2) NOT NULL,
    payment_type    ENUM('deposit','rental_fee') NOT NULL COMMENT 'what the customer says it is for',
    payment_method  ENUM('gcash','bank_transfer') NOT NULL,
    transaction_ref VARCHAR(80) NOT NULL,
    paid_on         DATE NOT NULL,
    status          ENUM('submitted','accepted','rejected','withdrawn') NOT NULL DEFAULT 'submitted',
    reviewed_by     INT UNSIGNED NULL,
    reviewed_at     DATETIME NULL,
    review_note     VARCHAR(255) NULL,
    payment_id      INT UNSIGNED NULL COMMENT 'the payment recorded when accepted',
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (booking_id)   REFERENCES bookings(booking_id),
    FOREIGN KEY (submitted_by) REFERENCES users(user_id),
    FOREIGN KEY (reviewed_by)  REFERENCES users(user_id),
    FOREIGN KEY (payment_id)   REFERENCES payments(payment_id),
    INDEX idx_submissions_booking (booking_id, status),
    INDEX idx_submissions_status (status, created_at),
    CHECK (amount > 0),
    CHECK ((status = 'accepted') = (payment_id IS NOT NULL))
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 8. INVOICES — the final bill for a closed booking, totals frozen at
--    issue. At most one non-void invoice per booking (enforced in
--    includes/payments_data.php); a correction means voiding it with a
--    reason and issuing a new one, so numbers are never reused or edited.
-- ---------------------------------------------------------------------
CREATE TABLE invoices (
    invoice_id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id         INT UNSIGNED NOT NULL,
    invoice_number     VARCHAR(30) NOT NULL UNIQUE COMMENT 'INV-YYYY-NNNNNN, sequential',
    issue_date         DATE NOT NULL,
    subtotal           DECIMAL(10,2) NOT NULL COMMENT 'rental base, or the cancellation fee',
    discount           DECIMAL(10,2) NOT NULL DEFAULT 0,
    additional_charges DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'late, fuel, damage, penalties',
    total              DECIMAL(10,2) NOT NULL,
    status             ENUM('unpaid','paid','void') NOT NULL DEFAULT 'unpaid',
    issued_by          INT UNSIGNED NOT NULL,
    voided_by          INT UNSIGNED NULL,
    voided_at          DATETIME NULL,
    void_reason        VARCHAR(255) NULL,
    FOREIGN KEY (booking_id) REFERENCES bookings(booking_id),
    FOREIGN KEY (issued_by)  REFERENCES users(user_id),
    FOREIGN KEY (voided_by)  REFERENCES users(user_id),
    INDEX idx_invoices_booking (booking_id),
    CHECK (total = subtotal - discount + additional_charges)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 9. MAINTENANCE — service jobs per vehicle, scheduled ahead or logged
--    after the fact. A job holds its car from service_date to end_date
--    (bookings can't overlap it, and vice versa). Starting a job puts the
--    car in the workshop (vehicles.status = 'maintenance'); completing the
--    last open job brings it back. next_* drive the service-due reminders.
-- ---------------------------------------------------------------------
CREATE TABLE maintenance (
    maintenance_id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vehicle_id            INT UNSIGNED NOT NULL,
    maintenance_type      ENUM('oil_change','tire_replacement','brake_service','general_inspection','repair','carwash','other') NOT NULL,
    service_date          DATE NOT NULL COMMENT 'start: planned, or actual once started',
    end_date              DATE NOT NULL COMMENT 'planned end; the actual completion date once completed',
    cost                  DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'final cost, entered on completion',
    description           VARCHAR(500),
    next_maintenance_date DATE NULL COMMENT 'next service due by date',
    next_due_km           INT UNSIGNED NULL COMMENT 'next service due at this odometer reading',
    odometer_km           INT UNSIGNED NULL COMMENT 'reading when the work was done',
    performed_by          VARCHAR(120) COMMENT 'shop/mechanic name — external, not a system user',
    logged_by             INT UNSIGNED NOT NULL COMMENT 'staff/admin user_id who logged the record',
    completed_by          INT UNSIGNED NULL,
    completed_at          DATETIME NULL,
    cancelled_by          INT UNSIGNED NULL,
    cancelled_at          DATETIME NULL,
    cancel_reason         VARCHAR(255) NULL,
    status                ENUM('scheduled','in_progress','completed','cancelled') NOT NULL DEFAULT 'scheduled',
    created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (vehicle_id)   REFERENCES vehicles(vehicle_id),
    FOREIGN KEY (logged_by)    REFERENCES users(user_id),
    FOREIGN KEY (completed_by) REFERENCES users(user_id),
    FOREIGN KEY (cancelled_by) REFERENCES users(user_id),
    -- the availability check filters on exactly these columns
    INDEX idx_maintenance_window (vehicle_id, status, service_date, end_date),
    CHECK (end_date >= service_date),
    CHECK (cost >= 0)
) ENGINE=InnoDB;

CREATE INDEX idx_maintenance_vehicle ON maintenance(vehicle_id);

-- ---------------------------------------------------------------------
-- 10. PENALTIES / ADDITIONAL CHARGES — itemized extras beyond base rent.
-- ---------------------------------------------------------------------
CREATE TABLE penalties (
    penalty_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id   INT UNSIGNED NOT NULL,
    charge_type  ENUM('late_return','damage','cleaning','extra_mileage','traffic_violation','other') NOT NULL,
    amount       DECIMAL(10,2) NOT NULL,
    description  VARCHAR(255),
    created_by   INT UNSIGNED NOT NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (booking_id) REFERENCES bookings(booking_id),
    FOREIGN KEY (created_by) REFERENCES users(user_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 11. DOCUMENTS — uploaded customer verification files. The files are
--     private (ID scans): they live in a deny-all folder and are only
--     ever served through api/document.php, which checks who's asking.
-- ---------------------------------------------------------------------
CREATE TABLE documents (
    document_id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id         INT UNSIGNED NOT NULL,
    document_type       ENUM('drivers_license','national_id','passport','proof_of_billing') NOT NULL,
    file_path           VARCHAR(255) NOT NULL COMMENT 'relative to project root; random filename',
    original_name       VARCHAR(255) NOT NULL COMMENT 'display only — never used as a path',
    mime_type           VARCHAR(50)  NOT NULL COMMENT 'sniffed from the file bytes, not the client',
    file_size           INT UNSIGNED NOT NULL,
    verification_status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    review_note         VARCHAR(255) NULL COMMENT 'required when rejected, shown to the customer',
    uploaded_by         INT UNSIGNED NULL COMMENT 'customer themself, or staff/admin at the desk',
    reviewed_by         INT UNSIGNED NULL,
    reviewed_at         DATETIME NULL,
    uploaded_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(customer_id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(user_id) ON DELETE SET NULL,
    FOREIGN KEY (reviewed_by) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_documents_status (verification_status)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 12. NOTIFICATIONS
-- ---------------------------------------------------------------------
CREATE TABLE notifications (
    notification_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    title           VARCHAR(120) NOT NULL,
    message         VARCHAR(500) NOT NULL,
    target          VARCHAR(40)  NULL COMMENT 'what it is about, e.g. booking:12 or customer:3; opened per role',
    is_read         TINYINT(1) NOT NULL DEFAULT 0,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_notifications_user ON notifications(user_id, is_read);

-- ---------------------------------------------------------------------
-- 14. SETTINGS — an admin's overrides of the business settings (Phase 13).
--    Defaults and the rules for each key live in config/settings.php;
--    a key with no row here uses its default.
-- ---------------------------------------------------------------------
CREATE TABLE settings (
    setting_key   VARCHAR(60)  NOT NULL PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL,
    updated_by    INT UNSIGNED NULL,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 13. SYSTEM LOGS — lightweight audit trail.
-- ---------------------------------------------------------------------
CREATE TABLE system_logs (
    log_id      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NULL,
    action      VARCHAR(80) NOT NULL,
    description VARCHAR(500),
    ip_address  VARCHAR(45),
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX idx_logs_created ON system_logs(created_at);

-- =====================================================================
-- SAMPLE DATA
-- Password for every seeded account is:  Password123!
-- Each hash below is a REAL password_hash('Password123!', PASSWORD_BCRYPT)
-- output — verified against that password, so these logins work as-is
-- against Phase 2's login.php. Each row gets its own hash (different
-- salt) even though the password is the same, matching how bcrypt
-- actually behaves — no two of these strings are copy-pasted.
-- =====================================================================

INSERT INTO users (role_id, username, email, password_hash, full_name, phone, status) VALUES
(1, 'admin',          'admin@autoway.local',  '$2y$10$1WaOXx.8Ahc4dPVLoQKZku60nVhHu8qpKjFfn1U2sW7wUFKD12xqu', 'Alex Rivera',   '09171234567', 'active'),
(2, 'staff.maria',    'maria@autoway.local',  '$2y$10$izvYJN6x3g201jH0qusv8uqUCoxDLFEq6FsT84tZYH4t2m1RN8qKi', 'Maria Santos',  '09179876543', 'active'),
(2, 'staff.jon',      'jon@autoway.local',    '$2y$10$KfIOb4maGTnOr0il4hgEheyrsh8i0pzP2n4TT1WrPEtUbBzn4W/Ve', 'Jon Dela Cruz', '09181112222', 'active'),
(3, 'customer.liam',  'liam@example.com',      '$2y$10$lIyVkH3/H8SrY0cvXwXAbuHbMkuNJaWmCxXcgtaKhVgOxV1Bui.ha', 'Liam Torres',   '09201234567', 'active'),
(3, 'customer.nadia', 'nadia@example.com',     '$2y$10$hq0.RnP.o8wvWMQRaKBJu.k9RcJuVt52M8dNS2uGbkfXaGvTZ881m', 'Nadia Reyes',   '09209876543', 'active');
-- The sample accounts are existing users, so they skip the first-login
-- quick tour. Accounts created later (sign-up, Add customer, Add staff or
-- admin) start with NULL and see it once. Replay it from Guide.
UPDATE users SET tour_completed_at = NOW();

INSERT INTO customers (user_id, date_of_birth, address_line, city, license_number, license_expiry, id_type, id_number, account_status) VALUES
(4, '1998-04-12', '22 Mabini St.',  'Calapan City',  'N01-23-456789', '2028-04-12', 'drivers_license', 'N01-23-456789', 'verified'),
(5, '1995-11-03', '8 Rizal Ave.',   'Naujan', 'N02-34-567890', '2027-11-03', 'drivers_license', 'N02-34-567890', 'verified');

INSERT INTO vehicles (brand, model, year, plate_number, vehicle_type, transmission, fuel_type, seating_capacity, color, mileage_km, daily_rate, status, description) VALUES
('Toyota',  'Vios',        2023, 'NBC 1234', 'sedan',     'automatic', 'gasoline', 5, 'White',  18500, 1800.00, 'available',   'Fuel-efficient city sedan, great for daily commuting.'),
('Toyota',  'Innova',      2022, 'NBD 5678', 'van',       'automatic', 'diesel',   7, 'Silver', 32100, 2800.00, 'available',   'Spacious 7-seater, ideal for family trips.'),
('Honda',   'CR-V',        2023, 'NBE 4321', 'suv',       'automatic', 'gasoline', 5, 'Black',  9800,  3200.00, 'rented',      'Mid-size SUV with great road presence.'),
('Mitsubishi','Mirage',    2021, 'NBF 8765', 'hatchback', 'manual',    'gasoline', 5, 'Red',    41200, 1400.00, 'available',   'Budget-friendly hatchback, easy to park.'),
('Ford',    'Ranger',      2022, 'NBG 2468', 'pickup',    'automatic', 'diesel',   5, 'Gray',   27300, 3000.00, 'maintenance', 'Off-road capable pickup truck.'),
('Toyota',  'Fortuner',    2024, 'NBH 1357', 'suv',       'automatic', 'diesel',   7, 'Black',  4200,  3800.00, 'available',   'Premium SUV, leather interior, top pick for road trips.'),
('Hyundai', 'Accent',      2022, 'NBI 9753', 'sedan',     'automatic', 'gasoline', 5, 'Blue',   21400, 1700.00, 'available',   'Comfortable sedan with good fuel economy.'),
('Kia',     'Soluto',      2023, 'NBJ 8642', 'sedan',     'manual',    'gasoline', 5, 'White',  11000, 1500.00, 'available',   'Compact and reliable, good for beginners.');

-- No seeded vehicle_images rows: those paths never corresponded to real
-- files on disk, which is exactly the gap Phase 4's upload feature closes.
-- Every vehicle starts with no image, same as one added through the UI
-- would, until someone uploads one.

-- Both customers were verified at the desk against an approved license.
-- The two images are placeholders shipped in assets/uploads/documents/.
INSERT INTO documents (customer_id, document_type, file_path, original_name, mime_type, file_size, verification_status, uploaded_by, reviewed_by, reviewed_at, uploaded_at) VALUES
(1, 'drivers_license', 'assets/uploads/documents/sample-license-liam.png',  'license-liam.png',  'image/png', 20701, 'approved', 4, 2, NOW() - INTERVAL 20 DAY, NOW() - INTERVAL 21 DAY),
(2, 'drivers_license', 'assets/uploads/documents/sample-license-nadia.png', 'license-nadia.png', 'image/png', 20564, 'approved', 5, 2, NOW() - INTERVAL 15 DAY, NOW() - INTERVAL 16 DAY);

-- Every date below is relative to when the file is imported, so the demo
-- tells the same story on any day:
--   * Liam's CR-V went out 3 days ago and was due back 5 hours ago
--     (overdue, with a small late fee building up);
--   * Nadia booked the Fortuner online for tomorrow, the desk confirmed
--     it, and she has paid in full (deposit + rental);
--   * the Ranger is in the workshop until the day after tomorrow.
-- Times are on the hour, like bookings made through the app.
INSERT INTO bookings (booking_reference, customer_id, vehicle_id, pickup_datetime, return_datetime, rental_days, daily_rate_snapshot, base_amount, discount_amount, deposit_amount, total_amount, booking_status, payment_status, created_by, confirmed_by, confirmed_at, created_at) VALUES
('BK-260917-3F9A21', 1, 3,
    DATE_FORMAT(NOW() - INTERVAL 77 HOUR, '%Y-%m-%d %H:00:00'), DATE_FORMAT(NOW() - INTERVAL 5 HOUR, '%Y-%m-%d %H:00:00'),
    3, 3200.00, 9600.00, 0, 2000.00, 11600.00, 'active', 'partial', 2, 2, NOW() - INTERVAL 4 DAY, NOW() - INTERVAL 4 DAY - INTERVAL 10 MINUTE),
('BK-260920-B7C4D8', 2, 6,
    CURDATE() + INTERVAL 1 DAY + INTERVAL 10 HOUR, CURDATE() + INTERVAL 3 DAY + INTERVAL 10 HOUR,
    2, 3800.00, 7600.00, 500, 2000.00, 9100.00, 'confirmed', 'paid', NULL, 1, NOW() - INTERVAL 2 DAY, NOW() - INTERVAL 2 DAY - INTERVAL 30 MINUTE);

INSERT INTO rentals (booking_id, released_by, released_at, odometer_out, fuel_level_out, condition_notes_out) VALUES
(1, 2, DATE_FORMAT(NOW() - INTERVAL 77 HOUR, '%Y-%m-%d %H:15:00'), 9800, 100, 'Small scratch on rear bumper, already there.');

-- Payments taken at the desk: Liam's deposit (cash) and part of his rental
-- (GCash); Nadia's deposit and rental by card. New payments continue the
-- receipt numbers from OR-2026-000005.
INSERT INTO payments (booking_id, receipt_number, amount, payment_type, payment_method, transaction_ref, status, recorded_by, paid_at) VALUES
(1, 'OR-2026-000001', 2000.00, 'deposit',    'cash',  NULL,            'completed', 2, NOW() - INTERVAL 4 DAY),
(1, 'OR-2026-000002', 6400.00, 'rental_fee', 'gcash', 'GC-8841203917', 'completed', 2, DATE_FORMAT(NOW() - INTERVAL 77 HOUR, '%Y-%m-%d %H:10:00')),
(2, 'OR-2026-000003', 2000.00, 'deposit',    'card',  'CARD-55129033', 'completed', 1, NOW() - INTERVAL 2 DAY),
(2, 'OR-2026-000004', 7100.00, 'rental_fee', 'card',  'CARD-55129034', 'completed', 1, NOW() - INTERVAL 2 DAY + INTERVAL 1 MINUTE);

-- Service history: completed jobs (with when the next one is due, so the
-- reminders have something to show: the Vios is past its mileage and due
-- by date in 4 days, the Mirage is 200 km past its mileage) and the
-- Ranger's brakes in the workshop right now.
INSERT INTO maintenance (vehicle_id, maintenance_type, service_date, end_date, cost, description, next_maintenance_date, next_due_km, odometer_km, performed_by, logged_by, completed_by, completed_at, status) VALUES
(1, 'oil_change',         CURDATE() - INTERVAL 176 DAY, CURDATE() - INTERVAL 176 DAY, 2800.00, 'Engine oil and filter.',          CURDATE() + INTERVAL 4 DAY,   17500, 12500, 'Calapan Auto Care',     2, 2, CURDATE() - INTERVAL 176 DAY + INTERVAL 16 HOUR, 'completed'),
(2, 'general_inspection', CURDATE() - INTERVAL 97 DAY,  CURDATE() - INTERVAL 97 DAY,  1500.00, '20-point inspection, no issues.', CURDATE() + INTERVAL 87 DAY,  37100, 27100, 'Calapan Auto Care',     2, 2, CURDATE() - INTERVAL 97 DAY + INTERVAL 15 HOUR,  'completed'),
(4, 'oil_change',         CURDATE() - INTERVAL 157 DAY, CURDATE() - INTERVAL 157 DAY, 2200.00, 'Engine oil and filter.',          CURDATE() + INTERVAL 27 DAY,  41000, 36000, 'Petron Car Care', 3, 3, CURDATE() - INTERVAL 157 DAY + INTERVAL 11 HOUR, 'completed'),
(5, 'brake_service',      CURDATE() - INTERVAL 21 DAY,  CURDATE() + INTERVAL 2 DAY,   4500.00, 'Front brake pads replaced, fluid flushed. Waiting for rotor delivery.', NULL, NULL, NULL, 'Mindoro Brake and Clutch', 1, NULL, NULL, 'in_progress');

-- =====================================================================
-- End of schema. See /database/README.md for table relationship notes.
-- =====================================================================
