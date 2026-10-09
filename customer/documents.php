<?php
/**
 * Customer-facing: upload ID documents for verification, see each one's
 * review status (and the reason, if rejected), and remove ones that
 * haven't been approved. Plain form posts (POST → redirect → GET), so it
 * works without JavaScript.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/customers_data.php';
require_once __DIR__ . '/../includes/customer_partial.php';

$user = require_role('customer');
$customer = get_customer_by_user_id((int) $user['user_id']);
if (!$customer) {
    // Every customer login gets a profile at registration; this only
    // happens if data was edited by hand.
    http_response_code(500);
    exit('Your customer profile is missing. Please contact the rental desk.');
}
$customer_id = (int) $customer['customer_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        flash('error', 'Your session expired. Please try again.');
        redirect('customer/documents.php');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'upload') {
        if ($customer['account_status'] === 'blocked') {
            flash('error', 'Your account is on hold. Please contact the rental desk.');
            redirect('customer/documents.php');
        }
        if ((int) $customer['pending_documents'] >= MAX_PENDING_DOCUMENTS) {
            flash('error', 'You already have ' . MAX_PENDING_DOCUMENTS . ' documents waiting for review. Please wait for staff to check those first.');
            redirect('customer/documents.php');
        }
        $type = $_POST['document_type'] ?? '';
        if (!in_array($type, DOCUMENT_TYPES, true)) {
            flash('error', 'Choose what kind of document you are uploading.');
            redirect('customer/documents.php');
        }
        $stored = store_document_upload($_FILES['document'] ?? null, $customer_id);
        if (isset($stored['error'])) {
            flash('error', $stored['error']);
            redirect('customer/documents.php');
        }
        $doc_id = add_document($customer_id, $type, $stored, (int) $user['user_id']);
        log_action((int) $user['user_id'], 'document_upload', "Customer uploaded document #$doc_id ($type)");
        notify_reviewers('Document to review', $user['full_name'] . ' uploaded a ' . strtolower(humanize($type)) . ' for verification.', 'customer:' . $customer_id);
        flash('success', 'Uploaded. Staff will review it shortly.');
        redirect('customer/documents.php');
    }

    if ($action === 'delete') {
        $document = get_document((int) ($_POST['document_id'] ?? 0));
        // Not theirs, or gone: same message — don't reveal other customers' document IDs.
        if (!$document || (int) $document['customer_id'] !== $customer_id) {
            flash('error', 'That document could not be found.');
        } elseif ($document['verification_status'] === 'approved') {
            flash('error', 'Approved documents can\'t be removed here. Contact the rental desk if it needs replacing.');
        } else {
            delete_document($document);
            log_action((int) $user['user_id'], 'document_delete', "Customer deleted their document #{$document['document_id']}");
            flash('success', 'Document removed.');
        }
        redirect('customer/documents.php');
    }

    flash('error', 'Unknown action.');
    redirect('customer/documents.php');
}

$status_note = [
    'verified'   => 'You\'re verified. You can still add documents here if the desk asks for one.',
    'unverified' => 'Upload a clear photo or scan of your driver\'s license (and a second ID if you have one), and add your license number and expiry on your Profile. Staff check both, then verify your account for booking.',
    'blocked'    => 'Your account is on hold, so uploads are paused. Please contact the rental desk.',
];

$page_title = 'My documents';
$active_nav = 'documents';

require __DIR__ . '/../includes/header.php';
?>

<div style="max-width: 760px;">
    <section class="panel mb-3">
        <div class="panel-header">
            Verification
            <span class="status-badge <?= e(status_badge_class($customer['account_status'])) ?>"><?= e($customer['account_status']) ?></span>
        </div>
        <div class="panel-body">
            <p class="mb-0 text-secondary"><?= e($status_note[$customer['account_status']] ?? '') ?></p>
        </div>
    </section>

    <?php if ($customer['account_status'] !== 'blocked'): ?>
    <section class="panel mb-3">
        <div class="panel-header">Upload a document</div>
        <div class="panel-body">
            <form method="post" enctype="multipart/form-data" class="row g-3 align-items-end">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="upload">
                <div class="col-sm-5">
                    <label class="form-label" for="document_type">Document type</label>
                    <select class="form-select" id="document_type" name="document_type" required>
                        <?php foreach (DOCUMENT_TYPES as $t): ?>
                            <option value="<?= e($t) ?>"><?= e(humanize($t)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-7">
                    <label class="form-label" for="document">File</label>
                    <input class="form-control" type="file" id="document" name="document" accept="image/jpeg,image/png,image/webp,application/pdf" required>
                </div>
                <div class="col-12 d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <span class="text-secondary small">JPEG, PNG, WebP, or PDF, up to 5&nbsp;MB. Make sure all four corners and the text are readable.</span>
                    <button type="submit" class="btn btn-brand"><i class="bi bi-upload me-1"></i>Upload</button>
                </div>
            </form>
        </div>
    </section>
    <?php endif; ?>

    <section class="panel">
        <div class="panel-header">Your documents</div>
        <div class="panel-body">
            <?= render_document_list($customer_id, 'owner') ?>
        </div>
    </section>
</div>

<script>
    // Plain-HTML forms with an "are you sure?" — uses the shared dialog.
    document.addEventListener('submit', function (e) {
        var form = e.target;
        var message = form.getAttribute('data-confirm');
        if (!message || form.dataset.confirmed) return;
        e.preventDefault();
        AutoWay.confirm(message, { confirmText: 'Delete', confirmClass: 'btn-danger' }).then(function (ok) {
            if (!ok) return;
            form.dataset.confirmed = '1';
            form.submit();
        });
    });
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
