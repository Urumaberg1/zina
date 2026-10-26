<?php
/**
 * List the signed-in user's groups or create a group from accepted friends.
 */
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
startAppSession();
$userId = requireAuthenticatedUser();
$method = $_SERVER['REQUEST_METHOD'];

try {
    $conn = initDatabase();
    if ($method === 'GET') {
        $stmt = $conn->prepare("
            SELECT g.id, g.name, g.icon_path, g.created_by, g.created_at, COUNT(gm.user_id) AS member_count
            FROM chat_groups g
            JOIN group_members mine ON mine.group_id = g.id AND mine.user_id = ?
            JOIN group_members gm ON gm.group_id = g.id
            GROUP BY g.id, g.name, g.icon_path, g.created_by, g.created_at
            ORDER BY g.created_at DESC
        ");
        $stmt->execute([$userId]);
        $groups = $stmt->fetchAll();
        if ($groups) {
            $ids = array_column($groups, 'id');
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $membersStmt = $conn->prepare("
                SELECT gm.group_id, gm.role, u.id, u.username, u.display_name, u.avatar
                FROM group_members gm
                JOIN users u ON u.id = gm.user_id
                WHERE gm.group_id IN ($marks)
                ORDER BY gm.group_id, u.display_name
            ");
            $membersStmt->execute($ids);
            $membersByGroup = [];
            foreach ($membersStmt->fetchAll() as $member) {
                $membersByGroup[$member['group_id']][] = [
                    'id' => (int)$member['id'],
                    'username' => $member['username'],
                    'display_name' => $member['display_name'],
                    'avatar' => $member['avatar'],
                    'role' => $member['role']
                ];
            }
            foreach ($groups as &$group) {
                $group['id'] = (int)$group['id'];
                $group['member_count'] = (int)$group['member_count'];
                $group['icon'] = $group['icon_path'] ? 'php/group_icon.php?id=' . $group['id'] : null;
                unset($group['icon_path']);
                $group['members'] = $membersByGroup[$group['id']] ?? [];
            }
            unset($group);
        }
        jsonResponse(['groups' => $groups]);
    }

    if ($method !== 'POST') {
        jsonResponse(['error' => 'Method not allowed.'], 405);
    }
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        jsonResponse(['error' => 'Send a valid JSON request.'], 400);
    }
    $name = trim(is_string($data['name'] ?? null) ? $data['name'] : '');
    $friendIds = $data['friend_ids'] ?? null;
    if ($name === '' || strlen($name) > 100) {
        jsonResponse(['error' => 'Group name must be between 1 and 100 characters.'], 400);
    }
    if (!is_array($friendIds) || count($friendIds) < 1 || count($friendIds) > 49) {
        jsonResponse(['error' => 'Select between 1 and 49 friends for the group.'], 400);
    }
    $friendIds = array_values(array_unique(array_filter(array_map('intval', $friendIds), function ($id) use ($userId) {
        return $id > 0 && $id !== $userId;
    })));
    if (!$friendIds) {
        jsonResponse(['error' => 'Select at least one accepted friend.'], 400);
    }

    $marks = implode(',', array_fill(0, count($friendIds), '?'));
    $params = array_merge([$userId], $friendIds, [$userId], $friendIds);
    $stmt = $conn->prepare("
        SELECT COUNT(DISTINCT f.id)
        FROM friendships f
        WHERE f.status = 'accepted'
            AND ((f.user_low = ? AND f.user_high IN ($marks))
                OR (f.user_high = ? AND f.user_low IN ($marks)))
    ");
    $stmt->execute($params);
    if ((int)$stmt->fetchColumn() !== count($friendIds)) {
        jsonResponse(['error' => 'Groups can only include your accepted friends.'], 403);
    }

    $conn->beginTransaction();
    $stmt = $conn->prepare('INSERT INTO chat_groups (name, created_by) VALUES (?, ?)');
    $stmt->execute([$name, $userId]);
    $groupId = (int)$conn->lastInsertId();
    $memberStmt = $conn->prepare('INSERT INTO group_members (group_id, user_id, role) VALUES (?, ?, ?)');
    $memberStmt->execute([$groupId, $userId, 'admin']);
    foreach ($friendIds as $friendId) {
        $memberStmt->execute([$groupId, $friendId, 'member']);
    }
    $conn->commit();
    jsonResponse(['status' => 'success', 'group_id' => $groupId], 201);
} catch (Throwable $error) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log('Groups API error: ' . $error->getMessage());
    jsonResponse(['error' => 'The group request could not be completed.'], 500);
}
?>
