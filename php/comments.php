<?php
/**
 * SocialVibe - Comments API
 * Reads comments and lets signed-in users add or delete their comments.
 */
require_once __DIR__ . '/config.php';
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

function requestData() {
    $data = json_decode(file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

function getComments() {
    $postId = (int)($_GET['post_id'] ?? 0);
    if (!$postId) {
        jsonResponse(['error' => 'Post ID is required.'], 400);
    }

    $conn = initDatabase();
    $stmt = $conn->prepare("
        SELECT c.id, c.content, c.created_at,
            u.display_name AS author, u.avatar AS author_avatar
        FROM comments c
        JOIN users u ON u.id = c.user_id
        WHERE c.post_id = ?
        ORDER BY c.created_at ASC
    ");
    $stmt->execute([$postId]);
    jsonResponse(['comments' => $stmt->fetchAll()]);
}

function addComment() {
    $userId = requireAuthenticatedUser();
    $data = requestData();
    $postId = (int)($data['post_id'] ?? 0);
    $rawContent = $data['content'] ?? '';
    if (!is_string($rawContent)) {
        jsonResponse(['error' => 'Comment content must be text.'], 400);
    }
    $content = trim($rawContent);
    if (!$postId || $content === '') {
        jsonResponse(['error' => 'Post ID and comment content are required.'], 400);
    }
    if (strlen($content) > 5000) {
        jsonResponse(['error' => 'Comments must be 5000 characters or fewer.'], 400);
    }

    $conn = initDatabase();
    $stmt = $conn->prepare('SELECT id FROM posts WHERE id = ?');
    $stmt->execute([$postId]);
    if (!$stmt->fetch()) {
        jsonResponse(['error' => 'Post not found.'], 404);
    }
    $stmt = $conn->prepare('INSERT INTO comments (user_id, post_id, content) VALUES (?, ?, ?)');
    $stmt->execute([$userId, $postId, $content]);

    $stmt = $conn->prepare("
        SELECT c.id, c.content, c.created_at,
            u.display_name AS author, u.avatar AS author_avatar
        FROM comments c
        JOIN users u ON u.id = c.user_id
        WHERE c.id = ?
    ");
    $stmt->execute([(int)$conn->lastInsertId()]);
    jsonResponse(['message' => 'Comment added.', 'comment' => $stmt->fetch()], 201);
}

function deleteComment() {
    $userId = requireAuthenticatedUser();
    $data = requestData();
    $commentId = (int)($data['comment_id'] ?? 0);
    if (!$commentId) {
        jsonResponse(['error' => 'Comment ID is required.'], 400);
    }

    $conn = initDatabase();
    $stmt = $conn->prepare('DELETE FROM comments WHERE id = ? AND user_id = ?');
    $stmt->execute([$commentId, $userId]);
    if (!$stmt->rowCount()) {
        jsonResponse(['error' => 'Comment not found or unauthorized.'], 404);
    }
    jsonResponse(['message' => 'Comment deleted.']);
}
?>
