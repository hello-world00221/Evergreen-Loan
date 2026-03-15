<?php
session_start();

// ── Load PHPMailer ───────────────────────────────────────────────
require 'PHPMailer-7.0.0/src/Exception.php';
require 'PHPMailer-7.0.0/src/PHPMailer.php';
require 'PHPMailer-7.0.0/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

// ── Mail config ──────────────────────────────────────────────────
$MAIL_HOST      = 'smtp.gmail.com';
$MAIL_PORT      = 587;
$MAIL_USERNAME  = 'franciscarpeso@gmail.com';
$MAIL_PASSWORD  = 'bwobttvnbpqvzimv';
$MAIL_FROM      = 'franciscarpeso@gmail.com';
$MAIL_FROM_NAME = 'Evergreen Trust and Savings';

// ── DB config ────────────────────────────────────────────────────
$DB_HOST = 'localhost';
$DB_NAME = 'loandb';
$DB_USER = 'root';
$DB_PASS = '';

function getDB($host, $name, $user, $pass): PDO {
    return new PDO(
        "mysql:host=$host;dbname=$name;charset=utf8mb4",
        $user, $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
}

// ── Send OTP email ───────────────────────────────────────────────
function sendResetPin(
    string $toEmail, string $toName, string $pin,
    string $host, int $port, string $user, string $pass,
    string $from, string $fromName
): bool|string {
    $mail = new PHPMailer(true);
    try {
        $mail->SMTPDebug  = SMTP::DEBUG_OFF;
        $mail->isSMTP();
        $mail->Host       = $host;
        $mail->SMTPAuth   = true;
        $mail->Username   = $user;
        $mail->Password   = $pass;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $port;
        $mail->SMTPOptions = ['ssl' => [
            'verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true
        ]];
        $mail->Timeout = 60;
        $mail->setFrom($from, $fromName);
        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);
        $mail->Subject = 'Evergreen – Password Reset Code';
        $mail->Body    = getResetEmailBody($toName, $pin);
        $mail->AltBody = "Hello {$toName},\n\nYour password reset code: {$pin}\n\nExpires in 10 minutes.\n\nIf you did not request this, ignore this email.";
        $mail->send();
        return true;
    } catch (Exception $e) {
        return $mail->ErrorInfo ?: $e->getMessage();
    }
}

function getResetEmailBody(string $name, string $pin): string {
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"/></head>
<body style="margin:0;padding:0;background:#f4f4f4;font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4;padding:40px 0;">
    <tr><td align="center">
      <table width="520" cellpadding="0" cellspacing="0"
             style="background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 4px 16px rgba(0,0,0,.10);">
        <tr>
          <td style="background:#0a3b2f;padding:28px 32px;text-align:center;">
            <h1 style="color:#fff;margin:0;font-size:22px;">🌿 EVERGREEN</h1>
            <p style="color:#a8d5b5;margin:6px 0 0;font-size:13px;">Trust and Savings Bank</p>
          </td>
        </tr>
        <tr>
          <td style="padding:36px 40px;">
            <p style="color:#2d4a3e;font-size:16px;margin:0 0 12px;">Hello, <strong>{$name}</strong> 👋</p>
            <p style="color:#555;font-size:14px;line-height:1.7;margin:0 0 24px;">
              We received a request to reset your password for your
              <strong>Evergreen Trust and Savings</strong> account.
              Use the code below — valid for <strong>10 minutes</strong>.
            </p>
            <table width="100%" cellpadding="0" cellspacing="0">
              <tr>
                <td align="center" style="padding:10px 0 28px;">
                  <div style="display:inline-block;background:#fff8e1;border:2px dashed #f9a825;border-radius:12px;padding:22px 52px;">
                    <p style="margin:0 0 8px;color:#888;font-size:12px;text-transform:uppercase;letter-spacing:1.5px;">Reset Code</p>
                    <p style="margin:0;font-size:44px;font-weight:800;letter-spacing:14px;color:#0a3b2f;">{$pin}</p>
                  </div>
                </td>
              </tr>
            </table>
            <p style="color:#e53935;font-size:13px;font-weight:600;">⚠ If you did not request a password reset, please ignore this email or contact support immediately.</p>
          </td>
        </tr>
        <tr>
          <td style="background:#f9f5f0;padding:20px 40px;text-align:center;border-top:1px solid #e8e0d8;">
            <p style="color:#aaa;font-size:12px;margin:0;">&copy; 2025 Evergreen Trust and Savings &middot; Automated message</p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
}

// ════════════════════════════════════════════════════════════════
//  Determine current step
//  step 1 → identity verification (account_number + email)
//  step 2 → OTP entry
//  step 3 → new password form
//  step 4 → success
// ════════════════════════════════════════════════════════════════
$step   = $_SESSION['fp_step'] ?? 1;
$error  = '';
$notice = '';

// ── STEP 1 POST: verify identity ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify_identity') {

    $account_number = trim($_POST['account_number'] ?? '');
    $user_email     = trim($_POST['user_email']     ?? '');

    if (empty($account_number) || empty($user_email)) {
        $error = "Both fields are required.";
    } elseif (!filter_var($user_email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {
        try {
            $pdo  = getDB($DB_HOST, $DB_NAME, $DB_USER, $DB_PASS);
            $stmt = $pdo->prepare(
                "SELECT id, full_name FROM users WHERE account_number = ? AND user_email = ? LIMIT 1"
            );
            $stmt->execute([$account_number, $user_email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                $pin = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

                unset($_SESSION['fp_step'], $_SESSION['fp_user_id'],
                      $_SESSION['fp_email'], $_SESSION['fp_name'],
                      $_SESSION['fp_pin'],   $_SESSION['fp_pin_expires'],
                      $_SESSION['fp_attempts']);

                $_SESSION['fp_step']        = 2;
                $_SESSION['fp_user_id']     = $user['id'];
                $_SESSION['fp_email']       = $user_email;
                $_SESSION['fp_name']        = $user['full_name'];
                $_SESSION['fp_pin']         = $pin;
                $_SESSION['fp_pin_expires'] = time() + 600;
                $_SESSION['fp_attempts']    = 0;

                $result = sendResetPin(
                    $user_email, $user['full_name'], $pin,
                    $MAIL_HOST, $MAIL_PORT, $MAIL_USERNAME,
                    $MAIL_PASSWORD, $MAIL_FROM, $MAIL_FROM_NAME
                );

                if ($result === true || $result === '') {
                    $step   = 2;
                    $notice = "A 6-digit reset code was sent to <strong>" . htmlspecialchars($user_email) . "</strong>.";
                } else {
                    $error = "Could not send email: " . $result;
                    // Roll back step so user can retry
                    unset($_SESSION['fp_step']);
                }
            } else {
                $error = "No account found matching that email and account number.";
            }
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
}

// ── STEP 2 POST: verify OTP ─────────────────────────────────────
elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify_otp') {

    $entered = trim(implode('', $_POST['otp_digit'] ?? []));

    if ($step !== 2) {
        $error = "Session expired. Please start again.";
        $step  = 1;
        unset($_SESSION['fp_step']);
    } elseif (time() > ($_SESSION['fp_pin_expires'] ?? 0)) {
        $error = "Your code has expired. Please start over.";
        $step  = 1;
        unset($_SESSION['fp_step'], $_SESSION['fp_pin'], $_SESSION['fp_pin_expires'], $_SESSION['fp_attempts']);
    } else {
        $_SESSION['fp_attempts']++;
        if ($_SESSION['fp_attempts'] > 5) {
            $error = "Too many incorrect attempts. Please start over.";
            $step  = 1;
            unset($_SESSION['fp_step'], $_SESSION['fp_pin'], $_SESSION['fp_pin_expires'], $_SESSION['fp_attempts']);
        } elseif ($entered === $_SESSION['fp_pin']) {
            $_SESSION['fp_step'] = 3;
            $step                = 3;
            unset($_SESSION['fp_pin']); // one-time use
        } else {
            $remaining = 5 - $_SESSION['fp_attempts'];
            $error = "Incorrect code. {$remaining} attempt(s) remaining.";
        }
    }
}

// ── STEP 2 POST: resend OTP ──────────────────────────────────────
elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'resend_otp') {

    if (($step === 2) && !empty($_SESSION['fp_email'])) {
        $pin = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $_SESSION['fp_pin']         = $pin;
        $_SESSION['fp_pin_expires'] = time() + 600;
        $_SESSION['fp_attempts']    = 0;

        $result = sendResetPin(
            $_SESSION['fp_email'], $_SESSION['fp_name'], $pin,
            $MAIL_HOST, $MAIL_PORT, $MAIL_USERNAME,
            $MAIL_PASSWORD, $MAIL_FROM, $MAIL_FROM_NAME
        );

        $notice = ($result === true || $result === '')
            ? "A new code was sent to <strong>" . htmlspecialchars($_SESSION['fp_email']) . "</strong>."
            : "Failed to resend: " . $result;
    }
}

// ── STEP 3 POST: change password ────────────────────────────────
elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {

    $password         = $_POST['password']         ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if ($step !== 3 || empty($_SESSION['fp_user_id'])) {
        $error = "Session expired. Please start again.";
        $step  = 1;
        unset($_SESSION['fp_step']);
    } else {
        $pw_errors = [];
        if (strlen($password) < 8)                      $pw_errors[] = "at least 8 characters";
        if (!preg_match('/[A-Z]/', $password))           $pw_errors[] = "one uppercase letter";
        if (!preg_match('/[0-9]/', $password))           $pw_errors[] = "one number";
        if (!preg_match('/[^a-zA-Z0-9]/', $password))   $pw_errors[] = "one special character";
        if ($password !== $confirm_password)             $pw_errors[] = "passwords must match";

        if (!empty($pw_errors)) {
            $error = "Password requires: " . implode(', ', $pw_errors) . ".";
        } else {
            try {
                $pdo  = getDB($DB_HOST, $DB_NAME, $DB_USER, $DB_PASS);
                $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                $stmt->execute([
                    password_hash($password, PASSWORD_BCRYPT),
                    $_SESSION['fp_user_id']
                ]);

                // Clear all fp_ session data
                foreach (array_keys($_SESSION) as $k) {
                    if (str_starts_with($k, 'fp_')) unset($_SESSION[$k]);
                }
                $_SESSION['fp_step'] = 4;
                $step = 4;

            } catch (PDOException $e) {
                $error = "Database error: " . $e->getMessage();
            }
        }
    }
}

// Sync step from session when no POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $step = $_SESSION['fp_step'] ?? 1;
}

// ── Reset action ─────────────────────────────────────────────────
if (isset($_GET['restart'])) {
    foreach (array_keys($_SESSION) as $k) {
        if (str_starts_with($k, 'fp_')) unset($_SESSION[$k]);
    }
    header('Location: forgotpass.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Forgot Password – Evergreen Trust and Savings</title>
  <style>
    *, *::before, *::after {
      margin: 0; padding: 0; box-sizing: border-box;
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }

    body {
      background-color: #f9f5f0;
      color: #2d4a3e;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
    }

    /* ── Header ── */
    .header {
      background-color: #0a3b2f;
      padding: 16px 24px;
      display: flex;
      align-items: center;
      gap: 12px;
    }
    .logo  { height: 40px; }
    .logo-text { color: white; font-size: 18px; font-weight: 700; letter-spacing: .5px; }

    /* ── Progress stepper ── */
    .stepper {
      display: flex;
      justify-content: center;
      align-items: center;
      gap: 0;
      margin: 28px auto 0;
      max-width: 420px;
      width: 90%;
    }
    .step-item {
      display: flex;
      flex-direction: column;
      align-items: center;
      flex: 1;
      position: relative;
    }
    .step-item:not(:last-child)::after {
      content: '';
      position: absolute;
      top: 16px;
      left: 60%;
      width: 80%;
      height: 2px;
      background: #d4c9be;
      z-index: 0;
    }
    .step-item.done:not(:last-child)::after,
    .step-item.active:not(:last-child)::after {
      background: #0a3b2f;
    }
    .step-bubble {
      width: 34px; height: 34px;
      border-radius: 50%;
      background: #e0d8d0;
      color: #999;
      display: flex; align-items: center; justify-content: center;
      font-weight: 700; font-size: 14px;
      position: relative; z-index: 1;
      transition: background .3s, color .3s;
    }
    .step-item.active .step-bubble {
      background: #0a3b2f; color: white;
    }
    .step-item.done .step-bubble {
      background: #43a047; color: white;
    }
    .step-label {
      font-size: 11px; margin-top: 6px; color: #999;
      font-weight: 600; text-align: center;
    }
    .step-item.active .step-label { color: #0a3b2f; }
    .step-item.done  .step-label  { color: #43a047; }

    /* ── Card ── */
    .card {
      max-width: 420px;
      width: 90%;
      margin: 24px auto 40px;
      padding: 32px;
      background: white;
      border-radius: 12px;
      box-shadow: 0 4px 16px rgba(0,0,0,.08);
      animation: fadeUp .35s ease;
    }
    @keyframes fadeUp {
      from { opacity: 0; transform: translateY(14px); }
      to   { opacity: 1; transform: translateY(0); }
    }
    .card h2 {
      text-align: center;
      color: #0a3b2f;
      margin-bottom: 6px;
      font-size: 20px;
    }
    .card .subtitle {
      text-align: center;
      color: #777;
      font-size: 13px;
      margin-bottom: 24px;
      line-height: 1.5;
    }

    /* ── Alerts ── */
    .alert {
      padding: 10px 14px;
      border-radius: 8px;
      margin-bottom: 18px;
      font-size: 14px;
      line-height: 1.5;
    }
    .alert-error   { color: #c62828; background: #ffebee; border: 1px solid #ffcdd2; }
    .alert-success { color: #1b5e20; background: #e8f5e9; border: 1px solid #a5d6a7; }

    /* ── Form elements ── */
    .form-group { margin-bottom: 20px; }
    .form-group label {
      display: block; margin-bottom: 6px;
      font-weight: 600; font-size: 14px;
    }
    .form-group input {
      width: 100%; padding: 12px;
      border: 1px solid #ccc; border-radius: 8px;
      font-size: 15px; transition: border-color .2s;
    }
    .form-group input:focus { outline: none; border-color: #0a3b2f; }

    /* ── OTP digits ── */
    .otp-row {
      display: flex;
      gap: 10px;
      justify-content: center;
      margin: 10px 0 22px;
    }
    .otp-digit {
      width: 52px; height: 60px;
      border: 2px solid #ccc;
      border-radius: 10px;
      text-align: center;
      font-size: 28px;
      font-weight: 700;
      color: #0a3b2f;
      transition: border-color .2s, box-shadow .2s;
      background: #fafafa;
      caret-color: #0a3b2f;
    }
    .otp-digit:focus {
      outline: none;
      border-color: #0a3b2f;
      box-shadow: 0 0 0 3px rgba(10,59,47,.12);
      background: #fff;
    }

    /* ── Password wrapper ── */
    .password-wrapper { position: relative; display: flex; align-items: center; }
    .password-wrapper input { flex: 1; padding-right: 44px; }
    .toggle-eye {
      position: absolute; right: 12px;
      background: none; border: none;
      cursor: pointer; color: #666; font-size: 18px; padding: 0;
    }
    .toggle-eye:hover { color: #0a3b2f; }

    /* ── Password strength ── */
    .strength-bar-container {
      margin-top: 8px; height: 6px;
      background: #e0e0e0; border-radius: 4px; overflow: hidden;
    }
    .strength-bar {
      height: 100%; width: 0;
      border-radius: 4px;
      transition: width .3s, background-color .3s;
    }
    .strength-label { font-size: 12px; margin-top: 5px; font-weight: 600; }
    .strength-rules { margin-top: 8px; display: flex; flex-wrap: wrap; gap: 6px; }
    .rule {
      font-size: 11px; padding: 2px 8px; border-radius: 20px;
      background: #f0f0f0; color: #888; transition: all .2s;
    }
    .rule.met { background: #e8f5e9; color: #2e7d32; }

    /* ── Confirm feedback ── */
    .confirm-feedback {
      font-size: 12px; font-weight: 600;
      margin-top: 6px; min-height: 18px;
    }
    .confirm-feedback.match    { color: #2e7d32; }
    .confirm-feedback.mismatch { color: #d32f2f; }
    #confirm_password.match    { border-color: #43a047; background: #f8fff8; }
    #confirm_password.mismatch { border-color: #e53935; background: #fff8f8; }

    /* ── Buttons ── */
    .btn-primary {
      width: 100%; padding: 13px;
      background: #0a3b2f; color: white;
      border: none; border-radius: 8px;
      font-size: 16px; font-weight: 600;
      cursor: pointer; transition: background .3s;
      margin-top: 4px;
    }
    .btn-primary:hover { background: #082e24; }
    .btn-link {
      background: none; border: none;
      color: #0a3b2f; font-weight: 600;
      font-size: 13px; cursor: pointer;
      text-decoration: underline; padding: 0;
    }
    .btn-link:hover { color: #082e24; }

    /* ── Bottom links ── */
    .bottom-links {
      text-align: center; margin-top: 18px;
      font-size: 13px; color: #666;
      display: flex; flex-direction: column; gap: 8px;
    }
    .bottom-links a { color: #0a3b2f; text-decoration: none; font-weight: 600; }
    .bottom-links a:hover { text-decoration: underline; }

    /* ── Success screen ── */
    .success-icon {
      text-align: center;
      font-size: 56px;
      margin-bottom: 12px;
    }
    .timer-text {
      font-size: 12px; color: #888;
      text-align: center; margin-top: 10px;
    }
    #countdown { font-weight: 700; color: #0a3b2f; }

    /* ── Generate btn ── */
    .generate-row {
      display: flex;
      gap: 8px;
      align-items: center;
    }
    .generate-row .password-wrapper { flex: 1; }
    .btn-generate {
      white-space: nowrap; padding: 10px 14px;
      background: #e8f5e9; color: #0a3b2f;
      border: 1px solid #a5d6a7; border-radius: 8px;
      font-size: 13px; font-weight: 600;
      cursor: pointer; transition: background .2s; flex-shrink: 0;
    }
    .btn-generate:hover { background: #c8e6c9; }
    #copy-toast { font-size: 11px; color: #2e7d32; margin-top: 4px; display: none; }
  </style>
</head>
<body>

<div class="header">
  <img src="pictures/logo.png" alt="Evergreen Logo" class="logo">
  <div class="logo-text">EVERGREEN</div>
</div>

<?php
// ── Stepper (only steps 1–3) ──────────────────────────────────
if ($step < 4):
  $labels = ['Verify Identity', 'Enter Code', 'New Password'];
?>
<div class="stepper">
  <?php foreach ($labels as $i => $label):
    $n = $i + 1;
    $cls = $n < $step ? 'done' : ($n === $step ? 'active' : '');
    $icon = $n < $step ? '✓' : $n;
  ?>
    <div class="step-item <?= $cls ?>">
      <div class="step-bubble"><?= $icon ?></div>
      <div class="step-label"><?= $label ?></div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card">

<?php if ($step === 1): ?>
  <!-- ══════════════════════════════════════════
       STEP 1 – Verify Identity
  ══════════════════════════════════════════ -->
  <h2>🔐 Forgot Password?</h2>
  <p class="subtitle">Enter your registered bank account number and email to receive a reset code.</p>

  <?php if ($error):  ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <?php if ($notice): ?><div class="alert alert-success"><?= $notice ?></div><?php endif; ?>

  <form method="POST" action="forgotpass.php">
    <input type="hidden" name="action" value="verify_identity">

    <div class="form-group">
      <label for="account_number">Bank Account Number</label>
      <input type="text" id="account_number" name="account_number"
             required placeholder="e.g. 1234567890"
             inputmode="numeric" maxlength="20"
             value="<?= htmlspecialchars($_POST['account_number'] ?? '') ?>">
    </div>

    <div class="form-group">
      <label for="user_email">Registered Email Address</label>
      <input type="email" id="user_email" name="user_email"
             required placeholder="john@example.com"
             value="<?= htmlspecialchars($_POST['user_email'] ?? '') ?>">
    </div>

    <button type="submit" class="btn-primary">Send Reset Code →</button>
  </form>

  <div class="bottom-links">
    <span>Remembered your password? <a href="login.php">Login here</a></span>
  </div>

<?php elseif ($step === 2): ?>
  <!-- ══════════════════════════════════════════
       STEP 2 – OTP Entry
  ══════════════════════════════════════════ -->
  <h2>📩 Check Your Email</h2>
  <p class="subtitle">
    We sent a 6-digit code to<br>
    <strong><?= htmlspecialchars($_SESSION['fp_email'] ?? '') ?></strong>
  </p>

  <?php if ($error):  ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <?php if ($notice): ?><div class="alert alert-success"><?= $notice ?></div><?php endif; ?>

  <form method="POST" action="forgotpass.php" id="otp-form">
    <input type="hidden" name="action" value="verify_otp">

    <div class="otp-row">
      <?php for ($i = 0; $i < 6; $i++): ?>
        <input type="text" class="otp-digit" name="otp_digit[]"
               maxlength="1" inputmode="numeric" pattern="\d"
               autocomplete="off" required>
      <?php endfor; ?>
    </div>

    <button type="submit" class="btn-primary">Verify Code →</button>
  </form>

  <div class="timer-text">
    Code expires in <span id="countdown">10:00</span>
  </div>

  <div class="bottom-links" style="margin-top: 16px;">
    <span>Didn't receive it?
      <form method="POST" action="forgotpass.php" style="display:inline;">
        <input type="hidden" name="action" value="resend_otp">
        <button type="submit" class="btn-link">Resend code</button>
      </form>
    </span>
    <span><a href="forgotpass.php?restart=1">← Start over</a></span>
  </div>

<?php elseif ($step === 3): ?>
  <!-- ══════════════════════════════════════════
       STEP 3 – New Password
  ══════════════════════════════════════════ -->
  <h2>🔑 Set New Password</h2>
  <p class="subtitle">Choose a strong new password for your account.</p>

  <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <form method="POST" action="forgotpass.php">
    <input type="hidden" name="action" value="change_password">

    <div class="form-group">
      <label>New Password</label>
      <div class="generate-row">
        <div class="password-wrapper">
          <input type="password" id="password" name="password"
                 required placeholder="Create a strong password"
                 oninput="checkStrength(this.value); checkConfirm()">
          <button type="button" class="toggle-eye"
                  onclick="toggleVis('password',this)">👁</button>
        </div>
        <button type="button" class="btn-generate" onclick="generatePassword()">⚡ Generate</button>
      </div>
      <div class="strength-bar-container">
        <div class="strength-bar" id="strength-bar"></div>
      </div>
      <div class="strength-label" id="strength-label"></div>
      <div class="strength-rules">
        <span class="rule" id="rule-len">8+ chars</span>
        <span class="rule" id="rule-upper">Uppercase</span>
        <span class="rule" id="rule-num">Number</span>
        <span class="rule" id="rule-special">Special char</span>
      </div>
      <div id="copy-toast">✓ Password copied to clipboard</div>
    </div>

    <div class="form-group">
      <label>Confirm New Password</label>
      <div class="password-wrapper">
        <input type="password" id="confirm_password" name="confirm_password"
               required placeholder="Re-enter password"
               oninput="checkConfirm()">
        <button type="button" class="toggle-eye"
                onclick="toggleVis('confirm_password',this)">👁</button>
      </div>
      <div class="confirm-feedback" id="confirm-feedback"></div>
    </div>

    <button type="submit" class="btn-primary">Save New Password →</button>
  </form>

<?php elseif ($step === 4): ?>
  <!-- ══════════════════════════════════════════
       STEP 4 – Success
  ══════════════════════════════════════════ -->
  <div class="success-icon">🎉</div>
  <h2>Password Updated!</h2>
  <p class="subtitle" style="margin-bottom:28px;">
    Your password has been changed successfully.<br>
    You can now log in with your new credentials.
  </p>
  <a href="login.php" class="btn-primary" style="display:block;text-align:center;text-decoration:none;">
    Go to Login →
  </a>
  <p class="timer-text">Redirecting in <span id="redir-count">5</span>s…</p>

<?php endif; ?>

</div><!-- /.card -->

<script>
/* ── OTP input: auto-advance, backspace, paste ── */
(function () {
  const digits = document.querySelectorAll('.otp-digit');
  if (!digits.length) return;

  digits.forEach((el, i) => {
    el.addEventListener('input', e => {
      const val = e.target.value.replace(/\D/g, '');
      e.target.value = val.slice(-1);
      if (val && i < digits.length - 1) digits[i + 1].focus();
    });

    el.addEventListener('keydown', e => {
      if (e.key === 'Backspace' && !el.value && i > 0) digits[i - 1].focus();
    });
  });

  // Paste: spread 6 digits across boxes
  digits[0].addEventListener('paste', e => {
    e.preventDefault();
    const pasted = (e.clipboardData || window.clipboardData)
      .getData('text').replace(/\D/g, '').slice(0, 6);
    pasted.split('').forEach((ch, idx) => {
      if (digits[idx]) digits[idx].value = ch;
    });
    const nextEmpty = [...digits].findIndex(d => !d.value);
    (digits[nextEmpty] || digits[digits.length - 1]).focus();
  });
})();

/* ── OTP countdown timer ── */
(function () {
  const el = document.getElementById('countdown');
  if (!el) return;
  const expires = <?= json_encode($_SESSION['fp_pin_expires'] ?? (time() + 600)) ?>;
  function tick() {
    const left = expires - Math.floor(Date.now() / 1000);
    if (left <= 0) { el.textContent = 'Expired'; el.style.color = '#e53935'; return; }
    const m = String(Math.floor(left / 60)).padStart(2, '0');
    const s = String(left % 60).padStart(2, '0');
    el.textContent = m + ':' + s;
    setTimeout(tick, 1000);
  }
  tick();
})();

/* ── Redirect countdown on success ── */
(function () {
  const el = document.getElementById('redir-count');
  if (!el) return;
  let n = 5;
  const t = setInterval(() => {
    n--;
    el.textContent = n;
    if (n <= 0) { clearInterval(t); location.href = 'login.php'; }
  }, 1000);
})();

/* ── Password strength checker ── */
function checkStrength(val) {
  const bar = document.getElementById('strength-bar');
  const lbl = document.getElementById('strength-label');
  if (!bar) return;
  const r = {
    len:     val.length >= 8,
    upper:   /[A-Z]/.test(val),
    num:     /[0-9]/.test(val),
    special: /[^a-zA-Z0-9]/.test(val)
  };
  ['len','upper','num','special'].forEach(k =>
    document.getElementById('rule-' + k).classList.toggle('met', r[k])
  );
  const s = Object.values(r).filter(Boolean).length;
  const L = [
    {w:'0%',  c:'#e0e0e0', t:'',          tc:'#999'},
    {w:'25%', c:'#e53935', t:'Weak',       tc:'#e53935'},
    {w:'50%', c:'#fb8c00', t:'Fair',       tc:'#fb8c00'},
    {w:'75%', c:'#fdd835', t:'Good',       tc:'#f9a825'},
    {w:'100%',c:'#43a047', t:'Strong ✓',  tc:'#2e7d32'}
  ];
  const lv = val.length === 0 ? L[0] : L[s];
  bar.style.width = lv.w;
  bar.style.backgroundColor = lv.c;
  lbl.textContent = lv.t;
  lbl.style.color = lv.tc;
}

/* ── Confirm match feedback ── */
function checkConfirm() {
  const p = document.getElementById('password');
  const c = document.getElementById('confirm_password');
  const f = document.getElementById('confirm-feedback');
  if (!c || !c.value) {
    c && c.classList.remove('match','mismatch');
    if (f) f.textContent = '';
    return;
  }
  const ok = c.value === p.value;
  c.classList.toggle('match', ok);
  c.classList.toggle('mismatch', !ok);
  f.className = 'confirm-feedback ' + (ok ? 'match' : 'mismatch');
  f.textContent = ok ? '✓ Passwords match' : '✗ Passwords do not match';
}

/* ── Show/hide password ── */
function toggleVis(id, btn) {
  const f = document.getElementById(id);
  f.type = f.type === 'password' ? 'text' : 'password';
  btn.textContent = f.type === 'password' ? '👁' : '🙈';
}

/* ── Generate password ── */
function generatePassword() {
  const U = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
        Lo = 'abcdefghijklmnopqrstuvwxyz',
        N = '0123456789',
        S = '!@#$%^&*()_+-=[]{}|;:,.<>?',
        A = U + Lo + N + S;
  const rnd = s => s[Math.floor(Math.random() * s.length)];
  let p = [rnd(U), rnd(Lo), rnd(N), rnd(S)];
  for (let i = 4; i < 14; i++) p.push(rnd(A));
  p = p.sort(() => Math.random() - .5).join('');
  ['password','confirm_password'].forEach(id => {
    const f = document.getElementById(id);
    if (f) { f.value = p; f.type = 'text'; }
  });
  checkStrength(p); checkConfirm();
  navigator.clipboard.writeText(p).then(() => {
    const t = document.getElementById('copy-toast');
    if (t) { t.style.display = 'block'; setTimeout(() => t.style.display = 'none', 3000); }
  });
}
</script>
</body>
</html>