<?php
// 1. Initialize the session
session_start();

// 2. Unset all session variables
$_SESSION = array();

// 3. Destroy the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// 4. Destroy the session completely
session_destroy();

// 5. Clear the "Keep me logged in" cookie if it exists
if (isset($_COOKIE['user_login'])) {
    setcookie("user_login", "", time() - 3600, "/");
}

// 6. Redirect back to the login page (Absolute path to be safe)
header("Location: /1AG5_SQA_project/auth/login.php");
exit();
