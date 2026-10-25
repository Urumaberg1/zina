<?php
/**
 * SocialVibe - Comments API
 * Handles CRUD operations for comments
 */

require_once 'config.php';
setCorsHeaders();

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        getComments();
        break;
    case 'POST':
        addComment();
        break;
    case 'DELETE':
        deleteComment();
        break;
    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}

// Get comments for a post
function getComments() {
    $conn = getConnection();
    $postId = $_GET['post_id'] ?? null;
    
    if (!$postId) {
        jsonResponse(['error' => 'Post ID is required'], 400);
    }
    
    $stmt = $conn->prepare("
        SELECT 
            c.id,
            c.content,
            c.created_at,
            u.display_name AS author,
            u.avatar AS author_avatar
        FROM comments c
        JOIN users u ON c.user_id = u.id
        WHERE c.post_id = ?
        ORDER BY c.created_at ASC
    ");
    $stmt->execute([$postId]);
    
    jsonResponse(['comments' => $stmt->fetchAll()]);
}

// Add a comment to a post
function addComment() {
    $conn = getConnection();
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['user_id']) || empty($data['post_id']) || empty($data['content'])) {
        jsonResponse(['error' => 'User ID, Post ID, and content are required'], 400);
    }
    
    $stmt = $conn->prepare("
        INSERT INTO comments (user_id, post_id, content)
        VALUES (?, ?, ?)
    ");
    
    $stmt->execute([
        $data['user_id'],
        $data['post_id'],
        htmlspecialchars($data['content'])
    ]);
    
    $commentId = $conn->lastInsertId();
    
    // Return the new comment with user info
    $fetchStmt = $conn->prepare("
        SELECT 
            c.id,
            c.content,
            c.created_at,
            u.display_name AS author,
            u.avatar AS author_avatar
        FROM comments c
        JOIN users u ON c.user_id = u.id
        WHERE c.id = ?
    ");
    $fetchStmt->execute([$commentId]);
    
    jsonResponse(['message' => 'Comment added', 'comment' => $fetchStmt->fetch()], 201);
}

// Delete a comment
function deleteComment() {
    $conn = getConnection();
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['comment_id']) || empty($data['user_id'])) {
        jsonResponse(['error' => 'Comment ID and User ID are required'], 400);
    }
    
    $stmt = $conn->prepare("DELETE FROM comments WHERE id = ? AND user_id = ?");
    $stmt->execute([$data['comment_id'], $data['user_id']]);
    
    if ($stmt->rowCount() > 0) {
        jsonResponse(['message' => 'Comment deleted']);
    } else {
        jsonResponse(['error' => 'Comment not found or unauthorized'], 404);
    }
}
?>

