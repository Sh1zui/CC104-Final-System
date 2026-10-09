<?php
/**
 * Customer management + document review, one action per POST. Open to
 * admin and staff (front-desk verification is staff work too; the staff
 * portal page arrives in Phase 10 and reuses this endpoint as-is).
 *
 * Every response is JSON: {"success": true, ...} or {"success": false, "error": "..."}.
 * Anything that changes a customer returns both the refreshed table rows
 * ("table_html") and the refreshed detail ("detail"), so the page never
 * needs a second request to see its own change.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/customers_data.php';
require_once __DIR__ . '/../includes/customer_partial.php';

header('Content-Type: application/json');

// JSON (not a redirect) for RBAC/CSRF failures — fetch() can't use an HTML login page.
$acting_user = current_user();
if (!$acting_user || !in_array($acting_user['role_name'], ['admin', 'staff'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => "You don't have access to that."]);
    exit;
}

if (!verify_csrf($_POST['csrf_token'] ?? null)) {
    http_response_code(419);
    echo json_encode(['success' => false, 'error' => 'Your session expired. Refresh the page and try again.']);
    exit;
}

function fail(string $message, int $status = 422): void
{
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

function customer_detail(int $customer_id): array
{
    $customer = get_customer($customer_id);
    return [
        'customer'       => $customer,
        'documents_html' => render_document_list($customer_id, 'review'),
        'summary'        => customer_booking_summary($customer_id),
        'blockers'       => customer_verification_blockers($customer),
        'license_expired'=> license_is_expired($customer['license_expiry']),
    ];
}

function respond(int $customer_id, string $message, array $extra = []): void
{
    echo json_encode(array_merge([
        'success'    => true,
        'message'    => $message,
        'table_html' => render_customer_rows(list_customers()),
        'detail'     => customer_detail($customer_id),
    ], $extra));
    exit;
}

function require_customer(): array
{
    $customer = get_customer((int) ($_POST['customer_id'] ?? 0));
    if (!$customer) {
        fail('That customer no longer exists.', 404);
    }
    return $customer;
}

/** The document, and the customer it belongs to. */
function require_document(): array
{
    $document = get_document((int) ($_POST['document_id'] ?? 0));
    if (!$document) {
        fail('That document is already gone.', 404);
    }
    return [$document, get_customer((int) $document['customer_id'])];
}

/**
 * A verified customer must keep at least one approved document; otherwise
 * "verified" would be pointing at nothing. Un-verify first, then change it.
 */
function guard_last_approved_document(array $document, array $customer, string $verb): void
{
    if ($customer['account_status'] === 'verified'
        && $document['verification_status'] === 'approved'
        && (int) $customer['approved_documents'] <= 1) {
        fail("This is the only approved document for a verified customer. Mark the customer unverified before you $verb it.");
    }
}

$action = $_POST['action'] ?? '';

switch ($action) {
    case 'get': {
        $customer = require_customer();
        echo json_encode(['success' => true, 'detail' => customer_detail((int) $customer['customer_id'])]);
        break;
    }

    case 'create': {
        $errors = validate_customer_input($_POST, null);
        if ($errors) {
            fail(implode(' ', $errors));
        }
        try {
            $id = create_customer($_POST);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') { // a duplicate slipped in between the check and the insert
                fail('That username or email was just taken. Try another.');
            }
            throw $e;
        }
        log_action($acting_user['user_id'], 'customer_create', "Registered customer #$id ({$_POST['full_name']}) at the desk");
        respond($id, 'Customer added. You can upload their documents now.', ['new_id' => $id]);
        break;
    }

    case 'update': {
        $customer = require_customer();
        $errors = validate_customer_input($_POST, $customer);
        if ($errors) {
            fail(implode(' ', $errors));
        }
        update_customer($customer, $_POST);

        // Editing the license can quietly invalidate a verification
        // (e.g. a new expiry date that's already past). Don't leave a
        // customer "verified" on details that no longer qualify.
        $message = 'Customer updated.';
        $updated = get_customer((int) $customer['customer_id']);
        if ($updated['account_status'] === 'verified' && customer_verification_blockers($updated)) {
            set_customer_status((int) $customer['customer_id'], 'unverified');
            $message = 'Customer updated. Their verification was removed because ' . implode('; ', customer_verification_blockers($updated)) . '.';
            log_action($acting_user['user_id'], 'customer_unverify', "Customer #{$customer['customer_id']} auto-unverified after profile edit");
        }

        log_action($acting_user['user_id'], 'customer_update', "Updated customer #{$customer['customer_id']}");
        respond((int) $customer['customer_id'], $message);
        break;
    }

    case 'set_status': {
        $customer = require_customer();
        $status = $_POST['status'] ?? '';
        if (!in_array($status, CUSTOMER_STATUSES, true)) {
            fail('Unknown status.');
        }
        if ($status === $customer['account_status']) {
            fail('The customer is already ' . $status . '.');
        }
        // Blocking is a safety stop any desk can pull; lifting it is an admin decision.
        if ($customer['account_status'] === 'blocked' && $acting_user['role_name'] !== 'admin') {
            fail('Only an admin can unblock a customer.', 403);
        }
        if ($status === 'verified') {
            $blockers = customer_verification_blockers($customer);
            if ($blockers) {
                fail('Can\'t verify yet: ' . implode('; ', $blockers) . '.');
            }
        }

        set_customer_status((int) $customer['customer_id'], $status);
        log_action($acting_user['user_id'], 'customer_status', "Customer #{$customer['customer_id']}: {$customer['account_status']} → $status");

        $messages = [
            'verified'   => 'Customer verified — they can now book vehicles.',
            'unverified' => $customer['account_status'] === 'blocked' ? 'Customer unblocked. They\'ll need to be verified again before booking.' : 'Customer marked unverified.',
            'blocked'    => 'Customer blocked from making bookings.',
        ];
        $notify = [
            'verified'   => ['Your account is verified', 'Your documents were approved. You can now book any available vehicle.'],
            'unverified' => ['Your account needs verification', 'Your account needs to be verified again before your next booking. Check your documents page.'],
            'blocked'    => ['Your account is on hold', 'Your account can\'t make bookings right now. Please contact the rental desk.'],
        ];
        notify_user((int) $customer['user_id'], ...$notify[$status]);

        $message = $messages[$status];
        if ($status === 'blocked') {
            $open = customer_booking_summary((int) $customer['customer_id'])['open_bookings'];
            if ($open > 0) {
                $message .= " Note: they still have $open open booking(s), which are not cancelled automatically.";
            }
        }
        respond((int) $customer['customer_id'], $message);
        break;
    }

    case 'upload_document': {
        $customer = require_customer();
        $type = $_POST['document_type'] ?? '';
        if (!in_array($type, DOCUMENT_TYPES, true)) {
            fail('Choose what kind of document this is.');
        }
        $stored = store_document_upload($_FILES['document'] ?? null, (int) $customer['customer_id']);
        if (isset($stored['error'])) {
            fail($stored['error']);
        }
        $doc_id = add_document((int) $customer['customer_id'], $type, $stored, (int) $acting_user['user_id']);
        log_action($acting_user['user_id'], 'document_upload', "Uploaded document #$doc_id ($type) for customer #{$customer['customer_id']}");
        respond((int) $customer['customer_id'], 'Document uploaded. Review it below.');
        break;
    }

    case 'review_document': {
        [$document, $customer] = require_document();
        $decision = $_POST['decision'] ?? '';
        $note = trim($_POST['note'] ?? '');
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            fail('Unknown decision.');
        }
        if ($decision === $document['verification_status']) {
            fail('That document is already ' . $decision . '.');
        }
        if ($decision === 'rejected') {
            if ($note === '') {
                fail('Give a reason for rejecting — the customer sees it so they know what to fix.');
            }
            guard_last_approved_document($document, $customer, 'reject');
        }

        review_document((int) $document['document_id'], $decision, (int) $acting_user['user_id'], $note);
        log_action($acting_user['user_id'], 'document_review', "Document #{$document['document_id']} (customer #{$customer['customer_id']}) $decision");

        $label = humanize($document['document_type']);
        notify_user(
            (int) $customer['user_id'],
            $decision === 'approved' ? "$label approved" : "$label needs another look",
            $decision === 'approved' ? "Your $label was approved." : "Your $label was rejected: $note",
            'documents'
        );

        $message = $decision === 'approved' ? 'Document approved.' : 'Document rejected. The customer has been told why.';
        if ($decision === 'approved' && $customer['account_status'] === 'unverified') {
            $after = get_customer((int) $customer['customer_id']);
            $message .= customer_verification_blockers($after)
                ? ''
                : ' Everything needed is in place — you can verify the customer now.';
        }
        respond((int) $customer['customer_id'], $message);
        break;
    }

    case 'delete_document': {
        if ($acting_user['role_name'] !== 'admin') {
            fail('Only an admin can delete a document. Reject it instead if it\'s wrong.', 403);
        }
        [$document, $customer] = require_document();
        guard_last_approved_document($document, $customer, 'delete');
        delete_document($document);
        log_action($acting_user['user_id'], 'document_delete', "Deleted document #{$document['document_id']} of customer #{$customer['customer_id']}");
        respond((int) $customer['customer_id'], 'Document deleted.');
        break;
    }

    default:
        fail('Unknown action.', 400);
}
