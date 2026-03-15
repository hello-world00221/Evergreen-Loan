<?php
// check_loan.php — Diagnostic script for loandb normalized tables

// ─── Connect to loandb ────────────────────────────────────────────────────────
$conn = new mysqli("localhost", "root", "", "loandb");
$conn->set_charset("utf8mb4");

if ($conn->connect_error) {
    die("❌ DB connection failed: " . $conn->connect_error . "\n");
}

echo "✓ Connected to loandb\n";
echo str_repeat("-", 80) . "\n";

// ─── Check all expected tables exist ─────────────────────────────────────────
echo "\nChecking loandb tables...\n";

$expectedTables = [
    'loan_applications',
    'loan_types',
    'loan_borrowers',
    'loan_valid_id',
    'loan_valid_ids',
    'loan_approvals',
    'loan_rejections',
    'loan_documents',
];

foreach ($expectedTables as $table) {
    $check = $conn->query("SHOW TABLES LIKE '$table'");
    if ($check && $check->num_rows > 0) {
        echo "  ✓ $table exists\n";
    } else {
        echo "  ✗ $table NOT found\n";
    }
}

echo str_repeat("-", 80) . "\n";

// ─── Recent loan applications — JOIN all normalized tables ────────────────────
// full_name     → loan_borrowers
// loan_type     → loan_types
// approved_at   → loan_approvals
// rejected_at   → loan_rejections
echo "\nRecent loan applications (last 5):\n";
echo str_repeat("-", 80) . "\n";

$result = $conn->query("
    SELECT
        la.id,
        la.user_email,
        la.loan_amount,
        la.loan_terms,
        la.monthly_payment,
        la.status,
        la.created_at,
        la.next_payment_due,

        COALESCE(lt.name, 'Unknown Type')         AS loan_type,
        COALESCE(lb.full_name, la.user_email)     AS full_name,
        lb.account_number,
        lb.contact_number,
        lb.email                                  AS borrower_email,

        lvi.valid_id_type,
        lvid.valid_id_number,

        lap.approved_at,
        lap.approved_by,

        lr.rejected_at,
        lr.rejection_remarks,

        ld.file_name,
        ld.proof_of_income,
        ld.coe_document

    FROM loan_applications la
    LEFT JOIN loan_types     lt   ON lt.id  = la.loan_type_id
    LEFT JOIN loan_borrowers lb   ON lb.loan_application_id = la.id
    LEFT JOIN loan_valid_ids lvid ON lvid.loan_application_id = la.id
    LEFT JOIN loan_valid_id  lvi  ON lvi.id = lvid.loan_valid_id_type
    LEFT JOIN loan_approvals lap  ON lap.loan_application_id = la.id
    LEFT JOIN loan_rejections lr  ON lr.loan_application_id  = la.id
    LEFT JOIN loan_documents  ld  ON ld.loan_application_id  = la.id
    ORDER BY la.id DESC
    LIMIT 5
");

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo sprintf(
            "ID: %d | Name: %-25s | Type: %-18s | Amount: %10.2f | Status: %-10s | Date: %s\n",
            $row['id'],
            $row['full_name'],
            $row['loan_type'],
            $row['loan_amount'],
            $row['status'],
            $row['created_at']
        );
        echo sprintf(
            "        Email: %-30s | Account: %-15s | Contact: %s\n",
            $row['borrower_email'] ?? $row['user_email'],
            $row['account_number'] ?? 'N/A',
            $row['contact_number'] ?? 'N/A'
        );
        echo sprintf(
            "        Valid ID: %-20s | ID No: %-20s | Terms: %s\n",
            $row['valid_id_type']   ?? 'N/A',
            $row['valid_id_number'] ?? 'N/A',
            $row['loan_terms']      ?? 'N/A'
        );
        echo sprintf(
            "        Monthly: %10.2f | Next Due: %-12s | Approved By: %s\n",
            $row['monthly_payment'] ?? 0,
            $row['next_payment_due'] ?? 'N/A',
            $row['approved_by'] ?? 'N/A'
        );
        if (!empty($row['rejected_at'])) {
            echo sprintf(
                "        Rejected At: %-20s | Reason: %s\n",
                $row['rejected_at'],
                $row['rejection_remarks'] ?? 'N/A'
            );
        }
        echo str_repeat("-", 80) . "\n";
    }
} else {
    echo "No loan applications found.\n";
}

// ─── Row counts per table ─────────────────────────────────────────────────────
echo "\nRow counts per table:\n";
echo str_repeat("-", 80) . "\n";

foreach ($expectedTables as $table) {
    $countResult = $conn->query("SELECT COUNT(*) AS total FROM `$table`");
    if ($countResult) {
        $countRow = $countResult->fetch_assoc();
        echo sprintf("  %-25s → %d row(s)\n", $table, $countRow['total']);
    }
}

// ─── Status breakdown ─────────────────────────────────────────────────────────
echo "\nLoan status breakdown:\n";
echo str_repeat("-", 80) . "\n";

$statusResult = $conn->query("SELECT status, COUNT(*) AS total FROM loan_applications GROUP BY status ORDER BY total DESC");
if ($statusResult && $statusResult->num_rows > 0) {
    while ($row = $statusResult->fetch_assoc()) {
        echo sprintf("  %-15s → %d loan(s)\n", $row['status'], $row['total']);
    }
} else {
    echo "  No data.\n";
}

// ─── Valid ID types available ─────────────────────────────────────────────────
echo "\nAvailable Valid ID types (loan_valid_id):\n";
echo str_repeat("-", 80) . "\n";

$vidResult = $conn->query("SELECT id, valid_id_type FROM loan_valid_id ORDER BY valid_id_type");
if ($vidResult && $vidResult->num_rows > 0) {
    while ($row = $vidResult->fetch_assoc()) {
        echo sprintf("  ID: %d | %s\n", $row['id'], $row['valid_id_type']);
    }
} else {
    echo "  No valid ID types found — add rows to loan_valid_id table.\n";
}

// ─── Loan types available ─────────────────────────────────────────────────────
echo "\nAvailable Loan types (loan_types):\n";
echo str_repeat("-", 80) . "\n";

$ltResult = $conn->query("SELECT id, name, is_active FROM loan_types ORDER BY name");
if ($ltResult && $ltResult->num_rows > 0) {
    while ($row = $ltResult->fetch_assoc()) {
        $activeLabel = $row['is_active'] ? '✓ active' : '✗ inactive';
        echo sprintf("  ID: %d | %-25s | %s\n", $row['id'], $row['name'], $activeLabel);
    }
} else {
    echo "  No loan types found — add rows to loan_types table.\n";
}

$conn->close();
echo "\n✓ Check complete.\n";
?>