<?php 
date_default_timezone_set('Asia/Shanghai');
session_start();
require_once '../../config/db.php';

$book = null;
$user = null;
$error = null;
$success = null;

if (isset($_GET['book_id']) && isset($_GET['user_id'])) {
    try {
        // Get book details
        $stmt = $pdo->prepare("SELECT * FROM book_info2 WHERE id = ? AND available = 1");
        $stmt->execute([$_GET['book_id']]);
        $book = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$book) {
            $error = "找不到可借閱的書籍";
        }

        // Get user details with role and borrowed books info
        $stmt = $pdo->prepare("
            SELECT s.*, r.quota, r.days,
                   s.book_note_return as current_borrowed,
                   (r.quota - s.book_note_return) as remaining_quota
            FROM students s 
            LEFT JOIN role r ON s.role = r.id 
            WHERE s.id = ?");
        $stmt->execute([$_GET['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $error = "找不到使用者資料";
        } else {
            // Check user status and borrow limits
            if ($user['valid_or_not'] != 1) {
                $error = "此帳戶已被停權，請聯繫圖書館管理員";
            } elseif ($user['current_borrowed'] >= $user['quota']) {
                $error = "此帳戶已借滿 {$user['quota']} 本書，不能再借";
            }
        }
    } catch(PDOException $e) {
        $error = "查詢失敗：" . $e->getMessage();
    }
}

// Calculate return date based on user's role days
if ($user && isset($user['days'])) {
    $borrow_date = new DateTime();
    $return_date = clone $borrow_date;
    $days = intval($user['days']);
    $return_date->modify("+{$days} days");
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['book_id']) && isset($_POST['user_id'])) {
    try {
        $pdo->beginTransaction();

        // Double check user's borrow limit
        $stmt = $pdo->prepare("
            SELECT s.book_note_return, r.quota 
            FROM students s
            LEFT JOIN role r ON s.role = r.id
            WHERE s.id = ? FOR UPDATE");
        $stmt->execute([$_POST['user_id']]);
        $current = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($current['book_note_return'] >= $current['quota']) {
            throw new Exception("已超過借閱上限");
        }

        // Update book_info2
        $stmt = $pdo->prepare("UPDATE book_info2 SET available = 0, borrow_time = borrow_time + 1 WHERE id = ?");
        $stmt->execute([$_POST['book_id']]);

        // Update student's book_note_return
        $stmt = $pdo->prepare("UPDATE students SET book_note_return = book_note_return + 1 WHERE id = ?");
        $stmt->execute([$_POST['user_id']]);

        // Update can_borrow_or_not based on quota
        $stmt = $pdo->prepare("
            UPDATE students s 
            SET s.can_borrow_or_not = CASE 
                WHEN s.book_note_return + 1 >= (SELECT r.quota FROM role r WHERE r.id = s.role) THEN 0 
                ELSE 1 
            END 
            WHERE s.id = ?");
        $stmt->execute([$_POST['user_id']]);

        // Insert into book_info
        $stmt = $pdo->prepare("
            INSERT INTO book_info (
                isbn, cal_no, title, author, publisher, published_date, 
                available, borrow_date, return_date,
                student_card_number, name, role, image_url
            ) SELECT 
                isbn, cal_no, title, author, publisher, published_date,
                0, CURDATE(), DATE_ADD(CURDATE(), INTERVAL :days DAY),
                :student_card_number, :name, :role, image_url
            FROM book_info2 WHERE id = :book_id");

        $stmt->execute([
            ':days' => $days,
            ':student_card_number' => $user['student_card_number'],
            ':name' => $user['name'],
            ':role' => $user['role'] == 1 ? '老師' : '學生',
            ':book_id' => $_POST['book_id']
        ]);

        $pdo->commit();

        $success = sprintf(
            "%s-%s-%s 已成功借出 %s書籍 及 到時還書日期為：%s",
            $user['name'],
            $user['class_type'],
            $user['student_number'],
            $book['title'],
            $return_date->format('Y-m-d')
        );

    } catch(Exception $e) {
        $pdo->rollBack();
        $error = "借閱失敗：" . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>確認借閱 - 圖書借還系統</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../../css/style.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="../../index.php">圖書借還系統</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
        </div>
    </nav>

    <div class="container mt-4">
        <h2>確認借閱</h2>
        
        <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php elseif ($book && $user): ?>
            <div class="row">
                <div class="col-md-6">
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="card-title mb-0">書籍資料</h5>
                        </div>
                        <div class="card-body">
                            <p class="card-text">
                                <strong>書名：</strong><?php echo htmlspecialchars($book['title']); ?><br>
                                <strong>ISBN：</strong><?php echo htmlspecialchars($book['isbn']); ?><br>
                                <strong>館藏編號：</strong><?php echo htmlspecialchars($book['cal_no']); ?><br>
                                <strong>作者：</strong><?php echo htmlspecialchars($book['author']); ?><br>
                                <strong>出版社：</strong><?php echo htmlspecialchars($book['publisher']); ?><br>
                                <strong>出版日期：</strong><?php echo htmlspecialchars($book['published_date']); ?>
                            </p>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="card-title mb-0">借閱者資料</h5>
                        </div>
                        <div class="card-body">
                            <p class="card-text">
                                <strong>姓名：</strong><?php echo htmlspecialchars($user['name']); ?><br>
                                <strong>班級：</strong><?php echo htmlspecialchars($user['class_type']); ?><br>
                                <strong>學號：</strong><?php echo htmlspecialchars($user['student_number']); ?><br>
                                <strong>身份：</strong><?php echo $user['role'] == 1 ? '老師' : '學生'; ?><br>
                                <strong>已借冊數：</strong><?php echo $user['current_borrowed']; ?> / <?php echo $user['quota']; ?><br>
                                <strong>借閱日期：</strong><?php echo (new DateTime())->format('Y-m-d'); ?><br>
                                <strong>預計還書日期：</strong><?php echo $return_date->format('Y-m-d'); ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <form method="POST" class="mb-3">
                <input type="hidden" name="book_id" value="<?php echo $book['id']; ?>">
                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                <button type="submit" class="btn btn-primary" onclick="return confirmBorrow()">確認借閱</button>
                <a href="javascript:history.back()" class="btn btn-secondary">返回修改</a>
            </form>
        <?php else: ?>
            <div class="alert alert-warning">參數錯誤，請重新操作</div>
        <?php endif; ?>
        
        <a href="search.php" class="btn btn-link">返回查詢</a>
    </div>

    <?php if (isset($success)): ?>
    <script>
        alert('<?php echo addslashes($success); ?>');
        window.location.href = 'search.php';
    </script>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function confirmBorrow() {
        <?php if (isset($user) && isset($book)): ?>
        return confirm('確定要借出此書嗎？\n\n' + 
            '借閱人：<?php echo addslashes($user['name']); ?>\n' +
            '班級：<?php echo addslashes($user['class_type']); ?>\n' +
            '學號：<?php echo addslashes($user['student_number']); ?>\n' +
            '已借冊數：<?php echo $user['current_borrowed']; ?> / <?php echo $user['quota']; ?>\n' +
            '書名：<?php echo addslashes($book['title']); ?>\n' +
            '預計還書日期：<?php echo $return_date->format('Y-m-d'); ?>');
        <?php endif; ?>
        return false;
    }
    </script>
</body>
</html>