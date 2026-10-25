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

