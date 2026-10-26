<?php
/**
 * SocialVibe - Database Configuration & Connection
 * Backend PHP for Social Media Website
 */

// Database configuration
$DB_HOST = 'localhost';
$DB_NAME = 'socialvibe';
$DB_USER = 'root';
$DB_PASS = '';

// Create connection
function getConnection() {
    global $DB_HOST, $DB_NAME, $DB_USER, $DB_PASS;
    
    try {
        $conn = new PDO(
            "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
            $DB_USER,
            $DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]
        );
        return $conn;
    } catch (PDOException $e) {
        error_log('Database connection failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Database connection failed. Check the server configuration.']);
        exit;
    }
}

// Initialize database tables
function initDatabase() {
    $conn = getConnection();
    
    // Users table
    $conn->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) UNIQUE NOT NULL,
        email VARCHAR(100) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        display_name VARCHAR(100) NOT NULL,
        bio TEXT DEFAULT '',
        avatar VARCHAR(255) DEFAULT '',
        cover_photo VARCHAR(255) DEFAULT '',
        profile_effect VARCHAR(30) NOT NULL DEFAULT 'none',
        work VARCHAR(100) DEFAULT '',
        education VARCHAR(100) DEFAULT '',
        location VARCHAR(100) DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    if (!$conn->query("SHOW COLUMNS FROM users LIKE 'cover_photo'")->fetch()) {
        $conn->exec("ALTER TABLE users ADD cover_photo VARCHAR(255) DEFAULT ''");
    }
    if (!$conn->query("SHOW COLUMNS FROM users LIKE 'profile_effect'")->fetch()) {
        $conn->exec("ALTER TABLE users ADD profile_effect VARCHAR(30) NOT NULL DEFAULT 'none'");
    }
    $conn->exec("CREATE TABLE IF NOT EXISTS presence_sessions (
        user_id INT NOT NULL,
        client_id VARCHAR(64) NOT NULL,
        last_active_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, client_id),
        INDEX presence_last_active (last_active_at),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");
    
    // Posts table
    $conn->exec("CREATE TABLE IF NOT EXISTS posts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        content TEXT NOT NULL,
        image VARCHAR(255) DEFAULT NULL,
        visibility ENUM('public', 'friends', 'private') DEFAULT 'public',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");
    
    // Likes table
    $conn->exec("CREATE TABLE IF NOT EXISTS likes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        post_id INT NOT NULL,
        reaction VARCHAR(16) NOT NULL DEFAULT 'like',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_like (user_id, post_id),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
    )");
    if (!$conn->query("SHOW COLUMNS FROM likes LIKE 'reaction'")->fetch()) {
        $conn->exec("ALTER TABLE likes ADD reaction VARCHAR(16) NOT NULL DEFAULT 'like'");
    }
    
    // Comments table
    $conn->exec("CREATE TABLE IF NOT EXISTS comments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        post_id INT NOT NULL,
        content TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
    )");

    $conn->exec("CREATE TABLE IF NOT EXISTS friendships (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_low INT NOT NULL,
        user_high INT NOT NULL,
        requester_id INT NOT NULL,
        addressee_id INT NOT NULL,
        status ENUM('pending', 'accepted') NOT NULL DEFAULT 'pending',
        seen_at TIMESTAMP NULL DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_friendship (user_low, user_high),
        FOREIGN KEY (user_low) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (user_high) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (requester_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (addressee_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    $conn->exec("CREATE TABLE IF NOT EXISTS stories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        media_path VARCHAR(255) NOT NULL,
        media_type ENUM('image', 'video') NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expires_at TIMESTAMP NOT NULL,
        INDEX story_expiry_lookup (expires_at, user_id),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    $conn->exec("CREATE TABLE IF NOT EXISTS messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sender_id INT NOT NULL,
        receiver_id INT NULL,
        group_id INT NULL,
        content TEXT NOT NULL,
        attachment_path VARCHAR(255) DEFAULT NULL,
        attachment_type VARCHAR(30) DEFAULT NULL,
        attachment_name VARCHAR(255) DEFAULT NULL,
        reply_to_id INT NULL,
        edited_at TIMESTAMP NULL DEFAULT NULL,
        deleted_at TIMESTAMP NULL DEFAULT NULL,
        read_at TIMESTAMP NULL DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX conversation_lookup (sender_id, receiver_id, id),
        INDEX group_conversation_lookup (group_id, id),
        FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE
    )");
    $conn->exec("CREATE TABLE IF NOT EXISTS message_reactions (
        message_id INT NOT NULL,
        user_id INT NOT NULL,
        reaction VARCHAR(16) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (message_id, user_id),
        INDEX message_reaction_lookup (message_id, reaction),
        FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    $conn->exec("CREATE TABLE IF NOT EXISTS chat_groups (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        icon_path VARCHAR(255) DEFAULT NULL,
        created_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
    )");

    $conn->exec("CREATE TABLE IF NOT EXISTS group_members (
        group_id INT NOT NULL,
        user_id INT NOT NULL,
        role ENUM('admin', 'member') NOT NULL DEFAULT 'member',
        joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (group_id, user_id),
        FOREIGN KEY (group_id) REFERENCES chat_groups(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    $conn->exec("CREATE TABLE IF NOT EXISTS typing_indicators (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        conversation_key VARCHAR(64) NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_typing_indicator (user_id, conversation_key),
        INDEX typing_conversation_lookup (conversation_key, updated_at),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    $conn->exec("CREATE TABLE IF NOT EXISTS calls (
        id CHAR(36) PRIMARY KEY,
        initiated_by INT NOT NULL,
        group_id INT NULL,
        direct_user_id INT NULL,
        status ENUM('active', 'ended') NOT NULL DEFAULT 'active',
        media_type ENUM('audio', 'video') NOT NULL DEFAULT 'audio',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        ended_at TIMESTAMP NULL DEFAULT NULL,
        INDEX active_calls (status, created_at),
        INDEX calls_initiator (initiated_by),
        INDEX calls_group (group_id),
        INDEX calls_direct_user (direct_user_id),
        FOREIGN KEY (group_id) REFERENCES chat_groups(id) ON DELETE CASCADE,
        FOREIGN KEY (initiated_by) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (direct_user_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    $conn->exec("CREATE TABLE IF NOT EXISTS call_participants (
        call_id CHAR(36) NOT NULL,
        user_id INT NOT NULL,
        status ENUM('ringing', 'joined', 'declined', 'left') NOT NULL DEFAULT 'ringing',
        is_muted TINYINT(1) NOT NULL DEFAULT 0,
        is_camera_enabled TINYINT(1) NOT NULL DEFAULT 0,
        is_sharing_screen TINYINT(1) NOT NULL DEFAULT 0,
        joined_at TIMESTAMP NULL DEFAULT NULL,
        last_seen_at TIMESTAMP NULL DEFAULT NULL,
        PRIMARY KEY (call_id, user_id),
        INDEX user_active_calls (user_id, status),
        FOREIGN KEY (call_id) REFERENCES calls(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    $conn->exec("CREATE TABLE IF NOT EXISTS call_signals (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        call_id CHAR(36) NOT NULL,
        sender_id INT NOT NULL,
        receiver_id INT NOT NULL,
        signal_type ENUM('offer', 'answer', 'candidate') NOT NULL,
        payload LONGTEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX recipient_signals (receiver_id, id),
        INDEX call_signals_call (call_id),
        INDEX call_signals_sender (sender_id),
        FOREIGN KEY (call_id) REFERENCES calls(id) ON DELETE CASCADE,
        FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE
    )");
    if (!$conn->query("SHOW COLUMNS FROM call_participants LIKE 'is_muted'")->fetch()) {
        $conn->exec('ALTER TABLE call_participants ADD is_muted TINYINT(1) NOT NULL DEFAULT 0');
    }
    if (!$conn->query("SHOW COLUMNS FROM call_participants LIKE 'is_camera_enabled'")->fetch()) {
        $conn->exec('ALTER TABLE call_participants ADD is_camera_enabled TINYINT(1) NOT NULL DEFAULT 0');
    }
    if (!$conn->query("SHOW COLUMNS FROM call_participants LIKE 'is_sharing_screen'")->fetch()) {
        $conn->exec('ALTER TABLE call_participants ADD is_sharing_screen TINYINT(1) NOT NULL DEFAULT 0');
    }

    if (!$conn->query("SHOW COLUMNS FROM friendships LIKE 'seen_at'")->fetch()) {
        $conn->exec('ALTER TABLE friendships ADD seen_at TIMESTAMP NULL DEFAULT NULL');
    }
    if (!$conn->query("SHOW COLUMNS FROM messages LIKE 'read_at'")->fetch()) {
        $conn->exec('ALTER TABLE messages ADD read_at TIMESTAMP NULL DEFAULT NULL');
    }
    if (!$conn->query("SHOW COLUMNS FROM messages LIKE 'group_id'")->fetch()) {
        $conn->exec('ALTER TABLE messages ADD group_id INT NULL, ADD INDEX group_conversation_lookup (group_id, id)');
    }
    if (!$conn->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'messages'
            AND COLUMN_NAME = 'group_id' AND REFERENCED_TABLE_NAME = 'chat_groups'")->fetch()) {
        $conn->exec('ALTER TABLE messages ADD CONSTRAINT fk_messages_group FOREIGN KEY (group_id) REFERENCES chat_groups(id) ON DELETE CASCADE');
    }
    if (!$conn->query("SHOW COLUMNS FROM messages LIKE 'attachment_path'")->fetch()) {
        $conn->exec('ALTER TABLE messages ADD attachment_path VARCHAR(255) DEFAULT NULL');
    }
    if (!$conn->query("SHOW COLUMNS FROM messages LIKE 'attachment_type'")->fetch()) {
        $conn->exec('ALTER TABLE messages ADD attachment_type VARCHAR(30) DEFAULT NULL');
    }
    if (!$conn->query("SHOW COLUMNS FROM messages LIKE 'attachment_name'")->fetch()) {
        $conn->exec('ALTER TABLE messages ADD attachment_name VARCHAR(255) DEFAULT NULL');
    }
    if (!$conn->query("SHOW COLUMNS FROM messages LIKE 'reply_to_id'")->fetch()) {
        $conn->exec('ALTER TABLE messages ADD reply_to_id INT NULL');
    }
    if (!$conn->query("SHOW COLUMNS FROM messages LIKE 'edited_at'")->fetch()) {
        $conn->exec('ALTER TABLE messages ADD edited_at TIMESTAMP NULL DEFAULT NULL');
    }
    if (!$conn->query("SHOW COLUMNS FROM messages LIKE 'deleted_at'")->fetch()) {
        $conn->exec('ALTER TABLE messages ADD deleted_at TIMESTAMP NULL DEFAULT NULL');
    }
    if (!$conn->query("SHOW COLUMNS FROM chat_groups LIKE 'icon_path'")->fetch()) {
        $conn->exec('ALTER TABLE chat_groups ADD icon_path VARCHAR(255) DEFAULT NULL');
    }
    $receiverColumn = $conn->query("SHOW COLUMNS FROM messages LIKE 'receiver_id'")->fetch();
    if (strtoupper($receiverColumn['Null']) !== 'YES') {
        $conn->exec('ALTER TABLE messages MODIFY receiver_id INT NULL');
    }
    
    return $conn;
}

function startAppSession() {
    if (session_status() === PHP_SESSION_NONE) {
        $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        session_set_cookie_params([
            'httponly' => true,
            'secure' => $isHttps,
            'samesite' => 'Lax'
        ]);
        session_start();
    }
}

function requireAuthenticatedUser() {
    startAppSession();
    if (empty($_SESSION['user_id'])) {
        jsonResponse(['error' => 'Please sign in to continue.'], 401);
    }
    return (int)$_SESSION['user_id'];
}

// CORS Headers for API
function setCorsHeaders() {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
    header('Access-Control-Allow-Headers: Content-Type');
}

// Send JSON response
function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data);
    exit;
}
?>
