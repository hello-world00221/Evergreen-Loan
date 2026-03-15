<?php
session_start();

// ── Load PHPMailer FIRST, then declare use ───────────────────────
require 'PHPMailer-7.0.0/src/Exception.php';
require 'PHPMailer-7.0.0/src/PHPMailer.php';
require 'PHPMailer-7.0.0/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

// ── Mail config (variables, NOT define — avoids "already defined" crash) ──
$MAIL_HOST      = 'smtp.gmail.com';
$MAIL_PORT      = 587;
$MAIL_USERNAME  = 'franciscarpeso@gmail.com';
$MAIL_PASSWORD  = 'bwobttvnbpqvzimv';
$MAIL_FROM      = 'franciscarpeso@gmail.com';
$MAIL_FROM_NAME = 'Evergreen Trust and Savings';

// ── Send 6-digit PIN ─────────────────────────────────────────────
function sendVerificationPin(
    string $toEmail, string $toName, string $pin,
    string $host, int $port, string $user, string $pass,
    string $from, string $fromName
): bool|string {
    $mail = new PHPMailer(true);
    try {
        $mail->SMTPDebug  = SMTP::DEBUG_OFF;
        $mail->isSMTP();
        $mail->SMTPKeepAlive = false;
        $mail->Host       = $host;
        $mail->SMTPAuth   = true;
        $mail->Username   = $user;
        $mail->Password   = $pass;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $port;
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ]
        ];
        $mail->Timeout = 60;
        $mail->setFrom($from, $fromName);
        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);
        $mail->Subject = 'Your Evergreen Verification Code';
        $mail->Body    = getEmailBody($toName, $pin);
        $mail->AltBody = "Hello, {$toName}!\n\nYour code: {$pin}\n\nExpires in 10 minutes.";
     $mail->send();
    return true;

    } catch (Exception $e) {

    // If email was actually accepted by SMTP, treat as success
    if ($mail->ErrorInfo === '') {
        return true;
    }

    return $mail->ErrorInfo;
}
}

function getEmailBody(string $name, string $pin): string {
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <link rel="icon" type="logo/png" href="pictures/logo.png" /> 

</head>
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
              Thank you for registering with <strong>Evergreen Trust and Savings</strong>.
              Enter the code below to complete setup. Valid for <strong>10 minutes</strong>.
            </p>
            <table width="100%" cellpadding="0" cellspacing="0">
              <tr>
                <td align="center" style="padding:10px 0 28px;">
                  <div style="display:inline-block;background:#f0faf3;border:2px dashed #43a047;border-radius:12px;padding:22px 52px;">
                    <p style="margin:0 0 8px;color:#888;font-size:12px;text-transform:uppercase;letter-spacing:1.5px;">Verification Code</p>
                    <p style="margin:0;font-size:44px;font-weight:800;letter-spacing:14px;color:#0a3b2f;">{$pin}</p>
                  </div>
                </td>
              </tr>
            </table>
            <p style="color:#888;font-size:13px;">Never share this code with anyone.</p>
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
//  POST handler
// ════════════════════════════════════════════════════════════════
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $full_name        = trim($_POST['full_name']        ?? '');
    $user_email       = trim($_POST['user_email']       ?? '');
    $password         = $_POST['password']              ?? '';
    $confirm_password = $_POST['confirm_password']      ?? '';
    $role             = $_POST['role']                  ?? '';
    $account_number   = trim($_POST['account_number']   ?? '');
    $contact_number   = trim($_POST['contact_number']   ?? '');

    // ── Validation ──
    if (empty($full_name))
        $errors[] = "Full name is required.";
    if (empty($user_email) || !filter_var($user_email, FILTER_VALIDATE_EMAIL))
        $errors[] = "A valid email address is required.";
    if (strlen($password) < 8)
        $errors[] = "Password must be at least 8 characters.";
    if (!preg_match('/[A-Z]/', $password))
        $errors[] = "Password must contain at least one uppercase letter.";
    if (!preg_match('/[0-9]/', $password))
        $errors[] = "Password must contain at least one number.";
    if (!preg_match('/[^a-zA-Z0-9]/', $password))
        $errors[] = "Password must contain at least one special character.";
    if ($password !== $confirm_password)
        $errors[] = "Passwords do not match.";
    if (!in_array($role, ['Admin', 'User']))
        $errors[] = "Please select a valid account type.";
    if (empty($account_number))
        $errors[] = "Bank account number is required.";
    elseif (!preg_match('/^\d{8,20}$/', $account_number))
        $errors[] = "Account number must be 8–20 digits.";
    if (empty($contact_number))
        $errors[] = "Contact number is required.";
    elseif (!preg_match('/^[0-9+\-\s()]{7,20}$/', $contact_number))
        $errors[] = "Please enter a valid contact number.";

    // ── DB duplicate checks ──
    if (empty($errors)) {
        $dbhost = 'localhost'; $dbname = 'loandb'; $dbuser = 'root'; $dbpass = '';
        try {
            $pdo = new PDO(
                "mysql:host=$dbhost;dbname=$dbname;charset=utf8mb4",
                $dbuser, $dbpass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            $check = $pdo->prepare("SELECT id FROM users WHERE user_email = ?");
            $check->execute([$user_email]);
            if ($check->fetch()) $errors[] = "An account with that email already exists.";

            if (empty($errors)) {
                $checkAcc = $pdo->prepare("SELECT id FROM users WHERE account_number = ?");
                $checkAcc->execute([$account_number]);
                if ($checkAcc->fetch()) $errors[] = "That bank account number is already registered.";
            }

            // ── All clear: generate PIN, store session, send email ──
            if (empty($errors)) {
                $pin = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

                // Wipe any leftover pending registration
                unset($_SESSION['pending_reg']);

                $_SESSION['pending_reg'] = [
                    'full_name'      => $full_name,
                    'user_email'     => $user_email,
                    'password_hash'  => password_hash($password, PASSWORD_BCRYPT),
                    'role'           => $role,
                    'account_number' => $account_number,
                    'contact_number' => $contact_number,
                    'pin'            => $pin,
                    'pin_expires'    => time() + 600,
                    'attempts'       => 0,
                ];

                $result = sendVerificationPin(
                    $user_email, $full_name, $pin,
                    $MAIL_HOST, $MAIL_PORT, $MAIL_USERNAME,
                    $MAIL_PASSWORD, $MAIL_FROM, $MAIL_FROM_NAME
                );

                if ($result === true || $result === '') {
                    session_write_close();
                    header('Location: verify-email.php');
                exit;
                } else {
                    $errors[] = "Failed to send verification email: " . $result;
                }
            }

        } catch (PDOException $e) {
            $errors[] = "Database error: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Register – Evergreen Trust and Savings</title>
  <style>
    * { margin:0; padding:0; box-sizing:border-box;
        font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif; }
    body { background-color:#f9f5f0; color:#2d4a3e; min-height:100vh;
           display:flex; flex-direction:column; }
    .header { background-color:#0a3b2f; padding:16px 24px;
              display:flex; align-items:center; gap:12px; }
    .logo { height:40px; }
    .logo-text { color:white; font-size:18px; font-weight:700; letter-spacing:.5px; }
    .register-container { max-width:480px; width:90%; margin:40px auto;
        padding:32px; background:white; border-radius:10px;
        box-shadow:0 4px 12px rgba(0,0,0,.08); }
    .register-container h2 { text-align:center; margin-bottom:24px; color:#0a3b2f; }
    .alert { padding:10px 14px; border-radius:6px; margin-bottom:20px;
             text-align:center; font-size:14px; }
    .alert-error { color:#d32f2f; background:#ffebee; border:1px solid #ffcdd2; }
    .form-group { margin-bottom:20px; }
    .form-group label { display:block; margin-bottom:6px; font-weight:600; font-size:14px; }
    .form-group input, .form-group select {
        width:100%; padding:12px; border:1px solid #ccc; border-radius:6px;
        font-size:15px; transition:border-color .2s; background:#fff; }
    .form-group input:focus, .form-group select:focus
        { outline:none; border-color:#0a3b2f; }
    .password-wrapper { position:relative; display:flex; align-items:center; gap:8px; }
    .password-wrapper input { flex:1; padding-right:42px; }
    .toggle-eye { position:absolute; right:12px; background:none; border:none;
        cursor:pointer; color:#666; font-size:18px; padding:0;
        display:flex; align-items:center; }
    .toggle-eye:hover { color:#0a3b2f; }
    .btn-generate { white-space:nowrap; padding:10px 14px; background:#e8f5e9;
        color:#0a3b2f; border:1px solid #a5d6a7; border-radius:6px; font-size:13px;
        font-weight:600; cursor:pointer; transition:background .2s; flex-shrink:0; }
    .btn-generate:hover { background:#c8e6c9; }
    .strength-bar-container { margin-top:8px; height:6px; background:#e0e0e0;
        border-radius:4px; overflow:hidden; }
    .strength-bar { height:100%; width:0%; border-radius:4px;
        transition:width .3s ease,background-color .3s ease; }
    .strength-label { font-size:12px; margin-top:5px; font-weight:600; }
    .strength-rules { margin-top:8px; display:flex; flex-wrap:wrap; gap:6px; }
    .rule { font-size:11px; padding:2px 8px; border-radius:20px;
            background:#f0f0f0; color:#888; transition:all .2s; }
    .rule.met { background:#e8f5e9; color:#2e7d32; }
    .btn-register { width:100%; padding:13px; background:#0a3b2f; color:white;
        border:none; border-radius:6px; font-size:16px; font-weight:600;
        cursor:pointer; transition:background .3s; margin-top:10px; }
    .btn-register:hover { background:#082e24; }
    .login-link { text-align:center; margin-top:20px; font-size:14px; }
    .login-link a { color:#0a3b2f; text-decoration:none; font-weight:600; }
    .login-link a:hover { text-decoration:underline; }
    #copy-toast { display:none; font-size:11px; color:#2e7d32; margin-top:4px; }
    .confirm-feedback { font-size:12px; font-weight:600; margin-top:6px;
        display:flex; align-items:center; gap:5px; min-height:18px; }
    .confirm-feedback.mismatch { color:#d32f2f; }
    .confirm-feedback.match    { color:#2e7d32; }
    #confirm_password.mismatch { border-color:#e53935; background:#fff8f8; }
    #confirm_password.match    { border-color:#43a047; background:#f8fff8; }
  </style>
</head>
<body>

<div class="header">
  <img src="pictures/logo.png" alt="Evergreen Logo" class="logo">
  <div class="logo-text">EVERGREEN</div>
</div>

<div class="register-container">
  <h2>Create an Account</h2>

  <?php if (!empty($errors)): ?>
    <div class="alert alert-error">
      <?= implode('<br>', array_map('htmlspecialchars', $errors)) ?>
    </div>
  <?php endif; ?>

  <form method="POST" action="register-account.php">

    <div class="form-group">
      <label for="full_name">Full Name</label>
      <input type="text" id="full_name" name="full_name" required placeholder="John Doe"
             value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>"/>
    </div>

    <div class="form-group">
      <label for="user_email">Email Address</label>
      <input type="email" id="user_email" name="user_email" required
             placeholder="john@example.com"
             value="<?= htmlspecialchars($_POST['user_email'] ?? '') ?>"/>
    </div>

    <div class="form-group">
      <label for="account_number">Bank Account Number</label>
      <input type="text" id="account_number" name="account_number" required
             placeholder="e.g. 1234567890" maxlength="20"
             inputmode="numeric" pattern="\d{8,20}" title="8 to 20 digits only"
             value="<?= htmlspecialchars($_POST['account_number'] ?? '') ?>"/>
    </div>

    <div class="form-group">
      <label for="contact_number">Contact Number</label>
      <input type="tel" id="contact_number" name="contact_number" required
             placeholder="e.g. +63 912 345 6789" maxlength="20"
             value="<?= htmlspecialchars($_POST['contact_number'] ?? '') ?>"/>
    </div>

    <div class="form-group">
      <label for="password">Password</label>
      <div class="password-wrapper">
        <input type="password" id="password" name="password" required
               placeholder="Create a strong password"
               oninput="checkStrength(this.value); checkConfirm()"/>
        <button type="button" class="toggle-eye"
                onclick="toggleVis('password',this)">👁</button>
        <button type="button" class="btn-generate"
                onclick="generatePassword()">⚡ Generate</button>
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
      <label for="confirm_password">Confirm Password</label>
      <div class="password-wrapper">
        <input type="password" id="confirm_password" name="confirm_password" required
               placeholder="Re-enter password" oninput="checkConfirm()"/>
        <button type="button" class="toggle-eye"
                onclick="toggleVis('confirm_password',this)">👁</button>
      </div>
      <div class="confirm-feedback" id="confirm-feedback"></div>
    </div>

    <div class="form-group">
      <label for="role">Account Type</label>
      <select id="role" name="role" required>
        <option value="" disabled selected>Select your role</option>
        <option value="User"  <?= (($_POST['role'] ?? '') === 'User')  ? 'selected' : '' ?>>User</option>
        <option value="Admin" <?= (($_POST['role'] ?? '') === 'Admin') ? 'selected' : '' ?>>Admin</option>
      </select>
    </div>

    <button type="submit" class="btn-register">Register &amp; Send Code →</button>
  </form>

  <div class="login-link">
    Already have an account? <a href="login.php">Login here</a>
  </div>
</div>

<script>
  function toggleVis(id, btn) {
    const f = document.getElementById(id);
    f.type = f.type === 'password' ? 'text' : 'password';
    btn.textContent = f.type === 'password' ? '👁' : '🙈';
  }
  function checkStrength(val) {
    const bar=document.getElementById('strength-bar'),lbl=document.getElementById('strength-label');
    const r={len:val.length>=8,upper:/[A-Z]/.test(val),num:/[0-9]/.test(val),special:/[^a-zA-Z0-9]/.test(val)};
    ['len','upper','num','special'].forEach(k=>document.getElementById('rule-'+k).classList.toggle('met',r[k]));
    const s=Object.values(r).filter(Boolean).length;
    const L=[{w:'0%',c:'#e0e0e0',t:'',tc:'#999'},{w:'25%',c:'#e53935',t:'Weak',tc:'#e53935'},
             {w:'50%',c:'#fb8c00',t:'Fair',tc:'#fb8c00'},{w:'75%',c:'#fdd835',t:'Good',tc:'#f9a825'},
             {w:'100%',c:'#43a047',t:'Strong ✓',tc:'#2e7d32'}];
    const lv=val.length===0?L[0]:L[s];
    bar.style.width=lv.w;bar.style.backgroundColor=lv.c;lbl.textContent=lv.t;lbl.style.color=lv.tc;
  }
  function generatePassword() {
    const U='ABCDEFGHIJKLMNOPQRSTUVWXYZ',L='abcdefghijklmnopqrstuvwxyz',
          N='0123456789',S='!@#$%^&*()_+-=[]{}|;:,.<>?',A=U+L+N+S;
    let p=[U[r(U)],L[r(L)],N[r(N)],S[r(S)]];
    for(let i=4;i<14;i++) p.push(A[r(A)]);
    p=p.sort(()=>Math.random()-.5).join('');
    ['password','confirm_password'].forEach(id=>{const f=document.getElementById(id);f.value=p;f.type='text';});
    checkStrength(p);checkConfirm();
    navigator.clipboard.writeText(p).then(()=>{
      const t=document.getElementById('copy-toast');t.style.display='block';setTimeout(()=>t.style.display='none',3000);
    });
  }
  function r(s){return Math.floor(Math.random()*s.length);}
  function checkConfirm(){
    const p=document.getElementById('password').value,c=document.getElementById('confirm_password'),
          f=document.getElementById('confirm-feedback');
    if(!c.value){c.classList.remove('mismatch','match');f.textContent='';return;}
    const ok=c.value===p;
    c.classList.toggle('match',ok);c.classList.toggle('mismatch',!ok);
    f.className='confirm-feedback '+(ok?'match':'mismatch');
    f.textContent=ok?'✓ Passwords match':'✗ Passwords do not match';
  }
  document.getElementById('account_number').addEventListener('input',function(){
    this.value=this.value.replace(/\D/g,'');
  });
</script>
</body>
</html>