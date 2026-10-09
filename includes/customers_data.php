<?php
/**
 * Everything the customer-management pages and APIs need to read or change
 * about customers and their verification documents. Shared by
 * admin/customers.php, api/customers.php, customer/documents.php, and
 * api/document.php so there's one copy of each rule.
 *
 * Two different "statuses" exist on purpose:
 *   users.status              — can this person log in at all? (Users module)
 *   customers.account_status  — may this customer rent? (this module)
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php'; // role_id_by_name(), hash_password()

const CUSTOMER_STATUSES = ['unverified', 'verified', 'blocked'];
const ID_TYPES          = ['drivers_license', 'national_id', 'passport'];
const DOCUMENT_TYPES    = ['drivers_license', 'national_id', 'passport', 'proof_of_billing'];
const DOCUMENT_STATUSES = ['pending', 'approved', 'rejected'];

// A customer can't pile up more unreviewed uploads than this (abuse/disk guard).
const MAX_PENDING_DOCUMENTS = 10;

// ---------------------------------------------------------------------
// Reads
// ---------------------------------------------------------------------

const CUSTOMER_SELECT = '
    SELECT c.*, u.username, u.email, u.full_name, u.phone, u.status AS login_status, u.created_at AS registered_at,
           (SELECT COUNT(*) FROM documents d WHERE d.customer_id = c.customer_id AND d.verification_status = \'pending\')  AS pending_documents,
           (SELECT COUNT(*) FROM documents d WHERE d.customer_id = c.customer_id AND d.verification_status = \'approved\') AS approved_documents,
           (SELECT COUNT(*) FROM bookings b WHERE b.customer_id = c.customer_id) AS booking_count
    FROM customers c
    JOIN users u ON u.user_id = c.user_id';

/**
 * @param array{search?: string, status?: string} $filters
 */
function list_customers(array $filters = []): array
{
    $where  = ['1 = 1'];
    $params = [];

    if (!empty($filters['search'])) {
        $where[] = '(u.full_name LIKE ? OR u.email LIKE ? OR u.username LIKE ? OR c.license_number LIKE ?)';
        $like = '%' . $filters['search'] . '%';
        array_push($params, $like, $like, $like, $like);
    }
    if (!empty($filters['status']) && in_array($filters['status'], CUSTOMER_STATUSES, true)) {
        $where[] = 'c.account_status = ?';
        $params[] = $filters['status'];
    }

    $stmt = Database::getConnection()->prepare(
        CUSTOMER_SELECT . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY u.full_name'
    );
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function get_customer(int $customer_id): ?array
{
    $stmt = Database::getConnection()->prepare(CUSTOMER_SELECT . ' WHERE c.customer_id = ?');
    $stmt->execute([$customer_id]);

    return $stmt->fetch() ?: null;
}

function get_customer_by_user_id(int $user_id): ?array
{
    $stmt = Database::getConnection()->prepare(CUSTOMER_SELECT . ' WHERE c.user_id = ?');
    $stmt->execute([$user_id]);

    return $stmt->fetch() ?: null;
}

/** Booking counts and money for the customer detail view. */
function customer_booking_summary(int $customer_id): array
{
    $pdo = Database::getConnection();

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS total,
                SUM(booking_status IN ('pending', 'confirmed', 'active')) AS open_bookings,
                SUM(booking_status = 'completed') AS completed,
                SUM(booking_status IN ('cancelled', 'no_show')) AS cancelled
         FROM bookings WHERE customer_id = ?"
    );
    $stmt->execute([$customer_id]);
    $counts = $stmt->fetch();

    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(" . NET_CASH_SQL . "), 0)
         FROM payments p JOIN bookings b ON b.booking_id = p.booking_id
         WHERE b.customer_id = ? AND p.status = 'completed'"
    );
    $stmt->execute([$customer_id]);
    $paid = (float) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT b.booking_id, b.booking_reference, b.booking_status, b.pickup_datetime, b.return_datetime, b.total_amount,
                v.brand, v.model, v.plate_number
         FROM bookings b JOIN vehicles v ON v.vehicle_id = b.vehicle_id
         WHERE b.customer_id = ?
         ORDER BY b.pickup_datetime DESC
         LIMIT 5"
    );
    $stmt->execute([$customer_id]);
    $recent = $stmt->fetchAll();
    foreach ($recent as &$row) {
        $row['total_amount'] = (float) $row['total_amount'];
    }
    unset($row);

    return [
        'total'         => (int) $counts['total'],
        'open_bookings' => (int) $counts['open_bookings'],
        'completed'     => (int) $counts['completed'],
        'cancelled'     => (int) $counts['cancelled'],
        'total_paid'    => $paid,
        'recent'        => $recent,
    ];
}

// ---------------------------------------------------------------------
// Validation + writes
// ---------------------------------------------------------------------

function email_taken(string $email, ?int $except_user_id = null): bool
{
    $sql = 'SELECT user_id FROM users WHERE email = ?';
    $params = [$email];
    if ($except_user_id !== null) {
        $sql .= ' AND user_id != ?';
        $params[] = $except_user_id;
    }
    $stmt = Database::getConnection()->prepare($sql);
    $stmt->execute($params);

    return (bool) $stmt->fetch();
}

function username_taken(string $username): bool
{
    $stmt = Database::getConnection()->prepare('SELECT user_id FROM users WHERE username = ?');
    $stmt->execute([$username]);

    return (bool) $stmt->fetch();
}

/**
 * Validate the customer profile form. $existing is the current customer
 * row when editing, null when creating (creating also needs username +
 * password). Returns a list of error strings; empty means clean.
 */
function validate_customer_input(array $input, ?array $existing = null): array
{
    $errors = [];
    $full_name = trim($input['full_name'] ?? '');
    $email     = trim($input['email'] ?? '');
    $phone     = trim($input['phone'] ?? '');

    if ($full_name === '' || mb_strlen($full_name) > 120) {
        $errors[] = 'Full name is required (up to 120 characters).';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 120) {
        $errors[] = 'Enter a valid email address.';
    } elseif (email_taken($email, $existing ? (int) $existing['user_id'] : null)) {
        $errors[] = 'That email belongs to another account.';
    }
    if ($phone !== '' && !preg_match('/^[0-9+()\-\s]{7,20}$/', $phone)) {
        $errors[] = 'Phone may only contain digits, spaces, and + ( ) - (7–20 characters).';
    }

    if ($existing === null) {
        $username = trim($input['username'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9_.]{4,50}$/', $username)) {
            $errors[] = 'Username must be 4–50 characters: letters, numbers, "." and "_" only.';
        } elseif (username_taken($username)) {
            $errors[] = 'That username is already taken.';
        }
        if ($password_error = password_policy_error($input['password'] ?? '')) {
            $errors[] = $password_error;
        }
    }

    $dob = trim($input['date_of_birth'] ?? '');
    if ($dob !== '') {
        $date = parse_date($dob);
        if (!$date) {
            $errors[] = 'Date of birth must be a valid date.';
        } elseif ($date > new DateTimeImmutable('-' . MIN_RENTER_AGE . ' years')) {
            $errors[] = 'Customers must be at least ' . MIN_RENTER_AGE . ' years old.';
        } elseif ($date < new DateTimeImmutable('-120 years')) {
            $errors[] = 'Date of birth looks wrong — check the year.';
        }
    }

    $expiry = trim($input['license_expiry'] ?? '');
    if ($expiry !== '' && !parse_date($expiry)) {
        $errors[] = 'License expiry must be a valid date.';
    }

    if (!in_array($input['id_type'] ?? '', ID_TYPES, true)) {
        $errors[] = 'Choose a valid ID type.';
    }

    foreach (['license_number' => 50, 'id_number' => 50, 'address_line' => 255, 'city' => 100] as $field => $max) {
        if (mb_strlen(trim($input[$field] ?? '')) > $max) {
            $errors[] = humanize($field) . " can't be longer than $max characters.";
        }
    }

    return $errors;
}

/** Profile columns on `customers`, normalized from form input ('' -> NULL). */
function customer_profile_params(array $input): array
{
    $nullable = function (string $key) use ($input): ?string {
        $value = trim($input[$key] ?? '');
        return $value === '' ? null : $value;
    };

    return [
        'date_of_birth'  => $nullable('date_of_birth'),
        'address_line'   => $nullable('address_line'),
        'city'           => $nullable('city'),
        'license_number' => $nullable('license_number'),
        'license_expiry' => $nullable('license_expiry'),
        'id_type'        => $input['id_type'],
        'id_number'      => $nullable('id_number'),
    ];
}

/** Creates the login (users) and the customer profile together. Returns customer_id. */
function create_customer(array $input): int
{
    $pdo = Database::getConnection();
    $pdo->beginTransaction();
    try {
        $phone = trim($input['phone'] ?? '');
        $pdo->prepare(
            'INSERT INTO users (role_id, username, email, password_hash, full_name, phone)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            role_id_by_name('customer'),
            trim($input['username']),
            trim($input['email']),
            hash_password($input['password']),
            trim($input['full_name']),
            $phone === '' ? null : $phone,
        ]);
        $user_id = (int) $pdo->lastInsertId();

        $profile = customer_profile_params($input);
        $pdo->prepare(
            'INSERT INTO customers (user_id, date_of_birth, address_line, city, license_number, license_expiry, id_type, id_number)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute(array_merge([$user_id], array_values($profile)));
        $customer_id = (int) $pdo->lastInsertId();

        $pdo->commit();
        return $customer_id;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function update_customer(array $customer, array $input): void
{
    $pdo = Database::getConnection();
    $pdo->beginTransaction();
    try {
        $phone = trim($input['phone'] ?? '');
        $pdo->prepare('UPDATE users SET full_name = ?, email = ?, phone = ? WHERE user_id = ?')
            ->execute([trim($input['full_name']), trim($input['email']), $phone === '' ? null : $phone, $customer['user_id']]);

        $profile = customer_profile_params($input);
        $pdo->prepare(
            'UPDATE customers SET date_of_birth = ?, address_line = ?, city = ?, license_number = ?,
                    license_expiry = ?, id_type = ?, id_number = ?
             WHERE customer_id = ?'
        )->execute(array_merge(array_values($profile), [$customer['customer_id']]));

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * What stops this customer from being marked verified. Empty = OK.
 * Verified means "may rent", so it needs: a license on file that hasn't
 * expired, and at least one document a person has actually looked at and
 * approved.
 */
function customer_verification_blockers(array $customer): array
{
    $blockers = [];
    if (empty($customer['license_number'])) {
        $blockers[] = 'add the driver\'s license number';
    }
    $expiry = parse_date($customer['license_expiry'] ?? null);
    if (!$expiry) {
        $blockers[] = 'add the license expiry date';
    } elseif ($expiry < new DateTimeImmutable('today')) {
        $blockers[] = 'the driver\'s license has expired';
    }
    if ((int) $customer['approved_documents'] === 0) {
        $blockers[] = 'approve at least one uploaded ID document';
    }
    return $blockers;
}

function license_is_expired(?string $license_expiry): bool
{
    $expiry = parse_date($license_expiry);
    return $expiry !== null && $expiry < new DateTimeImmutable('today');
}

function set_customer_status(int $customer_id, string $status): void
{
    Database::getConnection()
        ->prepare('UPDATE customers SET account_status = ? WHERE customer_id = ?')
        ->execute([$status, $customer_id]);
}

// ---------------------------------------------------------------------
// Documents
// ---------------------------------------------------------------------

function customer_documents(int $customer_id): array
{
    $stmt = Database::getConnection()->prepare(
        'SELECT d.*, r.full_name AS reviewer_name
         FROM documents d
         LEFT JOIN users r ON r.user_id = d.reviewed_by
         WHERE d.customer_id = ?
         ORDER BY d.uploaded_at DESC, d.document_id DESC'
    );
    $stmt->execute([$customer_id]);

    return $stmt->fetchAll();
}

function get_document(int $document_id): ?array
{
    $stmt = Database::getConnection()->prepare('SELECT * FROM documents WHERE document_id = ?');
    $stmt->execute([$document_id]);

    return $stmt->fetch() ?: null;
}

/**
 * Validate and save one uploaded document file. Returns
 * ['error' => string] or ['path' => ..., 'mime' => ..., 'size' => ..., 'original' => ...].
 * Trusts only the file's actual bytes — never the client-supplied MIME
 * type or the original filename/extension.
 */
function store_document_upload(?array $file, int $customer_id): array
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['error' => 'Choose a file to upload.'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['error' => 'Upload failed (error code ' . (int) $file['error'] . '). Try a smaller file.'];
    }
    if ($file['size'] > MAX_UPLOAD_BYTES) {
        return ['error' => 'That file is larger than ' . (MAX_UPLOAD_BYTES / 1024 / 1024) . ' MB.'];
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return ['error' => 'Upload failed. Try again.'];
    }

    $real_type = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $extension = ALLOWED_DOCUMENT_TYPES[$real_type] ?? null;
    if ($extension === null) {
        return ['error' => 'Only JPEG, PNG, WebP, or PDF files are allowed.'];
    }

    $filename = 'doc_' . $customer_id . '_' . bin2hex(random_bytes(12)) . '.' . $extension;
    if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR_DOCUMENTS . $filename)) {
        return ['error' => 'Could not save the uploaded file. Check folder permissions.'];
    }

    // Display name only; strip anything path-like or control characters.
    $original = preg_replace('/[\x00-\x1F\x7F\/\\\\]/', '', basename((string) $file['name']));
    $original = mb_substr($original !== '' ? $original : 'document.' . $extension, 0, 255);

    return [
        'path'     => 'assets/uploads/documents/' . $filename,
        'mime'     => $real_type,
        'size'     => (int) $file['size'],
        'original' => $original,
    ];
}

function add_document(int $customer_id, string $document_type, array $stored, ?int $uploaded_by): int
{
    $pdo = Database::getConnection();
    $pdo->prepare(
        'INSERT INTO documents (customer_id, document_type, file_path, original_name, mime_type, file_size, uploaded_by)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([$customer_id, $document_type, $stored['path'], $stored['original'], $stored['mime'], $stored['size'], $uploaded_by]);

    return (int) $pdo->lastInsertId();
}

/** $decision is 'approved' or 'rejected'; a rejection must say why (the customer sees it). */
function review_document(int $document_id, string $decision, int $reviewer_id, string $note = ''): void
{
    $note = trim($note);
    Database::getConnection()->prepare(
        'UPDATE documents SET verification_status = ?, review_note = ?, reviewed_by = ?, reviewed_at = NOW()
         WHERE document_id = ?'
    )->execute([$decision, $note === '' ? null : mb_substr($note, 0, 255), $reviewer_id, $document_id]);
}

/** Removes the row and the file on disk. */
function delete_document(array $document): void
{
    Database::getConnection()
        ->prepare('DELETE FROM documents WHERE document_id = ?')
        ->execute([$document['document_id']]);

    $path = dirname(__DIR__) . '/' . $document['file_path'];
    // Only ever delete inside the documents folder, whatever the DB says.
    $real = realpath($path);
    $dir  = realpath(UPLOAD_DIR_DOCUMENTS);
    if ($real && $dir && strpos($real, $dir . DIRECTORY_SEPARATOR) === 0) {
        unlink($real);
    }
}

/**
 * Who may open a document file: admin and staff always; a customer only
 * their own.
 */
function can_view_document(array $user, array $document): bool
{
    if (in_array($user['role_name'], ['admin', 'staff'], true)) {
        return true;
    }
    if ($user['role_name'] === 'customer') {
        $customer = get_customer_by_user_id((int) $user['user_id']);
        return $customer && (int) $customer['customer_id'] === (int) $document['customer_id'];
    }
    return false;
}

/** Tell every active admin and staff member that something needs review. */
function notify_reviewers(string $title, string $message, ?string $target = null): void
{
    Database::getConnection()->prepare(
        "INSERT INTO notifications (user_id, title, message, target)
         SELECT u.user_id, ?, ?, ?
         FROM users u JOIN roles r ON r.role_id = u.role_id
         WHERE r.role_name IN ('admin', 'staff') AND u.status = 'active'"
    )->execute([mb_substr($title, 0, 120), mb_substr($message, 0, 500), $target]);
}

/** $target says what it's about ('booking:12', 'customer:3'); notification_url() turns it into the right page for the reader. */
function notify_user(int $user_id, string $title, string $message, ?string $target = null): void
{
    Database::getConnection()
        ->prepare('INSERT INTO notifications (user_id, title, message, target) VALUES (?, ?, ?, ?)')
        ->execute([$user_id, mb_substr($title, 0, 120), mb_substr($message, 0, 500), $target]);
}

function format_bytes(int $bytes): string
{
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1) . ' MB';
    }
    return max(1, (int) round($bytes / 1024)) . ' KB';
}
