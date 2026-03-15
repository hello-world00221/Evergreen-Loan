<?php
session_start();

// Redirect if already logged in — role-based
if (isset($_SESSION['user_id'])) {
    $role = strtolower($_SESSION['role'] ?? '');
    header("Location: " . ($role === 'admin' ? 'adminindex.php' : 'index.php'));
    exit;
}

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email    = trim($_POST['email']    ?? '');
    $password = $_POST['password']      ?? '';

    if (empty($email) || empty($password)) {
        $error = "Please enter both email and password.";
    } else {

        $host   = 'localhost';
        $dbname = 'loandb';
        $dbuser = 'root';
        $dbpass = '';

        try {
            $pdo = new PDO(
                "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
                $dbuser,
                $dbpass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            // Fetch user by email — pull role from loandb.users
            $stmt = $pdo->prepare(
                "SELECT id, full_name, user_email, password_hash, role
                 FROM users WHERE user_email = ? LIMIT 1"
            );
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['password_hash'])) {

                $role = strtolower(trim($user['role'] ?? 'user'));

                // ─── Store session variables ───────────────────────────────
                // These keys are used by BOTH the loan system and admin system
                $_SESSION['user_id']    = $user['id'];
                $_SESSION['full_name']  = $user['full_name'];
                $_SESSION['email']      = $user['user_email'];
                $_SESSION['role']       = $role;

                // Keys expected by loan system (index.php / Loan_AppForm.php)
                $_SESSION['user_email'] = $user['user_email'];
                $_SESSION['user_name']  = $user['full_name'];
                $_SESSION['user_role']  = $role;

                // Keys expected by admin system (admin_header.php)
                $_SESSION['admin_name']      = $user['full_name'];
                $_SESSION['loan_officer_id'] = 'LO-' . str_pad($user['id'], 4, '0', STR_PAD_LEFT);

                // ─── Role-based redirect ───────────────────────────────────
                if ($role === 'admin') {
                    header("Location: adminindex.php");
                } else {
                    header("Location: index.php");
                }
                exit;

            } else {
                $error = "Invalid email or password. Please try again.";
            }

        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Login – Evergreen Trust and Savings</title>
  <link rel="icon" type="logo/png" href="pictures/logo.png" /> 
  <style>
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }
    body {
      background-color: #f9f5f0;
      color: #2d4a3e;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
    }
    .header {
      background-color: #0a3b2f;
      padding: 16px 24px;
      display: flex;
      align-items: center;
      gap: 12px;
    }
    .logo { height: 40px; width: auto; }
    .logo-text {
      color: white;
      font-size: 18px;
      font-weight: 700;
      letter-spacing: 0.5px;
    }
    .login-container {
      max-width: 400px;
      width: 90%;
      margin: 60px auto;
      padding: 32px;
      background: white;
      border-radius: 10px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    }
    .login-container h2 {
      text-align: center;
      margin-bottom: 24px;
      color: #0a3b2f;
    }
    .error {
      color: #d32f2f;
      background-color: #ffebee;
      padding: 10px 14px;
      border-radius: 6px;
      margin-bottom: 20px;
      text-align: center;
      font-size: 14px;
      border: 1px solid #ffcdd2;
    }
    .form-group { margin-bottom: 20px; }
    .form-group label {
      display: block;
      margin-bottom: 6px;
      font-weight: 600;
      font-size: 14px;
    }
    .password-wrapper {
      position: relative;
      display: flex;
      align-items: center;
    }
    .form-group input {
      width: 100%;
      padding: 12px;
      border: 1px solid #ccc;
      border-radius: 6px;
      font-size: 16px;
      transition: border-color 0.2s;
    }
    .form-group input:focus {
      outline: none;
      border-color: #0a3b2f;
    }
    .toggle-eye {
      position: absolute;
      right: 12px;
      background: none;
      border: none;
      cursor: pointer;
      color: #666;
      font-size: 18px;
      padding: 0;
      line-height: 1;
    }
    .toggle-eye:hover { color: #0a3b2f; }
    .forgot-row {
      display: flex;
      justify-content: flex-end;
      margin-top: 6px;
      margin-bottom: -8px;
    }
    .forgot-link {
      font-size: 13px;
      color: #0a3b2f;
      text-decoration: none;
      font-weight: 600;
    }
    .forgot-link:hover { text-decoration: underline; }
    .btn-login {
      width: 100%;
      padding: 12px;
      background-color: #0a3b2f;
      color: white;
      border: none;
      border-radius: 6px;
      font-size: 16px;
      font-weight: 600;
      cursor: pointer;
      transition: background-color 0.3s;
      margin-top: 4px;
    }
    .btn-login:hover { background-color: #082e24; }
    .register-link {
      text-align: center;
      margin-top: 20px;
      font-size: 14px;
      color: #555;
    }
    .register-link a {
      color: #0a3b2f;
      text-decoration: none;
      font-weight: 600;
    }
    .register-link a:hover { text-decoration: underline; }
  </style>
</head>
<body>

  <div class="header">
    <img src="pictures/logo.png" alt="Evergreen Logo" class="logo">
    <div class="logo-text">EVERGREEN</div>
  </div>

  <div class="login-container">
    <h2>Login to Your Account</h2>

    <?php if (!empty($error)): ?>
      <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="">
      <div class="form-group">
        <label for="email">Email Address</label>
        <input type="email" id="email" name="email" required
               autocomplete="email"
               placeholder="john@example.com"
               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" />
      </div>

      <div class="form-group">
        <label for="password">Password</label>
        <div class="password-wrapper">
          <input type="password" id="password" name="password" required
                 autocomplete="current-password"
                 placeholder="Enter your password" />
          <button type="button" class="toggle-eye" onclick="toggleVis()" title="Show/hide password">👁</button>
        </div>
        <div class="forgot-row">
          <a href="forgotpass.php" class="forgot-link">Forgot password?</a>
        </div>
      </div>

      <button type="submit" class="btn-login">Login</button>
    </form>

    <div class="register-link">
      Don't have an account yet? <a href="register-account.php">Sign up here</a>
    </div>
  </div>

  <script>
    function toggleVis() {
      const input = document.getElementById('password');
      const btn   = document.querySelector('.toggle-eye');
      if (input.type === 'password') {
        input.type      = 'text';
        btn.textContent = '🙈';
      } else {
        input.type      = 'password';
        btn.textContent = '👁';
      }
    }
  </script>
</body>
</html>