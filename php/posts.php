<?php
/**
 * SocialVibe - Posts API
 * Reads the public feed and creates or deletes the signed-in user's posts.
 */
require_once __DIR__ . '/config.php';
setCorsHeaders();
startAppSession();

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

function requestData() {
    $data = json_decode(file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

function getPosts() {
    $conn = initDatabase();
    $userId = (int)($_SESSION['user_id'] ?? 0);
    $mine = isset($_GET['mine']) && $_GET['mine'] === '1';

    if ($mine && !$userId) {
        jsonResponse(['error' => 'Please sign in to view your posts.'], 401);
    }

    $sql = "
        SELECT p.id, p.user_id, p.content, p.image, p.visibility, p.created_at,
            u.username, u.display_name AS author, u.avatar AS author_avatar,
            (SELECT COUNT(*) FROM likes l WHERE l.post_id = p.id) AS likes_count,
            (SELECT COUNT(*) FROM comments c WHERE c.post_id = p.id) AS comments_count,
            EXISTS(SELECT 1 FROM likes mine WHERE mine.post_id = p.id AND mine.user_id = ?) AS liked
        FROM posts p
        JOIN users u ON u.id = p.user_id
        WHERE " . ($mine ? 'p.user_id = ?' : "p.visibility = 'public'") . "
        ORDER BY p.created_at DESC
        LIMIT 50
    ";
    $stmt = $conn->prepare($sql);
    $params = [$userId];
    if ($mine) {
        $params[] = $userId;
    }
    $stmt->execute($params);
    $posts = $stmt->fetchAll();

    if ($posts) {
        $postIds = array_column($posts, 'id');
        $placeholders = implode(',', array_fill(0, count($postIds), '?'));
        $commentStmt = $conn->prepare("
            SELECT c.id, c.post_id, c.content, c.created_at,
                u.display_name AS author, u.avatar AS author_avatar
            FROM comments c
            JOIN users u ON u.id = c.user_id
            WHERE c.post_id IN ($placeholders)
            ORDER BY c.created_at ASC
        ");
        $commentStmt->execute($postIds);
        $commentsByPost = [];
        foreach ($commentStmt->fetchAll() as $comment) {
            $commentsByPost[$comment['post_id']][] = $comment;
        }
        foreach ($posts as &$post) {
            $post['comments'] = $commentsByPost[$post['id']] ?? [];
            $post['liked'] = (bool)$post['liked'];
        }
        unset($post);
    }

    jsonResponse(['posts' => $posts]);
}

function savePostImage($image) {
    if ($image === null || $image === '') {
        return null;
    }
    if (!is_string($image)) {
        jsonResponse(['error' => 'Invalid post image.'], 400);
    }
    if (strpos($image, 'data:') !== 0) {
        if (strlen($image) > 255) {
            jsonResponse(['error' => 'The image URL is too long.'], 400);
        }
        return $image;
    }

    if (!preg_match('/^data:image\/(jpeg|png|webp|gif);base64,([A-Za-z0-9+\/=]+)$/', $image, $matches)) {
        jsonResponse(['error' => 'Use a JPEG, PNG, WebP, or GIF image.'], 400);
    }
    $contents = base64_decode($matches[2], true);
    if ($contents === false || strlen($contents) > 5 * 1024 * 1024) {
        jsonResponse(['error' => 'Post images must be 5 MB or smaller.'], 400);
    }

    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->buffer($contents);
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif'
    ];
    if (!isset($extensions[$mimeType])) {
        jsonResponse(['error' => 'The uploaded file is not a supported image.'], 400);
    }

    $uploadDirectory = __DIR__ . '/uploads';
    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
        throw new RuntimeException('Could not create the post image directory.');
    }
    $filename = 'post-' . bin2hex(random_bytes(16)) . '.' . $extensions[$mimeType];
    if (file_put_contents($uploadDirectory . '/' . $filename, $contents) === false) {
        throw new RuntimeException('Could not save the post image.');
    }
    return 'php/uploads/' . $filename;
}

function createPost() {
    $userId = requireAuthenticatedUser();
    $data = requestData();
    $rawContent = $data['content'] ?? '';
    if (!is_string($rawContent)) {
        jsonResponse(['error' => 'Post content must be text.'], 400);
    }
    $content = trim($rawContent);
    if (strlen($content) > 10000) {
        jsonResponse(['error' => 'Posts must be 10000 characters or fewer.'], 400);
    }
    if ($content === '' && empty($data['image'])) {
        jsonResponse(['error' => 'Write something or add an image before posting.'], 400);
    }

    $visibility = $data['visibility'] ?? 'public';
    if (!in_array($visibility, ['public', 'friends', 'private'], true)) {
        jsonResponse(['error' => 'Invalid post visibility.'], 400);
    }
    $image = savePostImage($data['image'] ?? null);
    $conn = initDatabase();
    $stmt = $conn->prepare('INSERT INTO posts (user_id, content, image, visibility) VALUES (?, ?, ?, ?)');
    try {
        $stmt->execute([$userId, $content, $image, $visibility]);
    } catch (Throwable $error) {
        if ($image && strpos($image, 'php/uploads/') === 0) {
            $imagePath = __DIR__ . '/uploads/' . basename($image);
            if (is_file($imagePath) && !unlink($imagePath)) {
                error_log('Could not remove unsaved post image: ' . $imagePath);
            }
        }
        throw $error;
    }
    jsonResponse(['message' => 'Post created.', 'post_id' => (int)$conn->lastInsertId()], 201);
}

function deletePost() {
    $userId = requireAuthenticatedUser();
    $data = requestData();
    $postId = (int)($data['post_id'] ?? 0);
    if (!$postId) {
        jsonResponse(['error' => 'Post ID is required.'], 400);
    }

    $conn = initDatabase();
    $stmt = $conn->prepare('SELECT image FROM posts WHERE id = ? AND user_id = ?');
    $stmt->execute([$postId, $userId]);
    $post = $stmt->fetch();
    if (!$post) {
        jsonResponse(['error' => 'Post not found or unauthorized.'], 404);
    }

    $stmt = $conn->prepare('DELETE FROM posts WHERE id = ? AND user_id = ?');
    $stmt->execute([$postId, $userId]);
    if ($post['image'] && strpos($post['image'], 'php/uploads/') === 0) {
        $imagePath = __DIR__ . '/uploads/' . basename($post['image']);
        if (is_file($imagePath) && !unlink($imagePath)) {
            error_log('Could not remove deleted post image: ' . $imagePath);
        }
    }
    jsonResponse(['message' => 'Post deleted.']);
}
?>
