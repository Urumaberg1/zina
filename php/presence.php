<?php
/**
 * Track active browser tabs and report presence for accepted friends.
 */
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$userId = requireAuthenticatedUser();
$conn = initDatabase();
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            $data = $_POST;
        }
        $clientId = $data['client_id'] ?? '';
        $active = filter_var($data['active'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if (!is_string($clientId) || !preg_match('/^[A-Za-z0-9-]{16,64}$/', $clientId) || $active === null) {
            jsonResponse(['error' => 'Invalid presence update.'], 400);
        }

        if ($active) {
            $stmt = $conn->prepare("
                INSERT INTO presence_sessions (user_id, client_id, last_active_at)
                VALUES (?, ?, CURRENT_TIMESTAMP)
                ON DUPLICATE KEY UPDATE last_active_at = CURRENT_TIMESTAMP
            ");
            $stmt->execute([$userId, $clientId]);
        } else {
            $stmt = $conn->prepare('DELETE FROM presence_sessions WHERE user_id = ? AND client_id = ?');
            $stmt->execute([$userId, $clientId]);
        }
        jsonResponse(['status' => 'success']);
    }

    if ($method !== 'GET' || ($_GET['action'] ?? '') !== 'list') {
        jsonResponse(['error' => 'Method not allowed.'], 405);
    }

    $conn->exec("DELETE FROM presence_sessions
        WHERE last_active_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 DAY)");
    $stmt = $conn->prepare("
        SELECT DISTINCT u.id
        FROM friendships f
        JOIN users u ON u.id = IF(f.requester_id = ?, f.addressee_id, f.requester_id)
        JOIN presence_sessions p
            ON p.user_id = u.id
            AND p.last_active_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 45 SECOND)
        WHERE (f.requester_id = ? OR f.addressee_id = ?)
            AND f.status = 'accepted'
    ");
    $stmt->execute([$userId, $userId, $userId]);
    jsonResponse(['online_ids' => array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN))]);
} catch (Throwable $error) {
    error_log('Presence API error: ' . $error->getMessage());
    jsonResponse(['error' => 'Online status could not be updated.'], 500);
}
?>
