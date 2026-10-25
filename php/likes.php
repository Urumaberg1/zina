<?php
/**
 * SocialVibe - Likes API
 * Handles like/unlike operations
 */

require_once 'config.php';
setCorsHeaders();

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'POST':
        toggleLike();
        break;
    case 'GET':
        getLikes();
        break;
    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}

// Toggle like on a post (like/unlike)
function toggleLike() {
    $conn = getConnection();
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['user_id']) || empty($data['post_id'])) {
        jsonResponse(['error' => 'User ID and Post ID are required'], 400);
    }
    
    // Check if already liked
    $checkStmt = $conn->prepare("SELECT id FROM likes WHERE user_id = ? AND post_id = ?");
    $checkStmt->execute([$data['user_id'], $data['post_id']]);
    
    if ($checkStmt->fetch()) {
        // Unlike
        $stmt = $conn->prepare("DELETE FROM likes WHERE user_id = ? AND post_id = ?");
        $stmt->execute([$data['user_id'], $data['post_id']]);
        $action = 'unliked';
    } else {
        // Like
        $stmt = $conn->prepare("INSERT INTO likes (user_id, post_id) VALUES (?, ?)");
        $stmt->execute([$data['user_id'], $data['post_id']]);
        $action = 'liked';
    }
    
    // Get updated like count
    $countStmt = $conn->prepare("SELECT COUNT(*) as count FROM likes WHERE post_id = ?");
    $countStmt->execute([$data['post_id']]);
    $count = $countStmt->fetch()['count'];
    
    jsonResponse(['action' => $action, 'likes_count' => $count]);
}

// Get likes for a post
function getLikes() {
    $conn = getConnection();
    $postId = $_GET['post_id'] ?? null;
    
    if (!$postId) {
        jsonResponse(['error' => 'Post ID is required'], 400);
    }
    
    $stmt = $conn->prepare("
        SELECT 
            u.display_name,
            u.avatar,
            l.created_at
        FROM likes l
        JOIN users u ON l.user_id = u.id
        WHERE l.post_id = ?
        ORDER BY l.created_at DESC
    ");
    $stmt->execute([$postId]);
    
    jsonResponse(['likes' => $stmt->fetchAll()]);
}
?>

