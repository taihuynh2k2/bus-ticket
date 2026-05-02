<?php
// php/handle_refund.php
require_once __DIR__ . '/db.php';
// Bắt đầu session nếu chưa được bắt đầu
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Chỉ cho phép truy cập nếu người dùng đã đăng nhập
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php'); // Chuyển hướng về trang đăng nhập
    exit();
}

$user_id = $_SESSION['user_id'];
$conn = get_db_connection();
$response_message = ''; // Để lưu thông báo phản hồi
$redirect_url = '../tickets.php'; // URL để chuyển hướng sau khi xử lý

if (isset($_GET['ticket_id']) && is_numeric($_GET['ticket_id'])) {
    $ticket_id = (int)$_GET['ticket_id'];

    // Bắt đầu transaction
    $conn->begin_transaction();

    try {
        // 1. Lấy thông tin vé để xác minh và lấy schedule_id, booking_id
        // Đảm bảo vé thuộc về người dùng hiện tại và đang ở trạng thái 'active'
        $stmt_get_ticket = $conn->prepare("
            SELECT t.id, t.status, b.schedule_id, s.departure_time, s.price
            FROM tickets t
            JOIN bookings b ON t.booking_id = b.id
            JOIN schedules s ON b.schedule_id = s.id
            WHERE t.id = ? AND b.user_id = ? FOR UPDATE
        ");
        $stmt_get_ticket->bind_param("ii", $ticket_id, $user_id);
        $stmt_get_ticket->execute();
        $result_ticket = $stmt_get_ticket->get_result();

        if ($result_ticket->num_rows === 0) {
            throw new Exception("Vé không tồn tại hoặc bạn không có quyền truy cập.");
        }

        $ticket_info = $result_ticket->fetch_assoc();
        $current_ticket_status = $ticket_info['status'];
        $schedule_id = $ticket_info['schedule_id'];
        $departure_time = strtotime($ticket_info['departure_time']);
        $ticket_price = $ticket_info['price']; // Giá của 1 ghế

        // Kiểm tra trạng thái vé và thời gian hoàn tiền (ví dụ: chỉ cho phép hủy trước 2 tiếng)
        if ($current_ticket_status !== 'active') {
            throw new Exception("Vé này không thể hoàn tiền (trạng thái: " . htmlspecialchars($current_ticket_status) . ").");
        }
        if ($departure_time < time() + (2 * 3600)) { // 2 giờ = 2 * 3600 giây
            throw new Exception("Không thể hoàn tiền. Yêu cầu hoàn tiền phải trước giờ khởi hành ít nhất 2 giờ.");
        }

        // 2. Cập nhật trạng thái vé thành 'refunded' hoặc 'cancelled'
        $stmt_update_ticket = $conn->prepare("UPDATE tickets SET status = 'cancelled' WHERE id = ?");
        $stmt_update_ticket->bind_param("i", $ticket_id);
        if (!$stmt_update_ticket->execute()) {
            throw new Exception("Lỗi khi cập nhật trạng thái vé: " . $stmt_update_ticket->error);
        }
        $stmt_update_ticket->close();

        // 3. Cập nhật trạng thái ghế trong seat_status
        $stmt_update_seat_status = $conn->prepare("UPDATE seat_status SET is_booked = FALSE WHERE schedule_id = ? AND seat_number = (SELECT seat_number FROM tickets WHERE id = ?)");
        $stmt_update_seat_status->bind_param("ii", $schedule_id, $ticket_id);
        if (!$stmt_update_seat_status->execute()) {
            throw new Exception("Lỗi khi cập nhật trạng thái ghế: " . $stmt_update_seat_status->error);
        }
        $stmt_update_seat_status->close();


        // 4. Tăng số ghế trống trong lịch trình
        $stmt_update_schedule = $conn->prepare("UPDATE schedules SET available_seats = available_seats + 1 WHERE id = ?");
        $stmt_update_schedule->bind_param("i", $schedule_id);
        if (!$stmt_update_schedule->execute()) {
            throw new Exception("Lỗi khi cập nhật số ghế trống lịch trình: " . $stmt_update_schedule->error);
        }
        $stmt_update_schedule->close();

        // 5. Cập nhật tổng tiền trong booking (nếu nhiều vé trong 1 booking)
        // Trong trường hợp này, mỗi ticket là 1 ghế, và chúng ta chỉ hủy 1 vé
        // Cần tính lại total_amount của booking hoặc chỉ ghi nhận hoàn tiền cho ticket này
        // Để đơn giản, ta sẽ chỉ đánh dấu vé là đã hủy và không thay đổi total_amount của booking
        // Trong hệ thống phức tạp hơn, bạn sẽ cần một bảng giao dịch hoàn tiền.

        $conn->commit();
        $response_message = "Yêu cầu hoàn tiền thành công. Vé của bạn đã được hủy.";
        $_SESSION['success_message'] = $response_message;

    } catch (Exception $e) {
        $conn->rollback();
        $response_message = "Lỗi xử lý hoàn tiền: " . $e->getMessage();
        $_SESSION['error_message'] = $response_message;
    } finally {
        $conn->close();
    }
} else {
    $response_message = "Mã vé không được cung cấp hoặc không hợp lệ.";
    $_SESSION['error_message'] = $response_message;
}

// Chuyển hướng về trang vé của tôi với thông báo
header('Location: ' . $redirect_url);
exit();
?>
