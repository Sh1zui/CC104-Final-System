<?php
/**
 * HTML fragments shared by admin/customers.php (first load),
 * api/customers.php (after any AJAX change), and customer/documents.php,
 * so a customer row and a document list each have exactly one template.
 */

require_once __DIR__ . '/functions.php';       // e(), status_badge_class(), humanize()
require_once __DIR__ . '/customers_data.php';  // customer_documents(), license_is_expired(), format_bytes()

const CUSTOMER_TABLE_COLUMNS = 7;

function render_customer_rows(array $customers): string
{
    if (!$customers) {
        return '<tr class="empty-row"><td colspan="' . CUSTOMER_TABLE_COLUMNS . '">'
            . 'No customers yet. Click "Add customer" to register one at the desk.</td></tr>';
    }

    $html = '';
    foreach ($customers as $c) {
        $search = strtolower(implode(' ', [
            $c['full_name'], $c['username'], $c['email'], $c['phone'] ?? '', $c['license_number'] ?? '', $c['city'] ?? '',
        ]));
        $pending = (int) $c['pending_documents'];

        if (empty($c['license_expiry'])) {
            $license_state = 'missing';
        } elseif (license_is_expired($c['license_expiry'])) {
            $license_state = 'expired';
        } else {
            $license_state = 'valid';
        }

        $html .= '<tr data-search="' . e($search) . '" data-status="' . e($c['account_status']) . '"'
            . ' data-docs="' . ($pending > 0 ? 'pending' : 'none') . '" data-license="' . $license_state . '">';

        $html .= '<td><div class="fw-semibold">' . e($c['full_name']) . '</div>'
            . '<div class="cell-sub mono">' . e($c['username']) . '</div></td>';

        $html .= '<td>' . e($c['email']) . '<div class="cell-sub">' . e($c['phone'] ?: '—') . '</div></td>';

        $html .= '<td data-value="' . e($c['license_expiry'] ?? '') . '">';
        if ($license_state === 'missing') {
            $html .= '<span class="text-secondary">Not on file</span>';
        } else {
            $html .= '<span class="mono">' . e($c['license_number'] ?: '—') . '</span>'
                . '<div class="cell-sub' . ($license_state === 'expired' ? ' text-danger fw-semibold' : '') . '">'
                . ($license_state === 'expired' ? 'Expired ' : 'Expires ') . e(date('M j, Y', strtotime($c['license_expiry']))) . '</div>';
        }
        $html .= '</td>';

        $html .= '<td data-value="' . $pending . '">'
            . ($pending > 0
                ? '<span class="status-badge status-warning">' . $pending . ' to review</span>'
                : '<span class="text-secondary">' . (int) $c['approved_documents'] . ' approved</span>')
            . '</td>';

        $html .= '<td class="mono" data-value="' . (int) $c['booking_count'] . '">' . (int) $c['booking_count'] . '</td>';

        $html .= '<td data-value="' . e($c['account_status']) . '"><span class="status-badge ' . e(status_badge_class($c['account_status'])) . '">'
            . e($c['account_status']) . '</span>'
            . ($c['login_status'] !== 'active' ? '<div class="cell-sub">login ' . e($c['login_status']) . '</div>' : '')
            . '</td>';

        $html .= '<td class="text-end"><button type="button" class="btn btn-sm btn-outline-secondary" data-action="open" data-id="'
            . (int) $c['customer_id'] . '">Open</button></td>';
        $html .= '</tr>';
    }

    return $html;
}

/**
 * The document list. $mode is 'review' (staff/admin: approve, reject,
 * delete any) or 'owner' (the customer: view, and delete their own while
 * it's still pending or was rejected).
 */
function render_document_list(int $customer_id, string $mode = 'review'): string
{
    $documents = customer_documents($customer_id);
    if (!$documents) {
        return '<p class="text-secondary small mb-0">No documents uploaded yet.</p>';
    }

    $html = '<ul class="doc-list">';
    foreach ($documents as $d) {
        $id     = (int) $d['document_id'];
        $status = $d['verification_status'];
        $icon   = $d['mime_type'] === 'application/pdf' ? 'bi-file-earmark-pdf' : 'bi-file-earmark-image';
        $url    = BASE_URL . 'api/document.php?id=' . $id;

        $html .= '<li class="doc-item" data-document-id="' . $id . '">';
        $html .= '<div class="doc-icon"><i class="bi ' . $icon . '"></i></div>';
        $html .= '<div class="doc-main">';
        $html .= '<div class="doc-title">' . e(humanize($d['document_type']))
            . ' <span class="status-badge ' . e(status_badge_class($status)) . '">' . e($status) . '</span></div>';
        $html .= '<div class="cell-sub"><a href="' . e($url) . '" target="_blank" rel="noopener">' . e($d['original_name']) . '</a>'
            . ' &middot; ' . e(format_bytes((int) $d['file_size']))
            . ' &middot; uploaded ' . e(date('M j, Y g:i A', strtotime($d['uploaded_at']))) . '</div>';

        if ($status !== 'pending') {
            $html .= '<div class="cell-sub">' . ($status === 'approved' ? 'Approved' : 'Rejected')
                . ($mode === 'review' && $d['reviewer_name'] ? ' by ' . e($d['reviewer_name']) : '')
                . ($d['reviewed_at'] ? ' on ' . e(date('M j, Y', strtotime($d['reviewed_at']))) : '') . '</div>';
        }
        if ($d['review_note']) {
            $html .= '<div class="doc-note">' . e($d['review_note']) . '</div>';
        }
        $html .= '</div>';

        $html .= '<div class="doc-actions">';
        if ($mode === 'review') {
            if ($status !== 'approved') {
                $html .= '<button type="button" class="btn btn-sm btn-outline-success" data-doc-action="approve" data-document-id="' . $id . '">Approve</button>';
            }
            if ($status !== 'rejected') {
                $html .= '<button type="button" class="btn btn-sm btn-outline-danger" data-doc-action="reject" data-document-id="' . $id . '">Reject</button>';
            }
            if ((current_user()['role_name'] ?? '') === 'admin') {
                $html .= '<button type="button" class="btn btn-sm btn-outline-secondary" data-doc-action="delete" data-document-id="' . $id . '" title="Delete document" aria-label="Delete document"><i class="bi bi-trash"></i></button>';
            }
        } elseif ($status !== 'approved') {
            $html .= '<form method="post" class="d-inline" data-confirm="Delete this document?">'
                . csrf_field()
                . '<input type="hidden" name="action" value="delete">'
                . '<input type="hidden" name="document_id" value="' . $id . '">'
                . '<button type="submit" class="btn btn-sm btn-outline-secondary" aria-label="Delete document"><i class="bi bi-trash"></i></button></form>';
        }
        $html .= '</div>';

        if ($mode === 'review') {
            $html .= '<div class="doc-reject-form d-none" data-reject-form="' . $id . '">'
                . '<label class="form-label small mb-1" for="reject-note-' . $id . '">Reason (the customer will see this)</label>'
                . '<div class="d-flex gap-2">'
                . '<input type="text" class="form-control form-control-sm" id="reject-note-' . $id . '" maxlength="255" placeholder="e.g. Photo is blurry — please re-upload">'
                . '<button type="button" class="btn btn-sm btn-danger" data-doc-action="reject-confirm" data-document-id="' . $id . '">Reject</button>'
                . '<button type="button" class="btn btn-sm btn-outline-secondary" data-doc-action="reject-cancel" data-document-id="' . $id . '">Cancel</button>'
                . '</div></div>';
        }

        $html .= '</li>';
    }
    $html .= '</ul>';

    return $html;
}
