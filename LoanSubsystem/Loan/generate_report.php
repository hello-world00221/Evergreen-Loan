<?php
require_once('fpdf/fpdf.php');

// ─── Chart image from POST ────────────────────────────────────────────────────
$chartImageData = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $chartImageData = $input['chartImage'] ?? null;
}

// ─── Validate report type ─────────────────────────────────────────────────────
if (!isset($_GET['type']) || !in_array($_GET['type'], ['all','active','approved','pending','rejected','closed'])) {
    echo json_encode(['error' => 'Invalid report type']);
    exit();
}

$report_type = $_GET['type'];

// ─── Admin info from session ──────────────────────────────────────────────────
$admin_name      = $_SESSION['user_name']       ?? $_SESSION['full_name']  ?? 'Loan Officer';
$loan_officer_id = $_SESSION['loan_officer_id'] ?? 'LO-0001';

// ─── Connect to loandb ────────────────────────────────────────────────────────
$conn = new mysqli("localhost", "root", "", "loandb");
$conn->set_charset("utf8mb4");

if ($conn->connect_error) {
    echo json_encode(['error' => 'DB error: ' . $conn->connect_error]);
    exit();
}

// ─── WHERE clause ─────────────────────────────────────────────────────────────
$where_clause = '';
$report_title = 'All Loans';
switch ($report_type) {
    case 'active':   $where_clause = "WHERE la.status = 'Active'";   $report_title = 'Active Loans'; break;
    case 'approved': $where_clause = "WHERE la.status = 'Approved'"; $report_title = 'Approved Loans (Awaiting Claim)'; break;
    case 'pending':  $where_clause = "WHERE la.status = 'Pending'";  $report_title = 'Pending Loans'; break;
    case 'rejected': $where_clause = "WHERE la.status = 'Rejected'"; $report_title = 'Rejected Loans'; break;
    case 'closed':   $where_clause = "WHERE la.status = 'Closed'";   $report_title = 'Closed Loans'; break;
}

// ─── Fetch loans — JOIN all normalized tables ─────────────────────────────────
// full_name     → loan_borrowers
// loan_type     → loan_types
// approved_at   → loan_approvals
// rejected_at   → loan_rejections
// next_payment_due is on loan_applications
$sql = "
    SELECT
        la.id                                       AS client_id,
        COALESCE(lb.full_name, la.user_email)       AS client_name,
        COALESCE(lt.name, 'Unknown')                AS loan_type,
        la.loan_amount,
        la.loan_terms,
        la.monthly_payment,
        la.status,
        la.created_at,
        la.next_payment_due,
        lap.approved_at,
        lr.rejected_at
    FROM loan_applications la
    LEFT JOIN loan_types     lt  ON lt.id  = la.loan_type_id
    LEFT JOIN loan_borrowers lb  ON lb.loan_application_id = la.id
    LEFT JOIN loan_approvals lap ON lap.loan_application_id = la.id
    LEFT JOIN loan_rejections lr ON lr.loan_application_id  = la.id
    $where_clause
    ORDER BY la.created_at DESC
";

$result = $conn->query($sql);
$loans = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $loans[] = $row;
    }
}

// ─── Status counts ────────────────────────────────────────────────────────────
$counts = ['Active' => 0, 'Approved' => 0, 'Pending' => 0, 'Rejected' => 0, 'Closed' => 0];
$allLoansResult = $conn->query("SELECT status, COUNT(*) AS total FROM loan_applications GROUP BY status");
if ($allLoansResult) {
    while ($row = $allLoansResult->fetch_assoc()) {
        $status = ucfirst(strtolower(trim($row['status'])));
        if (array_key_exists($status, $counts)) {
            $counts[$status] = (int)$row['total'];
        }
    }
}
$conn->close();

// ─── Generate PDF ─────────────────────────────────────────────────────────────
$pdf = new FPDF('L', 'mm', 'A4');
$pdf->AddPage();
$pdf->SetFont('Arial', 'B', 16);

$pdf->Cell(0, 15, 'EVERGREEN TRUST AND SAVINGS LOAN SERVICES', 0, 1, 'C');
$pdf->Ln(3);

$pdf->SetFont('Arial', 'B', 14);
$pdf->Cell(0, 10, 'Loan Officer Report: ' . $report_title, 0, 1, 'L');
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(0, 6, 'Reporting Period: ' . date('F Y'), 0, 1, 'L');
$pdf->Cell(0, 6, 'Prepared by: Loan Officer - ' . $admin_name, 0, 1, 'L');
$pdf->Cell(0, 6, 'Department: Loan Subsystem', 0, 1, 'L');
$pdf->Ln(8);

// ─── Pie chart ────────────────────────────────────────────────────────────────
if ($chartImageData) {
    $chartImageData = str_replace('data:image/png;base64,', '', $chartImageData);
    $chartImageData = str_replace(' ', '+', $chartImageData);
    $decodedImage   = base64_decode($chartImageData);

    $uploadDir = 'uploads/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    $tempChartPath = $uploadDir . 'temp_chart_' . time() . '.png';
    file_put_contents($tempChartPath, $decodedImage);

    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 8, 'Loan Portfolio Analytics', 0, 1, 'L');
    $pdf->Ln(2);

    $startY  = $pdf->GetY();
    $pdf->Image($tempChartPath, 15, $startY, 90, 60);

    $statsX = 115;
    $pdf->SetXY($statsX, $startY);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->SetFillColor(240, 240, 240);
    $pdf->Cell(70, 8, 'Overall Statistics:', 1, 1, 'C', true);

    $statRows = [
        'Active Loans:'         => $counts['Active'],
        'Approved (Awaiting):'  => $counts['Approved'],
        'Pending Review:'       => $counts['Pending'],
        'Rejected:'             => $counts['Rejected'],
    ];
    foreach ($statRows as $label => $value) {
        $pdf->SetXY($statsX, $pdf->GetY());
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(40, 7, $label, 1, 0, 'L');
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(30, 7, $value, 1, 1, 'C');
    }
    $pdf->SetXY($statsX, $pdf->GetY());
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetFillColor(220, 220, 220);
    $pdf->Cell(40, 7, 'Total Loans:', 1, 0, 'L', true);
    $pdf->Cell(30, 7, array_sum($counts), 1, 1, 'C', true);

    @unlink($tempChartPath);
    $pdf->SetY($startY + 65);
    $pdf->Ln(5);
}

// ─── Table header ─────────────────────────────────────────────────────────────
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetFillColor(200, 200, 200);
$w      = [20, 40, 30, 30, 25, 30, 30, 30, 25];
$header = ['Loan ID','Client Name','Loan Type','Amount','Term','Monthly Payment','Total Payable','Status','Date'];
foreach ($header as $i => $h) {
    $pdf->Cell($w[$i], 8, $h, 1, 0, 'C', true);
}
$pdf->Ln();

// ─── Table rows ───────────────────────────────────────────────────────────────
$pdf->SetFont('Arial', '', 8);
foreach ($loans as $loan) {
    if ($pdf->GetY() > 180) {
        $pdf->AddPage();
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetFillColor(200, 200, 200);
        foreach ($header as $i => $h) $pdf->Cell($w[$i], 8, $h, 1, 0, 'C', true);
        $pdf->Ln();
        $pdf->SetFont('Arial', '', 8);
    }

    // Date column logic
    if ($loan['status'] === 'Active' && !empty($loan['approved_at'])) {
        $display_date = date('m/d/Y', strtotime($loan['approved_at']));
    } elseif ($loan['status'] === 'Approved' && !empty($loan['approved_at'])) {
        $display_date = date('m/d/Y', strtotime($loan['approved_at']));
    } elseif ($loan['status'] === 'Rejected' && !empty($loan['rejected_at'])) {
        $display_date = date('m/d/Y', strtotime($loan['rejected_at']));
    } else {
        $display_date = date('m/d/Y', strtotime($loan['created_at']));
    }

    $data = [
        $loan['client_id'],
        substr($loan['client_name'], 0, 25),
        substr($loan['loan_type'],   0, 18),
        'PHP ' . number_format($loan['loan_amount'],   2),
        $loan['loan_terms'],
        'PHP ' . number_format($loan['monthly_payment'], 2),
        'PHP ' . number_format($loan['loan_amount'] * 1.20, 2),
        ucfirst($loan['status']),
        $display_date
    ];
    foreach ($data as $i => $cell) {
        $pdf->Cell($w[$i], 7, $cell, 1, 0, 'L');
    }
    $pdf->Ln();
}

// ─── Summary ──────────────────────────────────────────────────────────────────
$pdf->Ln(8);
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 8, 'Summary Statistics:', 0, 1);
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(0, 6, "Total Loans in Report: " . count($loans), 0, 1, 'L');
$pdf->Cell(0, 6, "Report Generated: " . date('F j, Y \a\t g:i A'), 0, 1, 'L');

// ─── Notes ────────────────────────────────────────────────────────────────────
$pdf->Ln(4);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 7, 'Important Notes:', 0, 1);
$pdf->SetFont('Arial', '', 9);
foreach ([
    "- This report is generated from the Evergreen Trust and Savings Loan Management System.",
    "- All monetary amounts are in Philippine Peso (PHP).",
    "- Interest rate applied: 20% per annum.",
    "- Approved loans require client to claim within 30 days.",
    "- For inquiries, contact the Loan Officer Department."
] as $note) {
    $pdf->Cell(0, 5, $note, 0, 1, 'L');
}

// ─── Footer ───────────────────────────────────────────────────────────────────
$pdf->SetY(-15);
$pdf->SetFont('Arial', 'I', 8);
$pdf->Cell(0, 10, 'Generated by Evergreen Trust and Savings - Page ' . $pdf->PageNo(), 0, 0, 'C');

// ─── Save PDF ─────────────────────────────────────────────────────────────────
$uploadDir = 'uploads/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

$filename = "loan_report_{$report_type}_" . date('YmdHis') . ".pdf";
$fullPath = $uploadDir . $filename;
$pdf->Output('F', $fullPath);

echo json_encode(['success' => true, 'filename' => $fullPath]);
?>