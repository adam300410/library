<?php
session_start();
require_once '../../config/db.php';

$records = [];
$search_type = $_GET['type'] ?? 'all';
$search_query = $_GET['search'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

try {
    $params = [];
    $conditions = [];

    if (!empty($search_query)) {
        $search = "%$search_query%";
        switch ($search_type) {
            case 'book':
                $conditions[] = "(isbn LIKE ? OR anco LIKE ? OR title LIKE ?)";
                $params = array_merge($params, [$search, $search, $search]);
                break;
            case 'user':
                $conditions[] = "(school_card_number LIKE ? OR name LIKE ?)";
                $params = array_merge($params, [$search, $search]);
                break;
            default:
                $conditions[] = "(isbn LIKE ? OR anco LIKE ? OR title LIKE ? OR school_card_number LIKE ? OR name LIKE ?)";
                $params = array_merge($params, [$search, $search, $search, $search, $search]);
        }
    }

    if (!empty($start_date)) {
        $conditions[] = "borrow_date >= ?";
        $params[] = $start_date;
    }

    if (!empty($end_date)) {
        $conditions[] = "borrow_date <= ?";
        $params[] = $end_date;
    }

    $sql = "SELECT b.*, s.class, b2.id as book_id FROM book_info b
            LEFT JOIN students s ON b.school_card_number = s.school_card_number
            LEFT JOIN book_info2 b2 ON b.isbn = b2.isbn AND b.anco = b2.anco
            WHERE b.available = 0";
            
    if (!empty($conditions)) {
        $sql .= " AND " . implode(" AND ", $conditions);
    }
    $sql .= " ORDER BY borrow_date DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $error = "查詢失敗：" . $e->getMessage();
}

// Processing form submission for returning books
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['book_id'])) {
    try {
        $book_id = $_POST['book_id'];
        $original_id = $_POST['original_id'] ?? null;
        $is_damaged = isset($_POST['is_damaged']) ? 1 : 0;
        $is_lost = isset($_POST['is_lost']) ? 1 : 0;
        $is_exchanged = isset($_POST['is_exchanged']) ? 1 : 0;
        $remark = $_POST['remark'] ?? '';
        
        // Start transaction
        $pdo->beginTransaction();
        
        // Update book_info status
        $update_sql = "UPDATE book_info SET 
                      available = 1, 
                      return_date = CURRENT_DATE(), 
                      remark = ?, 
                      is_damaged = ?, 
                      is_lost = ?, 
                      is_exchanged = ? 
                      WHERE id = ?";
        $update_stmt = $pdo->prepare($update_sql);
        $update_stmt->execute([$remark, $is_damaged, $is_lost, $is_exchanged, $original_id]);
        
        // If the book is not lost, update book_info2 availability
        if (!$is_lost) {
            $update_book_sql = "UPDATE book_info2 SET available = 1 WHERE id = ?";
            $update_book_stmt = $pdo->prepare($update_book_sql);
            $update_book_stmt->execute([$book_id]);
        }
        
        $pdo->commit();
        
        // Redirect to prevent form resubmission
        header("Location: " . $_SERVER['PHP_SELF'] . (isset($_GET['search']) ? '?' . http_build_query($_GET) : ''));
        exit;
    } catch (PDOException $e) {
        $pdo->rollBack();
        $error = "還書失敗：" . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>查詢借閱紀錄 - 圖書借還系統</title>
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
        <h2>查詢借閱紀錄</h2>
        
        <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>

        <form method="GET" class="mb-4">
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label for="type">搜尋類型</label>
                    <select class="form-control" id="type" name="type">
                        <option value="all" <?php echo $search_type == 'all' ? 'selected' : ''; ?>>全部</option>
                        <option value="book" <?php echo $search_type == 'book' ? 'selected' : ''; ?>>書籍</option>
                        <option value="user" <?php echo $search_type == 'user' ? 'selected' : ''; ?>>借閱人</option>
                    </select>
                </div>
                <div class="col-md-8 mb-3">
                    <label for="search">搜尋關鍵字</label>
                    <input type="text" class="form-control" id="search" name="search" value="<?php echo htmlspecialchars($search_query); ?>" placeholder="輸入關鍵字">
                </div>
            </div>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="start_date">開始日期</label>
                    <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo $start_date; ?>">
                </div>
                <div class="col-md-6 mb-3">
                    <label for="end_date">結束日期</label>
                    <input type="date" class="form-control" id="end_date" name="end_date" value="<?php echo $end_date; ?>">
                </div>
            </div>
            <button type="submit" class="btn btn-primary">搜尋</button>
            <?php if (!empty($records)): ?>
                <a href="export_csv.php?<?php echo http_build_query($_GET); ?>" class="btn btn-success ms-2">匯出 CSV</a>
            <?php endif; ?>
        </form>

        <?php if (!empty($records)): ?>
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>ISBN</th>
                        <th>館藏編號</th>
                        <th>書名</th>
                        <th>學號</th>
                        <th>借閱人姓名</th>
                        <th>班級</th>
                        <th>身分</th>
                        <th>借出日期</th>
                        <th>歸還日期</th>
                        <th>借閱天數</th>
                        <th>狀態</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($records as $record): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($record['isbn']); ?></td>
                            <td><?php echo htmlspecialchars($record['anco']); ?></td>
                            <td><?php echo htmlspecialchars($record['title']); ?></td>
                            <td><?php echo htmlspecialchars($record['school_card_number']); ?></td>
                            <td><?php echo htmlspecialchars($record['name']); ?></td>
                            <td><?php echo htmlspecialchars($record['class'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($record['role']); ?></td>
                            <td><?php echo htmlspecialchars($record['borrow_date']); ?></td>
                            <td><?php echo $record['return_date'] ? htmlspecialchars($record['return_date']) : '-'; ?></td>
                            <td><?php echo $record['borrow_day'] ?? '-'; ?></td>
                            <td><?php echo !$record['available'] ? '借出中' : '已歸還'; ?></td>
                            <td>
                                <?php if($record['book_id']): ?>
                                    <a href="../book/edit.php?id=<?php echo htmlspecialchars($record['book_id']); ?>&referer=<?php echo urlencode($_SERVER['PHP_SELF'] . '?' . http_build_query($_GET)); ?>"
                                       class="btn btn-primary btn-sm">
                                        編輯
                                    </a>
                                    <button type="button" class="btn btn-sm btn-success ms-1"
                                        onclick="showReturnModal(
                                            '<?php echo $record['id']; ?>',
                                            '<?php echo $record['book_id']; ?>',
                                            '<?php echo addslashes($record['title']); ?>',
                                            '<?php echo addslashes($record['name']); ?>',
                                            '<?php echo addslashes($record['class'] ?? '-'); ?>',
                                            '<?php echo addslashes($record['school_card_number']); ?>'
                                        )">
                                        還書
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php elseif ($_SERVER['REQUEST_METHOD'] == 'GET'): ?>
            <div class="alert alert-info">沒有找到符合條件的借閱紀錄</div>
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
                        <button type="submit" class="btn btn-primary">確認還書</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let returnModal;
        document.addEventListener('DOMContentLoaded', function() {
            returnModal = new bootstrap.Modal(document.getElementById('returnModal'));
        });

        function showReturnModal(bookId, originalId, title, name, classType, studentNumber) {
            document.getElementById('modal_book_id').value = bookId;
            document.getElementById('modal_original_id').value = originalId;
            document.getElementById('book_info').innerHTML = `
                書名：${title}<br>
                借閱人：${name}<br>
                班級：${classType}<br>
                學號：${studentNumber}
            `;
            returnModal.show();
        }
    </script>
</body>
</html>
