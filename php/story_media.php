<?php
/**
 * Serves unexpired story media only to its owner and accepted friends.
 */
require_once __DIR__ . '/config.php';
startAppSession();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    exit;
}
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    exit;
}

$storyId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$storyId || $storyId < 1) {
    http_response_code(400);
    exit;
}

try {
    $conn = initDatabase();
    $viewerId = (int)$_SESSION['user_id'];
    $stmt = $conn->prepare("
        SELECT s.user_id, s.media_path, s.media_type
        FROM stories s
        WHERE s.id = ? AND s.expires_at > CURRENT_TIMESTAMP
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
    ");
    $stmt->execute([$storyId, $viewerId, $viewerId, $viewerId]);
    $story = $stmt->fetch();
    if (!$story) {
        http_response_code(404);
        exit;
    }

    $prefix = 'php/uploads/story-' . (int)$story['user_id'] . '-';
    if (strpos($story['media_path'], $prefix) !== 0) {
        error_log('Invalid story media path for story ' . $storyId);
        http_response_code(404);
        exit;
    }
    $filePath = __DIR__ . '/uploads/' . basename($story['media_path']);
    if (!is_file($filePath)) {
        http_response_code(404);
        exit;
    }
    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($filePath);
    $allowedTypes = $story['media_type'] === 'image'
        ? ['image/jpeg', 'image/png', 'image/webp', 'image/gif']
        : ['video/mp4', 'video/webm', 'video/quicktime'];
    if (!$mimeType || !in_array($mimeType, $allowedTypes, true)) {
        error_log('Invalid story media type for story ' . $storyId);
        http_response_code(415);
        exit;
    }

    $fileSize = filesize($filePath);
    if ($fileSize === false || $fileSize < 1) {
        throw new RuntimeException('Could not determine story media size.');
    }
    $start = 0;
    $end = $fileSize - 1;
    $range = $_SERVER['HTTP_RANGE'] ?? '';
    if ($range !== '') {
        if (!preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $matches)) {
            http_response_code(416);
            header('Content-Range: bytes */' . $fileSize);
            exit;
        }
        if ($matches[1] === '') {
            $suffixLength = (int)$matches[2];
            $start = max(0, $fileSize - $suffixLength);
        } else {
            $start = (int)$matches[1];
            if ($matches[2] !== '') {
                $end = min((int)$matches[2], $end);
            }
        }
        if ($start > $end || $start >= $fileSize) {
            http_response_code(416);
            header('Content-Range: bytes */' . $fileSize);
            exit;
        }
        http_response_code(206);
        header("Content-Range: bytes $start-$end/$fileSize");
    }

    $handle = fopen($filePath, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Could not read story media.');
    }
    if ($start > 0 && fseek($handle, $start) !== 0) {
        fclose($handle);
        throw new RuntimeException('Could not seek within story media.');
    }

    header('Content-Type: ' . $mimeType);
    header('Accept-Ranges: bytes');
    header('Content-Length: ' . ($end - $start + 1));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    $remaining = $end - $start + 1;
    while ($remaining > 0 && !feof($handle)) {
        $chunk = fread($handle, min(8192, $remaining));
        if ($chunk === false || $chunk === '') {
            break;
        }
        echo $chunk;
        $remaining -= strlen($chunk);
    }
    fclose($handle);
} catch (Throwable $error) {
    error_log('Story media request failed: ' . $error->getMessage());
    http_response_code(500);
}
?>
