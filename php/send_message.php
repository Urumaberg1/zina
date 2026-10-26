<?php
/**
 * Save text or a supported attachment to a direct or group conversation.
 * Uses the app's socialvibe connection from config.php and requires login.
 */
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
startAppSession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed.'], 405);
}
$userId = requireAuthenticatedUser();
$isMultipart = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data') === 0;
if ($isMultipart) {
    $data = $_POST;
} else {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        jsonResponse(['error' => 'Send a valid message request.'], 400);
    }
}

$otherId = filter_var($data['user_id'] ?? null, FILTER_VALIDATE_INT);
$groupId = filter_var($data['group_id'] ?? null, FILTER_VALIDATE_INT);
$replyToId = filter_var($data['reply_to_id'] ?? null, FILTER_VALIDATE_INT);
$content = $data['content'] ?? '';
if (!is_string($content)) {
    jsonResponse(['error' => 'Message content must be text.'], 400);
}
$content = trim($content);
if (strlen($content) > 5000) {
    jsonResponse(['error' => 'Messages must be 5000 characters or fewer.'], 400);
}
if ((bool)$otherId === (bool)$groupId) {
    jsonResponse(['error' => 'Choose exactly one direct or group conversation.'], 400);
}
if ($otherId && $otherId === $userId) {
    jsonResponse(['error' => 'You cannot message yourself.'], 400);
}

$storedPath = null;
$attachmentType = null;
$attachmentName = null;
try {
    $conn = initDatabase();
    if ($groupId) {
        $stmt = $conn->prepare('SELECT 1 FROM group_members WHERE group_id = ? AND user_id = ?');
        $stmt->execute([$groupId, $userId]);
        if (!$stmt->fetch()) {
            jsonResponse(['error' => 'You are not a member of this group.'], 403);
        }
    } else {
        $low = min($userId, $otherId);
        $high = max($userId, $otherId);
        $stmt = $conn->prepare("
            SELECT 1 FROM friendships
            WHERE user_low = ? AND user_high = ? AND status = 'accepted'
        ");
        $stmt->execute([$low, $high]);
        if (!$stmt->fetch()) {
            jsonResponse(['error' => 'You can message users after they accept your friend request.'], 403);
        }
    }

    if ($replyToId) {
        if ($groupId) {
            $replyStmt = $conn->prepare('SELECT id FROM messages WHERE id = ? AND group_id = ?');
            $replyStmt->execute([$replyToId, $groupId]);
        } else {
            $replyStmt = $conn->prepare("
                SELECT id FROM messages
                WHERE id = ? AND group_id IS NULL
                    AND ((sender_id = ? AND receiver_id = ?)
                        OR (sender_id = ? AND receiver_id = ?))
            ");
            $replyStmt->execute([$replyToId, $userId, $otherId, $otherId, $userId]);
        }
        if (!$replyStmt->fetch()) {
            jsonResponse(['error' => 'The message you are replying to is not in this conversation.'], 400);
        }
    } else {
        $replyToId = null;
    }

    $file = $_FILES['attachment'] ?? null;
    if ($file && $file['error'] !== UPLOAD_ERR_OK) {
        jsonResponse(['error' => 'The attachment could not be uploaded. Check the file size and try again.'], 400);
    }
    if ($content === '' && !$file) {
        jsonResponse(['error' => 'Write a message or attach a file.'], 400);
    }
    if ($file) {
        if ($file['size'] > 25 * 1024 * 1024) {
            jsonResponse(['error' => 'Attachments must be 25 MB or smaller.'], 400);
        }
        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $types = [
            'image/jpeg' => ['jpg', 'image'],
            'image/png' => ['png', 'image'],
            'image/gif' => ['gif', 'image'],
            'image/webp' => ['webp', 'image'],
            'video/mp4' => ['mp4', 'video'],
            'video/webm' => ['webm', 'video'],
            'video/quicktime' => ['mov', 'video'],
            'audio/mpeg' => ['mp3', 'audio'],
            'audio/mp4' => ['m4a', 'audio'],
            'audio/ogg' => ['ogg', 'audio'],
            'audio/wav' => ['wav', 'audio'],
            'audio/x-wav' => ['wav', 'audio'],
            'audio/webm' => ['webm', 'audio'],
            'application/pdf' => ['pdf', 'file'],
            'text/plain' => ['txt', 'file'],
            'application/zip' => ['zip', 'file'],
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx', 'file'],
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx', 'file'],
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['pptx', 'file'],
            'application/msword' => ['doc', 'file'],
            'application/vnd.ms-excel' => ['xls', 'file'],
            'application/vnd.ms-powerpoint' => ['ppt', 'file']
        ];
        if (!isset($types[$mimeType])) {
            jsonResponse(['error' => 'Use an image, video, audio recording, PDF, text, or Office document.'], 400);
        }
        if ($mimeType === 'video/webm' && strpos($file['name'], 'voice-message.') === 0) {
            $types[$mimeType][1] = 'audio';
        }
        $uploadDirectory = __DIR__ . '/uploads/messages';
        if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
            throw new RuntimeException('Could not create the message attachment directory.');
        }
        $filename = bin2hex(random_bytes(20)) . '.' . $types[$mimeType][0];
        if (!move_uploaded_file($file['tmp_name'], $uploadDirectory . '/' . $filename)) {
            throw new RuntimeException('Could not save the message attachment.');
        }
        $storedPath = 'php/uploads/messages/' . $filename;
        $attachmentType = $types[$mimeType][1];
        $originalName = basename(str_replace('\\', '/', $file['name']));
        $attachmentName = substr($originalName, 0, 255);
    }

    if ($groupId) {
        $stmt = $conn->prepare("
            INSERT INTO messages
                (sender_id, receiver_id, group_id, content, attachment_path, attachment_type, attachment_name, reply_to_id)
            VALUES (?, NULL, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$userId, $groupId, $content, $storedPath, $attachmentType, $attachmentName, $replyToId]);
    } else {
        $stmt = $conn->prepare("
            INSERT INTO messages
                (sender_id, receiver_id, content, attachment_path, attachment_type, attachment_name, reply_to_id)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$userId, $otherId, $content, $storedPath, $attachmentType, $attachmentName, $replyToId]);
    }
    jsonResponse(['status' => 'success', 'message_id' => (int)$conn->lastInsertId()], 201);
} catch (Throwable $error) {
    if ($storedPath) {
        $attachmentFile = __DIR__ . '/uploads/messages/' . basename($storedPath);
        if (is_file($attachmentFile) && !unlink($attachmentFile)) {
            error_log('Could not remove an unsaved message attachment: ' . $attachmentFile);
        }
    }
    error_log('Send message API error: ' . $error->getMessage());
    jsonResponse(['error' => 'The message could not be saved. Check the database configuration.'], 500);
}
?>
