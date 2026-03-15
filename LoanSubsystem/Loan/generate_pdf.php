<?php
/**
 * generate_pdf.php
 * AJAX endpoint — always returns JSON, never HTML.
 *
 * The ONLY reason this file returns <!DOCTYPE HTML is one of:
 *   A) session_start() conflicts with another session already started by an include
 *   B) header.php or some auto_prepend_file is prepending HTML
 *   C) PHP has output_buffering=Off and a warning fires before header()
 *
 * Fix: use output_buffering at php.ini level AND output_encoding guard below.
 */

// ── 0. Prevent ANY auto-prepend or include from adding HTML ──────────────────
// If php.ini has auto_prepend_file set, this can't stop it — but the ob below catches it.

// ── 1. Session — only start if not already active ────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── 2. Kill ALL output so far (handles auto_prepend_file HTML) ───────────────
// ob_start BEFORE ini_set so even startup notices are buffered
ob_start();

// ── 3. Suppress display, log to file ─────────────────────────────────────────
ini_set('display_errors',         '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors',             '1');
ini_set('error_log',              __DIR__ . '/pdf_errors.log');
error_reporting(E_ALL);

// ── 4. Fatal-error → JSON shutdown handler ───────────────────────────────────
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error'   => 'PHP fatal: ' . $err['message'] . ' (line ' . $err['line'] . ')',
        ]);
    }
});

// ── 5. Clean any HTML that was already buffered (auto_prepend_file, etc.) ────
// Discard everything buffered so far so the response starts clean
while (ob_get_level() > 0) ob_end_clean();

// ── 6. Start a fresh buffer and force JSON content-type ──────────────────────
ob_start();
header('Content-Type: application/json; charset=utf-8');

// ── 7. JSON-error helper ──────────────────────────────────────────────────────
function jsonError(string $msg): void {
    while (ob_get_level() > 0) ob_end_clean();
    // Re-assert content-type in case something changed it
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

// ── 8. Auth ───────────────────────────────────────────────────────────────────
if (empty($_SESSION['user_email']) && empty($_SESSION['user_id'])) {
    jsonError('Session expired. Please refresh and log in again.');
}

// ── 9. FPDF ───────────────────────────────────────────────────────────────────
$fpdfPath = __DIR__ . '/fpdf/fpdf.php';
if (!file_exists($fpdfPath)) {
    jsonError('FPDF not found at fpdf/fpdf.php — place the FPDF library in the fpdf/ folder.');
}
require_once $fpdfPath;

// ── 10. Input validation ──────────────────────────────────────────────────────
$loan_id    = isset($_GET['loan_id']) ? (int)$_GET['loan_id'] : 0;
$notif_type = isset($_GET['type'])    ? trim($_GET['type'])   : '';

if ($loan_id <= 0) jsonError('Invalid loan_id parameter.');
if (!in_array($notif_type, ['approved', 'active', 'rejected'], true)) {
    jsonError('Invalid type. Must be: approved, active, or rejected.');
}

// ── 11. Database ──────────────────────────────────────────────────────────────
$conn = new mysqli('localhost', 'root', '', 'loandb');
$conn->set_charset('utf8mb4');
if ($conn->connect_error) jsonError('DB connection failed: ' . $conn->connect_error);

$stmt = $conn->prepare("
    SELECT
        la.id,
        la.loan_terms,
        la.loan_amount,
        la.monthly_payment,
        la.next_payment_due,
        la.created_at,
        COALESCE(lt.name, 'Unknown')          AS loan_type_name,
        COALESCE(lb.full_name, la.user_email) AS full_name,
        lap.approved_at,
        lr.rejected_at,
        lr.rejection_remarks
    FROM loan_applications la
    LEFT JOIN loan_types      lt  ON lt.id  = la.loan_type_id
    LEFT JOIN loan_borrowers  lb  ON lb.loan_application_id = la.id
    LEFT JOIN loan_approvals  lap ON lap.loan_application_id = la.id
    LEFT JOIN loan_rejections lr  ON lr.loan_application_id  = la.id
    WHERE la.id = ?
    LIMIT 1
");
if (!$stmt) jsonError('DB prepare failed: ' . $conn->error);

$stmt->bind_param('i', $loan_id);
if (!$stmt->execute()) jsonError('DB execute failed: ' . $stmt->error);

$result = $stmt->get_result();
if (!$result || $result->num_rows === 0) jsonError('Loan not found for ID: ' . $loan_id);

$loan = $result->fetch_assoc();
$stmt->close();
$conn->close();

if (empty($loan['full_name']))   jsonError('Borrower name missing — check loan_borrowers table for loan_application_id ' . $loan_id . '.');
if (empty($loan['loan_amount'])) jsonError('Loan amount missing for loan ID: ' . $loan_id . '.');

// ── 12. Uploads folder ────────────────────────────────────────────────────────
$uploadDir = __DIR__ . '/uploads/';
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true)) {
        jsonError('Cannot create uploads/ folder. Check server file permissions.');
    }
}
if (!is_writable($uploadDir)) {
    jsonError('uploads/ folder is not writable. Run: chmod 755 uploads/');
}

// ── 13. Build PDF ─────────────────────────────────────────────────────────────
$pdf = new FPDF('P', 'mm', 'A4');
$pdf->AddPage();
$pdf->SetAutoPageBreak(true, 25);

$pdf->SetFont('Arial', 'B', 18);
$pdf->Cell(0, 15, 'EVERGREEN TRUST AND SAVINGS', 0, 1, 'C');
$pdf->SetFont('Arial', 'B', 14);
$pdf->Cell(0, 10, 'LOAN SERVICES', 0, 1, 'C');
$pdf->Ln(8);

$titles = [
    'approved' => 'LOAN APPROVAL NOTIFICATION',
    'active'   => 'LOAN ACTIVATION NOTIFICATION',
    'rejected' => 'LOAN REJECTION NOTICE',
];
$pdf->SetFont('Arial', 'B', 14);
$pdf->Cell(0, 10, $titles[$notif_type], 0, 1, 'C');
$pdf->Ln(5);

$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 8, 'Dear ' . $loan['full_name'] . ',', 0, 1, 'L');
$pdf->Ln(3);

$pdf->SetFont('Arial', '', 11);
if ($notif_type === 'approved') {
    $body = "We are pleased to inform you that your loan application has been APPROVED!\n\n"
          . "Please visit our bank within 30 days to claim your loan.\n\n"
          . "Please bring a valid ID and be prepared to sign the loan agreement documents.";
} elseif ($notif_type === 'active') {
    $body = "Your loan has been successfully disbursed and is now ACTIVE.\n\n";
    if (!empty($loan['next_payment_due'])) {
        $body .= 'Your first payment of PHP ' . number_format($loan['monthly_payment'], 2)
               . ' is due on ' . date('F j, Y', strtotime($loan['next_payment_due'])) . ".\n\n";
    }
    $body .= "Please make your monthly payments on time to avoid penalties.";
} else {
    $reason = !empty($loan['rejection_remarks'])
        ? $loan['rejection_remarks']
        : 'Your application does not meet our current lending requirements.';
    $body = "We regret to inform you that your loan application has been REJECTED.\n\n"
          . "REASON: {$reason}\n\n"
          . "You may reapply in the future. Please contact our loan officer for details.";
}
$pdf->MultiCell(0, 6, $body, 0, 'L');
$pdf->Ln(5);

$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 10, 'LOAN DETAILS', 0, 1, 'L');

$statusMap = ['approved' => 'Approved - Awaiting Claim', 'active' => 'Active', 'rejected' => 'Rejected'];
$rows = [
    'Loan ID'              => $loan['id'],
    'Loan Type'            => $loan['loan_type_name'],
    'Loan Amount'          => 'PHP ' . number_format($loan['loan_amount'], 2),
    'Interest Rate'        => '20% per annum',
    'Monthly Payment'      => 'PHP ' . number_format($loan['monthly_payment'] ?? 0, 2),
    'Total Amount Payable' => 'PHP ' . number_format($loan['loan_amount'] * 1.20, 2),
    'Status'               => $statusMap[$notif_type],
];
foreach ($rows as $lbl => $val) {
    $pdf->SetFont('Arial', 'B', 10); $pdf->Cell(65, 7, $lbl . ':', 0, 0, 'L');
    $pdf->SetFont('Arial', '', 10);  $pdf->Cell(0,  7, $val,        0, 1, 'L');
}
$pdf->Ln(5);

$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 10, 'TIME DURATION', 0, 1, 'L');

if (in_array($notif_type, ['approved', 'active']) && !empty($loan['approved_at'])) {
    $pdf->SetFont('Arial', 'B', 10); $pdf->Cell(65, 7, 'Date Approved:', 0, 0, 'L');
    $pdf->SetFont('Arial', '', 10);  $pdf->Cell(0, 7, date('F j, Y', strtotime($loan['approved_at'])), 0, 1, 'L');
}
if (!empty($loan['loan_terms'])) {
    $pdf->SetFont('Arial', 'B', 10); $pdf->Cell(65, 7, 'Loan Duration:', 0, 0, 'L');
    $pdf->SetFont('Arial', '', 10);  $pdf->Cell(0,  7, $loan['loan_terms'], 0, 1, 'L');
}
if ($notif_type === 'approved' && !empty($loan['approved_at'])) {
    $pdf->SetFont('Arial', 'B', 10); $pdf->Cell(65, 7, 'Claim Deadline:', 0, 0, 'L');
    $pdf->SetFont('Arial', '', 10);  $pdf->Cell(0,  7, date('F j, Y', strtotime($loan['approved_at'] . ' +30 days')), 0, 1, 'L');
} elseif ($notif_type === 'active') {
    if (!empty($loan['loan_terms'])) {
        $tm = (int)filter_var($loan['loan_terms'], FILTER_SANITIZE_NUMBER_INT);
        $pdf->SetFont('Arial', 'B', 10); $pdf->Cell(65, 7, 'Final Payment Date:', 0, 0, 'L');
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(0, 7, date('F j, Y', strtotime($loan['created_at'] . " +{$tm} months")), 0, 1, 'L');
    }
    if (!empty($loan['next_payment_due'])) {
        $pdf->SetFont('Arial', 'B', 10); $pdf->Cell(65, 7, 'Next Payment Due:', 0, 0, 'L');
        $pdf->SetFont('Arial', '', 10);  $pdf->Cell(0,  7, date('F j, Y', strtotime($loan['next_payment_due'])), 0, 1, 'L');
    }
} elseif ($notif_type === 'rejected' && !empty($loan['rejected_at'])) {
    $pdf->SetFont('Arial', 'B', 10); $pdf->Cell(65, 7, 'Rejection Date:', 0, 0, 'L');
    $pdf->SetFont('Arial', '', 10);  $pdf->Cell(0,  7, date('F j, Y', strtotime($loan['rejected_at'])), 0, 1, 'L');
}

$pdf->Ln(10);
$pdf->SetFont('Arial', 'I', 10);
$pdf->MultiCell(0, 6,
    $notif_type === 'rejected'
        ? 'We appreciate your interest. Please contact us if you have any questions.'
        : 'Thank you for choosing Evergreen Trust and Savings. We are committed to excellent financial services.',
    0, 'C');
$pdf->Ln(3);
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(0, 5, 'support@evergreenbank.com | 1-800-EVERGREEN', 0, 1, 'C');
$pdf->SetY(-18);
$pdf->SetFont('Arial', 'I', 8);
$pdf->Cell(0, 10, 'Generated by Evergreen Trust and Savings - ' . date('Y-m-d H:i:s'), 0, 0, 'C');

// ── 14. Save PDF ──────────────────────────────────────────────────────────────
$filename = 'loan_' . $notif_type . '_' . $loan_id . '_' . time() . '.pdf';
$savePath = $uploadDir . $filename;

// CRITICAL: flush ALL buffers before FPDF writes the file
// If any buffer remains open, FPDF Output('F') may fail silently
while (ob_get_level() > 0) ob_end_clean();

$pdf->Output('F', $savePath);

if (!file_exists($savePath) || filesize($savePath) === 0) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'PDF was not created. Check uploads/ permissions (chmod 755 uploads/).']);
    exit;
}

// ── 15. Respond with JSON ─────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success'  => true,
    'filename' => $filename,
    'filesize' => filesize($savePath),
    'type'     => $notif_type,
    'loan_id'  => $loan_id,
    'message'  => 'PDF generated successfully',
]);
exit;