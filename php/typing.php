<?php
/**
 * Set or read short-lived typing indicators for a direct or group conversation.
 */
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
startAppSession();
$userId = requireAuthenticatedUser();
$method = $_SERVER['REQUEST_METHOD'];
$data = $method === 'POST' ? json_decode(file_get_contents('php://input'), true) : [];
if ($method !== 'GET' && $method !== 'POST') {
    jsonResponse(['error' => 'Method not allowed.'], 405);
}
if ($method === 'POST' && !is_array($data)) {
    jsonResponse(['error' => 'Send a valid JSON request.'], 400);
}

$groupId = $method === 'GET'
    ? filter_var($_GET['group_id'] ?? null, FILTER_VALIDATE_INT)
    : filter_var($data['group_id'] ?? null, FILTER_VALIDATE_INT);
$otherId = $method === 'GET'
    ? filter_var($_GET['user_id'] ?? null, FILTER_VALIDATE_INT)
    : filter_var($data['user_id'] ?? null, FILTER_VALIDATE_INT);

try {
    $conn = initDatabase();
    if ($groupId) {
        $stmt = $conn->prepare('SELECT 1 FROM group_members WHERE group_id = ? AND user_id = ?');
        $stmt->execute([$groupId, $userId]);
        if (!$stmt->fetch()) {
            jsonResponse(['error' => 'You are not a member of this group.'], 403);
        }
        $conversationKey = 'group_' . $groupId;
    } else {
        if (!$otherId || $otherId === $userId) {
            jsonResponse(['error' => 'Choose a valid conversation.'], 400);
        }
        $low = min($userId, $otherId);
        $high = max($userId, $otherId);
        $stmt = $conn->prepare("
            SELECT 1 FROM friendships
            WHERE user_low = ? AND user_high = ? AND status = 'accepted'
        ");
        $stmt->execute([$low, $high]);
        if (!$stmt->fetch()) {
            jsonResponse(['error' => 'You can only message accepted friends.'], 403);
        }
        $conversationKey = 'user_' . $low . '_' . $high;
    }

    if ($method === 'POST') {
        if (!is_bool($data['is_typing'] ?? null)) {
            jsonResponse(['error' => 'is_typing must be true or false.'], 400);
        }
        if ($data['is_typing']) {
            $stmt = $conn->prepare("
                INSERT INTO typing_indicators (user_id, conversation_key, updated_at)
                VALUES (?, ?, CURRENT_TIMESTAMP)
                ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP
            ");
            $stmt->execute([$userId, $conversationKey]);
        } else {
            $stmt = $conn->prepare(
                'DELETE FROM typing_indicators WHERE user_id = ? AND conversation_key = ?'
            );
            $stmt->execute([$userId, $conversationKey]);
        }
        jsonResponse(['status' => 'success']);
    }

    $stmt = $conn->prepare("
        SELECT u.display_name
        FROM typing_indicators t
        JOIN users u ON u.id = t.user_id
        WHERE t.conversation_key = ? AND t.user_id <> ?
            AND t.updated_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 6 SECOND)
        ORDER BY t.updated_at DESC
    ");
    $stmt->execute([$conversationKey, $userId]);
    jsonResponse(['typing' => array_column($stmt->fetchAll(), 'display_name')]);
} catch (Throwable $error) {
    error_log('Typing API error: ' . $error->getMessage());
    jsonResponse(['error' => 'Typing status could not be updated.'], 500);
}
?>
