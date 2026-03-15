<?php
session_start();
header('Content-Type: application/json');

// ─── Auth check ───────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_email'])) {
    http_response_code(403);
    exit(json_encode(['error' => 'Not logged in']));
}

// ─── Connect to loandb ────────────────────────────────────────────────────────
$conn = new mysqli("localhost", "root", "", "loandb");
$conn->set_charset("utf8mb4");
if ($conn->connect_error) {
    exit(json_encode(['error' => 'DB error: ' . $conn->connect_error]));
}

// ─── Input ────────────────────────────────────────────────────────────────────
$data         = json_decode(file_get_contents('php://input'), true);
$loan_id      = (int)($data['loan_id']  ?? 0);
$pdf_filename = trim($data['pdf_path']  ?? ''); // just the filename, no path prefix
$pdf_type     = trim($data['type']      ?? '');

if ($loan_id <= 0 || empty($pdf_filename) || empty($pdf_type)) {
    exit(json_encode(['error' => 'Invalid input — missing loan_id, pdf_path, or type']));
}

if (!in_array($pdf_type, ['approved', 'active', 'rejected'])) {
    exit(json_encode(['error' => 'Invalid type. Must be: approved, active, or rejected']));
}

// ─── Ownership check — email is in loan_borrowers ─────────────────────────────
$own = $conn->prepare("
    SELECT lb.email
    FROM loan_applications la
    LEFT JOIN loan_borrowers lb ON lb.loan_application_id = la.id
    WHERE la.id = ?
    LIMIT 1
");
$own->bind_param("i", $loan_id);
$own->execute();
$own_result = $own->get_result();

if ($own_result->num_rows === 0) {
    $own->close(); $conn->close();
    exit(json_encode(['error' => 'Loan not found']));
}

$own_row       = $own_result->fetch_assoc();
$borrower_email = $own_row['email'] ?? null;
$own->close();

// Allow if email matches session OR if borrower email is null (loan not yet completed)
if ($borrower_email !== null && $borrower_email !== $_SESSION['user_email']) {
    $conn->close();
    exit(json_encode(['error' => 'Unauthorized']));
}

// ─── Build full path and verify file exists ───────────────────────────────────
$full_pdf_path = 'uploads/' . basename($pdf_filename);

if (!file_exists($full_pdf_path)) {
    $conn->close();
    exit(json_encode(['error' => 'PDF file not found on server: ' . $full_pdf_path]));
}

// ─── Map type to column in loan_documents ─────────────────────────────────────
// loan_documents columns: id, loan_application_id, file_name,
//                         proof_of_income, coe_document,
//                         pdf_approved, pdf_active, pdf_rejected
$column_map = [
    'approved' => 'pdf_approved',
    'active'   => 'pdf_active',
    'rejected' => 'pdf_rejected',
];
$column = $column_map[$pdf_type];

// ─── Upsert into loan_documents ───────────────────────────────────────────────
// If a loan_documents row already exists for this loan, update just the PDF column.
// If it doesn't exist yet, insert a minimal row with the PDF column populated.
$sql = "
    INSERT INTO loan_documents (loan_application_id, {$column})
    VALUES (?, ?)
    ON DUPLICATE KEY UPDATE {$column} = VALUES({$column})
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    $conn->close();
    exit(json_encode(['error' => 'Prepare failed: ' . $conn->error]));
}

$stmt->bind_param("is", $loan_id, $full_pdf_path);

if ($stmt->execute()) {
    $stmt->close();
    $conn->close();
    exit(json_encode([
        'success'  => true,
        'type'     => $pdf_type,
        'column'   => $column,
        'pdf_path' => $full_pdf_path,
        'message'  => 'PDF path updated successfully'
    ]));
} else {
    $err = $stmt->error;
    $stmt->close();
    $conn->close();
    exit(json_encode(['error' => 'Update failed: ' . $err]));
}
?>