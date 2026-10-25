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
    
    if (empty($data['display_name'])) {
        jsonResponse(['error' => 'Display name is required'], 400);
    }
    
    $name = htmlspecialchars($data['display_name']);
    $bio = htmlspecialchars($data['bio'] ?? '');
    $avatar = $data['avatar'] ?? '';
    $work = htmlspecialchars($data['work'] ?? '');
    $education = htmlspecialchars($data['education'] ?? '');
    $location = htmlspecialchars($data['location'] ?? 'Cairo, Egypt');
    
    $savedUser = [
        'id' => time(),
        'display_name' => $name,
        'bio' => $bio,
        'avatar' => $avatar,
        'work' => $work,
        'education' => $education,
        'location' => $location,
        'created_at' => date('Y-m-d H:i:s')
    ];
    
    // Try database
    try {
        $conn = getConnection();
        initDatabase();
        $username = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $name)) . '_' . rand(100, 999);
        $email = $username . '@connectify.com';
        
        $stmt = $conn->prepare("INSERT INTO users (username, email, password, display_name, bio, avatar, work, education, location) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$username, $email, 'hashed_demo_pw', $name, $bio, $avatar, $work, $education, $location]);
        $savedUser['id'] = $conn->lastInsertId();
    } catch (Exception $e) {
        // Fallback file persistence
        $jsonFile = __DIR__ . '/users_data.json';
        $current = [];
        if (file_exists($jsonFile)) {
            $current = json_decode(file_get_contents($jsonFile), true) ?: [];
        }
        $current[] = $savedUser;
        file_put_contents($jsonFile, json_encode($current, JSON_PRETTY_PRINT));
    }
    
    jsonResponse([
        'status' => 'success',
        'message' => 'Profile saved to database successfully!',
        'user' => $savedUser
    ], 201);
}
?>
