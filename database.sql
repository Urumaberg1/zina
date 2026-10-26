-- ======================================
-- SocialVibe Database Setup
-- Run this SQL to create the database
-- ======================================

CREATE DATABASE IF NOT EXISTS socialvibe
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE socialvibe;

-- Users Table
CREATE TABLE IF NOT EXISTS users (
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
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Posts Table
CREATE TABLE IF NOT EXISTS posts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    content TEXT NOT NULL,
    image VARCHAR(255) DEFAULT NULL,
    visibility ENUM('public', 'friends', 'private') DEFAULT 'public',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Likes Table
CREATE TABLE IF NOT EXISTS likes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    post_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_like (user_id, post_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Comments Table
CREATE TABLE IF NOT EXISTS comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    post_id INT NOT NULL,
    content TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Friend requests and accepted friendships
CREATE TABLE IF NOT EXISTS friendships (
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
) ENGINE=InnoDB;

-- Friend-only image and video stories expire 24 hours after creation
CREATE TABLE IF NOT EXISTS stories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    media_path VARCHAR(255) NOT NULL,
    media_type ENUM('image', 'video') NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    INDEX story_expiry_lookup (expires_at, user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Private group conversations and their members
CREATE TABLE IF NOT EXISTS chat_groups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    icon_path VARCHAR(255) DEFAULT NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS group_members (
    group_id INT NOT NULL,
    user_id INT NOT NULL,
    role ENUM('admin', 'member') NOT NULL DEFAULT 'member',
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (group_id, user_id),
    FOREIGN KEY (group_id) REFERENCES chat_groups(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Direct messages are only exposed to accepted friends by the API
CREATE TABLE IF NOT EXISTS messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_id INT NOT NULL,
    receiver_id INT DEFAULT NULL,
    group_id INT DEFAULT NULL,
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
    FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (group_id) REFERENCES chat_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- WebRTC call sessions and short-lived offer/answer/ICE signaling.
CREATE TABLE IF NOT EXISTS calls (
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
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS call_participants (
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
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS call_signals (
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
) ENGINE=InnoDB;

-- Typing indicators expire automatically when the user stops typing.
CREATE TABLE IF NOT EXISTS typing_indicators (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    conversation_key VARCHAR(64) NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_typing_indicator (user_id, conversation_key),
    INDEX typing_conversation_lookup (conversation_key, updated_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Insert sample user
INSERT INTO users (username, email, password, display_name, bio, work, education, location)
VALUES ('user1', 'user@example.com', '$2y$10$samplehashedpassword', 'User Name', 
        'Welcome to SocialVibe! 🚀', 'Tech Company', 'University', 'Cairo, Egypt');

-- Insert sample posts
INSERT INTO posts (user_id, content) VALUES
(1, 'Just finished building my first web application! 🚀 The feeling of seeing your code come to life is absolutely incredible. #WebDevelopment #JavaScript'),
(1, 'Beautiful sunset today 🌅 Sometimes you need to take a break from coding and enjoy nature.'),
(1, '📚 Learning CSS Grid and Flexbox. Understanding when to use each one can really level up your front-end skills!');

SELECT 'Database setup complete!' AS status;
