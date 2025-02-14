<?php
session_start();
require_once '../../config/db.php';

$search_query = "";
$books = [];

if ($_SERVER['REQUEST_METHOD'] == 'GET' && !empty($_GET['search'])) {
    try {
        $search = "%" . $_GET['search'] . "%";
        $stmt = $pdo->prepare("SELECT * FROM book_info2 WHERE 
            isbn LIKE ? OR 
            dsedj_no LIKE ? OR
            cal_no LIKE ? OR 
            title LIKE ? OR 
            author LIKE ? OR 
            publisher LIKE ?
            ORDER BY id DESC");
        
        $stmt->execute([$search, $search, $search, $search, $search, $search]);
        $books = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        $error = "查詢失敗：" . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>查詢書籍 - 圖書借還系統</title>
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
        <h2>查詢書籍</h2>
        
        <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>

        <form method="GET" class="mb-4">
            <div class="input-group">
                <input type="text" class="form-control" name="search" placeholder="輸入ISBN、教青局書目編號、館藏編號、書名、作者或出版社" value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                <button type="submit" class="btn btn-primary">搜尋</button>
            </div>
        </form>

        <?php if (!empty($books)): ?>
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>ISBN</th>
                        <th>教青局書目編號</th>
                        <th>館藏編號</th>
                        <th>書名</th>
                        <th>作者</th>
                        <th>出版社</th>
                        <th>狀態</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($books as $book): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($book['isbn']); ?></td>
                            <td><?php echo htmlspecialchars($book['dsedj_no'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($book['cal_no']); ?></td>
                            <td><?php echo htmlspecialchars($book['title']); ?></td>
                            <td><?php echo htmlspecialchars($book['author']); ?></td>
                            <td><?php echo htmlspecialchars($book['publisher']); ?></td>
                            <td><?php echo !$book['available'] ? '已借出' : '可借閱'; ?></td>
                            <td>
                                <?php if ($book['available']): ?>
                                    <a href="borrow.php?id=<?php echo $book['id']; ?>" class="btn btn-sm btn-primary">借閱</a>
                                <?php endif; ?>
                                <a href="edit.php?id=<?php echo $book['id']; ?>" class="btn btn-sm btn-secondary">編輯</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php elseif (isset($_GET['search'])): ?>
            <div class="alert alert-info">沒有找到符合條件的書籍</div>
        <?php endif; ?>

        <a href="../../index.php" class="btn btn-secondary">返回首頁</a>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
