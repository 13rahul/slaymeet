-- SlayMeet OSS schema (single-tenant v1, company_id = 1)

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    company_id INT NOT NULL DEFAULT 1,
    role ENUM('admin','user','guest') NOT NULL DEFAULT 'user',
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO users (name, email, password, company_id, role, status)
SELECT 'Admin', 'admin@localhost', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 1, 'admin', 'active'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM users WHERE email = 'admin@localhost');

CREATE TABLE IF NOT EXISTS slay_meet_rooms (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL DEFAULT 1,
    calendar_event_id BIGINT UNSIGNED NULL,
    channel_id BIGINT UNSIGNED NULL,
    created_by INT NOT NULL,
    room_name VARCHAR(220) NOT NULL,
    public_token VARCHAR(64) NOT NULL,
    host_token VARCHAR(64) NOT NULL,
    status ENUM('scheduled','live','ended','cancelled') NOT NULL DEFAULT 'scheduled',
    starts_at DATETIME NULL,
    ended_at DATETIME NULL,
    metadata_json JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_public_token (public_token),
    UNIQUE KEY uniq_host_token (host_token),
    KEY idx_company_status (company_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS slay_meet_participants (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    room_id BIGINT UNSIGNED NOT NULL,
    user_id INT NOT NULL,
    role ENUM('host','moderator','participant') NOT NULL DEFAULT 'participant',
    admission_status ENUM('pending','admitted','denied') NOT NULL DEFAULT 'admitted',
    joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    requested_at DATETIME NULL,
    decided_at DATETIME NULL,
    decided_by INT NULL,
    left_at DATETIME NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uniq_room_user (room_id, user_id),
    KEY idx_room_active (room_id, is_active),
    KEY idx_room_admission (room_id, admission_status, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS slay_meet_signals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    room_id BIGINT UNSIGNED NOT NULL,
    from_user_id INT NOT NULL,
    to_user_id INT NULL,
    signal_type ENUM('offer','answer','ice','system') NOT NULL,
    payload_json LONGTEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_room_id_id (room_id, id),
    KEY idx_to_user (to_user_id),
    KEY idx_from_user (from_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS slaymeet_calls (
    id INT AUTO_INCREMENT PRIMARY KEY,
    caller_id INT NOT NULL,
    receiver_id INT NOT NULL,
    room_token VARCHAR(255) NOT NULL,
    status ENUM('ringing', 'accepted', 'rejected', 'ended') DEFAULT 'ringing',
    started_at DATETIME DEFAULT NULL,
    ended_at DATETIME DEFAULT NULL,
    duration_sec INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_receiver (receiver_id),
    INDEX idx_caller (caller_id),
    INDEX idx_room_token (room_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
