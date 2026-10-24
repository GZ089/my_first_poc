SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS officer_portal
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE officer_portal;

DROP TABLE IF EXISTS request_media;
DROP TABLE IF EXISTS request_history;
DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS requests;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS settings;

CREATE TABLE users (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username       VARCHAR(40)  NOT NULL UNIQUE,
    password_hash  VARCHAR(255) NOT NULL,
    name           VARCHAR(100) NOT NULL,
    role           ENUM('sso','admin','officer') NOT NULL DEFAULT 'officer',
    dept           VARCHAR(40)  NOT NULL DEFAULT 'general',
    designation    VARCHAR(100) NOT NULL DEFAULT '',
    contact        VARCHAR(120) NOT NULL DEFAULT '',
    allowed_parts  LONGTEXT     NOT NULL,
    active         TINYINT(1)   NOT NULL DEFAULT 1,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_users_role   (role),
    KEY idx_users_active (active),
    KEY idx_users_dept   (dept)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE requests (
    id                VARCHAR(32)  PRIMARY KEY,
    title             VARCHAR(160) NOT NULL,
    initiated_by      VARCHAR(40)  NOT NULL,
    initiated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    current_step      VARCHAR(40)  NOT NULL DEFAULT 'part1',
    status            VARCHAR(20)  NOT NULL DEFAULT 'In Progress',
    returned_to       VARCHAR(40)  NULL,
    returned_from     VARCHAR(40)  NULL,
    return_reason     TEXT         NULL,
    rejection_reason  TEXT         NULL,
    override_reason   TEXT         NULL,
    form_data         LONGTEXT     NOT NULL,
    stage_status      LONGTEXT     NOT NULL,
    assignments       LONGTEXT     NOT NULL,
    KEY idx_req_status    (status),
    KEY idx_req_step      (current_step),
    KEY idx_req_initiator (initiated_by),
    KEY idx_req_updated   (updated_at),
    CONSTRAINT fk_req_initiator FOREIGN KEY (initiated_by)
        REFERENCES users(username) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE request_media (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id    VARCHAR(32) NOT NULL,
    stage         VARCHAR(40) NOT NULL DEFAULT 'part1',
    media_type    ENUM('image','video') NOT NULL,
    file_path     VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime_type     VARCHAR(100) NOT NULL,
    size_bytes    INT UNSIGNED NOT NULL,
    uploaded_by   VARCHAR(40) NOT NULL,
    uploaded_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_media_req   (request_id),
    KEY idx_media_type  (media_type),
    CONSTRAINT fk_media_request FOREIGN KEY (request_id)
        REFERENCES requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE request_history (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id VARCHAR(32) NOT NULL,
    username   VARCHAR(40) NOT NULL,
    action     VARCHAR(40) NOT NULL,
    part       VARCHAR(40) NULL,
    reason     TEXT        NULL,
    timestamp  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_hist_request   (request_id),
    KEY idx_hist_timestamp (timestamp),
    CONSTRAINT fk_hist_request FOREIGN KEY (request_id)
        REFERENCES requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE audit_logs (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id VARCHAR(32) NULL,
    username   VARCHAR(40) NOT NULL,
    role       VARCHAR(20) NOT NULL,
    action     VARCHAR(60) NOT NULL,
    details    TEXT        NULL,
    timestamp  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_audit_timestamp (timestamp),
    KEY idx_audit_user      (username),
    KEY idx_audit_request   (request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE settings (
    setting_key   VARCHAR(60) PRIMARY KEY,
    setting_value LONGTEXT NOT NULL,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;