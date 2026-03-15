<?php
// download_pdf.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ini_set('display_errors', '0');
ini_set('log_errors',     '1');
ini_set('error_log',      __DIR__ . '/pdf_errors.log');

// Auth — both session key formats
$sessionEmail = $_SESSION['user_email'] ?? null;
$sessionId    = $_SESSION['user_id']    ?? null;

if (empty($sessionEmail) && empty($sessionId)) {
    http_response_code(403);
    die('Access denied. Please log in.');
}

$filename = isset($_GET['file']) ? trim($_GET['file']) : '';
if (empty($filename)) { http_response_code(400); die('No file specified.'); }

$filename = basename($filename);
if (!preg_match('/\.pdf$/i', $filename)) { http_response_code(400); die('Invalid file type.'); }

$filepath = __DIR__ . '/uploads/' . $filename;
if (!file_exists($filepath)) { http_response_code(404); die('File not found: ' . htmlspecialchars($filename)); }

// Ownership check
$loan_id = null;
if (preg_match('/^loan_\w+_(\d+)_\d+\.pdf$/i', $filename, $m)) {
    $loan_id = (int)$m[1];
}

if ($loan_id > 0 && !empty($sessionEmail)) {
    $conn = new mysqli('localhost', 'root', '', 'loandb');
    $conn->set_charset('utf8mb4');
    if (!$conn->connect_error) {
        $stmt = $conn->prepare("
            SELECT lb.email FROM loan_applications la
            LEFT JOIN loan_borrowers lb ON lb.loan_application_id = la.id
            WHERE la.id = ? LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param('i', $loan_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $borrowerEmail = $row['email'] ?? null;
            if ($borrowerEmail !== null && $borrowerEmail !== $sessionEmail) {
                $role = strtolower($_SESSION['role'] ?? $_SESSION['user_role'] ?? '');
                if ($role !== 'admin') {
                    $conn->close();
                    http_response_code(403);
                    die('Access denied.');
                }
            }
        }
        $conn->close();
    }
}

while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($filepath));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');
readfile($filepath);
exit;