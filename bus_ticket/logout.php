<?php
// logout.php
// Bắt đầu session nếu chưa được bắt đầu
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Hủy tất cả các biến session
$_SESSION = array();


 if (ini_get("session.use_cookies")) {
     $params = session_get_cookie_params();
     setcookie(session_name(), '', time() - 42000,
         $params["path"], $params["domain"],
         $params["secure"], $params["httponly"]
     );
}

// Hủy phiên làm việc
session_destroy();

// Chuyển hướng người dùng về trang đăng nhập hoặc trang chủ
header('Location: login.php'); // Hoặc index.php
exit();
?>
