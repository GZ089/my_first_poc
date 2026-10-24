<?php
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'officer_portal');
define('DB_USER', 'root');
define('DB_PASS', '');

define('APP_COOKIE_PATH', '/officer/');

// Upload limits
define('UPLOAD_DIR',          __DIR__ . '/uploads');
define('UPLOAD_URL_PREFIX',   'uploads');
define('MAX_IMAGE_BYTES',     1048576);   // 1 MB
define('MAX_VIDEO_BYTES',     2097152);   // 2 MB
define('MAX_VIDEO_SECONDS',   30);
define('MAX_IMAGES_PER_REQ',  3);
define('MAX_VIDEOS_PER_REQ',  1);