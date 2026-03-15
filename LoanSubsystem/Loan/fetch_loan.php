<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

// ─── Authentication check ─────────────────────────────────────────────────────
// Supports both direct loan-system login and marketing-system session bridge
if (!isset($_SESSION['user_email'])) {
    if (isset($_SESSION['user_id']) && isset($_SESSION['email'])) {
        $_SESSION['user_email'] = $_SESSION['email'];
        $_SESSION['user_name']  = $_SESSION['full_name'] ?? (($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
        $_SESSION['user_role']  = 'client';
    } else {
        echo json_encode(['error' => 'Not authenticated. Please log in.']);
        exit;
    }
}

// ─── Connect to loandb ────────────────────────────────────────────────────────
$host   = "localhost";
$dbuser = "root";
$dbpass = "";
$dbname = "loandb";

$conn = new mysqli($host, $dbuser, $dbpass, $dbname);
$conn->set_charset("utf8mb4");

if ($conn->connect_error) {
    echo json_encode(['error' => 'Database connection failed: ' . $conn->connect_error]);
    exit;
}

$email = $_SESSION['user_email'];

// ─── Main query ───────────────────────────────────────────────────────────────
// loan_applications  → core loan data
// loan_types         → readable loan type name  (via loan_type_id)
// loan_valid_id      → readable valid ID type   (via loan_valid_id_type → id)
// loan_borrowers     → borrower profile fields  (full_name, account_number, etc.)
// loan_approvals     → approved_at, approved_by, remarks
// loan_rejections    → rejected_at, rejected_by, rejection_remarks
// loan_documents     → file attachments + pdf paths
// loan_valid_ids     → stored valid ID number
// ─────────────────────────────────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT
        -- Core application (only columns confirmed in loan_applications schema)
        la.id,
        la.user_id,
        la.user_email,
        la.loan_type_id,
        la.loan_terms,
        la.loan_amount,
        la.monthly_payment,
        la.purpose,
        la.status,
        la.next_payment_due,
        la.created_at,

        -- Loan type name (from loan_types → id, name)
        lt.name                             AS loan_type,

        -- Valid ID type label
        -- loan_valid_ids stores loan_valid_id_type (FK) + valid_id_number
        -- loan_valid_id stores the label in valid_id_type column
        lvi.valid_id_type                   AS valid_id_type,
        lvid.valid_id_number                AS valid_id_number,

        -- Borrower profile (from loan_borrowers)
        lb.full_name,
        lb.account_number,
        lb.contact_number,
        lb.email                            AS borrower_email,
        lb.job,
        lb.monthly_salary,

        -- Approval data (from loan_approvals)
        -- Columns: id, loan_application_id, approved_by, approved_by_user_id, approved_at
        lap.approved_at,
        lap.approved_by,
        lap.approved_by_user_id,

        -- Rejection data (from loan_rejections)
        -- Columns: id, loan_application_id, rejected_by, rejected_by_user_id, rejected_at, rejection_remarks
        lr.rejected_at,
        lr.rejected_by,
        lr.rejection_remarks,

        -- Documents + PDF paths (from loan_documents)
        ld.file_name,
        ld.proof_of_income,
        ld.coe_document,
        ld.pdf_approved,
        ld.pdf_active,
        ld.pdf_rejected

    FROM loan_applications la

    -- loan_types: id, name (+ other cols)
    LEFT JOIN loan_types lt
        ON lt.id = la.loan_type_id

    -- loan_valid_ids: id, loan_application_id, loan_valid_id_type, valid_id_number
    LEFT JOIN loan_valid_ids lvid
        ON lvid.loan_application_id = la.id

    -- loan_valid_id: id, valid_id_type (the label table)
    -- Join through loan_valid_ids.loan_valid_id_type → loan_valid_id.id
    LEFT JOIN loan_valid_id lvi
        ON lvi.id = lvid.loan_valid_id_type

    -- loan_borrowers: id, loan_application_id, full_name, account_number, contact_number, email, job, monthly_salary
    LEFT JOIN loan_borrowers lb
        ON lb.loan_application_id = la.id

    -- loan_approvals: id, loan_application_id, approved_by, approved_by_user_id, approved_at
    LEFT JOIN loan_approvals lap
        ON lap.loan_application_id = la.id

    -- loan_rejections: id, loan_application_id, rejected_by, rejected_by_user_id, rejected_at, rejection_remarks
    LEFT JOIN loan_rejections lr
        ON lr.loan_application_id = la.id

    -- loan_documents: id, loan_application_id, + file/pdf columns
    LEFT JOIN loan_documents ld
        ON ld.loan_application_id = la.id

    WHERE la.user_email = ?
    ORDER BY la.id DESC
");

if (!$stmt) {
    echo json_encode(['error' => 'Query prepare failed: ' . $conn->error]);
    $conn->close();
    exit;
}

$stmt->bind_param("s", $email);

if (!$stmt->execute()) {
    echo json_encode(['error' => 'Query execute failed: ' . $stmt->error]);
    $stmt->close();
    $conn->close();
    exit;
}

$result = $stmt->get_result();
$loans  = [];

while ($row = $result->fetch_assoc()) {
    // Fallback values so the dashboard JS never gets null/undefined
    $row['loan_type']         = $row['loan_type']         ?? 'Unknown Loan Type';
    $row['loan_amount']       = $row['loan_amount']       ?? '0.00';
    $row['monthly_payment']   = $row['monthly_payment']   ?? '0.00';
    $row['valid_id_type']     = $row['valid_id_type']     ?? 'N/A';
    $row['valid_id_number']   = $row['valid_id_number']   ?? 'N/A';
    $row['full_name']         = $row['full_name']         ?? '';
    $row['account_number']    = $row['account_number']    ?? '';
    $row['contact_number']    = $row['contact_number']    ?? '';
    // borrower_email aliased to avoid clash with la.user_email
    $row['email']             = $row['borrower_email']    ?? $email;
    $row['job']               = $row['job']               ?? '';
    $row['monthly_salary']    = $row['monthly_salary']    ?? '0.00';
    // loan_approvals has no 'remarks' column — omitted
    $row['approved_at']       = $row['approved_at']       ?? '';
    $row['approved_by']       = $row['approved_by']       ?? '';
    $row['rejection_remarks'] = $row['rejection_remarks'] ?? '';
    $row['rejected_at']       = $row['rejected_at']       ?? '';
    $row['rejected_by']       = $row['rejected_by']       ?? '';
    $row['file_name']         = $row['file_name']         ?? '';
    $row['proof_of_income']   = $row['proof_of_income']   ?? '';
    $row['coe_document']      = $row['coe_document']      ?? '';
    $row['pdf_approved']      = $row['pdf_approved']      ?? '';
    $row['pdf_active']        = $row['pdf_active']        ?? '';
    $row['pdf_rejected']      = $row['pdf_rejected']      ?? '';

    $loans[] = $row;
}

$stmt->close();
$conn->close();

echo json_encode($loans);
exit;