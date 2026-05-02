<?php
// tickets.php
require_once __DIR__ . '/php/db.php';
// Bắt đầu session nếu chưa được bắt đầu
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

$page_title = "Vé của tôi";
$user_tickets = [];
$error_message = '';

// Yêu cầu đăng nhập
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$conn = get_db_connection();

$stmt = $conn->prepare("
    SELECT
        t.id AS ticket_id,
        t.seat_number,
        t.passenger_name,
        t.passenger_phone,
        t.status AS ticket_status,
        b.id AS booking_id,
        b.booking_date,
        b.total_amount,
        b.status AS booking_status,
        s.departure_time,
        s.arrival_time,
        s.price AS seat_price,
        r.origin,
        r.destination,
        bus.bus_number
    FROM
        tickets t
    JOIN
        bookings b ON t.booking_id = b.id
    JOIN
        schedules s ON b.schedule_id = s.id
    JOIN
        routes r ON s.route_id = r.id
    JOIN
        buses bus ON s.bus_id = bus.id
    WHERE
        b.user_id = ?
    ORDER BY b.booking_date DESC, t.seat_number ASC
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $user_tickets[] = $row;
    }
} else {
    $error_message = "Bạn chưa có vé nào.";
}
$stmt->close();
$conn->close();

// Bao gồm layout chung
include_once __DIR__ . '/partials/layout.php';
?>

<h2 class="text-center mb-20"><?php echo htmlspecialchars($page_title); ?></h2>

<?php if ($error_message): ?>
    <div class="alert alert-info"><?php echo htmlspecialchars($error_message); ?></div>
<?php endif; ?>

<?php if (!empty($user_tickets)): ?>
    <table class="data-table">
        <thead>
            <tr>
                <th>Mã vé</th>
                <th>Tuyến đường</th>
                <th>Giờ đi</th>
                <th>Ghế</th>
                <th>Hành khách</th>
                <th>Trạng thái</th>
                <th>Chi tiết</th>
                <!-- Thêm cột hành động nếu có hoàn tiền/hủy vé -->
            </tr>
        </thead>
        <tbody>
            <?php foreach ($user_tickets as $ticket): ?>
                <tr>
                    <td>#<?php echo htmlspecialchars($ticket['ticket_id']); ?></td>
                    <td><?php echo htmlspecialchars($ticket['origin']); ?> &rarr; <?php echo htmlspecialchars($ticket['destination']); ?></td>
                    <td><?php echo date('H:i d/m/Y', strtotime($ticket['departure_time'])); ?></td>
                    <td><?php echo htmlspecialchars($ticket['seat_number']); ?></td>
                    <td><?php echo htmlspecialchars($ticket['passenger_name']); ?></td>
                    <td>
                        <?php
                            $status_class = '';
                            if ($ticket['ticket_status'] == 'active') {
                                $status_class = 'badge-success'; // giả định có css cho badge
                                echo '<span class="badge ' . $status_class . '">Active</span>';
                            } elseif ($ticket['ticket_status'] == 'used') {
                                $status_class = 'badge-info';
                                echo '<span class="badge ' . $status_class . '">Đã sử dụng</span>';
                            } elseif ($ticket['ticket_status'] == 'cancelled') {
                                $status_class = 'badge-danger';
                                echo '<span class="badge ' . $status_class . '">Đã hủy</span>';
                            } elseif ($ticket['ticket_status'] == 'refunded') {
                                $status_class = 'badge-warning';
                                echo '<span class="badge ' . $status_class . '">Đã hoàn tiền</span>';
                            } else {
                                echo htmlspecialchars($ticket['ticket_status']);
                            }
                        ?>
                    </td>
                    <td>
                        <a href="view_ticket.php?ticket_id=<?php echo htmlspecialchars($ticket['ticket_id']); ?>" class="btn btn-info btn-sm">Xem chi tiết</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php
// Đóng thẻ main và div container từ layout.php
echo '</div></main>';
// Bao gồm footer từ layout.php
include_once __DIR__ . '/partials/layout_end.php';
?>
