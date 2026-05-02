<?php
// login.php
require_once __DIR__ . '/php/db.php';
// Bắt đầu session nếu chưa được bắt đầu
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

$page_title = "Đăng nhập tài khoản";
$error_message = '';

// Nếu người dùng đã đăng nhập, chuyển hướng về trang chủ
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username_or_email = trim($_POST['username_or_email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($username_or_email) || empty($password)) {
        $error_message = "Vui lòng điền đầy đủ tên đăng nhập/email và mật khẩu.";
    } else {
        $conn = get_db_connection();

        // Chuẩn bị câu lệnh SQL để truy vấn người dùng bằng username hoặc email
        $stmt = $conn->prepare("SELECT id, username, password FROM users WHERE username = ? OR email = ?");
        $stmt->bind_param("ss", $username_or_email, $username_or_email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows == 1) {
            $user = $result->fetch_assoc();
            // Xác minh mật khẩu
            if (password_verify($password, $user['password'])) {
                // Đăng nhập thành công, lưu thông tin vào session
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['is_logged_in'] = true; // Đánh dấu đã đăng nhập
                header('Location: index.php'); // Chuyển hướng về trang chủ
                exit();
            } else {
                $error_message = "Mật khẩu không đúng.";
            }
        } else {
            $error_message = "Tên đăng nhập hoặc Email không tồn tại.";
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

    <form action="login.php" method="POST">
        <div class="form-group">
            <label for="username_or_email">Tên đăng nhập hoặc Email:</label>
            <input type="text" id="username_or_email" name="username_or_email" value="<?php echo htmlspecialchars($_POST['username_or_email'] ?? ''); ?>" required>
        </div>
        <div class="form-group">
            <label for="password">Mật khẩu:</label>
            <input type="password" id="password" name="password" required>
        </div>
        <div class="form-group text-center">
            <button type="submit" class="btn btn-primary">Đăng nhập</button>
        </div>
        <p class="text-center mt-20">Chưa có tài khoản? <a href="register.php">Đăng ký ngay</a></p>
    </form>
</div>

<?php
// Đóng thẻ main và div container từ layout.php
echo '</div></main>';
// Bao gồm footer từ layout.php
include_once __DIR__ . '/partials/layout_end.php';
?>
