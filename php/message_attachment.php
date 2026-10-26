<?php
/**
 * Stream a conversation attachment only to an authorized participant.
 */
require_once __DIR__ . '/config.php';
startAppSession();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    exit;
}
$userId = requireAuthenticatedUser();
$messageId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$messageId) {
    http_response_code(400);
    exit;
}

$conn = initDatabase();
$stmt = $conn->prepare("
    SELECT id, sender_id, receiver_id, group_id, attachment_path,
        attachment_type, attachment_name
    FROM messages
    WHERE id = ? AND attachment_path IS NOT NULL
");
$stmt->execute([$messageId]);
$message = $stmt->fetch();
if (!$message) {
    http_response_code(404);
    exit;
}

if ($message['group_id']) {
    $stmt = $conn->prepare('SELECT 1 FROM group_members WHERE group_id = ? AND user_id = ?');
    $stmt->execute([(int)$message['group_id'], $userId]);
} else {
    if ($userId !== (int)$message['sender_id'] && $userId !== (int)$message['receiver_id']) {
        http_response_code(403);
        exit;
    }
    $otherId = $userId === (int)$message['sender_id']
        ? (int)$message['receiver_id']
        : (int)$message['sender_id'];
    $stmt = $conn->prepare("
        SELECT 1 FROM friendships
        WHERE user_low = LEAST(?, ?)
            AND user_high = GREATEST(?, ?)
            AND status = 'accepted'
    ");
    $stmt->execute([
        $userId,
        $otherId,
        $userId,
        $otherId
    ]);
}
if (!$stmt->fetch()) {
    http_response_code(403);
    exit;
}

$filename = basename($message['attachment_path']);
$attachmentDirectory = realpath(__DIR__ . '/uploads/messages');
$filePath = realpath(__DIR__ . '/uploads/messages/' . $filename);
if (!$attachmentDirectory || !$filePath || strpos($filePath, $attachmentDirectory . DIRECTORY_SEPARATOR) !== 0 || !is_file($filePath)) {
    http_response_code(404);
    exit;
}

$mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($filePath);
if ($message['attachment_type'] === 'audio' && $mimeType === 'video/webm') {
    $mimeType = 'audio/webm';
}
$inlineTypes = [
    'image/jpeg', 'image/png', 'image/gif', 'image/webp',
    'video/mp4', 'video/webm', 'video/quicktime',
    'audio/mpeg', 'audio/mp4', 'audio/ogg', 'audio/wav',
    'audio/x-wav', 'audio/webm'
];
$disposition = in_array($mimeType, $inlineTypes, true) ? 'inline' : 'attachment';
$downloadName = str_replace(["\r", "\n"], '', $message['attachment_name'] ?: 'download');
header('Content-Type: ' . $mimeType);
header('Content-Disposition: ' . $disposition . "; filename*=UTF-8''" . rawurlencode($downloadName));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
$fileSize = filesize($filePath);
$start = 0;
$end = $fileSize - 1;
$range = $_SERVER['HTTP_RANGE'] ?? '';
if ($range !== '') {
    if (!preg_match('/^bytes=(\d*)-(\d*)$/', $range, $matches) || ($matches[1] === '' && $matches[2] === '')) {
        header('Content-Range: bytes */' . $fileSize);
        http_response_code(416);
        exit;
    }
    if ($matches[1] === '') {
        $suffixLength = (int)$matches[2];
        if ($suffixLength <= 0) {
            header('Content-Range: bytes */' . $fileSize);
            http_response_code(416);
            exit;
        }
        $start = max(0, $fileSize - $suffixLength);
    } else {
        $start = (int)$matches[1];
        if ($matches[2] !== '') {
            $end = (int)$matches[2];
        }
    }
    if ($start >= $fileSize || $end < $start) {
        header('Content-Range: bytes */' . $fileSize);
        http_response_code(416);
        exit;
    }
    $end = min($end, $fileSize - 1);
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$fileSize");
}
header('Accept-Ranges: bytes');
header('Content-Length: ' . ($end - $start + 1));
$stream = fopen($filePath, 'rb');
if ($stream === false) {
    http_response_code(500);
    exit;
}
if ($start > 0 && fseek($stream, $start) !== 0) {
    fclose($stream);
    http_response_code(500);
    exit;
}
$remaining = $end - $start + 1;
while ($remaining > 0 && !feof($stream)) {
    $chunk = fread($stream, min(8192, $remaining));
    if ($chunk === false || $chunk === '') {
        break;
    }
    echo $chunk;
    $remaining -= strlen($chunk);
}
fclose($stream);
?>
