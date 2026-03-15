<?php
// ─── Output buffer FIRST — catches any stray output before headers ────────────
ob_start();
session_start();
date_default_timezone_set('Asia/Manila');

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/loan_errors.log');

// Shutdown handler — fatal errors return JSON instead of blank 500
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (ob_get_length()) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error'   => 'Server error: ' . $err['message'] . ' in ' . basename($err['file']) . ':' . $err['line']
        ]);
    }
});

header('Content-Type: application/json; charset=utf-8');

// ─── Auth — admin only, BEFORE any require ────────────────────────────────────
$sessionRole = strtolower($_SESSION['role'] ?? $_SESSION['user_role'] ?? '');
if (!isset($_SESSION['user_id']) || $sessionRole !== 'admin') {
    ob_end_clean();
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied. Admin login required.']);
    exit;
}

// ─── PHPMailer — optional ─────────────────────────────────────────────────────
// NOTE: Do NOT use "use PHPMailer\..." statements unless files are loaded.
//       Conditional "use" after an if-block still causes a PHP fatal.
//       We call the classes via fully-qualified names (\PHPMailer\PHPMailer\PHPMailer)
//       to avoid this entirely.
$phpmailerAvailable = false;
$phpmailer_path     = __DIR__ . '/PHPMailer-7.0.0/src/';

if (
    file_exists($phpmailer_path . 'Exception.php') &&
    file_exists($phpmailer_path . 'PHPMailer.php') &&
    file_exists($phpmailer_path . 'SMTP.php')
) {
    require_once $phpmailer_path . 'Exception.php';
    require_once $phpmailer_path . 'PHPMailer.php';
    require_once $phpmailer_path . 'SMTP.php';
    $phpmailerAvailable = true;
} else {
    error_log("PHPMailer not found — email notifications disabled.");
}

// ─── Connect to loandb ────────────────────────────────────────────────────────
$conn = new mysqli("localhost", "root", "", "loandb");
$conn->set_charset("utf8mb4");
if ($conn->connect_error) {
    ob_end_clean();
    echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . $conn->connect_error]);
    exit;
}

// ─── Parse input ─────────────────────────────────────────────────────────────
$input          = json_decode(file_get_contents('php://input'), true) ?? [];
$loan_id        = (int)  ($input['loan_id']  ?? 0);
$status         = trim(   $input['status']   ?? '');
$action         = trim(   $input['action']   ?? '');
$custom_remarks = trim(   $input['remarks']  ?? '');

if ($loan_id <= 0) {
    ob_end_clean();
    echo json_encode(['success' => false, 'error' => 'Invalid loan ID']);
    exit;
}

$validActions = ['first_approve', 'second_approve', 'first_reject', 'second_reject'];
if (!in_array($action, $validActions)) {
    ob_end_clean();
    echo json_encode(['success' => false, 'error' => 'Invalid action: ' . htmlspecialchars($action)]);
    exit;
}

// ─── Fetch loan + borrower data ───────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT
        la.loan_amount,
        la.loan_terms,
        la.monthly_payment,
        la.next_payment_due,
        la.user_email,
        la.created_at,
        COALESCE(lt.name, 'Personal Loan')        AS loan_type,
        COALESCE(lb.full_name, la.user_email)     AS full_name,
        lb.email                                  AS borrower_email,
        lb.account_number
    FROM loan_applications la
    LEFT JOIN loan_types     lt ON lt.id = la.loan_type_id
    LEFT JOIN loan_borrowers lb ON lb.loan_application_id = la.id
    WHERE la.id = ?
    LIMIT 1
");
if (!$stmt) {
    ob_end_clean();
    echo json_encode(['success' => false, 'error' => 'DB prepare failed: ' . $conn->error]);
    exit;
}
$stmt->bind_param("i", $loan_id);
$stmt->execute();
$loan = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$loan) {
    ob_end_clean();
    echo json_encode(['success' => false, 'error' => 'Loan not found (ID: ' . $loan_id . ')']);
    exit;
}

$customer_email  = !empty($loan['borrower_email']) ? $loan['borrower_email'] : ($loan['user_email'] ?? null);
$admin_name      = $_SESSION['user_name'] ?? $_SESSION['full_name'] ?? 'Admin';
$admin_user_id   = (int)($_SESSION['user_id'] ?? 0);
$timestamp       = date('Y-m-d H:i:s');
$full_name       = $loan['full_name'];
$loan_amount_fmt = number_format($loan['loan_amount'], 2);
$monthly_fmt     = number_format($loan['monthly_payment'], 2);
$term            = $loan['loan_terms'];
$loan_type       = $loan['loan_type'];

// ─── DB updates inside transaction ───────────────────────────────────────────
$alert_message = '';
$email_type    = '';
$reason        = '';
$send_email    = false;

$conn->begin_transaction();

try {
    switch ($action) {

        case 'first_approve':
            $u = $conn->prepare("UPDATE loan_applications SET status = 'Approved' WHERE id = ?");
            if (!$u) throw new Exception("Prepare failed: " . $conn->error);
            $u->bind_param("i", $loan_id);
            if (!$u->execute()) throw new Exception("Execute failed: " . $u->error);
            $u->close();

            $del = $conn->prepare("DELETE FROM loan_approvals WHERE loan_application_id = ?");
            if (!$del) throw new Exception("Prepare failed: " . $conn->error);
            $del->bind_param("i", $loan_id); $del->execute(); $del->close();

            $ins = $conn->prepare("INSERT INTO loan_approvals (loan_application_id, approved_by, approved_by_user_id, approved_at) VALUES (?, ?, ?, NOW())");
            if (!$ins) throw new Exception("Prepare failed: " . $conn->error);
            $ins->bind_param("isi", $loan_id, $admin_name, $admin_user_id);
            if (!$ins->execute()) throw new Exception("Execute failed: " . $ins->error);
            $ins->close();

            $alert_message = "Loan approved! Client must claim within 30 days.";
            $email_type    = 'approved';
            $send_email    = true;
            break;

        case 'second_approve':
            $next_payment = date('Y-m-d', strtotime('+1 month'));

            $u = $conn->prepare("UPDATE loan_applications SET status = 'Active', next_payment_due = ? WHERE id = ?");
            if (!$u) throw new Exception("Prepare failed: " . $conn->error);
            $u->bind_param("si", $next_payment, $loan_id);
            if (!$u->execute()) throw new Exception("Execute failed: " . $u->error);
            $u->close();

            $del = $conn->prepare("DELETE FROM loan_approvals WHERE loan_application_id = ?");
            if (!$del) throw new Exception("Prepare failed: " . $conn->error);
            $del->bind_param("i", $loan_id); $del->execute(); $del->close();

            $ins = $conn->prepare("INSERT INTO loan_approvals (loan_application_id, approved_by, approved_by_user_id, approved_at) VALUES (?, ?, ?, NOW())");
            if (!$ins) throw new Exception("Prepare failed: " . $conn->error);
            $ins->bind_param("isi", $loan_id, $admin_name, $admin_user_id);
            if (!$ins->execute()) throw new Exception("Execute failed: " . $ins->error);
            $ins->close();

            $alert_message = "Loan activated successfully!";
            $email_type    = 'active';
            $send_email    = true;
            break;

        case 'first_reject':
        case 'second_reject':
            $reason = $custom_remarks ?: ($action === 'second_reject'
                ? 'Client did not claim within 30 days'
                : 'Application does not meet requirements');

            $u = $conn->prepare("UPDATE loan_applications SET status = 'Rejected' WHERE id = ?");
            if (!$u) throw new Exception("Prepare failed: " . $conn->error);
            $u->bind_param("i", $loan_id);
            if (!$u->execute()) throw new Exception("Execute failed: " . $u->error);
            $u->close();

            $del = $conn->prepare("DELETE FROM loan_rejections WHERE loan_application_id = ?");
            if (!$del) throw new Exception("Prepare failed: " . $conn->error);
            $del->bind_param("i", $loan_id); $del->execute(); $del->close();

            $ins = $conn->prepare("INSERT INTO loan_rejections (loan_application_id, rejected_by, rejected_by_user_id, rejected_at, rejection_remarks) VALUES (?, ?, ?, NOW(), ?)");
            if (!$ins) throw new Exception("Prepare failed: " . $conn->error);
            $ins->bind_param("isis", $loan_id, $admin_name, $admin_user_id, $reason);
            if (!$ins->execute()) throw new Exception("Execute failed: " . $ins->error);
            $ins->close();

            $alert_message = ($action === 'second_reject') ? "Approved loan cancelled." : "Loan rejected successfully.";
            $email_type    = ($action === 'second_reject') ? 'cancelled' : 'rejected';
            $send_email    = true;
            break;
    }

    $conn->commit();

} catch (Throwable $e) {
    $conn->rollback();
    error_log("upload_loan_status [loan {$loan_id}]: " . $e->getMessage());
    ob_end_clean();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}

// ─── BankingDB credit — best effort, never blocks response ───────────────────
$credit_success = false;
if ($action === 'second_approve') {
    try {
        $acct_no = $loan['account_number'] ?? '';
        if (empty($acct_no) || empty($customer_email)) {
            throw new Exception("Missing account number or email.");
        }
        $bc = new mysqli("localhost", "root", "", "BankingDB");
        $bc->set_charset("utf8mb4");
        if ($bc->connect_error) throw new Exception("BankingDB connect failed.");

        $as = $bc->prepare("
            SELECT ca.account_id, ca.account_status, ca.is_locked
            FROM customer_accounts ca
            INNER JOIN bank_customers bc2     ON ca.customer_id     = bc2.customer_id
            INNER JOIN bank_account_types bat ON ca.account_type_id = bat.account_type_id
            WHERE ca.account_number = ? AND bc2.email = ?
              AND bat.type_name IN ('Savings Account','Checking Account')
            LIMIT 1
        ");
        if (!$as) throw new Exception("BankingDB prepare failed.");
        $as->bind_param("ss", $acct_no, $customer_email);
        $as->execute();
        $acct = $as->get_result()->fetch_assoc();
        $as->close();

        if (!$acct)                                throw new Exception("Account not found.");
        if ($acct['is_locked'])                    throw new Exception("Account is locked.");
        if ($acct['account_status'] === 'closed')  throw new Exception("Account is closed.");

        $ref  = 'LOAN-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $amt  = (float)$loan['loan_amount'];
        $desc = "Loan Disbursement - Loan ID: {$loan_id}";

        $ts = $bc->prepare("INSERT INTO bank_transactions (transaction_ref, account_id, transaction_type_id, amount, description, created_at) VALUES (?, ?, 6, ?, ?, NOW())");
        if (!$ts) throw new Exception("TX prepare failed.");
        $ts->bind_param("sids", $ref, $acct['account_id'], $amt, $desc);
        if (!$ts->execute() || $ts->insert_id <= 0) throw new Exception("TX insert failed.");
        $ts->close();
        $bc->close();

        $credit_success  = true;
        $alert_message  .= " Account credited with ₱" . number_format($amt, 2) . ".";

    } catch (Throwable $ce) {
        error_log("Credit warning [loan {$loan_id}]: " . $ce->getMessage());
    }
}

// ─── Email — best effort, never blocks response ───────────────────────────────
if ($send_email && $customer_email && $phpmailerAvailable) {
    try {
        sendLoanEmail($customer_email, $full_name, $loan_id, $loan_type,
            $loan_amount_fmt, $monthly_fmt, $term,
            $admin_name, $timestamp, $email_type, $loan, $reason);
    } catch (Throwable $me) {
        error_log("Email error [loan {$loan_id}]: " . $me->getMessage());
    }
}

// ─── Respond ─────────────────────────────────────────────────────────────────
ob_end_clean();
echo json_encode([
    'success'       => true,
    'message'       => $alert_message,
    'new_status'    => $status,
    'credit_status' => $credit_success ? 'success' : 'skipped',
]);
$conn->close();
exit;

// ===================== EMAIL FUNCTIONS =====================

function sendLoanEmail($to, $name, $loan_id, $loan_type, $loan_amount, $monthly_payment, $term, $admin_name, $timestamp, $email_type, $loan_data, $rejection_reason = '') {
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail->SMTPDebug  = \PHPMailer\PHPMailer\SMTP::DEBUG_OFF;
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'evrgrn.64@gmail.com';
    $mail->Password   = 'dourhhbymvjejuct';
    $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;
    $mail->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];
    $mail->Timeout    = 30;
    $mail->setFrom('evrgrn.64@gmail.com', 'Evergreen Banking');
    $mail->addAddress($to, $name);
    $mail->isHTML(true);

    switch ($email_type) {
        case 'approved':
            $mail->Subject = 'Evergreen Banking - Loan Application APPROVED';
            $mail->Body    = getApprovedEmailTemplate($name, $loan_id, $loan_type, $loan_amount, $monthly_payment, $term, $admin_name, $timestamp);
            break;
        case 'active':
            $mail->Subject = 'Evergreen Banking - Your Loan is Now ACTIVE';
            $mail->Body    = getActiveEmailTemplate($name, $loan_id, $loan_type, $loan_amount, $monthly_payment, $term, $admin_name, $timestamp, $loan_data);
            break;
        case 'rejected':
            $mail->Subject = 'Evergreen Banking - Loan Application Status Update';
            $mail->Body    = getRejectedEmailTemplate($name, $loan_id, $loan_type, $loan_amount, $rejection_reason, $admin_name, $timestamp);
            break;
        case 'cancelled':
            $mail->Subject = 'Evergreen Banking - Approved Loan Cancelled';
            $mail->Body    = getCancelledEmailTemplate($name, $loan_id, $loan_type, $loan_amount, $rejection_reason, $admin_name, $timestamp);
            break;
        default:
            return false;
    }
    $mail->send();
    return true;
}

function getApprovedEmailTemplate($name, $loan_id, $loan_type, $loan_amount, $monthly_payment, $term, $admin_name, $timestamp) {
    $total = number_format((float)str_replace(',', '', $loan_amount) * 1.20, 2);
    return "<html><body style='font-family:Arial,sans-serif;padding:20px;background:#f5f5f5;'>
    <div style='max-width:600px;margin:0 auto;background:white;border-radius:12px;overflow:hidden;box-shadow:0 4px 12px rgba(0,0,0,.1)'>
        <div style='background:linear-gradient(135deg,#28a745,#20c997);padding:28px;text-align:center'><h1 style='color:white;margin:0'>✅ Loan Approved!</h1></div>
        <div style='padding:28px'>
            <p>Dear <strong>{$name}</strong>,</p>
            <p>Your loan application has been <strong style='color:#28a745'>APPROVED</strong>.</p>
            <div style='background:#d4edda;border-left:4px solid #28a745;padding:16px;margin:20px 0;border-radius:6px'>
                ⚠️ <strong>Please visit our bank within 30 days to claim your loan.</strong>
            </div>
            <table style='width:100%;border-collapse:collapse'>
                <tr><td style='padding:6px;color:#666'>Loan ID</td><td style='text-align:right;font-weight:600'>{$loan_id}</td></tr>
                <tr><td style='padding:6px;color:#666'>Loan Type</td><td style='text-align:right;font-weight:600'>{$loan_type}</td></tr>
                <tr><td style='padding:6px;color:#666'>Amount</td><td style='text-align:right;font-weight:700;color:#0d3d38'>₱{$loan_amount}</td></tr>
                <tr><td style='padding:6px;color:#666'>Term</td><td style='text-align:right;font-weight:600'>{$term}</td></tr>
                <tr><td style='padding:6px;color:#666'>Monthly Payment</td><td style='text-align:right;font-weight:700;color:#0d3d38'>₱{$monthly_payment}</td></tr>
                <tr><td style='padding:6px;color:#666'>Total Payable</td><td style='text-align:right;font-weight:600'>₱{$total}</td></tr>
            </table>
        </div>
        <div style='background:#f5f5f5;padding:14px;text-align:center;border-top:1px solid #e0e0e0'><p style='color:#999;font-size:12px;margin:0'>© 2025 Evergreen Banking</p></div>
    </div></body></html>";
}

function getActiveEmailTemplate($name, $loan_id, $loan_type, $loan_amount, $monthly_payment, $term, $admin_name, $timestamp, $loan_data) {
    $tm   = (int) filter_var($term, FILTER_SANITIZE_NUMBER_INT);
    $next = $loan_data['next_payment_due'] ?? date('Y-m-d', strtotime('+1 month'));
    $fin  = date('Y-m-d', strtotime(($loan_data['created_at'] ?? 'now') . " + {$tm} months"));
    return "<html><body style='font-family:Arial,sans-serif;padding:20px;background:#f5f5f5;'>
    <div style='max-width:600px;margin:0 auto;background:white;border-radius:12px;overflow:hidden;box-shadow:0 4px 12px rgba(0,0,0,.1)'>
        <div style='background:linear-gradient(135deg,#0d3d38,#1a6b62);padding:28px;text-align:center'><h1 style='color:white;margin:0'>🎉 Loan Activated!</h1></div>
        <div style='padding:28px'>
            <p>Dear <strong>{$name}</strong>,</p>
            <p>Your loan is now <strong style='color:#28a745'>ACTIVE</strong> and has been credited to your account.</p>
            <div style='background:#d1ecf1;border-left:4px solid #17a2b8;padding:16px;margin:20px 0;border-radius:6px'>
                <p style='margin:0'><strong>First Payment Due:</strong> " . date('F j, Y', strtotime($next)) . "</p>
                <p style='margin:6px 0 0'><strong>Final Payment:</strong> " . date('F j, Y', strtotime($fin)) . "</p>
            </div>
        </div>
        <div style='background:#f5f5f5;padding:14px;text-align:center;border-top:1px solid #e0e0e0'><p style='color:#999;font-size:12px;margin:0'>© 2025 Evergreen Banking</p></div>
    </div></body></html>";
}

function getRejectedEmailTemplate($name, $loan_id, $loan_type, $loan_amount, $reason, $admin_name, $timestamp) {
    return "<html><body style='font-family:Arial,sans-serif;padding:20px;background:#f5f5f5;'>
    <div style='max-width:600px;margin:0 auto;background:white;border-radius:12px;overflow:hidden;box-shadow:0 4px 12px rgba(0,0,0,.1)'>
        <div style='background:linear-gradient(135deg,#dc3545,#c82333);padding:28px;text-align:center'><h1 style='color:white;margin:0'>❌ Loan Application Update</h1></div>
        <div style='padding:28px'>
            <p>Dear <strong>{$name}</strong>,</p>
            <p>Your application has been <strong style='color:#dc3545'>REJECTED</strong>.</p>
            <div style='background:#f8d7da;border-left:4px solid #dc3545;padding:16px;margin:20px 0;border-radius:6px'><strong>Reason:</strong> {$reason}</div>
            <p>You may reapply in the future. Please contact our loan officer for more details.</p>
        </div>
        <div style='background:#f5f5f5;padding:14px;text-align:center;border-top:1px solid #e0e0e0'><p style='color:#999;font-size:12px;margin:0'>© 2025 Evergreen Banking</p></div>
    </div></body></html>";
}

function getCancelledEmailTemplate($name, $loan_id, $loan_type, $loan_amount, $reason, $admin_name, $timestamp) {
    return "<html><body style='font-family:Arial,sans-serif;padding:20px;background:#f5f5f5;'>
    <div style='max-width:600px;margin:0 auto;background:white;border-radius:12px;overflow:hidden;box-shadow:0 4px 12px rgba(0,0,0,.1)'>
        <div style='background:linear-gradient(135deg,#dc3545,#c82333);padding:28px;text-align:center'><h1 style='color:white;margin:0'>❌ Approved Loan Cancelled</h1></div>
        <div style='padding:28px'>
            <p>Dear <strong>{$name}</strong>,</p>
            <p>Your approved loan has been <strong style='color:#dc3545'>CANCELLED</strong>.</p>
            <div style='background:#f8d7da;border-left:4px solid #dc3545;padding:16px;margin:20px 0;border-radius:6px'><strong>Reason:</strong> {$reason}</div>
            <table style='width:100%;border-collapse:collapse'>
                <tr><td style='padding:6px;color:#666'>Loan ID</td><td style='text-align:right;font-weight:600'>{$loan_id}</td></tr>
                <tr><td style='padding:6px;color:#666'>Loan Type</td><td style='text-align:right;font-weight:600'>{$loan_type}</td></tr>
                <tr><td style='padding:6px;color:#666'>Amount</td><td style='text-align:right;font-weight:700;color:#0d3d38'>₱{$loan_amount}</td></tr>
            </table>
        </div>
        <div style='background:#f5f5f5;padding:14px;text-align:center;border-top:1px solid #e0e0e0'><p style='color:#999;font-size:12px;margin:0'>© 2025 Evergreen Banking</p></div>
    </div></body></html>";
}