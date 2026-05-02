<?php
// schedule.php
require_once __DIR__ . '/php/db.php';
// Bắt đầu session nếu chưa được bắt đầu
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

$page_title = "Lịch trình các chuyến xe";
$schedules = [];
$error_message = '';

$conn = get_db_connection();

// Lấy danh sách các điểm đi và điểm đến để hiển thị trong dropdown
$origins = [];
$destinations = [];

$stmt_origins = $conn->prepare("SELECT DISTINCT origin FROM routes ORDER BY origin");
if ($stmt_origins->execute()) {
    $result_origins = $stmt_origins->get_result();
    while ($row = $result_origins->fetch_assoc()) {
        $origins[] = $row['origin'];
    }
}
$stmt_origins->close();

$stmt_destinations = $conn->prepare("SELECT DISTINCT destination FROM routes ORDER BY destination");
if ($stmt_destinations->execute()) {
    $result_destinations = $stmt_destinations->get_result();
    while ($row = $result_destinations->fetch_assoc()) {
        $destinations[] = $row['destination'];
    }
}
$stmt_destinations->close();


$current_date = date('Y-m-d'); // Ngày hiện tại

// Lọc lịch trình dựa trên các tham số GET hoặc POST
$filter_origin = isset($_GET['origin']) ? trim($_GET['origin']) : (isset($_POST['origin']) ? trim($_POST['origin']) : '');
$filter_destination = isset($_GET['destination']) ? trim($_GET['destination']) : (isset($_POST['destination']) ? trim($_POST['destination']) : '');
$filter_date = isset($_GET['travel_date']) ? trim($_GET['travel_date']) : (isset($_POST['travel_date']) ? trim($_POST['travel_date']) : $current_date);

// Chuẩn bị câu lệnh SQL cơ bản
$sql = "
    SELECT
        s.id AS schedule_id,
        r.origin,
        r.destination,
        s.departure_time,
        s.arrival_time,
        s.price,
        s.available_seats,
        b.bus_number,
        b.capacity
    FROM
        schedules s
    JOIN
        routes r ON s.route_id = r.id
    JOIN
        buses b ON s.bus_id = b.id
    WHERE
        s.status = 'scheduled'
";
$params = [];
$types = '';

// Thêm điều kiện lọc
if (!empty($filter_origin)) {
    $sql .= " AND r.origin = ?";
    $params[] = $filter_origin;
    $types .= 's';
}
if (!empty($filter_destination)) {
    $sql .= " AND r.destination = ?";
    $params[] = $filter_destination;
    $types .= 's';
}
if (!empty($filter_date)) {
    $sql .= " AND s.departure_time BETWEEN ? AND ?";
    $params[] = $filter_date . ' 00:00:00';
    $params[] = $filter_date . ' 23:59:59';
    $types .= 'ss';
}

$sql .= " ORDER BY s.departure_time ASC";

$stmt = $conn->prepare($sql);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

if ($stmt->execute()) {
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $schedules[] = $row;
        }
    } else {
        $error_message = "Không tìm thấy chuyến xe nào phù hợp với các tiêu chí tìm kiếm.";
    }
} else {
    $error_message = "Lỗi khi tải lịch trình: " . $conn->error;
}
$stmt->close();
$conn->close();

// Bao gồm layout chung
include_once __DIR__ . '/partials/layout.php';
?>

<h2 class="text-center mb-20"><?php echo htmlspecialchars($page_title); ?></h2>

<div class="form-container">
    <form action="schedule.php" method="GET">
        <div class="form-group">
            <label for="origin">Điểm đi:</label>
            <select id="origin" name="origin">
                <option value="">Tất cả</option>
                <?php foreach ($origins as $o): ?>
                    <option value="<?php echo htmlspecialchars($o); ?>"
                        <?php echo ($filter_origin == $o) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($o); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="destination">Điểm đến:</label>
            <select id="destination" name="destination">
                <option value="">Tất cả</option>
                <?php foreach ($destinations as $d): ?>
                    <option value="<?php echo htmlspecialchars($d); ?>"
                        <?php echo ($filter_destination == $d) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($d); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="travel_date">Ngày đi:</label>
            <input type="date" id="travel_date" name="travel_date" value="<?php echo htmlspecialchars($filter_date); ?>" min="<?php echo date('Y-m-d'); ?>">
        </div>
        <div class="form-group text-center">
            <button type="submit" class="btn btn-primary">Lọc lịch trình</button>
        </div>
    </form>
</div>

<?php if ($error_message): ?>
    <div class="alert alert-danger mt-20"><?php echo htmlspecialchars($error_message); ?></div>
<?php endif; ?>

<?php if (!empty($schedules)): ?>
    <h3 class="mt-20 text-center">Lịch trình khả dụng</h3>
    <table class="data-table">
        <thead>
            <tr>
                <th>Chuyến xe</th>
                <th>Điểm đi</th>
                <th>Điểm đến</th>
                <th>Giờ khởi hành</th>
                <th>Giờ đến</th>
                <th>Giá vé</th>
                <th>Ghế trống</th>
                <th>Hành động</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($schedules as $schedule): ?>
                <tr>
                    <td><?php echo htmlspecialchars($schedule['bus_number']); ?></td>
                    <td><?php echo htmlspecialchars($schedule['origin']); ?></td>
                    <td><?php echo htmlspecialchars($schedule['destination']); ?></td>
                    <td><?php echo date('H:i', strtotime($schedule['departure_time'])); ?></td>
                    <td><?php echo date('H:i', strtotime($schedule['arrival_time'])); ?></td>
                    <td><?php echo number_format($schedule['price'], 0, ',', '.'); ?> VNĐ</td>
                    <td><?php echo htmlspecialchars($schedule['available_seats']); ?> / <?php echo htmlspecialchars($schedule['capacity']); ?></td>
                    <td>
                        <?php if ($schedule['available_seats'] > 0): ?>
                            <a href="booking.php?schedule_id=<?php echo htmlspecialchars($schedule['schedule_id']); ?>" class="btn btn-success btn-sm">Đặt vé</a>
                        <?php else: ?>
                            <span class="btn btn-secondary btn-sm" style="opacity: 0.7; cursor: not-allowed;">Hết vé</span>
                        <?php endif; ?>
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
