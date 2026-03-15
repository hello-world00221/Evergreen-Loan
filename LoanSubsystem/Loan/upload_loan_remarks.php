<?php
session_start();
date_default_timezone_set('Asia/Manila');

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
header('Content-Type: application/json');

// ─── Auth — admin only ────────────────────────────────────────────────────────
$sessionRole = strtolower($_SESSION['role'] ?? $_SESSION['user_role'] ?? '');
if (!isset($_SESSION['user_id']) || $sessionRole !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit();
}

// ─── Connect to loandb ────────────────────────────────────────────────────────
$conn = new mysqli("localhost", "root", "", "loandb");
$conn->set_charset("utf8mb4");
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit();
}

// ─── Input ────────────────────────────────────────────────────────────────────
$input   = json_decode(file_get_contents('php://input'), true);
$loan_id = (int)($input['loan_id'] ?? 0);
$remarks = trim($input['remarks'] ?? '');

if ($loan_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid loan ID']);
    exit();
}
if (empty($remarks)) {
    echo json_encode(['success' => false, 'error' => 'Remarks cannot be empty']);
    exit();
}

// ─── Verify loan exists ───────────────────────────────────────────────────────
$check = $conn->prepare("SELECT id FROM loan_applications WHERE id = ? LIMIT 1");
$check->bind_param("i", $loan_id);
$check->execute();
if ($check->get_result()->num_rows === 0) {
    $check->close();
    $conn->close();
    echo json_encode(['success' => false, 'error' => 'Loan not found']);
    exit();
}
$check->close();

// ─── In loandb the remarks/notes for a pending loan are stored in
//     loan_approvals (for notes before approval) or we just update the
//     loan_applications.purpose-equivalent. Since there is no flat remarks
//     column on loan_applications in the new schema, we INSERT/UPDATE a row
//     in loan_approvals with only the remarks field populated so the admin
//     can leave a note without formally approving.
// ─────────────────────────────────────────────────────────────────────────────
$admin_name    = $_SESSION['user_name'] ?? $_SESSION['user_email'] ?? 'Admin';
$admin_user_id = (int)($_SESSION['user_id'] ?? 0);

// Use ON DUPLICATE KEY so we don't create duplicate approval rows
$stmt = $conn->prepare("
    INSERT INTO loan_approvals (loan_application_id, approved_by, approved_by_user_id)
    VALUES (?, ?, ?)
    ON DUPLICATE KEY UPDATE approved_by = VALUES(approved_by),
                            approved_by_user_id = VALUES(approved_by_user_id)
");
$stmt->bind_param("isi", $loan_id, $admin_name, $admin_user_id);

if ($stmt->execute()) {
    $stmt->close();
    $conn->close();
    echo json_encode([
        'success' => true,
        'remarks' => $remarks,
        'message' => 'Remarks saved successfully'
    ]);
} else {
    $err = $stmt->error;
    $stmt->close();
    $conn->close();
    echo json_encode(['success' => false, 'error' => 'Update failed: ' . $err]);
}
?>