<?php
/**
 * Polling-based WebRTC signaling for authenticated direct and group calls.
 */
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
startAppSession();
$userId = requireAuthenticatedUser();

function callRequestData() {
    $data = json_decode(file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

try {
    $conn = initDatabase();
    $conn->exec("
        UPDATE calls
        SET status = 'ended', ended_at = CURRENT_TIMESTAMP
        WHERE status = 'active'
            AND created_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 2 HOUR)
    ");
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $after = max(0, (int)($_GET['after'] ?? 0));
        $stmt = $conn->prepare("
            SELECT c.id,
                IF(c.group_id IS NULL, 'direct', 'group') AS call_type,
                c.media_type, c.group_id, c.initiated_by AS started_by,
                c.status, c.created_at, starter.display_name AS starter_name,
                g.name AS group_name, mine.status AS my_status, mine.joined_at AS my_joined_at,
                mine.is_muted AS my_is_muted
            FROM calls c
            JOIN call_participants mine ON mine.call_id = c.id AND mine.user_id = ?
            JOIN users starter ON starter.id = c.initiated_by
            LEFT JOIN chat_groups g ON g.id = c.group_id
            WHERE c.status = 'active'
                AND c.created_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 2 HOUR)
                AND mine.status IN ('ringing', 'joined', 'declined', 'left')
            ORDER BY c.created_at DESC
            LIMIT 20
        ");
        $stmt->execute([$userId]);
        $calls = $stmt->fetchAll();
        if ($calls) {
            $callIds = array_column($calls, 'id');
            $marks = implode(',', array_fill(0, count($callIds), '?'));
            $memberStmt = $conn->prepare("
                SELECT cp.call_id, cp.user_id, cp.status, cp.is_muted,
                    cp.is_camera_enabled, cp.is_sharing_screen,
                    u.display_name, u.avatar
                FROM call_participants cp
                JOIN users u ON u.id = cp.user_id
                WHERE cp.call_id IN ($marks)
                ORDER BY cp.call_id, u.display_name
            ");
            $memberStmt->execute($callIds);
            $participants = [];
            foreach ($memberStmt->fetchAll() as $participant) {
                $participants[$participant['call_id']][] = [
                    'id' => (int)$participant['user_id'],
                    'status' => $participant['status'],
                    'is_muted' => (bool)$participant['is_muted'],
                    'is_camera_enabled' => (bool)$participant['is_camera_enabled'],
                    'is_sharing_screen' => (bool)$participant['is_sharing_screen'],
                    'name' => $participant['display_name'],
                    'avatar' => $participant['avatar']
                ];
            }
            foreach ($calls as &$call) {
                $call['group_id'] = $call['group_id'] === null ? null : (int)$call['group_id'];
                $call['started_by'] = (int)$call['started_by'];
                $call['my_joined_before'] = $call['my_joined_at'] !== null;
                unset($call['my_joined_at']);
                $call['my_is_muted'] = (bool)$call['my_is_muted'];
                $call['participants'] = $participants[$call['id']] ?? [];
            }
            unset($call);
        }
        $signalStmt = $conn->prepare("
            SELECT s.id, s.call_id, s.sender_id, s.signal_type, s.payload
            FROM call_signals s
            JOIN calls c ON c.id = s.call_id AND c.status = 'active'
            JOIN call_participants sender ON sender.call_id = s.call_id
                AND sender.user_id = s.sender_id AND sender.status = 'joined'
            JOIN call_participants receiver ON receiver.call_id = s.call_id
                AND receiver.user_id = s.receiver_id AND receiver.status = 'joined'
            WHERE s.receiver_id = ? AND s.id > ?
            ORDER BY s.id ASC
            LIMIT 100
        ");
        $signalStmt->execute([$userId, $after]);
        $signals = [];
        foreach ($signalStmt->fetchAll() as $signal) {
            $payload = json_decode($signal['payload'], true);
            if (!is_array($payload)) {
                continue;
            }
            $signals[] = [
                'id' => (int)$signal['id'],
                'call_id' => $signal['call_id'],
                'sender_id' => (int)$signal['sender_id'],
                'type' => $signal['signal_type'],
                'payload' => $payload
            ];
        }
        jsonResponse(['calls' => $calls, 'signals' => $signals]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['error' => 'Method not allowed.'], 405);
    }
    $data = callRequestData();
    $action = $data['action'] ?? '';

    if ($action === 'start') {
        $otherId = filter_var($data['user_id'] ?? null, FILTER_VALIDATE_INT);
        $groupId = filter_var($data['group_id'] ?? null, FILTER_VALIDATE_INT);
        $mediaType = $data['media_type'] ?? 'audio';
        if ((bool)$otherId === (bool)$groupId || !in_array($mediaType, ['audio', 'video'], true) || ($otherId && $otherId === $userId)) {
            jsonResponse(['error' => 'Choose a valid direct or group call.'], 400);
        }

        if ($groupId) {
            $memberStmt = $conn->prepare('SELECT user_id FROM group_members WHERE group_id = ? ORDER BY user_id');
            $memberStmt->execute([$groupId]);
            $participantIds = array_map('intval', array_column($memberStmt->fetchAll(), 'user_id'));
            if (!in_array($userId, $participantIds, true)) {
                jsonResponse(['error' => 'You are not a member of this group.'], 403);
            }
            if (count($participantIds) < 2) {
                jsonResponse(['error' => 'Add another member before starting a group call.'], 400);
            }
            $activeStmt = $conn->prepare("SELECT id FROM calls WHERE group_id = ? AND status = 'active' LIMIT 1");
            $activeStmt->execute([$groupId]);
        } else {
            $low = min($userId, $otherId);
            $high = max($userId, $otherId);
            $friendStmt = $conn->prepare("
                SELECT 1 FROM friendships
                WHERE user_low = ? AND user_high = ? AND status = 'accepted'
            ");
            $friendStmt->execute([$low, $high]);
            if (!$friendStmt->fetch()) {
                jsonResponse(['error' => 'You can call after accepting a friend request.'], 403);
            }
            $participantIds = [$userId, $otherId];
            $activeStmt = $conn->prepare("
                SELECT c.id FROM calls c
                WHERE c.group_id IS NULL AND c.status = 'active'
                    AND ((c.initiated_by = ? AND c.direct_user_id = ?)
                        OR (c.initiated_by = ? AND c.direct_user_id = ?))
                LIMIT 1
            ");
            $activeStmt->execute([$userId, $otherId, $otherId, $userId]);
        }
        if ($groupId) {
            $activeStmt->execute();
        }
        if ($activeStmt->fetch()) {
            jsonResponse(['error' => 'There is already an active call in this conversation.'], 409);
        }

        $conn->beginTransaction();
        $callId = sprintf(
            '%s-%s-%s-%s-%s',
            bin2hex(random_bytes(4)),
            bin2hex(random_bytes(2)),
            bin2hex(random_bytes(2)),
            bin2hex(random_bytes(2)),
            bin2hex(random_bytes(6))
        );
        $stmt = $conn->prepare('
            INSERT INTO calls (id, initiated_by, group_id, direct_user_id, status, media_type)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([$callId, $userId, $groupId ?: null, $otherId ?: null, 'active', $mediaType]);
        $insert = $conn->prepare("INSERT INTO call_participants (call_id, user_id, status, joined_at) VALUES (?, ?, ?, IF(? = 'joined', CURRENT_TIMESTAMP, NULL))");
        foreach ($participantIds as $participantId) {
            $status = $participantId === $userId ? 'joined' : 'ringing';
            $insert->execute([$callId, $participantId, $status, $status]);
        }
        $conn->commit();
        jsonResponse(['status' => 'success', 'call_id' => $callId], 201);
    }

    $callId = $data['call_id'] ?? null;
    if (!is_string($callId) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $callId)) {
        jsonResponse(['error' => 'A valid call is required.'], 400);
    }
    $callStmt = $conn->prepare("
        SELECT c.id, c.initiated_by AS started_by, c.group_id,
            c.status, cp.status AS participant_status
        FROM calls c
        JOIN call_participants cp ON cp.call_id = c.id AND cp.user_id = ?
        WHERE c.id = ?
    ");
    $callStmt->execute([$userId, $callId]);
    $call = $callStmt->fetch();
    if (!$call) {
        jsonResponse(['error' => 'You were not invited to this call.'], 403);
    }

    if ($action === 'join') {
        $conn->beginTransaction();
        $lockStmt = $conn->prepare('SELECT status FROM calls WHERE id = ? FOR UPDATE');
        $lockStmt->execute([$callId]);
        $lockedCall = $lockStmt->fetch();
        if (!$lockedCall || $lockedCall['status'] !== 'active') {
            $conn->rollBack();
            jsonResponse(['error' => 'This call has ended.'], 409);
        }
        $conn->prepare('DELETE FROM call_signals WHERE call_id = ? AND (sender_id = ? OR receiver_id = ?)')
            ->execute([$callId, $userId, $userId]);
        $conn->prepare("UPDATE call_participants SET status = 'joined', joined_at = CURRENT_TIMESTAMP, is_muted = 0, is_camera_enabled = 0, is_sharing_screen = 0, last_seen_at = NULL WHERE call_id = ? AND user_id = ?")
            ->execute([$callId, $userId]);
        $conn->commit();
        jsonResponse(['status' => 'success']);
    }

    if ($action === 'decline') {
        if ($call['status'] !== 'active' || $call['participant_status'] !== 'ringing') {
            jsonResponse(['error' => 'This call is no longer available to decline.'], 409);
        }
        $conn->prepare("
            UPDATE call_participants
            SET status = 'declined', last_seen_at = CURRENT_TIMESTAMP
            WHERE call_id = ? AND user_id = ?
        ")->execute([$callId, $userId]);
        jsonResponse(['status' => 'success']);
    }

    if ($action === 'media_state') {
        if ($call['status'] !== 'active' || $call['participant_status'] !== 'joined') {
            jsonResponse(['error' => 'Join an active call before changing media state.'], 409);
        }
        foreach (['is_muted', 'is_camera_enabled', 'is_sharing_screen'] as $field) {
            if (!array_key_exists($field, $data) || !in_array($data[$field], [true, false, 0, 1, '0', '1'], true)) {
                jsonResponse(['error' => 'Invalid call media state.'], 400);
            }
        }
        $conn->prepare('
            UPDATE call_participants
            SET is_muted = ?, is_camera_enabled = ?, is_sharing_screen = ?
            WHERE call_id = ? AND user_id = ?
        ')->execute([
            (int)$data['is_muted'],
            (int)$data['is_camera_enabled'],
            (int)$data['is_sharing_screen'],
            $callId,
            $userId
        ]);
        jsonResponse(['status' => 'success']);
    }

    if ($action === 'signal') {
        $type = $data['type'] ?? '';
        $recipientId = filter_var($data['recipient_id'] ?? null, FILTER_VALIDATE_INT);
        $payload = $data['payload'] ?? null;
        if ($call['status'] === 'ended' || $call['participant_status'] !== 'joined') {
            jsonResponse(['error' => 'Join the call before sending signaling data.'], 409);
        }
        if (!in_array($type, ['offer', 'answer', 'candidate'], true) || !$recipientId || !is_array($payload)) {
            jsonResponse(['error' => 'Invalid call signaling data.'], 400);
        }
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($encoded === false || strlen($encoded) > 65536) {
            jsonResponse(['error' => 'Call signaling data is too large.'], 400);
        }
        $recipientStmt = $conn->prepare("
            SELECT 1 FROM call_participants
            WHERE call_id = ? AND user_id = ? AND status = 'joined'
        ");
        $recipientStmt->execute([$callId, $recipientId]);
        if (!$recipientStmt->fetch()) {
            jsonResponse(['error' => 'The other participant has not joined this call.'], 409);
        }
        $stmt = $conn->prepare('INSERT INTO call_signals (call_id, sender_id, receiver_id, signal_type, payload) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$callId, $userId, $recipientId, $type, $encoded]);
        jsonResponse(['status' => 'success']);
    }

    if ($action === 'leave') {
        $conn->beginTransaction();
        $lockStmt = $conn->prepare('SELECT status FROM calls WHERE id = ? FOR UPDATE');
        $lockStmt->execute([$callId]);
        $lockedCall = $lockStmt->fetch();
        if (!$lockedCall || $lockedCall['status'] !== 'active') {
            $conn->rollBack();
            jsonResponse(['error' => 'This call has already ended.'], 409);
        }
        $conn->prepare('DELETE FROM call_signals WHERE call_id = ? AND (sender_id = ? OR receiver_id = ?)')
            ->execute([$callId, $userId, $userId]);
        $conn->prepare("
            UPDATE call_participants
            SET status = 'left', is_muted = 0, is_camera_enabled = 0,
                is_sharing_screen = 0, last_seen_at = CURRENT_TIMESTAMP
            WHERE call_id = ? AND user_id = ?
        ")->execute([$callId, $userId]);
        $conn->prepare("
            UPDATE calls
            SET status = 'ended', ended_at = CURRENT_TIMESTAMP
            WHERE id = ? AND status = 'active'
                AND NOT EXISTS (
                    SELECT 1 FROM call_participants
                    WHERE call_id = ? AND status = 'joined'
                )
        ")->execute([$callId, $callId]);
        $conn->commit();
        jsonResponse(['status' => 'success']);
    }

    jsonResponse(['error' => 'Unknown call action.'], 400);
} catch (Throwable $error) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log('Calls API error: ' . $error->getMessage());
    jsonResponse(['error' => 'The call request could not be completed.'], 500);
}
?>