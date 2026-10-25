<?php
/**
 * Connectify - User & Profile API
 * Handles user creation, listing, and updates
 */

require_once 'config.php';
setCorsHeaders();

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
    $userId = $_GET['user_id'] ?? null;
    
    // Check if database exists, otherwise fallback to local JSON storage
    try {
        $conn = getConnection();
        if ($userId) {
            $stmt = $conn->prepare("SELECT id, username, display_name, bio, avatar, work, education, location, created_at FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            if ($user) {
                jsonResponse(['status' => 'success', 'user' => $user]);
            }
        } else {
            $stmt = $conn->query("SELECT id, username, display_name, bio, avatar, work, location FROM users ORDER BY id DESC LIMIT 50");
            jsonResponse(['status' => 'success', 'users' => $stmt->fetchAll()]);
        }
    } catch (Exception $e) {
        // Fallback to json storage
        $jsonFile = __DIR__ . '/users_data.json';
        if (file_exists($jsonFile)) {
            $data = json_decode(file_get_contents($jsonFile), true) ?: [];
            jsonResponse(['status' => 'success', 'users' => $data, 'source' => 'file']);
        } else {
            jsonResponse(['status' => 'success', 'users' => [], 'source' => 'empty']);
        }
    }
}

function handleCreateOrUpdate() {
    $data = json_decode(file_get_contents('php://input'), true);

    session_start();
    if (empty($_SESSION['user_id'])) {
        jsonResponse(['error' => 'Please sign in to update your profile.'], 401);
    }
    if (empty($data['display_name'])) {
        jsonResponse(['error' => 'Display name is required'], 400);
    }

    $name = trim($data['display_name']);
    $bio = trim($data['bio'] ?? '');
    $avatar = $data['avatar'] ?? '';
    $work = trim($data['work'] ?? '');
    $education = trim($data['education'] ?? '');
    $location = trim($data['location'] ?? '');

    $conn = initDatabase();
    $stmt = $conn->prepare('UPDATE users SET display_name = ?, bio = ?, avatar = ?, work = ?, education = ?, location = ? WHERE id = ?');
    $stmt->execute([$name, $bio, $avatar, $work, $education, $location, (int)$_SESSION['user_id']]);
    $savedUser = [
        'id' => (int)$_SESSION['user_id'],
        'display_name' => $name,
        'bio' => $bio,
        'avatar' => $avatar,
        'work' => $work,
        'education' => $education,
        'location' => $location
    ];

    jsonResponse([
        'status' => 'success',
        'message' => 'Profile updated successfully.',
        'user' => $savedUser
    ]);
}
?>
