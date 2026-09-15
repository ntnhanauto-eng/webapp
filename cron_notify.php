<?php
// Bật hiển thị lỗi PHP trực tiếp lên màn hình
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

date_default_timezone_set('Asia/Ho_Chi_Minh');

echo "1. Đang gọi file db.php...<br>";
include 'db.php';
echo "2. Kết nối CSDL thành công!<br>";

if (isset($conn)) {
    echo "3. Biến kết nối \$conn hoạt động bình thường.";
} else {
    echo "3. Lỗi: Không tìm thấy biến \$conn trong db.php.";
}
?>
