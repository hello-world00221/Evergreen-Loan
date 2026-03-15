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

// Redirect if no pending registration in session
if (empty($_SESSION['pending_reg'])) {
    header('Location: register-account.php');
    exit;
}

// ── Plain copy of session data (never use a reference — breaks after session_destroy) ──
$reg          = $_SESSION['pending_reg'];
$error        = '';
$info         = '';
$show_success = false;

// ── Resend PIN ───────────────────────────────────────────────────
if (isset($_POST['resend'])) {
    $pin = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    $_SESSION['pending_reg']['pin']         = $pin;
    $_SESSION['pending_reg']['pin_expires'] = time() + 600;
    $_SESSION['pending_reg']['attempts']    = 0;
    $reg = $_SESSION['pending_reg']; // re-sync local copy

    $mail = new PHPMailer(true);
    try {
        $mail->SMTPDebug  = SMTP::DEBUG_OFF;
        $mail->isSMTP();
        $mail->Host       = $MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = $MAIL_USERNAME;
        $mail->Password   = $MAIL_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $MAIL_PORT;
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ]
        ];
        $mail->Timeout = 30;
        $mail->setFrom($MAIL_FROM, $MAIL_FROM_NAME);
        $mail->addAddress($reg['user_email'], $reg['full_name']);
        $mail->isHTML(true);
        $mail->Subject = 'Your New Evergreen Verification Code';
        $mail->Body    = getEmailBody($reg['full_name'], $pin);
        $mail->AltBody = "Hello {$reg['full_name']},\n\nYour new code is: {$pin}\n\nExpires in 10 minutes.";
        $mail->send();
        $info = "A new code was sent to " . htmlspecialchars($reg['user_email']) . ".";
    } catch (Exception $e) {
        $error = "Could not resend: " . $mail->ErrorInfo;
    }
}

// ── Verify PIN ───────────────────────────────────────────────────
if (isset($_POST['verify'])) {

    // Accept PIN from the hidden field (filled by JS) OR from d1–d6 fallback fields
    if (!empty($_POST['pin']) && strlen(trim($_POST['pin'])) === 6) {
        $entered = trim($_POST['pin']);
    } else {
        $entered = trim(
            ($_POST['d1'] ?? '') . ($_POST['d2'] ?? '') . ($_POST['d3'] ?? '') .
            ($_POST['d4'] ?? '') . ($_POST['d5'] ?? '') . ($_POST['d6'] ?? '')
        );
    }

    if ($reg['attempts'] >= 5) {
        session_destroy();
        header('Location: register-account.php?error=toomany');
        exit;
    }

    if (time() > $reg['pin_expires']) {
        $error = "Your code has expired. Please request a new one below.";

    } elseif ($entered !== $reg['pin']) {
        $_SESSION['pending_reg']['attempts']++;
        $reg['attempts']++;
        $left  = 5 - $reg['attempts'];
        $error = "Incorrect code. {$left} attempt(s) remaining.";

    } else {
        // ✅ Correct PIN — insert into DB
        $dbhost = 'localhost'; $dbname = 'loandb'; $dbuser = 'root'; $dbpass = '';
        try {
            $pdo = new PDO(
                "mysql:host=$dbhost;dbname=$dbname;charset=utf8mb4",
                $dbuser, $dbpass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $stmt = $pdo->prepare(
                "INSERT INTO users (full_name, user_email, password_hash, role, account_number, contact_number)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $reg['full_name'], $reg['user_email'], $reg['password_hash'],
                $reg['role'], $reg['account_number'], $reg['contact_number'],
            ]);

            session_destroy();
            $show_success = true;

        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
}

// ── Email HTML helper ────────────────────────────────────────────
function getEmailBody(string $name, string $pin): string {
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
              Here is your new 6-digit verification code. Valid for <strong>10 minutes</strong>.
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

function maskEmail(string $email): string {
    [$local, $domain] = explode('@', $email, 2);
    return mb_substr($local, 0, 1) . str_repeat('*', max(3, mb_strlen($local) - 1)) . '@' . $domain;
}

// Compute display values from local $reg (safe even after session_destroy)
$maskedEmail  = maskEmail($reg['user_email']);
$secondsLeft  = max(0, $reg['pin_expires'] - time());
$expired      = ($secondsLeft === 0 && !$show_success);
$attemptsDone = (int)$reg['attempts'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>Verify Email – Evergreen Trust and Savings</title>
  <style>
    * { margin:0; padding:0; box-sizing:border-box;
        font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif; }
    body { background:#f9f5f0; color:#2d4a3e; min-height:100vh; display:flex; flex-direction:column; }
    .header { background:#0a3b2f; padding:16px 24px; display:flex; align-items:center; gap:12px; }
    .logo { height:40px; }
    .logo-text { color:white; font-size:18px; font-weight:700; letter-spacing:.5px; }
    .card { max-width:440px; width:90%; margin:52px auto; padding:36px 32px;
            background:white; border-radius:12px; box-shadow:0 4px 16px rgba(0,0,0,.09); text-align:center; }
    .icon { font-size:52px; margin-bottom:16px; }
    .card h2 { color:#0a3b2f; font-size:22px; margin-bottom:10px; }
    .card p  { color:#666; font-size:14px; line-height:1.7; margin-bottom:24px; }
    .card p strong { color:#0a3b2f; }
    .alert { padding:10px 14px; border-radius:6px; margin-bottom:18px; font-size:14px; }
    .alert-error { color:#d32f2f; background:#ffebee; border:1px solid #ffcdd2; }
    .alert-info  { color:#1565c0; background:#e3f2fd; border:1px solid #90caf9; }
    .pin-row { display:flex; justify-content:center; gap:10px; margin-bottom:20px; }
    .pin-row input {
      width:48px; height:58px; text-align:center; font-size:26px; font-weight:700;
      border:2px solid #ccc; border-radius:8px; color:#0a3b2f;
      transition:border-color .2s, box-shadow .2s; }
    .pin-row input:focus { outline:none; border-color:#0a3b2f; box-shadow:0 0 0 3px rgba(10,59,47,.12); }
    .pin-row input.filled { border-color:#43a047; background:#f0faf3; }
    .pin-row input.error  { border-color:#e53935; background:#fff8f8; animation:shake .3s ease; }
    @keyframes shake { 0%,100%{transform:translateX(0)} 25%{transform:translateX(-4px)} 75%{transform:translateX(4px)} }
    .timer { font-size:13px; color:#888; margin-bottom:20px; }
    .timer span { font-weight:700; color:#0a3b2f; }
    .timer.warning span { color:#fb8c00; }
    .timer.expired span { color:#e53935; }
    .btn-verify { width:100%; padding:13px; background:#0a3b2f; color:white;
      border:none; border-radius:8px; font-size:16px; font-weight:600;
      cursor:pointer; transition:background .3s; margin-bottom:12px; }
    .btn-verify:hover:not(:disabled) { background:#082e24; }
    .btn-verify:disabled { background:#b0bec5; cursor:not-allowed; }
    .btn-resend { background:none; border:none; color:#0a3b2f; font-size:14px;
      font-weight:600; cursor:pointer; text-decoration:underline; padding:0; }
    .btn-resend:hover:not(:disabled) { color:#082e24; }
    .btn-resend:disabled { color:#aaa; cursor:not-allowed; text-decoration:none; }
    .resend-timer { font-size:12px; color:#999; margin-top:6px; }
    .back-link { margin-top:20px; font-size:13px; color:#999; }
    .back-link a { color:#0a3b2f; text-decoration:none; font-weight:600; }
    .back-link a:hover { text-decoration:underline; }
    .attempts-dots { display:flex; justify-content:center; gap:6px; margin-bottom:16px; }
    .dot { width:10px; height:10px; border-radius:50%; background:#e0e0e0; }
    .dot.used { background:#e53935; }
    /* Modal */
    @keyframes fadeIn  { from{opacity:0} to{opacity:1} }
    @keyframes slideUp { from{opacity:0;transform:translateY(30px) scale(.9)} to{opacity:1;transform:translateY(0) scale(1)} }
    @keyframes checkmark { 0%{transform:scale(0);opacity:0} 50%{transform:scale(1.2)} 100%{transform:scale(1);opacity:1} }
    @keyframes pulse { 0%,100%{box-shadow:0 0 0 0 rgba(10,59,47,.4)} 50%{box-shadow:0 0 0 20px rgba(10,59,47,0)} }
    @keyframes draw { to{stroke-dashoffset:0} }
    .modal-overlay { display:none; position:fixed; top:0; left:0; width:100%; height:100%;
      background:rgba(0,54,49,.85); backdrop-filter:blur(8px);
      align-items:center; justify-content:center; z-index:10000; animation:fadeIn .4s ease; }
    .modal-overlay.show { display:flex; }
    .modal-box { background:white; padding:3rem 2.5rem; border-radius:20px;
      box-shadow:0 25px 80px rgba(0,0,0,.4); max-width:480px; width:90%;
      text-align:center; animation:slideUp .5s cubic-bezier(.34,1.56,.64,1); }
    .modal-icon { width:100px; height:100px;
      background:linear-gradient(135deg,#0a3b2f 0%,#1a6b62 100%);
      border-radius:50%; display:flex; align-items:center; justify-content:center;
      margin:0 auto 2rem;
      animation:checkmark .6s ease .3s backwards, pulse 2s ease .9s infinite;
      box-shadow:0 10px 30px rgba(10,59,47,.3); }
    .modal-icon svg path { stroke-dasharray:50; stroke-dashoffset:50; animation:draw .5s ease .7s forwards; }
    .modal-box h3 { color:#0a3b2f; font-size:2rem; font-weight:700; letter-spacing:-.5px; margin-bottom:1rem; }
    .modal-box p  { color:#666; font-size:1.05rem; line-height:1.6; margin-bottom:2rem; }
    .modal-note { background:#f0f9f8; border-left:4px solid #1a6b62;
      padding:1rem 1.5rem; border-radius:8px; margin-bottom:2rem; text-align:left; }
    .modal-note p { color:#0a3b2f; font-size:.9rem; margin:0; line-height:1.5; }
    .modal-countdown { color:#999; font-size:.85rem; margin-bottom:1.5rem; }
    .modal-countdown span { color:#0a3b2f; font-weight:600; }
    .modal-btn { background:linear-gradient(135deg,#0a3b2f 0%,#1a6b62 100%);
      color:white; border:none; padding:14px 40px; border-radius:10px;
      font-size:15px; font-weight:600; cursor:pointer; transition:all .3s;
      box-shadow:0 4px 15px rgba(10,59,47,.3); letter-spacing:.5px; }
    .modal-btn:hover { transform:translateY(-2px); box-shadow:0 6px 20px rgba(10,59,47,.4); }
  </style>
</head>
<body>

<div class="header">
  <img src="pictures/logo.png" alt="Evergreen Logo" class="logo">
  <div class="logo-text">EVERGREEN</div>
</div>

<?php if (!$show_success): ?>
<div class="card">
  <div class="icon">📧</div>
  <h2>Verify your email</h2>
  <p>
    We sent a <strong>6-digit code</strong> to<br>
    <strong><?= htmlspecialchars($maskedEmail) ?></strong><br>
    Enter it below to complete your registration.
  </p>

  <?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>
  <?php if ($info): ?>
    <div class="alert alert-info"><?= htmlspecialchars($info) ?></div>
  <?php endif; ?>

  <div class="attempts-dots">
    <?php for ($i = 0; $i < 5; $i++): ?>
      <div class="dot <?= $i < $attemptsDone ? 'used' : '' ?>"></div>
    <?php endfor; ?>
  </div>

  <form method="POST" action="verify-email.php" id="verify-form">
    <!-- Hidden field filled by JS (primary method) -->
    <input type="hidden" name="pin" id="pin-hidden"/>

    <div class="pin-row" id="pin-row">
      <!-- d1–d6 are the PHP fallback if JS doesn't fire in time -->
      <input type="text" class="pin-digit" id="digit-1" name="d1" maxlength="1" inputmode="numeric" autocomplete="one-time-code" <?= $expired ? 'disabled' : '' ?>/>
      <input type="text" class="pin-digit" id="digit-2" name="d2" maxlength="1" inputmode="numeric" <?= $expired ? 'disabled' : '' ?>/>
      <input type="text" class="pin-digit" id="digit-3" name="d3" maxlength="1" inputmode="numeric" <?= $expired ? 'disabled' : '' ?>/>
      <input type="text" class="pin-digit" id="digit-4" name="d4" maxlength="1" inputmode="numeric" <?= $expired ? 'disabled' : '' ?>/>
      <input type="text" class="pin-digit" id="digit-5" name="d5" maxlength="1" inputmode="numeric" <?= $expired ? 'disabled' : '' ?>/>
      <input type="text" class="pin-digit" id="digit-6" name="d6" maxlength="1" inputmode="numeric" <?= $expired ? 'disabled' : '' ?>/>
    </div>

    <div class="timer <?= $expired ? 'expired' : '' ?>" id="timer-wrap">
      <?php if ($expired): ?>
        Code <span>expired</span> — request a new one below.
      <?php else: ?>
        Code expires in <span id="countdown"><?= gmdate('i:s', $secondsLeft) ?></span>
      <?php endif; ?>
    </div>

    <button type="submit" name="verify" class="btn-verify" id="btn-verify"
            <?= ($expired || $attemptsDone >= 5) ? 'disabled' : '' ?>>
      Verify Code
    </button>
  </form>

  <form method="POST" action="verify-email.php" id="resend-form">
    <button type="submit" name="resend" class="btn-resend" id="btn-resend">
      Didn't receive it? Resend code
    </button>
    <div class="resend-timer" id="resend-timer"></div>
  </form>

  <div class="back-link">
    Wrong email? <a href="register-account.php">Go back &amp; edit</a>
  </div>
</div>

<?php else: ?>
<div class="card" style="text-align:center;padding:60px 32px;">
  <div class="icon">✅</div>
  <p style="color:#0a3b2f;font-weight:600;">Account created! Redirecting...</p>
</div>
<?php endif; ?>

<!-- ── Success Modal ── -->
<div class="modal-overlay <?= $show_success ? 'show' : '' ?>" id="success-modal">
  <div class="modal-box">
    <div class="modal-icon">
      <svg width="50" height="50" viewBox="0 0 50 50">
        <path d="M 10 25 L 20 35 L 40 15" stroke="white" stroke-width="4"
              fill="none" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
    </div>
    <h3>Account Created!</h3>
    <p>Welcome to <strong style="color:#0a3b2f;">Evergreen Trust and Savings</strong>!<br>
       Your email has been verified and your account is ready.</p>
    <div class="modal-note">
      <p><strong>✅ You're all set</strong><br>
         <span style="color:#666;">You can now log in with your registered email and password.</span>
      </p>
    </div>
    <p class="modal-countdown">Redirecting to login in <span id="modal-countdown">3</span> seconds...</p>
    <button class="modal-btn" onclick="window.location.href='login.php'">Go to Login Now</button>
  </div>
</div>

<script>
<?php if (!$show_success): ?>

  const digits    = Array.from(document.querySelectorAll('.pin-digit'));
  const hidden    = document.getElementById('pin-hidden');
  const btnVerify = document.getElementById('btn-verify');
  const form      = document.getElementById('verify-form');

  digits.forEach((input, idx) => {
    // Only allow digits
    input.addEventListener('keypress', e => {
      if (!/[0-9]/.test(e.key)) e.preventDefault();
    });
    input.addEventListener('input', () => {
      input.value = input.value.replace(/\D/g, '').slice(-1);
      input.classList.toggle('filled', input.value !== '');
      if (input.value && idx < digits.length - 1) digits[idx + 1].focus();
      syncHidden();
    });
    input.addEventListener('keydown', e => {
      if (e.key === 'Backspace' && !input.value && idx > 0) {
        digits[idx - 1].value = '';
        digits[idx - 1].classList.remove('filled');
        digits[idx - 1].focus();
        syncHidden();
      }
    });
    input.addEventListener('paste', e => {
      e.preventDefault();
      const pasted = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g,'').slice(0,6);
      pasted.split('').forEach((ch, i) => {
        if (digits[i]) { digits[i].value = ch; digits[i].classList.add('filled'); }
      });
      digits[Math.min(pasted.length, digits.length - 1)].focus();
      syncHidden();
    });
  });

  function syncHidden() {
  hidden.value = digits.map(d => d.value).join('');
}

  <?php if ($error && strpos($error, 'Incorrect') !== false): ?>
  digits.forEach(d => { d.classList.add('error'); d.value = ''; d.classList.remove('filled'); });
  setTimeout(() => { digits.forEach(d => d.classList.remove('error')); digits[0].focus(); }, 400);
  <?php endif; ?>

  // Countdown timer
  let seconds     = <?= (int)$secondsLeft ?>;
  const countEl   = document.getElementById('countdown');
  const timerWrap = document.getElementById('timer-wrap');
  if (seconds > 0 && countEl) {
    const tick = setInterval(() => {
      seconds--;
      if (seconds <= 0) {
        clearInterval(tick);
        timerWrap.className = 'timer expired';
        timerWrap.innerHTML = 'Code <span>expired</span> — request a new one below.';
        btnVerify.disabled = true;
        digits.forEach(d => d.disabled = true);
      } else {
        const m = String(Math.floor(seconds/60)).padStart(2,'0');
        const s = String(seconds%60).padStart(2,'0');
        countEl.textContent = m+':'+s;
        timerWrap.className = 'timer'+(seconds<=60?' warning':'');
      }
    }, 1000);
  }

  // Resend cooldown
  const btnResend   = document.getElementById('btn-resend');
  const resendTimer = document.getElementById('resend-timer');
  function startResendCooldown() {
    let left = 30;
    btnResend.disabled = true;
    resendTimer.textContent = 'You can request a new code in '+left+'s';
    const cd = setInterval(() => {
      left--;
      resendTimer.textContent = 'You can request a new code in '+left+'s';
      if (left <= 0) { clearInterval(cd); btnResend.disabled = false; resendTimer.textContent = ''; }
    }, 1000);
  }
  startResendCooldown();
  document.getElementById('resend-form').addEventListener('submit', startResendCooldown);

<?php else: ?>
  let countdown = 3;
  const cdEl    = document.getElementById('modal-countdown');
  const cdTick  = setInterval(() => {
    countdown--;
    if (cdEl) cdEl.textContent = countdown;
    if (countdown <= 0) { clearInterval(cdTick); window.location.href = 'login.php'; }
  }, 1000);
<?php endif; ?>
</script>
</body>
</html>