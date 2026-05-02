<?php
// register.php
// Bao gồm tệp kết nối cơ sở dữ liệu
require_once __DIR__ . '/php/db.php';
// Bắt đầu session nếu chưa được bắt đầu
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

$page_title = "Đăng ký tài khoản";
$error_message = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $phone_number = trim($_POST['phone_number'] ?? '');

    // Kiểm tra đầu vào
    if (empty($username) || empty($password) || empty($email)) {
        $error_message = "Vui lòng điền đầy đủ các trường bắt buộc (Tên đăng nhập, Mật khẩu, Email).";
    } elseif ($password !== $confirm_password) {
        $error_message = "Mật khẩu xác nhận không khớp.";
    } elseif (strlen($password) < 6) {
        $error_message = "Mật khẩu phải có ít nhất 6 ký tự.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = "Địa chỉ email không hợp lệ.";
    } else {
        // Băm mật khẩu trước khi lưu vào database
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        $conn = get_db_connection();

        // Kiểm tra xem tên đăng nhập hoặc email đã tồn tại chưa
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $stmt->bind_param("ss", $username, $email);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            $error_message = "Tên đăng nhập hoặc Email đã tồn tại. Vui lòng chọn tên khác.";
        } else {
            // Chèn người dùng mới vào cơ sở dữ liệu
            $stmt = $conn->prepare("INSERT INTO users (username, password, email, full_name, phone_number) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sssss", $username, $hashed_password, $email, $full_name, $phone_number);

            if ($stmt->execute()) {
                $success_message = "Đăng ký thành công! Bạn có thể đăng nhập ngay bây giờ.";
                // Xóa dữ liệu form sau khi đăng ký thành công
                $_POST = array();
            } else {
                $error_message = "Có lỗi xảy ra khi đăng ký: " . $stmt->error;
            }
        }
        $stmt->close();
        $conn->close();
    }
}

// Bao gồm layout chung
include_once __DIR__ . '/partials/layout.php';
?>

<div class="form-container">
    <h2 class="text-center mb-20"><?php echo htmlspecialchars($page_title); ?></h2>

    <?php if ($error_message): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
    <?php endif; ?>
    <?php if ($success_message): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div>
    <?php endif; ?>

    <form action="register.php" method="POST">
        <div class="form-group">
            <label for="username">Tên đăng nhập: <span style="color: red;">*</span></label>
            <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" required>
        </div>
        <div class="form-group">
            <label for="email">Email: <span style="color: red;">*</span></label>
            <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
        </div>
        <div class="form-group">
            <label for="password">Mật khẩu: <span style="color: red;">*</span></label>
            <input type="password" id="password" name="password" required>
        </div>
        <div class="form-group">
            <label for="confirm_password">Xác nhận mật khẩu: <span style="color: red;">*</span></label>
            <input type="password" id="confirm_password" name="confirm_password" required>
        </div>
        <div class="form-group">
            <label for="full_name">Họ và tên:</label>
            <input type="text" id="full_name" name="full_name" value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>">
        </div>
        <div class="form-group">
            <label for="phone_number">Số điện thoại:</label>
            <input type="text" id="phone_number" name="phone_number" value="<?php echo htmlspecialchars($_POST['phone_number'] ?? ''); ?>">
        </div>
        <div class="form-group text-center">
            <button type="submit" class="btn btn-primary">Đăng ký</button>
        </div>
        <p class="text-center mt-20">Đã có tài khoản? <a href="login.php">Đăng nhập ngay</a></p>
    </form>
</div>

<?php
// Đóng thẻ main và div container từ layout.php
echo '</div></main>';
// Bao gồm footer từ layout.php
include_once __DIR__ . '/partials/layout_end.php'; // Sẽ tạo tệp này để đóng body/html
?>
