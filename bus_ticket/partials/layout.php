<?php
// partials/layout.php
// Bắt đầu session nếu chưa được bắt đầu
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Kiểm tra xem người dùng đã đăng nhập chưa
$is_logged_in = isset($_SESSION['user_id']);
$username = $is_logged_in ? $_SESSION['username'] : '';

?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? $page_title . ' - Bus Ticket Booking' : 'Bus Ticket Booking System'; ?></title>
    <!-- Bao gồm CSS chung -->
    <link rel="stylesheet" href="/bus_ticket/css/style.css">
    <!-- Bao gồm CSS riêng cho sơ đồ ghế nếu cần (chỉ trên trang booking) -->
    <?php if (isset($include_seat_map_css) && $include_seat_map_css): ?>
        <link rel="stylesheet" href="/bus_ticket/css/seat_map.css">
    <?php endif; ?>
    <!-- Link font Inter từ Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <header>
        <div class="container">
            <a href="/bus_ticket/index.php" class="logo">BusBooking</a>
            <nav>
                <ul>
                    <li><a href="/bus_ticket/index.php">Trang chủ</a></li>
                    <li><a href="/bus_ticket/schedule.php">Lịch trình</a></li>
                    <?php if ($is_logged_in): ?>
                        <li><a href="/bus-ticket/tickets.php">Vé của tôi</a></li>
                        <li><a href="#">Chào, <?php echo htmlspecialchars($username); ?>!</a></li>
                        <li><a href="/bus_ticket/logout.php" class="btn btn-danger">Đăng xuất</a></li>
                    <?php else: ?>
                        <li><a href="/bus_ticket/login.php">Đăng nhập</a></li>
                        <li><a href="/bus_ticket/register.php" class="btn btn-success">Đăng ký</a></li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
    </header>

    <main>
        <div class="container">
            <!-- Nội dung chính của từng trang sẽ được đặt ở đây -->
