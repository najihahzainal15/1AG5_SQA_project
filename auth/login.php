<?php
require_once '../config/db_connection.php';
session_start();

if (isset($_SESSION['user_id'])) {
    header("Location: ../dashboard.php");
    exit();
}
$error = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    // Using PDO for example
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role'] = $user['role'];

        if (isset($_POST['keep_logged_in'])) {
            setcookie("user_login", $user['user_id'], time() + (86400 * 30), "/");
        }
        header("Location: ../dashboard.php");
        exit();
    } else {
        $error = "Invalid email or password.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>LensTrack · Login</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/style.css">
</head>

<body>
    <div id="lo">
        <div class="lL">
            <div class="lh">
                <h1>LensTrack</h1>
                <div>Sign in to continue</div>
            </div>
            <div class="lc">
                <h2>Login</h2>
                <?php if ($error): ?><div style="color: var(--r); font-size: 13px; margin-bottom: 10px;"><?= $error ?></div><?php endif; ?>

                <form method="POST">
                    <div class="ip">
                        <span><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                <path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3z" />
                                <circle cx="12" cy="13" r="3.5" />
                            </svg></span>
                        <input name="email" placeholder="Email Address" autocomplete="off" required>
                    </div>
                    <div class="ip">
                        <span><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                <path d="M5 11h14v10H5zM8 11V7a4 4 0 0 1 8 0v4" />
                            </svg></span>
                        <input name="password" type="password" placeholder="Password" required>
                    </div>
                    <label class="tg">Keep me logged in <input type="checkbox" name="keep_logged_in"><i></i></label>
                    <button type="submit" class="lbtn">Log in</button>
                </form>
            </div>
        </div>
        <div class="lR">
            <svg viewBox="0 0 640 520" preserveAspectRatio="xMaxYMax meet" xmlns="http://www.w3.org/2000/svg">
                <ellipse cx="400" cy="300" rx="270" ry="230" fill="#c4e2fb" />
                <ellipse cx="560" cy="350" rx="190" ry="170" fill="#a6d4f8" />
                <rect x="40" y="400" width="600" height="120" fill="#ffc9a6" />
                <rect x="20" y="392" width="620" height="16" rx="8" fill="#d17c4f" />
                <g transform="translate(270,228)">
                    <rect y="40" width="230" height="132" rx="16" fill="#3a3f4b" />
                    <path d="M70 40l20-32h60l20 32z" fill="#2c303a" />
                    <rect x="102" y="16" width="36" height="18" rx="3" fill="#7a8296" />
                    <rect x="186" y="26" width="28" height="12" rx="4" fill="#e8453c" />
                    <rect y="58" width="42" height="114" rx="14" fill="#2c303a" />
                    <circle cx="125" cy="106" r="60" fill="#1f232b" />
                    <circle cx="125" cy="106" r="48" fill="#2f3541" stroke="#5b6375" stroke-width="4" />
                    <circle cx="125" cy="106" r="33" fill="#111318" />
                    <circle cx="125" cy="106" r="21" fill="#243b6b" />
                    <circle cx="116" cy="97" r="6" fill="#fff" opacity=".75" />
                    <rect x="180" y="150" width="36" height="6" rx="3" fill="#e8453c" />
                </g>
                <g transform="translate(70,310)">
                    <rect y="26" width="124" height="64" rx="8" fill="#dfe3ec" />
                    <rect y="10" width="124" height="26" rx="6" fill="#2c303a" />
                    <rect x="14" y="2" width="22" height="10" rx="3" fill="#7a8296" />
                    <circle cx="62" cy="60" r="28" fill="#2c303a" />
                    <circle cx="62" cy="60" r="19" fill="#111318" />
                    <circle cx="62" cy="60" r="10" fill="#243b6b" />
                    <circle cx="56" cy="54" r="3" fill="#fff" opacity=".7" />
                </g>
                <g transform="translate(528,296)">
                    <rect width="64" height="104" rx="9" fill="#2f3541" />
                    <rect y="20" width="64" height="8" fill="#5b6375" />
                    <rect y="40" width="64" height="4" fill="#e8453c" />
                    <rect y="62" width="64" height="7" fill="#5b6375" />
                    <rect x="6" y="82" width="52" height="4" rx="2" fill="#111318" />
                </g>
            </svg>
        </div>
    </div>
</body>

</html>