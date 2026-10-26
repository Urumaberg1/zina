<?php
/**
 * Friends-only story API. Stories are visible for 24 hours from creation.
 */
require_once __DIR__ . '/config.php';
setCorsHeaders();
startAppSession();

$method = $_SERVER['REQUEST_METHOD'];

try {
    $conn = initDatabase();
    $userId = requireAuthenticatedUser();

    if ($method === 'GET') {
        removeExpiredStories($conn);
        $stmt = $conn->prepare("
            SELECT s.id, s.user_id, s.media_path, s.media_type, s.created_at, s.expires_at,
                u.display_name, u.avatar
            FROM stories s
            JOIN users u ON u.id = s.user_id
            WHERE s.expires_at > CURRENT_TIMESTAMP
                AND (
                    s.user_id = ?
                    OR EXISTS (
                        SELECT 1 FROM friendships f
                        WHERE f.status = 'accepted'
                            AND (
                                (f.user_low = ? AND f.user_high = s.user_id)
                                OR (f.user_high = ? AND f.user_low = s.user_id)
                            )
                    )
                )
            ORDER BY s.created_at ASC
            LIMIT 100
        ");
        $stmt->execute([$userId, $userId, $userId]);
        $stories = array_map('publicStory', $stmt->fetchAll());
        jsonResponse(['status' => 'success', 'stories' => $stories]);
    }

    if ($method === 'POST') {
        createStory($conn, $userId);
    }

    if ($method === 'DELETE') {
        deleteStory($conn, $userId);
    }

    jsonResponse(['error' => 'Method not allowed.'], 405);
} catch (Throwable $error) {
    error_log('Story API failed: ' . $error->getMessage());
    jsonResponse(['error' => 'The story request could not be completed.'], 500);
}

function createStory($conn, $userId) {
    $file = $_FILES['media'] ?? null;
    if (!$file || !isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        $uploadError = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        $message = $uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE
            ? 'Story media exceeds the server upload limit.'
            : 'Choose an image or video to share.';
        jsonResponse(['error' => $message], 400);
    }

    $maxBytes = 25 * 1024 * 1024;
    if (!isset($file['size']) || $file['size'] <= 0 || $file['size'] > $maxBytes) {
        jsonResponse(['error' => 'Story media must be 25 MB or smaller.'], 400);
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        jsonResponse(['error' => 'The uploaded media could not be verified.'], 400);
    }

    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $allowedTypes = [
        'image/jpeg' => ['type' => 'image', 'extension' => 'jpg'],
        'image/png' => ['type' => 'image', 'extension' => 'png'],
        'image/webp' => ['type' => 'image', 'extension' => 'webp'],
        'image/gif' => ['type' => 'image', 'extension' => 'gif'],
        'video/mp4' => ['type' => 'video', 'extension' => 'mp4'],
        'video/webm' => ['type' => 'video', 'extension' => 'webm'],
        'video/quicktime' => ['type' => 'video', 'extension' => 'mov']
    ];
    if (!$mimeType || !isset($allowedTypes[$mimeType])) {
        jsonResponse(['error' => 'Use a JPEG, PNG, WebP, or GIF image, or an MP4, WebM, or MOV video.'], 400);
    }

    $uploadDirectory = __DIR__ . '/uploads';
    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
        throw new RuntimeException('Could not create the story upload directory.');
    }
    $filename = 'story-' . $userId . '-' . bin2hex(random_bytes(16)) . '.' . $allowedTypes[$mimeType]['extension'];
    $destination = $uploadDirectory . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new RuntimeException('Could not save uploaded story media.');
    }

    $mediaPath = 'php/uploads/' . $filename;
    try {
        $stmt = $conn->prepare("
            INSERT INTO stories (user_id, media_path, media_type, expires_at)
            VALUES (?, ?, ?, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 24 HOUR))
        ");
        $stmt->execute([$userId, $mediaPath, $allowedTypes[$mimeType]['type']]);
    } catch (Throwable $error) {
        if (is_file($destination) && !unlink($destination)) {
            error_log('Could not remove an unrecorded story upload: ' . $destination);
        }
        throw $error;
    }

    $stmt = $conn->prepare("
        SELECT s.id, s.user_id, s.media_path, s.media_type, s.created_at, s.expires_at,
            u.display_name, u.avatar
        FROM stories s JOIN users u ON u.id = s.user_id
        WHERE s.id = ?
    ");
    $stmt->execute([(int)$conn->lastInsertId()]);
    jsonResponse(['status' => 'success', 'story' => publicStory($stmt->fetch())], 201);
}

function publicStory($story) {
    return [
        'id' => (int)$story['id'],
        'user_id' => (int)$story['user_id'],
        'media_url' => 'php/story_media.php?id=' . (int)$story['id'],
        'media_type' => $story['media_type'],
        'created_at' => $story['created_at'],
        'expires_at' => $story['expires_at'],
        'display_name' => $story['display_name'],
        'avatar' => $story['avatar'] ?? ''
    ];
}

function deleteStory($conn, $userId) {
    $storyId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$storyId || $storyId < 1) {
        jsonResponse(['error' => 'A valid story ID is required.'], 400);
    }

    $stmt = $conn->prepare('SELECT media_path FROM stories WHERE id = ? AND user_id = ?');
    $stmt->execute([$storyId, $userId]);
    $mediaPath = $stmt->fetchColumn();
    if (!$mediaPath) {
        jsonResponse(['error' => 'Story not found.'], 404);
    }

    $stmt = $conn->prepare('DELETE FROM stories WHERE id = ? AND user_id = ?');
    $stmt->execute([$storyId, $userId]);
    removeStoryFile($mediaPath, $userId);
    jsonResponse(['status' => 'success']);
}

function removeExpiredStories($conn) {
    $stmt = $conn->query('SELECT id, user_id, media_path FROM stories WHERE expires_at <= CURRENT_TIMESTAMP');
    $expiredStories = $stmt->fetchAll();
    if (!$expiredStories) {
        return;
    }

    $deleteStmt = $conn->prepare('DELETE FROM stories WHERE id = ? AND expires_at <= CURRENT_TIMESTAMP');
    foreach ($expiredStories as $story) {
        removeStoryFile($story['media_path'], (int)$story['user_id']);
        $deleteStmt->execute([(int)$story['id']]);
    }
}

function removeStoryFile($mediaPath, $userId) {
    $prefix = 'php/uploads/story-' . $userId . '-';
    if (strpos($mediaPath, $prefix) !== 0) {
        return;
    }

    $filePath = __DIR__ . '/uploads/' . basename($mediaPath);
    if (is_file($filePath) && !unlink($filePath)) {
        error_log('Could not remove expired or deleted story media: ' . $filePath);
    }
}
?>
