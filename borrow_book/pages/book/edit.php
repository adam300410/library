<?php
session_start();
require_once '../../config/db.php';

$book = null;

if (isset($_GET['id'])) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM book_info2 WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        $book = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$book) {
            $error = "找不到書籍";
        }
    } catch(PDOException $e) {
        $error = "查詢失敗：" . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['id'])) {
    try {
        $image_url = $_POST['current_image'] ?? '';
        
        if (isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
            $target_dir = "../../uploads/books/";
            if (!file_exists($target_dir)) {
                mkdir($target_dir, 0777, true);
            }
            $image_url = $target_dir . basename($_FILES["image"]["name"]);
            move_uploaded_file($_FILES["image"]["tmp_name"], $image_url);
        }

        $stmt = $pdo->prepare("UPDATE book_info2 SET 
            isbn = ?,
            dsedj_no = ?,
            cal_no = ?,
            title = ?,
            author = ?,
            publisher = ?,
            published_date = ?,
            image_url = ?,
            available = ?,
            remark = ?
            WHERE id = ?");

        $stmt->execute([
            $_POST['isbn'],
            $_POST['dsedj_no'],
            $_POST['cal_no'],
            $_POST['title'],
            $_POST['author'],
            $_POST['publisher'],
            $_POST['published_date'],
            $image_url,
            isset($_POST['available']) ? 1 : 0,
            $_POST['remark'] ?? '',
            $_POST['id']
        ]);

        // Also update book_info if the book is currently borrowed
        if (!$book['available']) {
            $stmt = $pdo->prepare("UPDATE book_info SET 
                isbn = ?,
                cal_no = ?,
                title = ?,
                author = ?,
                publisher = ?,
                published_date = ?,
                image_url = ?,
                remark = ?
                WHERE isbn = ? AND available = 0");

            $stmt->execute([
                $_POST['isbn'],
                $_POST['cal_no'],
                $_POST['title'],
                $_POST['author'],
                $_POST['publisher'],
                $_POST['published_date'],
                $image_url,
                $_POST['remark'] ?? '',
                $book['isbn']
            ]);
        }

        $success = "更新成功！";
        
        // Refresh book data
        $stmt = $pdo->prepare("SELECT * FROM book_info2 WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        $book = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        $error = "更新失敗：" . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>編輯書籍 - 圖書借還系統</title>
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
        <h2>編輯書籍</h2>
        
        <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <?php if (isset($success)): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>

        <?php if ($book): ?>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="id" value="<?php echo $book['id']; ?>">
                <input type="hidden" name="current_image" value="<?php echo htmlspecialchars($book['image_url']); ?>">

                <div class="form-group mb-3">
                    <label for="isbn">ISBN</label>
                    <input type="text" class="form-control" id="isbn" name="isbn" value="<?php echo htmlspecialchars($book['isbn']); ?>" required>
                </div>

                <div class="form-group mb-3">
                    <label for="dsedj_no">教青局書目編號</label>
                    <input type="text" class="form-control" id="dsedj_no" name="dsedj_no" value="<?php echo htmlspecialchars($book['dsedj_no'] ?? ''); ?>">
                </div>

                <div class="form-group mb-3">
                    <label for="cal_no">館藏編號</label>
                    <input type="text" class="form-control" id="cal_no" name="cal_no" value="<?php echo htmlspecialchars($book['cal_no']); ?>" required>
                </div>

                <div class="form-group mb-3">
                    <label for="title">書名</label>
                    <input type="text" class="form-control" id="title" name="title" value="<?php echo htmlspecialchars($book['title']); ?>" required>
                </div>

                <div class="form-group mb-3">
                    <label for="author">作者</label>
                    <input type="text" class="form-control" id="author" name="author" value="<?php echo htmlspecialchars($book['author']); ?>" required>
                </div>

                <div class="form-group mb-3">
                    <label for="publisher">出版社</label>
                    <input type="text" class="form-control" id="publisher" name="publisher" value="<?php echo htmlspecialchars($book['publisher']); ?>" required>
                </div>

                <div class="form-group mb-3">
                    <label for="published_date">出版年份</label>
                    <input type="number" class="form-control" id="published_date" name="published_date" value="<?php echo $book['published_date']; ?>" min="1900" max="2100" step="1" required>
                </div>

                <div class="form-group mb-3">
                    <label for="remark">備註</label>
                    <textarea class="form-control" id="remark" name="remark" rows="3"><?php echo htmlspecialchars($book['remark'] ?? ''); ?></textarea>
                </div>

                <div class="form-group mb-3">
                    <label>借閱狀態</label>
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="available" name="available" <?php echo $book['available'] ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="available">可借閱</label>
                    </div>
                </div>

                <div class="form-group mb-3">
                    <label for="image">圖片</label>
                    <?php if ($book['image_url']): ?>
                        <div class="mb-2">
                            <img src="<?php echo htmlspecialchars($book['image_url']); ?>" alt="書籍圖片" style="max-width: 200px;">
                        </div>
                    <?php endif; ?>
                    <input type="file" class="form-control" id="image" name="image" accept="image/*">
                </div>

                <button type="submit" class="btn btn-primary">更新書籍</button>
                <a href="<?php echo isset($_GET['referer']) ? htmlspecialchars($_GET['referer']) : 'search.php'; ?>" class="btn btn-secondary">返回</a>
            </form>
        <?php else: ?>
            <div class="alert alert-warning">
                找不到書籍資料
                <br>
                <a href="search.php" class="btn btn-primary mt-2">返回查詢</a>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
