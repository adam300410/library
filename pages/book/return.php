<?php
session_start();
require_once '../../config/db.php';

$borrowed_books = [];

if ($_SERVER['REQUEST_METHOD'] == 'GET' && !empty($_GET['search'])) {
    try {
        $search = "%" . $_GET['search'] . "%";
        $stmt = $pdo->prepare("
            SELECT DISTINCT b.*, 
                   bi2.id as original_id,
                   s.name as student_name,
                   s.class_type,
                   s.student_number,
                   s.student_card_number,
                   s.book_note_return as current_borrowed,
                   s.can_borrow_or_not,
                   DATEDIFF(CURDATE(), b.borrow_date) as days_borrowed
            FROM book_info b 
            LEFT JOIN students s ON b.student_card_number = s.student_card_number
            LEFT JOIN book_info2 bi2 ON b.isbn = bi2.isbn AND b.anco = bi2.anco 
            WHERE b.available = 0 
            AND (b.isbn LIKE ? 
                OR b.anco LIKE ? 
                OR b.title LIKE ? 
                OR s.name LIKE ?
                OR s.student_number LIKE ?)
            ORDER BY b.borrow_date DESC");
        
        $stmt->execute([$search, $search, $search, $search, $search]);
        $borrowed_books = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        $error = "查詢失敗：" . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $pdo->beginTransaction();

        // First validate the book_id exists
        if (empty($_POST['book_id']) || !is_numeric($_POST['book_id'])) {
            throw new Exception("無效的書籍ID");
        }

        // Get the book and student info with a lock
        $stmt = $pdo->prepare("
            SELECT b.*, s.student_card_number, s.student_number, s.name, s.class_type, s.role, s.book_note_return,
                   bi2.id as book_info2_id
            FROM book_info b 
            LEFT JOIN students s ON b.student_card_number = s.student_card_number
            LEFT JOIN book_info2 bi2 ON b.isbn = bi2.isbn AND b.anco = bi2.anco
            WHERE b.id = ? AND b.available = 0
            FOR UPDATE");
        $stmt->execute([$_POST['book_id']]);
        $bookInfo = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$bookInfo) {
            throw new Exception("找不到借閱記錄或書籍已歸還");
        }

        if (!$bookInfo['student_card_number']) {
            throw new Exception("找不到學生資料");
        }

        // Update book_info status
        $stmt = $pdo->prepare("
            UPDATE book_info 
            SET available = 1, 
                return_date = CURDATE(),
                return_date_time = NOW(),
                borrow_day = DATEDIFF(CURDATE(), borrow_date)
            WHERE id = ? AND available = 0");
        $result = $stmt->execute([$_POST['book_id']]);

        if ($stmt->rowCount() === 0) {
            throw new Exception("更新書籍狀態失敗，可能書籍已歸還");
        }

        // Verify original_id exists and matches the book
        if (empty($_POST['original_id']) || $_POST['original_id'] != $bookInfo['book_info2_id']) {
            throw new Exception("無效的原始書籍ID");
        }

        // Process remarks
        $remark = [];
        if (!empty($_POST['is_damaged'])) $remark[] = "損壞";
        if (!empty($_POST['is_lost'])) $remark[] = "遺失";
        if (!empty($_POST['is_exchanged'])) $remark[] = "換書";
        
        $remarkText = !empty($remark) ? implode(",", $remark) : null;
        if (!empty($_POST['remark'])) {
            $remarkText = $remarkText ? $remarkText . "," . $_POST['remark'] : $_POST['remark'];
        }

        // Update book_info2
        $stmt = $pdo->prepare("
            UPDATE book_info2 
            SET available = 1,
                remark = ?
            WHERE id = ? AND isbn = ?");
        $result = $stmt->execute([
            $remarkText,
            $_POST['original_id'],
            $bookInfo['isbn']
        ]);

        if ($stmt->rowCount() === 0) {
            throw new Exception("更新館藏狀態失敗");
        }

        // Update student's book_note_return only if the student exists
        $stmt = $pdo->prepare("
            UPDATE students 
            SET book_note_return = GREATEST(0, book_note_return - 1),
                can_borrow_or_not = CASE 
                    WHEN (GREATEST(0, book_note_return - 1)) < (SELECT quota FROM role WHERE id = role) THEN 1 
                    ELSE 0 
                END
            WHERE student_card_number = ?");
        $result = $stmt->execute([$bookInfo['student_card_number']]);

        if ($stmt->rowCount() === 0) {
            throw new Exception("更新學生借閱數量失敗。學生卡號：" . $bookInfo['student_card_number']);
        }

        $pdo->commit();

        $success = sprintf(
            "還書成功!\n書名：%s\n借閱人：%s\n班級：%s\n學號：%s",
            $bookInfo['title'],
            $bookInfo['name'],
            $bookInfo['class_type'],
            $bookInfo['student_number']
        );

        if (!empty($_GET['search'])) {
            $_SESSION['success'] = $success;
            header("Location: return.php?search=" . urlencode($_GET['search']));
            exit;
        }
    } catch(PDOException $e) {
        $pdo->rollBack();
        $error = "還書失敗：" . $e->getMessage();
    } catch(Exception $e) {
        $pdo->rollBack();
        $error = $e->getMessage();
    }
}

// Get success message from session if exists
if (isset($_SESSION['success'])) {
    $success = $_SESSION['success'];
    unset($_SESSION['success']);
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>還書 - 圖書借還系統</title>
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
        <h2>還書</h2>
        
        <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?php echo nl2br(htmlspecialchars($error)); ?></div>
        <?php endif; ?>
        
        <?php if (isset($success)): ?>
            <div class="alert alert-success"><?php echo nl2br(htmlspecialchars($success)); ?></div>
        <?php endif; ?>

        <form method="GET" class="mb-4">
            <div class="input-group">
                <input type="text" class="form-control" name="search" 
                       placeholder="輸入ISBN、館藏編號、書名、借閱人姓名或學號" 
                       value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>"
                       autofocus>
                <button type="submit" class="btn btn-primary">搜尋</button>
            </div>
        </form>

        <?php if (!empty($borrowed_books)): ?>
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>ISBN</th>
                        <th>館藏編號</th>
                        <th>書名</th>
                        <th>借閱人</th>
                        <th>班別</th>
                        <th>學號</th>
                        <th>借出日期</th>
                        <th>已借閱天數</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($borrowed_books as $book): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($book['isbn']); ?></td>
                            <td><?php echo htmlspecialchars($book['anco']); ?></td>
                            <td><?php echo htmlspecialchars($book['title']); ?></td>
                            <td><?php echo htmlspecialchars($book['student_name']); ?></td>
                            <td><?php echo htmlspecialchars($book['class_type']); ?></td>
                            <td><?php echo htmlspecialchars($book['student_number']); ?></td>
                            <td><?php echo htmlspecialchars($book['borrow_date']); ?></td>
                            <td><?php echo htmlspecialchars($book['days_borrowed']); ?> 天</td>
                            <td>
                                <button type="button" class="btn btn-sm btn-success" 
                                        onclick="showReturnModal(
                                            '<?php echo $book['id']; ?>', 
                                            '<?php echo $book['original_id']; ?>',
                                            '<?php echo addslashes($book['title']); ?>',
                                            '<?php echo addslashes($book['student_name']); ?>',
                                            '<?php echo addslashes($book['class_type']); ?>',
                                            '<?php echo addslashes($book['student_number']); ?>'
                                        )">
                                    歸還
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php elseif (isset($_GET['search'])): ?>
            <div class="alert alert-info">沒有找到符合條件的借出書籍</div>
        <?php endif; ?>

        <a href="../../index.php" class="btn btn-secondary">返回首頁</a>
    </div>

    <!-- Return Modal -->
    <div class="modal fade" id="returnModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">還書確認</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="book_id" id="modal_book_id">
                        <input type="hidden" name="original_id" id="modal_original_id">
                        
                        <div id="book_info" class="alert alert-info mb-3"></div>

                        <div class="mb-3">
                            <label for="remark" class="form-label">備註</label>
                            <textarea class="form-control" name="remark" id="remark" rows="3" placeholder="請輸入備註"></textarea>
                        </div>

                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_damaged" value="1" id="is_damaged">
                                <label class="form-check-label" for="is_damaged">損壞</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_lost" value="1" id="is_lost">
                                <label class="form-check-label" for="is_lost">遺失</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_exchanged" value="1" id="is_exchanged">
                                <label class="form-check-label" for="is_exchanged">換書</label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                        <button type="submit" class="btn btn-primary">確認歸還</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function showReturnModal(bookId, originalId, title, studentName, classType, studentNumber) {
        document.getElementById('modal_book_id').value = bookId;
        document.getElementById('modal_original_id').value = originalId;
        
        // Display book and student info
        document.getElementById('book_info').innerHTML = `
            書名：${title}<br>
            借閱人：${studentName}<br>
            班級：${classType}<br>
            學號：${studentNumber}
        `;
        
        // Reset form
        document.getElementById('remark').value = '';
        document.getElementById('is_damaged').checked = false;
        document.getElementById('is_lost').checked = false;
        document.getElementById('is_exchanged').checked = false;
        
        // Show modal
        new bootstrap.Modal(document.getElementById('returnModal')).show();
    }
    </script>
</body>
</html>
