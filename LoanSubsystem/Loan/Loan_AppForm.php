<?php
session_start();

// ─── SESSION BRIDGE ───────────────────────────────────────────────────────────
if (!isset($_SESSION['user_email'])) {
    if (isset($_SESSION['user_id']) && isset($_SESSION['email'])) {

        $host   = "localhost";
        $dbuser = "root";
        $dbpass = "";
        $dbname = "loandb";

        try {
            $pdo = new PDO(
                "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
                $dbuser, $dbpass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            $stmt = $pdo->prepare(
                "SELECT id, full_name, user_email, role
                 FROM users WHERE id = ? AND user_email = ? LIMIT 1"
            );
            $stmt->execute([$_SESSION['user_id'], $_SESSION['email']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                $_SESSION['user_email'] = $user['user_email'];
                $_SESSION['user_name']  = $user['full_name'];
                $_SESSION['user_role']  = strtolower($user['role']);
            } else {
                session_destroy();
                header('Location: login.php');
                exit();
            }
        } catch (PDOException $e) {
            session_destroy();
            header('Location: login.php?error=db');
            exit();
        }
    } else {
        header('Location: login.php');
        exit();
    }
}

// ─── CONNECT TO loandb ────────────────────────────────────────────────────────
$host   = "localhost";
$dbuser = "root";
$dbpass = "";
$dbname = "loandb";

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $dbuser, $dbpass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die("Database connection failed. Please contact admin.");
}

// ─── BUILD $currentUser FROM SESSION + DB ────────────────────────────────────
$currentUser = null;

try {
    $stmt = $pdo->prepare(
        "SELECT full_name, user_email, contact_number, account_number
         FROM users WHERE user_email = ? LIMIT 1"
    );
    $stmt->execute([$_SESSION['user_email']]);
    $currentUser = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Fallback handled below
}

if (!$currentUser) {
    $currentUser = [
        'full_name'      => $_SESSION['user_name'] ?? $_SESSION['full_name'] ?? '',
        'user_email'     => $_SESSION['user_email'],
        'contact_number' => '',
        'account_number' => '',
    ];
}

// Normalize email key
$currentUser['email'] = $currentUser['user_email'] ?? $currentUser['email'] ?? $_SESSION['user_email'];

// ─── FETCH LOAN TYPES FROM loandb ─────────────────────────────────────────────
$loanTypes = [];
try {
    $lt = $pdo->query("SELECT id, name FROM loan_types WHERE is_active = 1 ORDER BY name");
    $loanTypes = $lt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Could not load loan types. Please contact admin.");
}

// ─── FETCH VALID ID TYPES FROM loandb ────────────────────────────────────────
$validIdTypes = [];
try {
    $vi = $pdo->query("SELECT id, valid_id_type FROM loan_valid_id ORDER BY valid_id_type");
    $validIdTypes = $vi->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Non-fatal: form will show empty dropdown with a fallback message
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Loan Application Form</title>
  <link rel="icon" type="logo/png" href="pictures/logo.png" /> 
  <link rel="stylesheet" href="Loan_AppForm.css" />
</head>
<body>

<?php include 'header.php'; ?>

<div class="page-content">
  <section class="application-container">
    <div class="form-section">
      <h1>Loan Application Form</h1>
      <p class="subtitle">Please review your account and loan details below.</p>

      <form id="loanForm" action="submit_loan.php" method="POST" enctype="multipart/form-data">

        <!-- ── SECTION 1: ACCOUNT INFORMATION ─────────────────────────────── -->
        <section id="step-account-info">
          <h2>Account Information</h2>
          <div class="input-group">
            <div class="input-container">
              <input type="text" name="full_name" id="full_name"
                     value="<?= htmlspecialchars($currentUser['full_name']) ?>"
                     placeholder="Full Name (e.g., John Doe)" required readonly />
              <span class="validation-message" id="name-error"></span>
            </div>
            <div class="input-container">
              <input type="text" name="account_number" id="account_number"
                     value="<?= htmlspecialchars($currentUser['account_number']) ?>"
                     placeholder="Account Number (10 digits)" required readonly />
              <span class="validation-message" id="account-error"></span>
            </div>
            <div class="input-container">
              <input type="tel" name="contact_number" id="contact_number"
                     value="<?= htmlspecialchars($currentUser['contact_number']) ?>"
                     placeholder="Contact Number (+63...)" required readonly />
              <span class="validation-message" id="contact-error"></span>
            </div>
            <div class="input-container">
              <input type="email" name="email" id="email"
                     value="<?= htmlspecialchars($currentUser['email']) ?>"
                     placeholder="Email Address" required readonly />
              <span class="validation-message" id="email-error"></span>
            </div>
          </div>
        </section>

        <!-- ── SECTION 2: LOAN DETAILS ────────────────────────────────────── -->
        <section id="step-loan-details">
          <h2>Loan Details</h2>
          <div class="input-group">
            <div class="input-container">
              <label for="loan_type">Loan Type <span class="required">*</span></label>
              <select name="loan_type_id" id="loan_type" required>
                <option value="">Select Loan Type</option>
                <?php foreach ($loanTypes as $type): ?>
                  <option value="<?= (int)$type['id'] ?>"><?= htmlspecialchars($type['name']) ?></option>
                <?php endforeach; ?>
              </select>
              <span class="validation-message" id="loan-type-error"></span>
            </div>

            <div class="input-container">
              <label for="loan_terms">Loan Term <span class="required">*</span></label>
              <select name="loan_terms" id="loan_terms" required>
                <option value="">Select Loan Terms</option>
                <option value="6 Months">6 Months</option>
                <option value="12 Months">12 Months</option>
                <option value="18 Months">18 Months</option>
                <option value="24 Months">24 Months</option>
                <option value="30 Months">30 Months</option>
                <option value="36 Months">36 Months</option>
              </select>
              <span class="validation-message" id="loan-terms-error"></span>
            </div>

            <div class="input-container">
              <label for="loan_amount">Loan Amount <span class="required">*</span></label>
              <input type="number" name="loan_amount" id="loan_amount"
                     placeholder="Loan Amount (Min ₱5,000)" min="5000" step="0.01" required />
              <span class="validation-message" id="amount-error"></span>
            </div>

            <div class="input-container">
              <label for="purpose">Purpose of Loan <span class="required">*</span></label>
              <textarea name="purpose" id="purpose" placeholder="Describe the purpose of your loan" required></textarea>
              <span class="validation-message" id="purpose-error"></span>
            </div>
          </div>
        </section>

        <!-- ── SECTION 3: SUPPORTING DETAILS ─────────────────────────────── -->
        <section id="step-supporting-details">
          <h2>Supporting Details</h2>
          <div class="input-group">

            <!-- Valid ID Type dropdown — populated from loan_valid_id table -->
            <div class="input-container">
              <label for="loan_valid_id_type">Valid ID Type <span class="required">*</span></label>
              <select name="loan_valid_id_type" id="loan_valid_id_type" required>
                <option value="">Select Valid ID</option>
                <?php if (!empty($validIdTypes)): ?>
                  <?php foreach ($validIdTypes as $idType): ?>
                    <option value="<?= (int)$idType['id'] ?>"><?= htmlspecialchars($idType['valid_id_type']) ?></option>
                  <?php endforeach; ?>
                <?php else: ?>
                  <option value="" disabled>No ID types available — contact admin</option>
                <?php endif; ?>
              </select>
              <span class="validation-message" id="valid-id-type-error"></span>
            </div>

            <!-- ID Number input (text for alphanumeric IDs) -->
            <div class="input-container">
              <label for="valid_id_number">ID Number <span class="required">*</span></label>
              <input type="text" name="valid_id_number" id="valid_id_number"
                     placeholder="Enter your ID number" maxlength="150" required />
              <span class="validation-message" id="valid-id-number-error"></span>
            </div>

            <!-- File uploads -->
            <div class="input-container">
              <label for="attachment">Upload Valid ID <span class="required">*</span></label>
              <small>Accepted: JPG, JPEG, PNG, PDF, DOC, DOCX (Max 5MB)</small>
              <input type="file" name="attachment" id="attachment"
                     accept=".jpg,.jpeg,.png,.pdf,.doc,.docx" required />
              <span class="validation-message" id="attachment-error"></span>
            </div>

            <div class="input-container">
              <label for="proof_of_income">Upload Proof of Income / Payslip <span class="required">*</span></label>
              <small>Accepted: JPG, JPEG, PNG, PDF, DOC, DOCX (Max 5MB)</small>
              <input type="file" name="proof_of_income" id="proof_of_income"
                     accept=".jpg,.jpeg,.png,.pdf,.doc,.docx" required />
              <span class="validation-message" id="proof-income-error"></span>
            </div>

            <div class="input-container">
              <label for="coe_document">Upload Certificate of Employment (COE) <span class="required">*</span></label>
              <small>Accepted: PDF, DOC, DOCX only (Max 5MB)</small>
              <input type="file" name="coe_document" id="coe_document"
                     accept=".pdf,.doc,.docx" required />
              <span class="validation-message" id="coe-error"></span>
            </div>

          </div>
        </section>

        <div class="form-actions">
          <button class="btn btn-back" type="button" onclick="location.href='index.php'">Back</button>
          <button type="submit" class="btn btn-submit">Submit Application</button>
        </div>
      </form>
    </div>

    <aside class="progress">
      <h3>Application Progress</h3>
      <div class="progress-step" id="progress-account">
        <span class="circle"></span><span>Account Information</span>
      </div>
      <div class="progress-step" id="progress-loan">
        <span class="circle"></span><span>Loan Details</span>
      </div>
    </aside>
  </section>
</div>

<!-- ── MODAL ────────────────────────────────────────────────────────────────── -->
<div id="combined-modal" class="modal hidden">
  <div class="modal-content">
    <div id="terms-view">
      <div class="modal-header">
        <img src="pictures/logo.png" alt="Evergreen Logo" class="logo-small">
        <h2>Terms and Agreement</h2>
      </div>
      <p class="subtitle-text">Please review our terms and conditions carefully before proceeding</p>

      <div class="terms-body" style="max-height: 300px; overflow-y:auto;">
        <h3>1. Overview</h3>
        <p>By using Evergreen Bank services, you agree to these Terms and our Privacy Policy...</p>
        <h3>2. Account Terms</h3>
        <p>You must provide accurate, current, and complete account information...</p>
        <h3>3. Privacy and Data Protection</h3>
        <p>We take privacy seriously and implement reasonable security measures...</p>
        <h3>4. Fees and Charges</h3>
        <p>Fees are deducted automatically as outlined in our Fee Schedule...</p>
        <h3>5. Security Measures</h3>
        <p>We employ strong authentication methods and monitor accounts for suspicious activity...</p>
        <h3>6. Dispute Resolution</h3>
        <p>Any disputes shall be resolved under binding arbitration according to applicable law.</p>
      </div>

      <div class="modal-footer">
        <div class="acceptance-text">
          By clicking "I Accept", you acknowledge that you have read and agree to these Terms.
        </div>
        <div class="modal-actions">
          <button class="btn btn-accept" onclick="acceptTerms()">I Accept</button>
          <button class="btn btn-decline" onclick="closeModal()">I Decline</button>
        </div>
      </div>
    </div>

    <div id="confirmation-view" class="hidden">
      <div class="confirm-modal-content">
        <div class="success-icon">
          <img src="pictures/check.png" alt="Success" style="width: 100px; height: 100px;">
        </div>
        <h2>Loan Application Submitted Successfully!</h2>
        <p class="message-text">Your loan request has been received. You will receive an update soon.</p>
        <div class="reference-details">
          Reference No: <span id="ref-number"></span><br>
          Date: <span id="ref-date"></span>
        </div>
        <button class="btn btn-dashboard" onclick="location.href='index.php?scrollTo=dashboard'">Go To Dashboard</button>
      </div>
    </div>
  </div>
</div>

<script src="loan_appform.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Auto-select loan type from URL param ?loanType=Personal%20Loan
    const urlParams = new URLSearchParams(window.location.search);
    const loanTypeName = urlParams.get('loanType');
    if (loanTypeName) {
        const loanSelect = document.getElementById('loan_type');
        for (let option of loanSelect.options) {
            if (option.text.trim() === decodeURIComponent(loanTypeName).trim()) {
                option.selected = true;
                break;
            }
        }
    }

    // ── File validation ──────────────────────────────────────────────────────
    const validIdInput = document.getElementById('attachment');
    const proofInput   = document.getElementById('proof_of_income');
    const coeInput     = document.getElementById('coe_document');

    const maxFileSize  = 5 * 1024 * 1024; // 5 MB
    const allFileTypes = [
        'image/jpeg', 'image/jpg', 'image/png',
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
    ];
    const coeTypes = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
    ];

    function validateFile(input, allowedTypes, errorId) {
        const file      = input.files[0];
        const errorSpan = document.getElementById(errorId);
        if (!file) return true;

        if (!allowedTypes.includes(file.type)) {
            errorSpan.textContent = 'Invalid file type. Please upload an allowed format.';
            input.value = '';
            return false;
        }
        if (file.size > maxFileSize) {
            errorSpan.textContent = 'File size exceeds 5MB. Please upload a smaller file.';
            input.value = '';
            return false;
        }
        errorSpan.textContent = '';
        return true;
    }

    validIdInput.addEventListener('change', () => validateFile(validIdInput, allFileTypes, 'attachment-error'));
    proofInput.addEventListener('change',   () => validateFile(proofInput,   allFileTypes, 'proof-income-error'));
    coeInput.addEventListener('change',     () => validateFile(coeInput,     coeTypes,     'coe-error'));
});

// Show Terms modal on valid form submit
document.getElementById('loanForm').addEventListener('submit', function (e) {
    e.preventDefault();
    if (this.checkValidity()) {
        const modal              = document.getElementById('combined-modal');
        const applicationContent = document.querySelector('.page-content');
        document.getElementById('terms-view').classList.remove('hidden');
        document.getElementById('confirmation-view').classList.add('hidden');
        modal.classList.remove('hidden');
        applicationContent.classList.add('blur-background');
        document.body.style.overflow = 'hidden';
    } else {
        this.reportValidity();
    }
});

// Accept terms → submit via fetch
async function acceptTerms() {
    const form       = document.getElementById('loanForm');
    const formData   = new FormData(form);
    const acceptBtn  = document.querySelector('.btn-accept');
    const origText   = acceptBtn.textContent;

    acceptBtn.disabled    = true;
    acceptBtn.textContent = 'Submitting...';

    try {
        const response = await fetch('submit_loan.php', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();

        if (result.success) {
            document.getElementById('terms-view').classList.add('hidden');
            document.getElementById('confirmation-view').classList.remove('hidden');

            if (result.loan_id) {
                document.getElementById('ref-number').textContent =
                    'LOAN-' + String(result.loan_id).padStart(6, '0');
            }
            document.getElementById('ref-date').textContent =
                new Date().toLocaleDateString('en-US', {
                    year: 'numeric', month: 'long', day: 'numeric'
                });
        } else {
            alert('❌ Error: ' + result.error);
            acceptBtn.disabled    = false;
            acceptBtn.textContent = origText;
        }
    } catch (error) {
        console.error('Submission error:', error);
        alert('❌ An error occurred while submitting your application. Please try again.');
        acceptBtn.disabled    = false;
        acceptBtn.textContent = origText;
    }
}

function closeModal() {
    document.getElementById('combined-modal').classList.add('hidden');
    document.querySelector('.page-content').classList.remove('blur-background');
    document.body.style.overflow = 'auto';
}
</script>

</body>
</html>