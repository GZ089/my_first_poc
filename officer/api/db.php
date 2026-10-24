<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

function getDB(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}

function jsonResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (isset($_SESSION['csrf'])) $data['_csrf'] = $_SESSION['csrf'];
    echo json_encode($data);
    exit;
}

function jsonError(string $message, int $status = 400): void
{
    jsonResponse(['error' => $message], $status);
}

function readJsonBody(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function iso(?string $dt): ?string
{
    return $dt ? date('c', strtotime($dt)) : null;
}

function currentUser(): ?array { return $_SESSION['user'] ?? null; }

function requireUser(): array
{
    $u = currentUser();
    if (!$u) jsonError('Authentication required', 401);
    return $u;
}

function requireAdmin(): array
{
    $u = requireUser();
    if (!in_array($u['role'], ['sso', 'admin'], true)) jsonError('Administrator access required', 403);
    return $u;
}

function requireSSO(): array
{
    $u = requireUser();
    if ($u['role'] !== 'sso') jsonError('SSO access required', 403);
    return $u;
}

function csrfRequired(): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) return;
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
        jsonError('Invalid or missing CSRF token', 403);
    }
}

function logAudit(?string $requestId, string $username, string $role, string $action, string $details = ''): void
{
    try {
        $stmt = getDB()->prepare('INSERT INTO audit_logs (request_id, username, role, action, details) VALUES (?,?,?,?,?)');
        $stmt->execute([$requestId, $username, $role, $action, $details]);
    } catch (Throwable $e) {
        error_log('audit insert failed: ' . $e->getMessage());
    }
}

function addHistory(string $requestId, string $username, string $action, ?string $part = null, ?string $reason = null): void
{
    $stmt = getDB()->prepare('INSERT INTO request_history (request_id, username, action, part, reason) VALUES (?,?,?,?,?)');
    $stmt->execute([$requestId, $username, $action, $part, $reason]);
}