<?php
/**
 * Connectify - User & Profile API
 * Handles user creation, listing, and updates
 */

require_once 'config.php';
setCorsHeaders();
startAppSession();

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        handleGet();
        break;
    case 'POST':
        handleCreateOrUpdate();
        break;
    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}

function handleGet() {
    if (isset($_GET['user_id']) && !filter_var($_GET['user_id'], FILTER_VALIDATE_INT)) {
        jsonResponse(['error' => 'A valid profile ID is required.'], 400);
    }
    $requestedId = filter_var($_GET['user_id'] ?? null, FILTER_VALIDATE_INT);
    $userId = $requestedId ?: ($_SESSION['user_id'] ?? null);
    $conn = initDatabase();
    if ($userId) {
        $stmt = $conn->prepare("
            SELECT id, username, display_name, bio, avatar, cover_photo, profile_effect,
                work, education, location, created_at
            FROM users
            WHERE id = ?
        ");
        $stmt->execute([(int)$userId]);
        $user = $stmt->fetch();
        if (!$user) {
            jsonResponse(['error' => 'Profile not found.'], 404);
        }
        $friendCount = $conn->prepare("
            SELECT COUNT(*) FROM friendships
            WHERE status = 'accepted' AND (user_low = ? OR user_high = ?)
        ");
        $friendCount->execute([(int)$userId, (int)$userId]);
        $user['friends_count'] = (int)$friendCount->fetchColumn();
        $friendsStmt = $conn->prepare("
            SELECT u.id, u.username, u.display_name, u.avatar, u.work, u.location
            FROM friendships f
            JOIN users u ON u.id = IF(f.user_low = ?, f.user_high, f.user_low)
            WHERE f.status = 'accepted' AND (f.user_low = ? OR f.user_high = ?)
            ORDER BY u.display_name
        ");
        $friendsStmt->execute([(int)$userId, (int)$userId, (int)$userId]);
        jsonResponse([
            'status' => 'success',
            'user' => $user,
            'friends' => $friendsStmt->fetchAll(),
            'is_owner' => !empty($_SESSION['user_id']) && (int)$_SESSION['user_id'] === (int)$userId
        ]);
    }

    $stmt = $conn->query('SELECT id, username, display_name, bio, avatar, work, location FROM users ORDER BY id DESC LIMIT 50');
    jsonResponse(['status' => 'success', 'users' => $stmt->fetchAll()]);
}

function handleCreateOrUpdate() {
    $data = json_decode(file_get_contents('php://input'), true);

    if (empty($_SESSION['user_id'])) {
        jsonResponse(['error' => 'Please sign in to update your profile.'], 401);
    }
    if (!is_array($data) || empty($data['display_name']) || !is_string($data['display_name'])) {
        jsonResponse(['error' => 'Display name is required'], 400);
    }

    $name = trim($data['display_name']);
    $bio = is_string($data['bio'] ?? '') ? trim($data['bio'] ?? '') : '';
    $work = is_string($data['work'] ?? '') ? trim($data['work'] ?? '') : '';
    $education = is_string($data['education'] ?? '') ? trim($data['education'] ?? '') : '';
    $location = is_string($data['location'] ?? '') ? trim($data['location'] ?? '') : '';
    $profileEffect = $data['profile_effect'] ?? 'none';
    $allowedEffects = ['none', 'aurora', 'neon', 'sunset', 'ocean', 'galaxy', 'emerald', 'rose', 'ember', 'prism', 'frost'];
    if ($name === '' || strlen($name) > 100) {
        jsonResponse(['error' => 'Display name must be between 1 and 100 characters.'], 400);
    }
    if (!is_string($profileEffect) || !in_array($profileEffect, $allowedEffects, true)) {
        jsonResponse(['error' => 'Choose a valid profile effect.'], 400);
    }

    $conn = initDatabase();
    $stmt = $conn->prepare('UPDATE users SET display_name = ?, bio = ?, work = ?, education = ?, location = ?, profile_effect = ? WHERE id = ?');
    $stmt->execute([$name, $bio, $work, $education, $location, $profileEffect, (int)$_SESSION['user_id']]);
    $savedUser = [
        'id' => (int)$_SESSION['user_id'],
        'display_name' => $name,
        'bio' => $bio,
        'work' => $work,
        'education' => $education,
        'location' => $location,
        'profile_effect' => $profileEffect
    ];

    jsonResponse([
        'status' => 'success',
        'message' => 'Profile updated successfully.',
        'user' => $savedUser
    ]);
}
?>
