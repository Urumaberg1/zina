<?php
/**
 * Return recent messages and typing indicators for one authorized conversation.
 * Connects through config.php to the existing socialvibe MySQL database.
 */
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
startAppSession();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['error' => 'Method not allowed.'], 405);
}
$userId = requireAuthenticatedUser();
$otherId = filter_var($_GET['user_id'] ?? null, FILTER_VALIDATE_INT);
$groupId = filter_var($_GET['group_id'] ?? null, FILTER_VALIDATE_INT);
if ((bool)$otherId === (bool)$groupId || ($otherId && $otherId === $userId)) {
    jsonResponse(['error' => 'Choose one valid conversation.'], 400);
}

try {
    $conn = initDatabase();
    if ($groupId) {
        $stmt = $conn->prepare('SELECT 1 FROM group_members WHERE group_id = ? AND user_id = ?');
        $stmt->execute([$groupId, $userId]);
        if (!$stmt->fetch()) {
            jsonResponse(['error' => 'You are not a member of this group.'], 403);
        }
        $stmt = $conn->prepare("
            SELECT m.id, m.sender_id, m.receiver_id, m.group_id,
                IF(m.deleted_at IS NULL, m.content, '[Message deleted]') AS content,
                IF(m.deleted_at IS NULL, m.attachment_path, NULL) AS attachment_path,
                IF(m.deleted_at IS NULL, m.attachment_type, NULL) AS attachment_type,
                IF(m.deleted_at IS NULL, m.attachment_name, NULL) AS attachment_name,
                m.edited_at, m.deleted_at, m.reply_to_id, m.created_at,
                u.display_name AS sender_name,
                replied.content AS reply_content,
                replied.deleted_at AS reply_deleted_at,
                reply_sender.display_name AS reply_sender_name
            FROM messages m
            JOIN users u ON u.id = m.sender_id
            LEFT JOIN messages replied ON replied.id = m.reply_to_id
            LEFT JOIN users reply_sender ON reply_sender.id = replied.sender_id
            WHERE m.group_id = ?
            ORDER BY m.id DESC
            LIMIT 200
        ");
        $stmt->execute([$groupId]);
        $conversationKey = 'group_' . $groupId;
    } else {
        $low = min($userId, $otherId);
        $high = max($userId, $otherId);
        $stmt = $conn->prepare("
            SELECT 1 FROM friendships
            WHERE user_low = ? AND user_high = ? AND status = 'accepted'
        ");
        $stmt->execute([$low, $high]);
        if (!$stmt->fetch()) {
            jsonResponse(['error' => 'You can view messages after accepting a friend request.'], 403);
        }
        $stmt = $conn->prepare("
            UPDATE messages
            SET read_at = CURRENT_TIMESTAMP
            WHERE receiver_id = ? AND sender_id = ? AND read_at IS NULL
        ");
        $stmt->execute([$userId, $otherId]);
        $stmt = $conn->prepare("
            SELECT m.id, m.sender_id, m.receiver_id, m.group_id,
                IF(m.deleted_at IS NULL, m.content, '[Message deleted]') AS content,
                IF(m.deleted_at IS NULL, m.attachment_path, NULL) AS attachment_path,
                IF(m.deleted_at IS NULL, m.attachment_type, NULL) AS attachment_type,
                IF(m.deleted_at IS NULL, m.attachment_name, NULL) AS attachment_name,
                m.edited_at, m.deleted_at, m.reply_to_id, m.created_at,
                u.display_name AS sender_name,
                replied.content AS reply_content,
                replied.deleted_at AS reply_deleted_at,
                reply_sender.display_name AS reply_sender_name
            FROM messages m
            JOIN users u ON u.id = m.sender_id
            LEFT JOIN messages replied ON replied.id = m.reply_to_id
            LEFT JOIN users reply_sender ON reply_sender.id = replied.sender_id
            WHERE (m.sender_id = ? AND m.receiver_id = ?)
                OR (m.sender_id = ? AND m.receiver_id = ?)
            ORDER BY m.id DESC
            LIMIT 200
        ");
        $stmt->execute([$userId, $otherId, $otherId, $userId]);
        $conversationKey = 'user_' . $low . '_' . $high;
    }

    $messages = array_reverse($stmt->fetchAll());
    $typingStmt = $conn->prepare("
        SELECT u.display_name
        FROM typing_indicators t
        JOIN users u ON u.id = t.user_id
        WHERE t.conversation_key = ? AND t.user_id <> ?
            AND t.updated_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 6 SECOND)
        ORDER BY t.updated_at DESC
    ");
    $typingStmt->execute([$conversationKey, $userId]);
    jsonResponse([
        'messages' => $messages,
        'typing' => array_column($typingStmt->fetchAll(), 'display_name')
    ]);
} catch (Throwable $error) {
    error_log('Get messages API error: ' . $error->getMessage());
    jsonResponse(['error' => 'Messages could not be loaded. Check the database configuration.'], 500);
}
?>
