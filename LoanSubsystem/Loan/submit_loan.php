<?php
session_start();
header('Content-Type: application/json');

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

$response = ['success' => false, 'error' => '', 'loan_id' => null];

try {
    // ─── Auth check ───────────────────────────────────────────────────────────
    if (!isset($_SESSION['user_email'])) {
        if (isset($_SESSION['user_id']) && isset($_SESSION['email'])) {
            $_SESSION['user_email'] = $_SESSION['email'];
            $_SESSION['user_name']  = $_SESSION['full_name'] ?? (($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
            $_SESSION['user_role']  = 'client';
        } else {
            throw new Exception("Not authenticated. Please log in.");
        }
    }

    // ─── Connect to loandb only ───────────────────────────────────────────────
    $conn = new mysqli("localhost", "root", "", "loandb");
    $conn->set_charset("utf8mb4");
    if ($conn->connect_error) {
        throw new Exception("Database connection failed: " . $conn->connect_error);
    }

    $email = $_SESSION['user_email'];

    // ─── Get user data from loandb.users ─────────────────────────────────────
    // No more bank_customers — everything comes from loandb
    $user_stmt = $conn->prepare(
        "SELECT id, full_name, user_email, contact_number, account_number
         FROM users WHERE user_email = ? LIMIT 1"
    );
    if (!$user_stmt) {
        throw new Exception("User query prepare failed: " . $conn->error);
    }
    $user_stmt->bind_param("s", $email);
    $user_stmt->execute();
    $currentUser = $user_stmt->get_result()->fetch_assoc();
    $user_stmt->close();

    if (!$currentUser) {
        throw new Exception("User not found in database for email: " . $email);
    }

    // ─── Collect & validate POST fields ──────────────────────────────────────
    $loan_type_id       = isset($_POST['loan_type_id'])       ? (int)$_POST['loan_type_id']           : 0;
    $loan_terms         = isset($_POST['loan_terms'])         ? trim($_POST['loan_terms'])             : '';
    $loan_amount        = isset($_POST['loan_amount'])        ? (float)$_POST['loan_amount']           : 0;
    $purpose            = isset($_POST['purpose'])            ? trim($_POST['purpose'])                : '';
    $account_number     = isset($_POST['account_number'])     ? trim($_POST['account_number'])         : '';
    $loan_valid_id_type = isset($_POST['loan_valid_id_type']) ? (int)$_POST['loan_valid_id_type']      : 0;
    $valid_id_number    = isset($_POST['valid_id_number'])    ? trim($_POST['valid_id_number'])        : '';

    if ($loan_type_id <= 0)      throw new Exception("Invalid loan type selected.");
    if (empty($loan_terms))      throw new Exception("Please select loan terms.");
    if ($loan_amount < 5000)     throw new Exception("Loan amount must be at least ₱5,000.");
    if (empty($purpose))         throw new Exception("Please provide the purpose of the loan.");
    if (empty($account_number))  throw new Exception("Please provide your account number.");
    if ($loan_valid_id_type <= 0) throw new Exception("Please select a valid ID type.");
    if (empty($valid_id_number)) throw new Exception("Please enter your ID number.");

    // ─── Verify loan_type exists and is active ────────────────────────────────
    $lt_stmt = $conn->prepare("SELECT id FROM loan_types WHERE id = ? AND is_active = 1");
    if (!$lt_stmt) throw new Exception("DB error: " . $conn->error);
    $lt_stmt->bind_param("i", $loan_type_id);
    $lt_stmt->execute();
    if ($lt_stmt->get_result()->num_rows === 0) {
        throw new Exception("Selected loan type is invalid or inactive.");
    }
    $lt_stmt->close();

    // ─── Verify valid ID type exists in loan_valid_id ─────────────────────────
    $vi_stmt = $conn->prepare("SELECT id FROM loan_valid_id WHERE id = ?");
    if (!$vi_stmt) throw new Exception("DB error: " . $conn->error);
    $vi_stmt->bind_param("i", $loan_valid_id_type);
    $vi_stmt->execute();
    if ($vi_stmt->get_result()->num_rows === 0) {
        throw new Exception("Invalid ID type selected.");
    }
    $vi_stmt->close();

    // ─── Monthly payment calculation (20% annual interest, amortized) ─────────
    $term_months = (int) filter_var($loan_terms, FILTER_SANITIZE_NUMBER_INT);
    if ($term_months <= 0) throw new Exception("Invalid loan term.");

    $monthly_rate    = 0.20 / 12;
    $monthly_payment = $loan_amount
        * ($monthly_rate * pow(1 + $monthly_rate, $term_months))
        / (pow(1 + $monthly_rate, $term_months) - 1);
    $monthly_payment = round($monthly_payment, 2);

    // ─── File uploads ─────────────────────────────────────────────────────────
    $upload_dir = __DIR__ . '/uploads/';
    if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true)) {
        throw new Exception("Failed to create uploads directory.");
    }

    $max_size     = 5 * 1024 * 1024;
    $all_types    = ['jpg','jpeg','png','pdf','doc','docx'];
    $doc_types    = ['pdf','doc','docx'];

    function uploadFile(string $field, array $allowed, int $maxSize, string $prefix): string {
        if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("Please upload your " . str_replace('_', ' ', $field) . ".");
        }
        if ($_FILES[$field]['size'] > $maxSize) {
            throw new Exception(ucfirst(str_replace('_', ' ', $field)) . " exceeds 5MB limit.");
        }
        $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed)) {
            throw new Exception("Invalid file type for " . str_replace('_', ' ', $field) . ". Allowed: " . implode(', ', $allowed));
        }
        $path = 'uploads/' . $prefix . '_' . time() . '_' . uniqid() . '.' . $ext;
        if (!move_uploaded_file($_FILES[$field]['tmp_name'], __DIR__ . '/' . $path)) {
            throw new Exception("Failed to upload " . str_replace('_', ' ', $field) . ".");
        }
        return $path;
    }

    $file_name       = uploadFile('attachment',      $all_types, $max_size, 'valid_id');
    $proof_of_income = uploadFile('proof_of_income', $all_types, $max_size, 'proof_income');
    $coe_document    = uploadFile('coe_document',    $doc_types, $max_size, 'coe');

    // ─── Pull confirmed user details ──────────────────────────────────────────
    $user_id        = (int)$currentUser['id'];
    $full_name      = $currentUser['full_name']      ?? '';
    $contact_number = $currentUser['contact_number'] ?? '';
    // Use account_number from POST (user selected it); fall back to profile value
    if (empty($account_number)) {
        $account_number = $currentUser['account_number'] ?? '';
    }

    // ─── BEGIN TRANSACTION ────────────────────────────────────────────────────
    $conn->begin_transaction();

    try {
        // 1. Insert into loan_applications
        //    Columns per your schema:
        //    id, user_id, loan_type_id, user_email, loan_terms, loan_amount,
        //    monthly_payment, due_date, next_payment_due, purpose, status, created_at
        $due_date          = date('Y-m-d', strtotime("+{$term_months} months"));
        $next_payment_due  = date('Y-m-d', strtotime('+1 month'));

        $la_stmt = $conn->prepare("
            INSERT INTO loan_applications
                (user_id, loan_type_id, user_email, loan_terms, loan_amount,
                 monthly_payment, due_date, next_payment_due, purpose, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', NOW())
        ");
        if (!$la_stmt) throw new Exception("Prepare failed (loan_applications): " . $conn->error);
        $la_stmt->bind_param(
            "iissddsss",
            $user_id,          // i
            $loan_type_id,     // i
            $email,            // s  user_email
            $loan_terms,       // s
            $loan_amount,      // d
            $monthly_payment,  // d
            $due_date,         // s
            $next_payment_due, // s
            $purpose           // s
        );
        if (!$la_stmt->execute()) throw new Exception("Insert failed (loan_applications): " . $la_stmt->error);
        $loan_id = $la_stmt->insert_id;
        $la_stmt->close();

        // 2. Insert into loan_borrowers
        //    Columns: id, loan_application_id, full_name, account_number,
        //             contact_number, email, job, monthly_salary
        $lb_stmt = $conn->prepare("
            INSERT INTO loan_borrowers
                (loan_application_id, full_name, account_number, contact_number, email)
            VALUES (?, ?, ?, ?, ?)
        ");
        if (!$lb_stmt) throw new Exception("Prepare failed (loan_borrowers): " . $conn->error);
        $lb_stmt->bind_param(
            "issss",
            $loan_id,
            $full_name,
            $account_number,
            $contact_number,
            $email
        );
        if (!$lb_stmt->execute()) throw new Exception("Insert failed (loan_borrowers): " . $lb_stmt->error);
        $lb_stmt->close();

        // 3. Insert into loan_valid_ids
        //    Columns: id, loan_application_id, loan_valid_id_type, valid_id_number
        $lvi_stmt = $conn->prepare("
            INSERT INTO loan_valid_ids
                (loan_application_id, loan_valid_id_type, valid_id_number)
            VALUES (?, ?, ?)
        ");
        if (!$lvi_stmt) throw new Exception("Prepare failed (loan_valid_ids): " . $conn->error);
        $lvi_stmt->bind_param(
            "iis",
            $loan_id,
            $loan_valid_id_type,
            $valid_id_number
        );
        if (!$lvi_stmt->execute()) throw new Exception("Insert failed (loan_valid_ids): " . $lvi_stmt->error);
        $lvi_stmt->close();

        // 4. Insert into loan_documents
        //    Columns: id, loan_application_id, file_name, proof_of_income, coe_document
        //    (pdf_approved, pdf_active, pdf_rejected left NULL until generated)
        $ld_stmt = $conn->prepare("
            INSERT INTO loan_documents
                (loan_application_id, file_name, proof_of_income, coe_document)
            VALUES (?, ?, ?, ?)
        ");
        if (!$ld_stmt) throw new Exception("Prepare failed (loan_documents): " . $conn->error);
        $ld_stmt->bind_param(
            "isss",
            $loan_id,
            $file_name,
            $proof_of_income,
            $coe_document
        );
        if (!$ld_stmt->execute()) throw new Exception("Insert failed (loan_documents): " . $ld_stmt->error);
        $ld_stmt->close();

        // ─── COMMIT ───────────────────────────────────────────────────────────
        $conn->commit();

    } catch (Exception $inner) {
        $conn->rollback();
        // Clean up uploaded files if DB insert failed
        foreach ([$file_name, $proof_of_income, $coe_document] as $f) {
            if ($f && file_exists(__DIR__ . '/' . $f)) unlink(__DIR__ . '/' . $f);
        }
        throw $inner;
    }

    $conn->close();

    $response['success']          = true;
    $response['loan_id']          = $loan_id;
    $response['message']          = 'Loan application submitted successfully!';
    $response['reference_number'] = 'LOAN-' . str_pad($loan_id, 6, '0', STR_PAD_LEFT);
    $response['date']             = date('F d, Y');

} catch (Exception $e) {
    $response['success'] = false;
    $response['error']   = $e->getMessage();
    error_log("Loan Submission Error: " . $e->getMessage() . " | Line: " . $e->getLine());
}

echo json_encode($response);
exit();
?>