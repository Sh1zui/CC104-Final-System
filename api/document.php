<?php
/**
 * Streams one customer document file to someone allowed to see it:
 * admin/staff, or the customer who owns it. The files themselves sit in a
 * deny-all folder, so this is the only way to open them.
 *
 * GET api/document.php?id=123            -> shown inline (image/PDF viewer)
 * GET api/document.php?id=123&download=1 -> saved as a file
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/customers_data.php';

$user = require_login();

$document = get_document((int) ($_GET['id'] ?? 0));
// Same 404 for "doesn't exist" and "not yours" — don't confirm which IDs exist.
if (!$document || !can_view_document($user, $document)) {
    http_response_code(404);
    exit('Document not found.');
}

$path = realpath(dirname(__DIR__) . '/' . $document['file_path']);
$dir  = realpath(UPLOAD_DIR_DOCUMENTS);
if (!$path || !$dir || strpos($path, $dir . DIRECTORY_SEPARATOR) !== 0 || !is_file($path)) {
    http_response_code(404);
    exit('The file for this document is missing.');
}

// Only types we accepted at upload time are ever served; the stored MIME
// type came from sniffing the bytes, not from the browser.
$mime = in_array($document['mime_type'], array_keys(ALLOWED_DOCUMENT_TYPES), true)
    ? $document['mime_type']
    : 'application/octet-stream';

$disposition = isset($_GET['download']) ? 'attachment' : 'inline';
$ascii_name  = preg_replace('/[^A-Za-z0-9._-]/', '_', $document['original_name']);

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . $disposition . '; filename="' . $ascii_name . '"; filename*=UTF-8\'\'' . rawurlencode($document['original_name']));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
if (strpos($mime, 'image/') === 0) {
    // Belt and braces for images opened directly in a tab.
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; frame-ancestors 'self'");
} else {
    // PDFs: replace the site-wide policy with one that only stops other
    // sites from framing it — a stricter one can break the browser's
    // built-in PDF viewer. Scripts inside a PDF don't run in that viewer.
    header("Content-Security-Policy: frame-ancestors 'self'");
}
header('X-Frame-Options: SAMEORIGIN');

readfile($path);
