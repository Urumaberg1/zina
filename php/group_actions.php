<?php
/**
 * Manage group membership and settings for authenticated group participants.
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
        jsonResponse(['error' => 'Send a valid group action.'], 400);
    }
}
$action = $data['action'] ?? '';
$groupId = filter_var($data['group_id'] ?? null, FILTER_VALIDATE_INT);
if (!$groupId || !in_array($action, ['update', 'add_members', 'remove_member', 'leave', 'delete'], true)) {
    jsonResponse(['error' => 'Choose a valid group action.'], 400);
}

$newIconPath = null;
try {
    $conn = initDatabase();
    $stmt = $conn->prepare("
        SELECT g.id, g.name, g.icon_path, g.created_by, gm.role
        FROM chat_groups g
        JOIN group_members gm ON gm.group_id = g.id AND gm.user_id = ?
        WHERE g.id = ?
    ");
    $stmt->execute([$userId, $groupId]);
    $group = $stmt->fetch();
    if (!$group) {
        jsonResponse(['error' => 'You are not a member of this group.'], 403);
    }
    $isAdmin = $group['role'] === 'admin';

    if ($action === 'update') {
        if (!$isAdmin) {
            jsonResponse(['error' => 'Only a group admin can change group settings.'], 403);
        }
        $name = trim(is_string($data['name'] ?? null) ? $data['name'] : $group['name']);
        if ($name === '' || strlen($name) > 100) {
            jsonResponse(['error' => 'Group name must be between 1 and 100 characters.'], 400);
        }
        $file = $_FILES['icon'] ?? null;
        if ($file && $file['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 5 * 1024 * 1024) {
                jsonResponse(['error' => 'Choose a group icon no larger than 5 MB.'], 400);
            }
            $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
            $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
            if (!isset($extensions[$mimeType])) {
                jsonResponse(['error' => 'Use a JPEG, PNG, WebP, or GIF group icon.'], 400);
            }
            $directory = __DIR__ . '/uploads/group-icons';
            if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new RuntimeException('Could not create the group icon directory.');
            }
            $filename = bin2hex(random_bytes(20)) . '.' . $extensions[$mimeType];
            if (!move_uploaded_file($file['tmp_name'], $directory . '/' . $filename)) {
                throw new RuntimeException('Could not save the group icon.');
            }
            $newIconPath = 'php/uploads/group-icons/' . $filename;
        }
        $iconPath = $newIconPath ?: $group['icon_path'];
        $stmt = $conn->prepare('UPDATE chat_groups SET name = ?, icon_path = ? WHERE id = ?');
        $stmt->execute([$name, $iconPath, $groupId]);
        if ($newIconPath && $group['icon_path']) {
            $oldIcon = __DIR__ . '/uploads/group-icons/' . basename($group['icon_path']);
            if (is_file($oldIcon) && !unlink($oldIcon)) {
                error_log('Could not remove replaced group icon: ' . $oldIcon);
            }
        }
        jsonResponse(['status' => 'success', 'name' => $name, 'icon' => $iconPath ? 'php/group_icon.php?id=' . $groupId : null]);
    }

    if ($action === 'add_members') {
        $memberIds = $data['user_ids'] ?? null;
        if (is_string($memberIds)) {
            $decoded = json_decode($memberIds, true);
            $memberIds = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($memberIds) || count($memberIds) < 1 || count($memberIds) > 49) {
            jsonResponse(['error' => 'Select between 1 and 49 friends to add.'], 400);
        }
        $memberIds = array_values(array_unique(array_filter(array_map('intval', $memberIds), function ($id) use ($userId) {
            return $id > 0 && $id !== $userId;
        })));
        if (!$memberIds) {
            jsonResponse(['error' => 'Select at least one friend to add.'], 400);
        }
        $marks = implode(',', array_fill(0, count($memberIds), '?'));
        $existingStmt = $conn->prepare("SELECT user_id FROM group_members WHERE group_id = ? AND user_id IN ($marks)");
        $existingStmt->execute(array_merge([$groupId], $memberIds));
        $existingIds = array_map('intval', array_column($existingStmt->fetchAll(), 'user_id'));
        $newIds = array_values(array_diff($memberIds, $existingIds));
        $memberCountStmt = $conn->prepare('SELECT COUNT(*) FROM group_members WHERE group_id = ?');
        $memberCountStmt->execute([$groupId]);
        if ((int)$memberCountStmt->fetchColumn() + count($newIds) > 100) {
            jsonResponse(['error' => 'A group can have at most 100 members.'], 400);
        }
        $friendStmt = $conn->prepare("
            SELECT COUNT(DISTINCT f.id)
            FROM friendships f
            WHERE f.status = 'accepted'
                AND ((f.user_low = ? AND f.user_high IN ($marks))
                    OR (f.user_high = ? AND f.user_low IN ($marks)))
        ");
        $friendStmt->execute(array_merge([$userId], $memberIds, [$userId], $memberIds));
        if ((int)$friendStmt->fetchColumn() !== count($memberIds)) {
            jsonResponse(['error' => 'You can only add your accepted friends.'], 403);
        }
        $insert = $conn->prepare("INSERT IGNORE INTO group_members (group_id, user_id, role) VALUES (?, ?, 'member')");
        foreach ($memberIds as $memberId) {
            $insert->execute([$groupId, $memberId]);
        }
        jsonResponse(['status' => 'success']);
    }

    if ($action === 'remove_member') {
        if (!$isAdmin) {
            jsonResponse(['error' => 'Only a group admin can remove members.'], 403);
        }
        $targetId = filter_var($data['user_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$targetId || $targetId === $userId) {
            jsonResponse(['error' => 'Use Leave group to remove yourself.'], 400);
        }
        $stmt = $conn->prepare("DELETE FROM group_members WHERE group_id = ? AND user_id = ? AND role <> 'admin'");
        $stmt->execute([$groupId, $targetId]);
        if (!$stmt->rowCount()) {
            jsonResponse(['error' => 'Member not found or cannot be removed.'], 404);
        }
        $conn->prepare("
            UPDATE call_participants cp
            JOIN calls c ON c.id = cp.call_id
            SET cp.status = 'left', cp.last_seen_at = CURRENT_TIMESTAMP
            WHERE c.group_id = ? AND c.status = 'active' AND cp.user_id = ?
        ")->execute([$groupId, $targetId]);
        jsonResponse(['status' => 'success']);
    }

    if ($action === 'leave') {
        $conn->beginTransaction();
        $deletedGroupAttachments = [];
        $deletedGroupIcon = null;
        if ($isAdmin) {
            $nextAdmin = $conn->prepare("
                SELECT user_id FROM group_members
                WHERE group_id = ? AND user_id <> ?
                ORDER BY joined_at, user_id LIMIT 1
            ");
            $nextAdmin->execute([$groupId, $userId]);
            $nextAdminId = $nextAdmin->fetchColumn();
            if ($nextAdminId) {
                $promote = $conn->prepare("UPDATE group_members SET role = 'admin' WHERE group_id = ? AND user_id = ?");
                $promote->execute([$groupId, $nextAdminId]);
                $conn->prepare('UPDATE chat_groups SET created_by = ? WHERE id = ?')->execute([$nextAdminId, $groupId]);
            }
        }
        $conn->prepare('DELETE FROM group_members WHERE group_id = ? AND user_id = ?')->execute([$groupId, $userId]);
        $conn->prepare("
            UPDATE call_participants cp
            JOIN calls c ON c.id = cp.call_id
            SET cp.status = 'left', cp.last_seen_at = CURRENT_TIMESTAMP
            WHERE c.group_id = ? AND c.status = 'active' AND cp.user_id = ?
        ")->execute([$groupId, $userId]);
        $remaining = $conn->prepare('SELECT COUNT(*) FROM group_members WHERE group_id = ?');
        $remaining->execute([$groupId]);
        if ((int)$remaining->fetchColumn() === 0) {
            $attachmentStmt = $conn->prepare('SELECT attachment_path FROM messages WHERE group_id = ? AND attachment_path IS NOT NULL');
            $attachmentStmt->execute([$groupId]);
            $deletedGroupAttachments = $attachmentStmt->fetchAll(PDO::FETCH_COLUMN);
            $deletedGroupIcon = $group['icon_path'];
            $conn->prepare('DELETE FROM chat_groups WHERE id = ?')->execute([$groupId]);
        }
        $conn->commit();
        foreach ($deletedGroupAttachments as $attachment) {
            $path = __DIR__ . '/uploads/messages/' . basename($attachment);
            if (is_file($path) && !unlink($path)) {
                error_log('Could not remove attachment from emptied group: ' . $path);
            }
        }
        if ($deletedGroupIcon) {
            $path = __DIR__ . '/uploads/group-icons/' . basename($deletedGroupIcon);
            if (is_file($path) && !unlink($path)) {
                error_log('Could not remove icon from emptied group: ' . $path);
            }
        }
        jsonResponse(['status' => 'success']);
    }

    if (!$isAdmin) {
        jsonResponse(['error' => 'Only a group admin can delete the group.'], 403);
    }
    $attachmentStmt = $conn->prepare('SELECT attachment_path FROM messages WHERE group_id = ? AND attachment_path IS NOT NULL');
    $attachmentStmt->execute([$groupId]);
    $attachments = $attachmentStmt->fetchAll(PDO::FETCH_COLUMN);
    $iconPath = $group['icon_path'];
    $stmt = $conn->prepare('DELETE FROM chat_groups WHERE id = ?');
    $stmt->execute([$groupId]);
    foreach ($attachments as $attachment) {
        $path = __DIR__ . '/uploads/messages/' . basename($attachment);
        if (is_file($path) && !unlink($path)) {
            error_log('Could not remove deleted group attachment: ' . $path);
        }
    }
    if ($iconPath) {
        $path = __DIR__ . '/uploads/group-icons/' . basename($iconPath);
        if (is_file($path) && !unlink($path)) {
            error_log('Could not remove deleted group icon: ' . $path);
        }
    }
    jsonResponse(['status' => 'success']);
} catch (Throwable $error) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    if ($newIconPath) {
        $path = __DIR__ . '/uploads/group-icons/' . basename($newIconPath);
        if (is_file($path) && !unlink($path)) {
            error_log('Could not remove unsaved group icon: ' . $path);
        }
    }
    error_log('Group actions API error: ' . $error->getMessage());
    jsonResponse(['error' => 'The group request could not be completed.'], 500);
}
?>
