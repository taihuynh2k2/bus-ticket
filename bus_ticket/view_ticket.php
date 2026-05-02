<?php
// view_ticket.php
require_once __DIR__ . '/php/db.php';
// Bắt đầu session nếu chưa được bắt đầu
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

$page_title = "Chi tiết vé";
$ticket_details = null;
$error_message = '';

// Yêu cầu đăng nhập
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
error_log("DEBUG: Logged in user_id: " . $user_id); // Ghi log user_id
$conn = get_db_connection();

if (!isset($_GET['ticket_id']) || !is_numeric($_GET['ticket_id'])) {
    $error_message = "Mã vé không hợp lệ.";
    error_log("DEBUG: Invalid or missing ticket_id.");
} else {
    $ticket_id = (int)$_GET['ticket_id'];
    error_log("DEBUG: Attempting to view ticket_id: " . $ticket_id); // Ghi log ticket_id

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
            bus.bus_number,
            bus.model AS bus_model
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
            t.id = ? AND b.user_id = ?
    ");

    if ($stmt === false) {
        $error_message = "Lỗi khi chuẩn bị truy vấn: " . $conn->error;
        error_log("DEBUG: Prepare failed: " . $conn->error); // Ghi log lỗi prepare
    } else {
        $stmt->bind_param("ii", $ticket_id, $user_id);
        if (!$stmt->execute()) {
            $error_message = "Lỗi khi thực thi truy vấn: " . $stmt->error;
            error_log("DEBUG: Execute failed: " . $stmt->error); // Ghi log lỗi execute
        } else {
            $result = $stmt->get_result();

            if ($result->num_rows == 1) {
                $ticket_details = $result->fetch_assoc();
                error_log("DEBUG: Ticket details fetched successfully for ticket_id: " . $ticket_id);
            } else {
                $error_message = "Không tìm thấy vé hoặc bạn không có quyền truy cập vé này.";
                error_log("DEBUG: No ticket found or access denied for ticket_id: " . $ticket_id . " and user_id: " . $user_id);
            }
        }
        $stmt->close();
    }
}
$conn->close();

// Bao gồm layout chung
include_once __DIR__ . '/partials/layout.php';
?>

<h2 class="text-center mb-20"><?php echo htmlspecialchars($page_title); ?></h2>

<?php if ($error_message): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
<?php endif; ?>

<?php if ($ticket_details): ?>
    <div class="form-container">
        <h3 class="mb-10">Chi tiết vé #<?php echo htmlspecialchars($ticket_details['ticket_id']); ?></h3>
        <p><strong>Mã đặt vé:</strong> #<?php echo htmlspecialchars($ticket_details['booking_id']); ?></p>
        <p><strong>Ngày đặt:</strong> <?php echo date('H:i d/m/Y', strtotime($ticket_details['booking_date'])); ?></p>
        <hr>
        <p><strong>Tuyến đường:</strong> <?php echo htmlspecialchars($ticket_details['origin']); ?> &rarr; <?php echo htmlspecialchars($ticket_details['destination']); ?></p>
        <p><strong>Chuyến xe:</strong> <?php echo htmlspecialchars($ticket_details['bus_number']); ?> (Model: <?php echo htmlspecialchars($ticket_details['bus_model']); ?>)</p>
        <p><strong>Khởi hành:</strong> <?php echo date('H:i d/m/Y', strtotime($ticket_details['departure_time'])); ?></p>
        <p><strong>Đến nơi:</strong> <?php echo date('H:i d/m/Y', strtotime($ticket_details['arrival_time'])); ?></p>
        <p><strong>Số ghế:</strong> <?php echo htmlspecialchars($ticket_details['seat_number']); ?></p>
        <p><strong>Giá ghế:</strong> <?php echo number_format($ticket_details['seat_price'], 0, ',', '.'); ?> VNĐ</p>
        <hr>
        <p><strong>Tên hành khách:</strong> <?php echo htmlspecialchars($ticket_details['passenger_name']); ?></p>
        <p><strong>Số điện thoại:</strong> <?php echo htmlspecialchars($ticket_details['passenger_phone']); ?></p>
        <p><strong>Trạng thái vé:</strong>
            <?php
                $status_class = '';
                if ($ticket_details['ticket_status'] == 'active') {
                    $status_class = 'btn-success'; // Dùng class của button để hiển thị
                    echo '<span class="btn ' . $status_class . ' btn-sm" style="cursor: default;">Active</span>';
                } elseif ($ticket_details['ticket_status'] == 'used') {
                    $status_class = 'btn-info';
                    echo '<span class="btn ' . $status_class . ' btn-sm" style="cursor: default;">Đã sử dụng</span>';
                } elseif ($ticket_details['ticket_status'] == 'cancelled') {
                    $status_class = 'btn-danger';
                    echo '<span class="btn ' . $status_class . ' btn-sm" style="cursor: default;">Đã hủy</span>';
                } elseif ($ticket_details['ticket_status'] == 'refunded') {
                    $status_class = 'btn-warning';
                    echo '<span class="btn ' . $status_class . ' btn-sm" style="cursor: default;">Đã hoàn tiền</span>';
                } else {
                    echo htmlspecialchars($ticket_details['ticket_status']);
                }
            ?>
        </p>

        <div class="text-center mt-20">
            <a href="tickets.php" class="btn btn-secondary">Quay lại danh sách vé</a>
            <?php if ($ticket_details['ticket_status'] == 'active' && strtotime($ticket_details['departure_time']) > time() + (2 * 3600)): // Chỉ cho phép hủy nếu còn hơn 2 tiếng trước giờ khởi hành ?>
                <button type="button" class="btn btn-danger ml-10" onclick="showRefundConfirmation(<?php echo htmlspecialchars($ticket_details['ticket_id']); ?>, '<?php echo htmlspecialchars($ticket_details['seat_number']); ?>')">Yêu cầu hoàn tiền</button>
            <?php elseif (strtotime($ticket_details['departure_time']) <= time() + (2 * 3600)): ?>
                <p class="alert alert-info mt-10">Không thể yêu cầu hoàn tiền: Đã quá gần giờ khởi hành (cần trước ít nhất 2 giờ).</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal xác nhận hoàn tiền -->
    <div id="refundConfirmationModal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.4);">
        <div style="background-color: #fefefe; margin: 15% auto; padding: 20px; border: 1px solid #888; width: 80%; max-width: 400px; border-radius: 10px; text-align: center; box-shadow: 0 4px 8px rgba(0,0,0,0.2);">
            <p>Bạn có chắc chắn muốn yêu cầu hoàn tiền cho vé #<span id="modalTicketId"></span> (ghế <span id="modalSeatNumber"></span>) không?</p>
            <p style="font-size: 0.9em; color: #666;">Yêu cầu này sẽ được xử lý bởi quản trị viên.</p>
            <div style="margin-top: 20px;">
                <button type="button" class="btn btn-danger" onclick="confirmRefund()">Xác nhận hoàn tiền</button>
                <button type="button" class="btn btn-secondary" onclick="hideRefundConfirmation()">Hủy</button>
            </div>
        </div>
    </div>

    <script>
        let currentTicketIdToRefund = null;

        function showRefundConfirmation(ticketId, seatNumber) {
            currentTicketIdToRefund = ticketId;
            document.getElementById('modalTicketId').textContent = ticketId;
            document.getElementById('modalSeatNumber').textContent = seatNumber;
            document.getElementById('refundConfirmationModal').style.display = 'block';
        }

        function hideRefundConfirmation() {
            document.getElementById('refundConfirmationModal').style.display = 'none';
            currentTicketIdToRefund = null;
        }

        function confirmRefund() {
            if (currentTicketIdToRefund) {
                // Gửi yêu cầu hoàn tiền qua AJAX hoặc chuyển hướng
                window.location.href = 'php/handle_refund.php?ticket_id=' + currentTicketIdToRefund;
            }
        }
    </script>

<?php else: ?>
    <?php if (!$error_message): ?>
        <div class="alert alert-info">Không tìm thấy thông tin chi tiết vé.</div>
    <?php endif; ?>
<?php endif; ?>

<?php
// Đóng thẻ main và div container từ layout.php
echo '</div></main>';
// Bao gồm footer từ layout.php
include_once __DIR__ . '/partials/layout_end.php';
?>
