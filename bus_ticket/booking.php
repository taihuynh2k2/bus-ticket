<?php
// booking.php
require_once __DIR__ . '/php/db.php';
// Bắt đầu session nếu chưa được bắt đầu
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

$page_title = "Đặt vé xe buýt";
$error_message = '';
$success_message = '';
$schedule_info = null;
$booked_seats = []; // Các ghế đã đặt cho chuyến này
$selected_seats_array = []; // Các ghế người dùng chọn (từ POST)

// Thêm thông tin tài khoản ngân hàng tĩnh
$bank_account_info = [
    'account_name' => 'NGUYEN VAN A',
    'account_number' => '1234567890',
    'bank_name' => 'Ngân hàng ABC',
];

$conn = get_db_connection();

// Kiểm tra xem schedule_id có được truyền qua URL không
if (!isset($_GET['schedule_id']) || !is_numeric($_GET['schedule_id'])) {
    $error_message = "ID lịch trình không hợp lệ.";
} else {
    $schedule_id = (int)$_GET['schedule_id'];

    // Lấy thông tin chi tiết của lịch trình
    $stmt = $conn->prepare("
        SELECT
            s.id AS schedule_id,
            r.origin,
            r.destination,
            s.departure_time,
            s.arrival_time,
            s.price,
            s.available_seats,
            s.total_seats,
            b.bus_number
        FROM
            schedules s
        JOIN
            routes r ON s.route_id = r.id
        JOIN
            buses b ON s.bus_id = b.id
        WHERE
            s.id = ? AND s.status = 'scheduled'
    ");
    $stmt->bind_param("i", $schedule_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows == 1) {
        $schedule_info = $result->fetch_assoc();

        // Lấy danh sách các ghế đã đặt cho lịch trình này
        $stmt_seats = $conn->prepare("SELECT seat_number FROM seat_status WHERE schedule_id = ? AND is_booked = TRUE");
        $stmt_seats->bind_param("i", $schedule_id);
        $stmt_seats->execute();
        $result_seats = $stmt_seats->get_result();
        while ($row = $result_seats->fetch_assoc()) {
            $booked_seats[] = $row['seat_number'];
        }
        $stmt_seats->close();

    } else {
        $error_message = "Không tìm thấy lịch trình hoặc lịch trình không khả dụng.";
    }
    $stmt->close();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['book_now'])) {
    // Kiểm tra đăng nhập
    if (!isset($_SESSION['user_id'])) {
        $error_message = "Bạn cần đăng nhập để đặt vé. Vui lòng <a href='login.php'>Đăng nhập</a>.";
    } elseif ($schedule_info) {
        $user_id = $_SESSION['user_id'];
        $selected_seats_raw = $_POST['selected_seats'] ?? '';
        $passenger_name = trim($_POST['passenger_name'] ?? '');
        $passenger_phone = trim($_POST['passenger_phone'] ?? '');

        if (empty($selected_seats_raw) || empty($passenger_name) || empty($passenger_phone)) {
            $error_message = "Vui lòng chọn ghế và điền đầy đủ thông tin hành khách.";
        } else {
            $selected_seats_array = explode(',', $selected_seats_raw);
            $selected_seats_array = array_filter(array_map('trim', $selected_seats_array)); // Lọc và trim

            if (empty($selected_seats_array)) {
                $error_message = "Vui lòng chọn ít nhất một ghế.";
            } else {
                // Bắt đầu transaction để đảm bảo tính toàn vẹn dữ liệu
                $conn->begin_transaction();
                try {
                    $num_seats_to_book = count($selected_seats_array);
                    $total_amount = $schedule_info['price'] * $num_seats_to_book;

                    // 1. Kiểm tra lại số ghế trống
                    $stmt_check_seats = $conn->prepare("SELECT available_seats FROM schedules WHERE id = ? FOR UPDATE"); // FOR UPDATE để khóa hàng
                    $stmt_check_seats->bind_param("i", $schedule_id);
                    $stmt_check_seats->execute();
                    $result_check_seats = $stmt_check_seats->get_result()->fetch_assoc();
                    $current_available_seats = $result_check_seats['available_seats'];
                    $stmt_check_seats->close();

                    if ($current_available_seats < $num_seats_to_book) {
                        throw new Exception("Số ghế trống không đủ. Vui lòng chọn lại.");
                    }

                    // 2. Kiểm tra xem các ghế đã chọn có bị ai khác đặt trước đó không
                    $placeholders = implode(',', array_fill(0, $num_seats_to_book, '?'));
                    $stmt_check_selected_seats = $conn->prepare("SELECT seat_number FROM seat_status WHERE schedule_id = ? AND seat_number IN ($placeholders) AND is_booked = TRUE");
                    $params_check_selected_seats = array_merge([$schedule_id], $selected_seats_array);
                    $types_check_selected_seats = 'i' . str_repeat('s', $num_seats_to_book);
                    $stmt_check_selected_seats->bind_param($types_check_selected_seats, ...$params_check_selected_seats);
                    $stmt_check_selected_seats->execute();
                    $result_check_selected_seats = $stmt_check_selected_seats->get_result();

                    if ($result_check_selected_seats->num_rows > 0) {
                        $booked_by_others = [];
                        while($row = $result_check_selected_seats->fetch_assoc()){
                            $booked_by_others[] = $row['seat_number'];
                        }
                        throw new Exception("Ghế " . implode(', ', $booked_by_others) . " đã có người đặt trong lúc bạn đang chọn. Vui lòng chọn lại ghế khác.");
                    }
                    $stmt_check_selected_seats->close();


                    // 3. Tạo Booking mới
                    $stmt_booking = $conn->prepare("INSERT INTO bookings (user_id, schedule_id, total_amount, status, payment_method) VALUES (?, ?, ?, 'confirmed', 'Online Payment')");
                    $stmt_booking->bind_param("ids", $user_id, $schedule_id, $total_amount);
                    if (!$stmt_booking->execute()) {
                        throw new Exception("Lỗi khi tạo đặt vé: " . $stmt_booking->error);
                    }
                    $booking_id = $conn->insert_id;
                    $stmt_booking->close();

                    // 4. Tạo từng Ticket cho mỗi ghế đã chọn
                    $stmt_ticket = $conn->prepare("INSERT INTO tickets (booking_id, seat_number, passenger_name, passenger_phone, status) VALUES (?, ?, ?, ?, 'active')");
                    foreach ($selected_seats_array as $seat_number) {
                        $stmt_ticket->bind_param("isss", $booking_id, $seat_number, $passenger_name, $passenger_phone);
                        if (!$stmt_ticket->execute()) {
                            throw new Exception("Lỗi khi tạo vé cho ghế " . htmlspecialchars($seat_number) . ": " . $stmt_ticket->error);
                        }
                    }
                    $stmt_ticket->close();

                    // 5. Cập nhật trạng thái ghế trong seat_status (thêm mới hoặc cập nhật is_booked = TRUE)
                    $stmt_update_seat_status = $conn->prepare("INSERT INTO seat_status (schedule_id, seat_number, is_booked) VALUES (?, ?, TRUE) ON DUPLICATE KEY UPDATE is_booked = TRUE");
                    foreach ($selected_seats_array as $seat_number) {
                        $stmt_update_seat_status->bind_param("is", $schedule_id, $seat_number);
                        if (!$stmt_update_seat_status->execute()) {
                             throw new Exception("Lỗi khi cập nhật trạng thái ghế " . htmlspecialchars($seat_number) . ": " . $stmt_update_seat_status->error);
                        }
                    }
                    $stmt_update_seat_status->close();


                    // 6. Cập nhật số ghế trống trong bảng schedules
                    $new_available_seats = $current_available_seats - $num_seats_to_book;
                    $stmt_update_schedule = $conn->prepare("UPDATE schedules SET available_seats = ? WHERE id = ?");
                    $stmt_update_schedule->bind_param("ii", $new_available_seats, $schedule_id);
                    if (!$stmt_update_schedule->execute()) {
                        throw new Exception("Lỗi khi cập nhật số ghế trống: " . $stmt_update_schedule->error);
                    }
                    $stmt_update_schedule->close();

                    $conn->commit();
                    $success_message = "Đặt vé thành công! Bạn có thể xem vé của mình trong mục 'Vé của tôi'.";
                    // Chuyển hướng sau khi đặt vé thành công
                    header("Refresh: 3; url=tickets.php"); // Chuyển hướng sau 3 giây
                    // Reset form fields
                    $_POST = array();
                    $selected_seats_array = []; // Clear selected seats on success
                    // Cập nhật lại danh sách ghế đã đặt cho lần render tiếp theo
                    $booked_seats = [];
                    $stmt_seats = $conn->prepare("SELECT seat_number FROM seat_status WHERE schedule_id = ? AND is_booked = TRUE");
                    $stmt_seats->bind_param("i", $schedule_id);
                    $stmt_seats->execute();
                    $result_seats = $stmt_seats->get_result();
                    while ($row = $result_seats->fetch_assoc()) {
                        $booked_seats[] = $row['seat_number'];
                    }
                    $stmt_seats->close();
                    $schedule_info['available_seats'] = $new_available_seats; // Update available seats for display
                } catch (Exception $e) {
                    $conn->rollback();
                    $error_message = "Lỗi đặt vé: " . $e->getMessage();
                }
            }
        }
    }
}

// Bao gồm layout chung và CSS sơ đồ ghế
$include_seat_map_css = true; // Báo hiệu cho layout.php để include seat_map.css
include_once __DIR__ . '/partials/layout.php';
?>

<h2 class="text-center mb-20"><?php echo htmlspecialchars($page_title); ?></h2>

<?php if ($error_message): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
<?php endif; ?>
<?php if ($success_message): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div>
<?php endif; ?>

<?php if ($schedule_info): ?>
    <div class="form-container">
        <h3 class="mb-10">Thông tin chuyến đi</h3>
        <p><strong>Chuyến xe:</strong> <?php echo htmlspecialchars($schedule_info['bus_number']); ?></p>
        <p><strong>Tuyến đường:</strong> <?php echo htmlspecialchars($schedule_info['origin']); ?> &rarr; <?php echo htmlspecialchars($schedule_info['destination']); ?></p>
        <p><strong>Thời gian:</strong> <?php echo date('H:i - d/m/Y', strtotime($schedule_info['departure_time'])); ?> &rarr; <?php echo date('H:i - d/m/Y', strtotime($schedule_info['arrival_time'])); ?></p>
        <p><strong>Giá vé:</strong> <?php echo number_format($schedule_info['price'], 0, ',', '.'); ?> VNĐ/ghế</p>
        <p><strong>Ghế trống:</strong> <?php echo htmlspecialchars($schedule_info['available_seats']); ?> / <?php echo htmlspecialchars($schedule_info['total_seats']); ?></p>

        <hr class="my-20">

        <h3 class="mb-10">Chọn ghế</h3>
        <div class="seat-map-container">
            <div class="bus-layout" id="busSeatLayout">
                </div>
            <div class="seat-legend">
                <div class="legend-item"><span class="legend-color available"></span>Ghế trống</div>
                <div class="legend-item"><span class="legend-color selected"></span>Đang chọn</div>
                <div class="legend-item"><span class="legend-color booked"></span>Đã đặt</div>
            </div>
        </div>

        <form action="booking.php?schedule_id=<?php echo htmlspecialchars($schedule_id); ?>" method="POST" class="mt-20">
            <input type="hidden" name="schedule_id" value="<?php echo htmlspecialchars($schedule_id); ?>">
            <div class="form-group">
                <label for="selected_seats_display">Ghế đã chọn:</label>
                <input type="text" id="selected_seats_display" value="<?php echo htmlspecialchars(implode(', ', $selected_seats_array)); ?>" readonly disabled>
                <input type="hidden" id="selected_seats_hidden" name="selected_seats" value="<?php echo htmlspecialchars(implode(',', $selected_seats_array)); ?>">
            </div>
            <div class="form-group">
                <label for="total_price_display">Tổng tiền:</label>
                <input type="text" id="total_price_display" value="0 VNĐ" readonly disabled>
                <input type="hidden" id="total_price_hidden" name="total_price">
            </div>
            
            <div id="payment-info" class="mt-20">
                </div>

            <h3 class="mt-20 mb-10">Thông tin hành khách</h3>
            <div class="form-group">
                <label for="passenger_name">Họ và tên hành khách:</label>
                <input type="text" id="passenger_name" name="passenger_name" value="<?php echo htmlspecialchars($_POST['passenger_name'] ?? ''); ?>" required>
            </div>
            <div class="form-group">
                <label for="passenger_phone">Số điện thoại hành khách:</label>
                <input type="text" id="passenger_phone" name="passenger_phone" value="<?php echo htmlspecialchars($_POST['passenger_phone'] ?? ''); ?>" required>
            </div>
            <div class="form-group text-center">
                <button type="submit" name="book_now" class="btn btn-primary">Xác nhận đặt vé</button>
            </div>
        </form>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const busLayout = document.getElementById('busSeatLayout');
            const totalSeats = <?php echo (int)$schedule_info['total_seats']; ?>;
            const bookedSeats = <?php echo json_encode($booked_seats); ?>;
            const seatPrice = <?php echo (float)$schedule_info['price']; ?>;

            let selectedSeats = new Set();
            const selectedSeatsDisplay = document.getElementById('selected_seats_display');
            const selectedSeatsHidden = document.getElementById('selected_seats_hidden');
            const totalPriceDisplay = document.getElementById('total_price_display');
            const totalPriceHidden = document.getElementById('total_price_hidden');
            const paymentInfoDiv = document.getElementById('payment-info');
            const bankAccountInfo = <?php echo json_encode($bank_account_info); ?>;

            // Định nghĩa số cột cho sơ đồ ghế (ví dụ: 4 ghế mỗi hàng)
            const seatsPerRow = 4;
            busLayout.style.gridTemplateColumns = `repeat(${seatsPerRow}, 1fr)`;

            for (let i = 1; i <= totalSeats; i++) {
                const seat = document.createElement('div');
                seat.classList.add('seat');
                seat.textContent = i; // Số ghế
                seat.dataset.seatNumber = i;

                if (bookedSeats.includes(String(i))) { // bookedSeats chứa string
                    seat.classList.add('booked');
                } else {
                    seat.addEventListener('click', function() {
                        if (seat.classList.contains('selected')) {
                            seat.classList.remove('selected');
                            selectedSeats.delete(seat.dataset.seatNumber);
                        } else {
                            seat.classList.add('selected');
                            selectedSeats.add(seat.dataset.seatNumber);
                        }
                        updateSelectedSeatsDisplay();
                    });
                }
                busLayout.appendChild(seat);
            }

            function updateSelectedSeatsDisplay() {
                const sortedSeats = Array.from(selectedSeats).sort((a, b) => parseInt(a) - parseInt(b));
                selectedSeatsDisplay.value = sortedSeats.join(', ');
                selectedSeatsHidden.value = sortedSeats.join(',');

                const total = sortedSeats.length * seatPrice;
                totalPriceDisplay.value = total.toLocaleString('vi-VN') + ' VNĐ';
                totalPriceHidden.value = total;

                // Cập nhật thông tin thanh toán
                if (total > 0) {
                    paymentInfoDiv.innerHTML = `
                        <div class="alert alert-info">
                            <h4>Thông tin thanh toán</h4>
                            <p><strong>Ngân hàng:</strong> ${bankAccountInfo.bank_name}</p>
                            <p><strong>Số tài khoản:</strong> ${bankAccountInfo.account_number}</p>
                            <p><strong>Tên chủ tài khoản:</strong> ${bankAccountInfo.account_name}</p>
                            <p><strong>Số tiền cần thanh toán:</strong> <strong>${total.toLocaleString('vi-VN')} VNĐ</strong></p>
                            <p class="small mt-10">Vui lòng chuyển khoản đúng số tiền trên. Nội dung chuyển khoản: Họ tên hành khách - Số điện thoại</p>
                        </div>
                    `;
                } else {
                    paymentInfoDiv.innerHTML = '';
                }
            }

            // Khởi tạo hiển thị nếu có ghế đã chọn từ POST (trường hợp lỗi form)
            const initialSelectedSeats = "<?php echo htmlspecialchars(implode(',', $selected_seats_array)); ?>";
            if (initialSelectedSeats) {
                initialSelectedSeats.split(',').forEach(seatNum => {
                    const seatElement = document.querySelector(`.seat[data-seat-number="${seatNum}"]`);
                    if (seatElement && !seatElement.classList.contains('booked')) {
                        seatElement.classList.add('selected');
                        selectedSeats.add(seatNum);
                    }
                });
                updateSelectedSeatsDisplay();
            }
        });
    </script>

<?php else: ?>
    <?php if (!$error_message): // Chỉ hiển thị nếu không có lỗi cụ thể ?>
        <div class="alert alert-info">Vui lòng chọn một lịch trình từ trang <a href="schedule.php">Lịch trình</a> hoặc <a href="index.php">Trang chủ</a> để đặt vé.</div>
    <?php endif; ?>
<?php endif; ?>


<?php
// Đóng thẻ main và div container từ layout.php
echo '</div></main>';
// Bao gồm footer từ layout.php
include_once __DIR__ . '/partials/layout_end.php';
?>