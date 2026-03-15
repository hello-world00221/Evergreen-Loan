<?php
// ─── Role-based access guard ──────────────────────────────────────────────────
// Supports both session key formats:
//   $_SESSION['role']      — set by login.php directly
//   $_SESSION['user_role'] — set by session bridge in index.php / Loan_AppForm.php
$sessionRole = strtolower($_SESSION['role'] ?? $_SESSION['user_role'] ?? '');

if (!isset($_SESSION['user_id']) || $sessionRole !== 'admin') {
    session_destroy();
    header('Location: login.php');
    exit();
}
?>

<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}
body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    overflow-x: hidden;
}
header {
    position: fixed;
    top: 0;
    width: 100%;
    background: #003631;
    padding: 1rem 5%;
    display: flex;
    justify-content: space-between;
    align-items: center;
    z-index: 1000;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    height: auto;
}
.logo-container {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    text-decoration: none;
    color: white;
}
.logo { height: 40px; width: auto; }
.logo-text {
    color: white;
    font-weight: bold;
    font-size: 1.2rem;
    letter-spacing: 1px;
}
.nav-center {
    display: flex;
    justify-content: center;
    flex-grow: 1;
}
nav ul {
    display: flex;
    list-style: none;
    gap: 2rem;
    margin: 0;
    padding: 0;
}
nav a {
    color: white;
    text-decoration: none;
    font-weight: 500;
    font-size: 1rem;
    transition: color 0.3s ease;
    position: relative;
    padding: 0.5rem 1rem;
}
nav a:hover { color: #F1B24A; }
nav a::after {
    content: '';
    position: absolute;
    bottom: -5px;
    left: 0;
    width: 0;
    height: 2px;
    background-color: #F1B24A;
    transition: width 0.3s ease;
}
nav a:hover::after,
nav a.active::after { width: 100%; }
nav a.active { color: #F1B24A; }
.admin-section {
    display: flex;
    align-items: center;
    gap: 12px;
    position: relative;
}
.datetime {
    color: white;
    font-size: 14px;
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    text-align: right;
    margin-right: 5px;
    font-weight: 500;
}
.datetime span { line-height: 1.4; }
.username {
    color: white;
    font-weight: 500;
    font-size: 14px;
}
.admin-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background-color: rgba(255,255,255,0.2);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: bold;
    cursor: pointer;
    transition: opacity 0.2s ease;
    font-size: 18px;
}
.admin-icon:hover { opacity: 0.85; }
.dropdown-content {
    display: none;
    position: absolute;
    background-color: #D9D9D9;
    min-width: 160px;
    box-shadow: 0 6px 18px rgba(0,0,0,0.12);
    z-index: 1001;
    top: calc(100% + 8px);
    right: 0;
    border-radius: 8px;
}
.dropdown-content a {
    color: #003631;
    padding: 0.65rem 1rem;
    display: block;
    text-decoration: none;
    transition: background 0.2s ease;
    font-weight: 600;
}
.dropdown-content a:hover { background-color: rgba(0,0,0,0.04); }
@media (max-width: 768px) {
    header { padding: 1rem 2%; }
    nav ul { gap: 1rem; }
    nav a { font-size: 0.9rem; padding: 0.4rem 0.8rem; }
    .datetime { display: none; }
}
</style>

<header id="main-header">
    <a href="adminindex.php" class="logo-container">
        <img src="pictures/logo.png" alt="Evergreen Logo" class="logo">
        <span class="logo-text">EVERGREEN</span>
    </a>

    <div class="nav-center">
        <nav>
            <ul>
                <li>
                    <a href="adminindex.php"
                       class="<?= basename($_SERVER['PHP_SELF']) === 'adminindex.php' ? 'active' : '' ?>">
                       Dashboard
                    </a>
                </li>
                <li>
                    <a href="adminapplications.php"
                       class="<?= basename($_SERVER['PHP_SELF']) === 'adminapplications.php' ? 'active' : '' ?>">
                       Loan Applications
                    </a>
                </li>
            </ul>
        </nav>
    </div>

    <div class="admin-section" id="adminUserContainer">
        <div class="datetime">
            <span id="currentDate"><?= date("Y/m/d") ?></span>
            <span id="currentTime"><?= date("h:i:s A") ?></span>
        </div>
        <!-- Use whichever name key is available -->
        <span class="username">
            <?= htmlspecialchars($_SESSION['user_name'] ?? $_SESSION['full_name'] ?? $_SESSION['admin_name'] ?? 'Admin') ?>
        </span>
        <div class="admin-icon" id="adminIcon">👤</div>

        <div class="dropdown-content" id="adminDropdown">
            <a href="logout.php">Logout</a>
        </div>
    </div>
</header>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const icon      = document.getElementById('adminIcon');
    const dropdown  = document.getElementById('adminDropdown');
    const container = document.getElementById('adminUserContainer');

    if (icon && dropdown) {
        icon.addEventListener('click', function (e) {
            e.stopPropagation();
            dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
        });
        document.addEventListener('click', function (e) {
            if (container && !container.contains(e.target)) {
                dropdown.style.display = 'none';
            }
        });
    }

    function updateTime() {
        const now     = new Date();
        const options = { timeZone: 'Asia/Manila', hour12: true };
        const timeEl  = document.getElementById('currentTime');
        const dateEl  = document.getElementById('currentDate');
        if (timeEl) timeEl.textContent = now.toLocaleTimeString('en-PH', options);
        if (dateEl) dateEl.textContent = now.toLocaleDateString('en-PH', { timeZone: 'Asia/Manila' });
    }
    setInterval(updateTime, 1000);
    updateTime();
});
</script>