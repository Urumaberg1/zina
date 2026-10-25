<?php
/**
 * Session-based account, friendship, and direct-message API.
 */
require_once __DIR__ . '/config.php';

$isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_set_cookie_params([
    'httponly' => true,
    'secure' => $isHttps,
    'samesite' => 'Lax'
]);
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function accountResponse($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function requestData() {
    if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) {
        return [];
    }
    $data = json_decode(file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

function publicUser($user) {
    return [
        'id' => (int)$user['id'],
        'username' => $user['username'],
        'display_name' => $user['display_name'],
        'bio' => $user['bio'] ?? '',
        'avatar' => $user['avatar'] ?? '',
        'cover_photo' => $user['cover_photo'] ?? '',
        'work' => $user['work'] ?? '',
        'location' => $user['location'] ?? ''
    ];
}

function requireUser() {
    if (empty($_SESSION['user_id'])) {
        accountResponse(['error' => 'Please sign in to continue.'], 401);
    }
    return (int)$_SESSION['user_id'];
}

function findFriendship($conn, $userId, $otherId) {
    $stmt = $conn->prepare('SELECT id, requester_id, addressee_id, status FROM friendships WHERE user_low = LEAST(?, ?) AND user_high = GREATEST(?, ?)');
    $stmt->execute([$userId, $otherId, $userId, $otherId]);
    return $stmt->fetch();
}

function requireFriendship($conn, $userId, $otherId) {
    if ($userId === $otherId) {
        accountResponse(['error' => 'You cannot message yourself.'], 400);
    }
    $friendship = findFriendship($conn, $userId, $otherId);
    if (!$friendship || $friendship['status'] !== 'accepted') {
        accountResponse(['error' => 'You can message users after they accept your friend request.'], 403);
    }
    return $friendship;
}

$method = $_SERVER['REQUEST_METHOD'];
$data = $method === 'POST' ? requestData() : [];
$action = $_GET['action'] ?? $data['action'] ?? '';

try {
    if ($action === 'register' && $method === 'POST') {
        $username = trim($data['username'] ?? '');
        $email = trim($data['email'] ?? '');
        $displayName = trim($data['display_name'] ?? '');
        $password = $data['password'] ?? '';

        if (!preg_match('/^[A-Za-z0-9_]{3,50}$/', $username)) {
            accountResponse(['error' => 'Username must be 3-50 characters using letters, numbers, or underscores.'], 400);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
            accountResponse(['error' => 'Enter a valid email address.'], 400);
        }
        if ($displayName === '' || strlen($displayName) > 100) {
            accountResponse(['error' => 'Display name is required and must be under 100 characters.'], 400);
        }
        if (strlen($password) < 8) {
            accountResponse(['error' => 'Password must be at least 8 characters.'], 400);
        }

        $conn = initDatabase();
        $stmt = $conn->prepare('INSERT INTO users (username, email, password, display_name) VALUES (?, ?, ?, ?)');
        try {
            $stmt->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT), $displayName]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                accountResponse(['error' => 'That username or email is already registered.'], 409);
            }
            throw $e;
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$conn->lastInsertId();
        accountResponse(['status' => 'success', 'user' => [
            'id' => $_SESSION['user_id'], 'username' => $username,
            'display_name' => $displayName, 'bio' => '', 'avatar' => '', 'cover_photo' => '',
            'work' => '', 'location' => ''
        ]], 201);
    }

    if ($action === 'login' && $method === 'POST') {
        $identifier = trim($data['identifier'] ?? '');
        $password = $data['password'] ?? '';
        $conn = initDatabase();
        $stmt = $conn->prepare('SELECT id, username, email, password, display_name, bio, avatar, cover_photo, work, location FROM users WHERE username = ? OR email = ? LIMIT 1');
        $stmt->execute([$identifier, $identifier]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, $user['password'])) {
            accountResponse(['error' => 'Username/email or password is incorrect.'], 401);
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        accountResponse(['status' => 'success', 'user' => publicUser($user)]);
    }

    if ($action === 'logout' && $method === 'POST') {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        accountResponse(['status' => 'success']);
    }

    if ($action === 'me' && $method === 'GET') {
        if (empty($_SESSION['user_id'])) accountResponse(['user' => null]);
        $conn = initDatabase();
        $stmt = $conn->prepare('SELECT id, username, display_name, bio, avatar, cover_photo, work, location FROM users WHERE id = ?');
        $stmt->execute([(int)$_SESSION['user_id']]);
        $user = $stmt->fetch();
        if (!$user) {
            $_SESSION = [];
            accountResponse(['user' => null]);
        }
        accountResponse(['user' => publicUser($user)]);
    }

    $userId = requireUser();
    $conn = initDatabase();

    if ($action === 'upload_cover' && $method === 'POST') {
        $file = $_FILES['cover_photo'] ?? null;
        if (!$file || !isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            accountResponse(['error' => 'Choose a banner image to upload.'], 400);
        }
        if ($file['size'] > 5 * 1024 * 1024) {
            accountResponse(['error' => 'Banner images must be 5 MB or smaller.'], 400);
        }

        $fileInfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $fileInfo->file($file['tmp_name']);
        $extensions = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif'
        ];
        if (!isset($extensions[$mimeType])) {
            accountResponse(['error' => 'Use a JPEG, PNG, WebP, or GIF banner image.'], 400);
        }

        $uploadDirectory = __DIR__ . '/uploads';
        if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
            throw new RuntimeException('Could not create the image upload directory.');
        }
        $filename = 'cover-' . $userId . '-' . bin2hex(random_bytes(16)) . '.' . $extensions[$mimeType];
        $destination = $uploadDirectory . '/' . $filename;
        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            throw new RuntimeException('Could not save the uploaded banner image.');
        }

        $coverPhoto = 'php/uploads/' . $filename;
        $stmt = $conn->prepare('UPDATE users SET cover_photo = ? WHERE id = ?');
        $stmt->execute([$coverPhoto, $userId]);
        accountResponse(['status' => 'success', 'cover_photo' => $coverPhoto]);
    }

    if ($action === 'search' && $method === 'GET') {
        $query = trim($_GET['q'] ?? '');
        if (strlen($query) < 2) accountResponse(['users' => []]);
        $like = '%' . $query . '%';
        $stmt = $conn->prepare("SELECT u.id, u.username, u.display_name, u.bio, u.avatar, u.work, u.location, u.password,
            f.id AS friendship_id, f.requester_id, f.status
            FROM users u
            LEFT JOIN friendships f ON f.user_low = LEAST(?, u.id) AND f.user_high = GREATEST(?, u.id)
            WHERE u.id <> ? AND (u.username LIKE ? OR u.display_name LIKE ?)
            ORDER BY u.username LIMIT 30");
        $stmt->execute([$userId, $userId, $userId, $like, $like]);
        $users = [];
        foreach ($stmt->fetchAll() as $row) {
            if (password_get_info($row['password'])['algoName'] === 'unknown') continue;
            $user = publicUser($row);
            $user['friendship_status'] = $row['status'];
            $user['request_direction'] = $row['status'] ? ((int)$row['requester_id'] === $userId ? 'outgoing' : 'incoming') : null;
            $users[] = $user;
        }
        accountResponse(['users' => $users]);
    }

    if ($action === 'friends' && $method === 'GET') {
        $stmt = $conn->prepare("SELECT u.id, u.username, u.display_name, u.bio, u.avatar, u.work, u.location,
            f.id AS friendship_id, f.status, f.requester_id
            FROM friendships f
            JOIN users u ON u.id = IF(f.requester_id = ?, f.addressee_id, f.requester_id)
            WHERE f.requester_id = ? OR f.addressee_id = ?
            ORDER BY f.status = 'pending' DESC, u.username");
        $stmt->execute([$userId, $userId, $userId]);
        $friends = [];
        foreach ($stmt->fetchAll() as $row) {
            $friend = publicUser($row);
            $friend['friendship_id'] = (int)$row['friendship_id'];
            $friend['friendship_status'] = $row['status'];
            $friend['request_direction'] = (int)$row['requester_id'] === $userId ? 'outgoing' : 'incoming';
            $friends[] = $friend;
        }
        accountResponse(['friends' => $friends]);
    }

    if ($action === 'notifications' && $method === 'GET') {
        $notifications = [];
        $stmt = $conn->prepare("SELECT u.id, u.username, u.display_name, u.avatar, f.created_at
            FROM friendships f JOIN users u ON u.id = f.requester_id
            WHERE f.addressee_id = ? AND f.status = 'pending' AND f.seen_at IS NULL
            ORDER BY f.created_at DESC LIMIT 20");
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll() as $row) {
            $notifications[] = [
                'type' => 'friend', 'user_id' => (int)$row['id'],
                'username' => $row['username'], 'display_name' => $row['display_name'],
                'avatar' => $row['avatar'], 'text' => 'sent you a friend request',
                'created_at' => $row['created_at']
            ];
        }

        $stmt = $conn->prepare("SELECT u.id, u.username, u.display_name, u.avatar,
                COUNT(m.id) AS unread_count, MAX(m.id) AS latest_id, MAX(m.created_at) AS created_at
            FROM messages m JOIN users u ON u.id = m.sender_id
            WHERE m.receiver_id = ? AND m.read_at IS NULL
            GROUP BY u.id, u.username, u.display_name, u.avatar
            ORDER BY latest_id DESC LIMIT 20");
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll() as $row) {
            $count = (int)$row['unread_count'];
            $notifications[] = [
                'type' => 'message', 'user_id' => (int)$row['id'],
                'username' => $row['username'], 'display_name' => $row['display_name'],
                'avatar' => $row['avatar'],
                'text' => 'sent you ' . $count . ' unread message' . ($count === 1 ? '' : 's'),
                'created_at' => $row['created_at']
            ];
        }
        usort($notifications, function ($left, $right) {
            return strtotime($right['created_at']) <=> strtotime($left['created_at']);
        });
        accountResponse(['notifications' => $notifications]);
    }

    if ($action === 'mark_read' && $method === 'POST') {
        $senderId = (int)($data['sender_id'] ?? 0);
        if ($senderId) {
            $stmt = $conn->prepare('UPDATE messages SET read_at = CURRENT_TIMESTAMP WHERE receiver_id = ? AND sender_id = ? AND read_at IS NULL');
            $stmt->execute([$userId, $senderId]);
            $stmt = $conn->prepare("UPDATE friendships SET seen_at = COALESCE(seen_at, CURRENT_TIMESTAMP) WHERE addressee_id = ? AND requester_id = ? AND status = 'pending'");
            $stmt->execute([$userId, $senderId]);
        } else {
            $stmt = $conn->prepare('UPDATE messages SET read_at = CURRENT_TIMESTAMP WHERE receiver_id = ? AND read_at IS NULL');
            $stmt->execute([$userId]);
            $stmt = $conn->prepare("UPDATE friendships SET seen_at = CURRENT_TIMESTAMP WHERE addressee_id = ? AND status = 'pending' AND seen_at IS NULL");
            $stmt->execute([$userId]);
        }
        accountResponse(['status' => 'success']);
    }

    if ($action === 'request' && $method === 'POST') {
        $otherId = (int)($data['user_id'] ?? 0);
        if (!$otherId || $otherId === $userId) accountResponse(['error' => 'Choose another user to add.'], 400);
        $stmt = $conn->prepare('SELECT id, password FROM users WHERE id = ?');
        $stmt->execute([$otherId]);
        $target = $stmt->fetch();
        if (!$target || password_get_info($target['password'])['algoName'] === 'unknown') {
            accountResponse(['error' => 'User not found.'], 404);
        }
        if (findFriendship($conn, $userId, $otherId)) accountResponse(['error' => 'A friend request or friendship already exists.'], 409);
        $stmt = $conn->prepare("INSERT INTO friendships (user_low, user_high, requester_id, addressee_id, status) VALUES (LEAST(?, ?), GREATEST(?, ?), ?, ?, 'pending')");
        $stmt->execute([$userId, $otherId, $userId, $otherId, $userId, $otherId]);
        accountResponse(['status' => 'success', 'message' => 'Friend request sent.'], 201);
    }

    if ($action === 'accept' && $method === 'POST') {
        $otherId = (int)($data['user_id'] ?? 0);
        $stmt = $conn->prepare("UPDATE friendships SET status = 'accepted', seen_at = COALESCE(seen_at, CURRENT_TIMESTAMP) WHERE requester_id = ? AND addressee_id = ? AND status = 'pending'");
        $stmt->execute([$otherId, $userId]);
        if (!$stmt->rowCount()) accountResponse(['error' => 'Incoming friend request not found.'], 404);
        accountResponse(['status' => 'success', 'message' => 'Friend request accepted.']);
    }

    if ($action === 'messages' && $method === 'GET') {
        $otherId = (int)($_GET['user_id'] ?? 0);
        requireFriendship($conn, $userId, $otherId);
        $readStmt = $conn->prepare('UPDATE messages SET read_at = CURRENT_TIMESTAMP WHERE receiver_id = ? AND sender_id = ? AND read_at IS NULL');
        $readStmt->execute([$userId, $otherId]);
        $stmt = $conn->prepare("SELECT id, sender_id, receiver_id, content, created_at FROM messages
            WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)
            ORDER BY id DESC LIMIT 200");
        $stmt->execute([$userId, $otherId, $otherId, $userId]);
        accountResponse(['messages' => array_reverse($stmt->fetchAll())]);
    }

    if ($action === 'message' && $method === 'POST') {
        $otherId = (int)($data['user_id'] ?? 0);
        $content = trim($data['content'] ?? '');
        requireFriendship($conn, $userId, $otherId);
        if ($content === '' || strlen($content) > 5000) accountResponse(['error' => 'Message must be between 1 and 5000 characters.'], 400);
        $stmt = $conn->prepare('INSERT INTO messages (sender_id, receiver_id, content) VALUES (?, ?, ?)');
        $stmt->execute([$userId, $otherId, $content]);
        accountResponse(['status' => 'success', 'message_id' => (int)$conn->lastInsertId()], 201);
    }

    accountResponse(['error' => 'Unknown action or method.'], 404);
} catch (Throwable $e) {
    error_log('Connectify account API error: ' . $e->getMessage());
    accountResponse(['error' => 'The account service could not complete the request. Check the database configuration.'], 500);
}
