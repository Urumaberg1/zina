<?php
/**
 * Edit or delete a message authored by the signed-in conversation participant.
 */
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
startAppSession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed.'], 405);
}
$userId = requireAuthenticatedUser();
$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    jsonResponse(['error' => 'Send a valid message action.'], 400);
}
$messageId = filter_var($data['message_id'] ?? null, FILTER_VALIDATE_INT);
$action = $data['action'] ?? '';
if (!$messageId || !in_array($action, ['edit', 'delete', 'react'], true)) {
    jsonResponse(['error' => 'Choose a valid message action.'], 400);
}

try {
    $conn = initDatabase();
    $stmt = $conn->prepare('
        SELECT id, sender_id, receiver_id, group_id, attachment_path, deleted_at
        FROM messages
        WHERE id = ?
    ');
    $stmt->execute([$messageId]);
    $message = $stmt->fetch();
    if (!$message) {
        jsonResponse(['error' => 'Message not found.'], 404);
    }
    if ($action !== 'react' && (int)$message['sender_id'] !== $userId) {
        jsonResponse(['error' => 'You can only edit or delete your own messages.'], 403);
    }
    if ($action !== 'react' && $message['deleted_at'] !== null) {
        jsonResponse(['error' => 'This message has already been deleted.'], 409);
    }

    if ($message['group_id'] !== null) {
        $memberStmt = $conn->prepare('SELECT 1 FROM group_members WHERE group_id = ? AND user_id = ?');
        $memberStmt->execute([(int)$message['group_id'], $userId]);
    } else {
        $otherId = (int)$message['receiver_id'];
        if ($otherId === $userId) {
            jsonResponse(['error' => 'Invalid direct conversation.'], 400);
        }
        $memberStmt = $conn->prepare("
            SELECT 1 FROM friendships
            WHERE user_low = LEAST(?, ?) AND user_high = GREATEST(?, ?)
                AND status = 'accepted'
        ");
        $memberStmt->execute([$userId, $otherId, $userId, $otherId]);
    }
    if (!$memberStmt->fetch()) {
        jsonResponse(['error' => 'You no longer have access to this conversation.'], 403);
    }

    if ($action === 'react') {
        if ($message['deleted_at'] !== null) {
            jsonResponse(['error' => 'Deleted messages cannot be reacted to.'], 409);
        }
        $reaction = $data['reaction'] ?? null;
        $allowedReactions = [
            'like', 'love', 'haha', 'wow', 'sad', 'angry', 'celebrate', 'clap', 'fire',
            'think', 'smile', 'laugh', 'cry', 'cool', 'party', 'pray', 'hundred',
            'eyes', 'heart_eyes', 'wink', 'grin', 'hug', 'rocket', 'sparkles'
        ];
        if ($reaction !== null && $reaction !== '' &&
            (!is_string($reaction) || !in_array($reaction, $allowedReactions, true))) {
            jsonResponse(['error' => 'Choose a valid message reaction.'], 400);
        }
        if ($reaction === null || $reaction === '') {
            $reactionStmt = $conn->prepare('DELETE FROM message_reactions WHERE message_id = ? AND user_id = ?');
            $reactionStmt->execute([$messageId, $userId]);
        } else {
            $reactionStmt = $conn->prepare('
                INSERT INTO message_reactions (message_id, user_id, reaction) VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE reaction = VALUES(reaction), created_at = CURRENT_TIMESTAMP
            ');
            $reactionStmt->execute([$messageId, $userId, $reaction]);
        }
        jsonResponse(['status' => 'success', 'message_id' => $messageId, 'reaction' => $reaction ?: null]);
    }

    if ($action === 'edit') {
        $content = $data['content'] ?? null;
        if (!is_string($content)) {
            jsonResponse(['error' => 'Message content must be text.'], 400);
        }
        $content = trim($content);
        if ($content === '' || strlen($content) > 5000) {
            jsonResponse(['error' => 'Edited messages must be between 1 and 5000 characters.'], 400);
        }
        $stmt = $conn->prepare('UPDATE messages SET content = ?, edited_at = CURRENT_TIMESTAMP WHERE id = ? AND sender_id = ? AND deleted_at IS NULL');
        $stmt->execute([$content, $messageId, $userId]);
        jsonResponse(['status' => 'success', 'message_id' => $messageId, 'content' => $content]);
    }

    $stmt = $conn->prepare("
        UPDATE messages
        SET content = '', attachment_path = NULL, attachment_type = NULL,
            attachment_name = NULL, deleted_at = CURRENT_TIMESTAMP
        WHERE id = ? AND sender_id = ? AND deleted_at IS NULL
    ");
    $stmt->execute([$messageId, $userId]);
    if ($message['attachment_path']) {
        $filePath = __DIR__ . '/uploads/messages/' . basename($message['attachment_path']);
        if (is_file($filePath) && !unlink($filePath)) {
            error_log('Could not remove deleted message attachment: ' . $filePath);
        }
    }
    jsonResponse(['status' => 'success', 'message_id' => $messageId]);
} catch (Throwable $error) {
    error_log('Message action API error: ' . $error->getMessage());
    jsonResponse(['error' => 'The message could not be updated.'], 500);
}
?>