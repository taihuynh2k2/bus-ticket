<?php
// php/db.php

// Bật hiển thị lỗi PHP (chỉ nên dùng trong môi trường phát triển)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Thông tin kết nối cơ sở dữ liệu
define('DB_SERVER', 'localhost'); // Thay bằng địa chỉ server của bạn
define('DB_USERNAME', 'root');    // Thay bằng tên người dùng database của bạn
define('DB_PASSWORD', '');        // Thay bằng mật khẩu database của bạn
define('DB_NAME', 'bus_ticket_db'); // Thay bằng tên database của bạn

// Tạo kết nối MySQLi
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Kiểm tra kết nối
if ($conn->connect_error) {
    // Ghi nhật ký lỗi kết nối
    error_log("Connection failed: " . $conn->connect_error);
    die("Kết nối thất bại: " . $conn->connect_error);
}

// Thiết lập bộ ký tự (charset) cho kết nối để hỗ trợ tiếng Việt
$conn->set_charset("utf8mb4");

// Có thể thêm hàm tiện ích để lấy kết nối
function get_db_connection() {
    global $conn; // Sử dụng biến kết nối toàn cục
    return $conn;
}
?>
