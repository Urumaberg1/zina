<?php
/**
 * SocialVibe - Posts API
 * Handles CRUD operations for posts
 */

require_once 'config.php';
setCorsHeaders();

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        getPosts();
        break;
    case 'POST':
        createPost();
        break;
    case 'DELETE':
        deletePost();
        break;
    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}

// Get all posts with user info, like count, and comments
function getPosts() {
    $conn = getConnection();
    
    $stmt = $conn->query("
        SELECT 
            p.id,
            p.content,
            p.image,
            p.visibility,
            p.created_at,
            u.display_name AS author,
            u.avatar AS author_avatar,
            (SELECT COUNT(*) FROM likes WHERE post_id = p.id) AS likes_count,
            (SELECT COUNT(*) FROM comments WHERE post_id = p.id) AS comments_count
        FROM posts p
        JOIN users u ON p.user_id = u.id
        WHERE p.visibility = 'public'
        ORDER BY p.created_at DESC
        LIMIT 50
    ");
    
    $posts = $stmt->fetchAll();
    
    // Fetch comments for each post
    foreach ($posts as &$post) {
        $commentStmt = $conn->prepare("
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
        $commentStmt->execute([$post['id']]);
        $post['comments'] = $commentStmt->fetchAll();
    }
    
    jsonResponse(['posts' => $posts]);
}

// Create a new post
function createPost() {
    $conn = getConnection();
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['user_id']) || empty($data['content'])) {
        jsonResponse(['error' => 'User ID and content are required'], 400);
    }
    
    $stmt = $conn->prepare("
        INSERT INTO posts (user_id, content, image, visibility)
        VALUES (?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $data['user_id'],
        htmlspecialchars($data['content']),
        $data['image'] ?? null,
        $data['visibility'] ?? 'public'
    ]);
    
    $postId = $conn->lastInsertId();
    
    jsonResponse(['message' => 'Post created', 'post_id' => $postId], 201);
}

// Delete a post
function deletePost() {
    $conn = getConnection();
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['post_id']) || empty($data['user_id'])) {
        jsonResponse(['error' => 'Post ID and User ID are required'], 400);
    }
    
    $stmt = $conn->prepare("DELETE FROM posts WHERE id = ? AND user_id = ?");
    $stmt->execute([$data['post_id'], $data['user_id']]);
    
    if ($stmt->rowCount() > 0) {
        jsonResponse(['message' => 'Post deleted']);
    } else {
        jsonResponse(['error' => 'Post not found or unauthorized'], 404);
    }
}
?>

