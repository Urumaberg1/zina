<?php
/**
 * SocialVibe - Likes API
 * Reads likes and toggles the signed-in user's like for a post.
 */
require_once __DIR__ . '/config.php';
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

function requestData() {
    $data = json_decode(file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

function toggleLike() {
    $userId = requireAuthenticatedUser();
    $data = requestData();
    $postId = (int)($data['post_id'] ?? 0);
    if (!$postId) {
        jsonResponse(['error' => 'Post ID is required.'], 400);
    }
    $reaction = $data['reaction'] ?? null;
    $allowedReactions = [
        'like', 'love', 'haha', 'wow', 'sad', 'angry', 'celebrate', 'clap', 'fire',
        'think', 'smile', 'laugh', 'cry', 'cool', 'party', 'pray', 'hundred',
        'eyes', 'heart_eyes', 'wink', 'grin', 'hug', 'rocket', 'sparkles'
    ];
    if ($reaction !== null && (!is_string($reaction) || !in_array($reaction, $allowedReactions, true))) {
        jsonResponse(['error' => 'Choose a valid post reaction.'], 400);
    }

    $conn = initDatabase();
    $stmt = $conn->prepare('SELECT id FROM posts WHERE id = ?');
    $stmt->execute([$postId]);
    if (!$stmt->fetch()) {
        jsonResponse(['error' => 'Post not found.'], 404);
    }

    if ($reaction !== null) {
        $stmt = $conn->prepare('
            INSERT INTO likes (user_id, post_id, reaction) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE reaction = VALUES(reaction)
        ');
        $stmt->execute([$userId, $postId, $reaction]);
        $liked = true;
    } else {
        $stmt = $conn->prepare('INSERT IGNORE INTO likes (user_id, post_id) VALUES (?, ?)');
        $stmt->execute([$userId, $postId]);
        if ($stmt->rowCount() === 0) {
            $stmt = $conn->prepare('DELETE FROM likes WHERE user_id = ? AND post_id = ?');
            $stmt->execute([$userId, $postId]);
            $liked = false;
        } else {
            $liked = true;
        }
    }

    $stmt = $conn->prepare('SELECT COUNT(*) AS count FROM likes WHERE post_id = ?');
    $stmt->execute([$postId]);
    jsonResponse([
        'liked' => $liked,
        'likes_count' => (int)$stmt->fetch()['count'],
        'reaction' => $liked
            ? ($reaction ?? 'like')
            : null
    ]);
}

function getLikes() {
    $postId = (int)($_GET['post_id'] ?? 0);
    if (!$postId) {
        jsonResponse(['error' => 'Post ID is required.'], 400);
    }
    $conn = initDatabase();
    $stmt = $conn->prepare("
        SELECT u.display_name, u.avatar, l.created_at
        FROM likes l
        JOIN users u ON u.id = l.user_id
        WHERE l.post_id = ?
        ORDER BY l.created_at DESC
    ");
    $stmt->execute([$postId]);
    jsonResponse(['likes' => $stmt->fetchAll()]);
}
?>
