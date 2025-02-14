<?php
require_once '../../config/db.php';

header('Content-Type: application/json');

$class_type = isset($_GET['class_type']) ? trim($_GET['class_type']) : '';
$response = [];

if ($class_type) {
    try {
        $stmt = $pdo->prepare("
            SELECT s.*,
                   r.quota,
                   r.days,
                   s.book_note_return as borrowed_count,
                   (r.quota - s.book_note_return) as remaining_quota
            FROM students s
            LEFT JOIN role r ON s.role = r.id
            WHERE s.class_type = :class_type
            AND s.valid_or_not = 1
            AND s.book_note_return < r.quota
            ORDER BY s.name ASC
            LIMIT 50");
            
        $stmt->bindParam(':class_type', $class_type, PDO::PARAM_STR);
        $stmt->execute();
        
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Add display information
        foreach ($users as &$user) {
            $user['can_borrow'] = ($user['remaining_quota'] > 0);
            $user['display_text'] = sprintf(
                "%s - %s (%s) [已借%d本/剩餘%d本]",
                $user['name'],
                $user['class_type'],
                $user['student_number'],
                $user['borrowed_count'],
                $user['remaining_quota']
            );
        }
        
        if (empty($users)) {
            $response = [
                'status' => 'error',
                'message' => '找不到該班級的學生'
            ];
        } else {
            $response = [
                'status' => 'success',
                'data' => $users
            ];
        }
        
    } catch(PDOException $e) {
        error_log("Database Error in filter_users.php: " . $e->getMessage());
        $response = [
            'status' => 'error',
            'message' => '搜尋過程中發生錯誤'
        ];
    }
} else {
    // If no class type provided, return distinct class types
    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT class_type 
            FROM students 
            WHERE valid_or_not = 1 
            ORDER BY class_type ASC");
        $stmt->execute();
        $classes = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $response = [
            'status' => 'success',
            'data' => $classes
        ];
        
    } catch(PDOException $e) {
        error_log("Database Error in filter_users.php: " . $e->getMessage());
        $response = [
            'status' => 'error',
            'message' => '獲取班級列表時發生錯誤'
        ];
    }
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
?>