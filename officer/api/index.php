<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => APP_COOKIE_PATH,
    'httponly' => true,
    'secure'   => $isHttps,
    'samesite' => 'Lax',
]);
session_name('officer_sid');
session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
csrfRequired();

const STAGE_META = [
    'part1'         => ['part' => 'part1', 'dept' => 'general'],
    'part2'         => ['part' => 'part2', 'dept' => 'deputy_admin'],
    'part3'         => ['part' => 'part3', 'dept' => 'deputy_admin'],
    'part4'         => ['part' => 'part4', 'dept' => 'part4'],
    'deputy_return' => ['part' => 'part3', 'dept' => 'deputy_admin'],
    'part5'         => ['part' => 'part5', 'dept' => 'part5'],
    'part6'         => ['part' => 'part6', 'dept' => 'part6'],
    'part7'         => ['part' => 'part7', 'dept' => 'general'],
];
const DEFAULT_WORKFLOW_ORDER = ['part1','part2','part3','part4','deputy_return','part5','part6','part7'];

function loadWorkflowOrder(): array
{
    $stmt = getDB()->prepare("SELECT setting_value FROM settings WHERE setting_key = 'workflowConfig'");
    $stmt->execute();
    $row = $stmt->fetch();
    if (!$row) return DEFAULT_WORKFLOW_ORDER;
    $v = json_decode($row['setting_value'], true);
    if (!is_array($v) || !isset($v['order']) || !is_array($v['order'])) return DEFAULT_WORKFLOW_ORDER;
    $valid = [];
    foreach ($v['order'] as $k) if (is_string($k) && isset(STAGE_META[$k])) $valid[] = $k;
    return $valid ?: DEFAULT_WORKFLOW_ORDER;
}

function buildWorkflow(): array
{
    $order = loadWorkflowOrder();
    $wf = [];
    foreach ($order as $i => $k) {
        $meta = STAGE_META[$k];
        $meta['next'] = $order[$i + 1] ?? 'completed';
        $wf[$k] = $meta;
    }
    return $wf;
}

function stagePartOf(array $wf, string $stage): string { return $wf[$stage]['part'] ?? $stage; }

function canActOnRequest(array $req, array $user, array $wf): bool
{
    if (in_array($req['status'], ['Rejected'], true) || $req['currentStep'] === 'completed') return false;
    if (in_array($user['role'], ['sso', 'admin'], true)) return true;
    $part = stagePartOf($wf, $req['currentStep']);
    $explicit = $req['assignments'][$req['currentStep']] ?? null;
    if ($explicit) return $explicit === $user['username'];
    if (is_array($user['allowedParts']) && !in_array($part, $user['allowedParts'], true)) return false;
    return $user['role'] === 'officer'
        && (in_array($part, ['part1', 'part7'], true)
            || ($wf[$req['currentStep']]['dept'] ?? null) === $user['dept']);
}

function nextActionUser(array $req, array $wf): ?array
{
    $stage = $req['currentStep'];
    if ($stage === 'completed' || $req['status'] === 'Rejected') return null;
    if ($req['status'] === 'Returned' && $req['returnedTo']) {
        $u = findUser($req['returnedTo']);
        return $u ? ['username'=>$u['username'],'name'=>$u['name'],'contact'=>$u['contact'],'role'=>'returned'] : null;
    }
    $dept = $wf[$stage]['dept'] ?? null;
    $part = stagePartOf($wf, $stage);
    if (!$dept) return null;
    $explicit = $req['assignments'][$stage] ?? null;
    if ($explicit) {
        $u = findUser($explicit);
        return $u ? ['username'=>$u['username'],'name'=>$u['name'],'contact'=>$u['contact'],'role'=>'assigned'] : null;
    }
    $stmt = getDB()->prepare("SELECT username, name, contact, allowed_parts FROM users WHERE active=1 AND role='officer' AND dept=?");
    $stmt->execute([$dept]);
    foreach ($stmt->fetchAll() as $u) {
        $ap = json_decode($u['allowed_parts'], true) ?: [];
        if (in_array($part, $ap, true)) return ['username'=>$u['username'],'name'=>$u['name'],'contact'=>$u['contact'],'role'=>'dept'];
    }
    $stmt = getDB()->prepare("SELECT username, name, contact FROM users WHERE active=1 AND role IN ('sso','admin') ORDER BY role='sso' DESC LIMIT 1");
    $stmt->execute();
    $u = $stmt->fetch();
    return $u ? ['username'=>$u['username'],'name'=>$u['name'],'contact'=>$u['contact'],'role'=>'admin'] : null;
}

function findUser(string $username): ?array
{
    $stmt = getDB()->prepare('SELECT username, name, contact FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $u = $stmt->fetch();
    return $u ?: null;
}

function listMediaFor(string $requestId): array
{
    $stmt = getDB()->prepare('SELECT id, stage, media_type, file_path, original_name, size_bytes, uploaded_at FROM request_media WHERE request_id = ? ORDER BY uploaded_at ASC');
    $stmt->execute([$requestId]);
    return array_map(static function ($r) {
        return [
            'id'           => (int) $r['id'],
            'stage'        => $r['stage'],
            'type'         => $r['media_type'],
            'url'          => '/' . ltrim($r['file_path'], '/'),
            'originalName' => $r['original_name'],
            'sizeBytes'    => (int) $r['size_bytes'],
            'uploadedAt'   => iso($r['uploaded_at']),
        ];
    }, $stmt->fetchAll());
}

function formatSummary(array $row, array $wf): array
{
    return [
        'id'          => $row['id'],
        'title'       => $row['title'],
        'initiatedBy' => $row['initiated_by'],
        'initiatedAt' => iso($row['initiated_at']),
        'updatedAt'   => iso($row['updated_at']),
        'currentStep' => $row['current_step'],
        'status'      => $row['status'],
        'stageStatus' => json_decode($row['stage_status'] ?: '{}', true) ?: [],
        'assignments' => json_decode($row['assignments'] ?: '{}', true) ?: [],
        'nextActionBy' => nextActionUser([
            'currentStep' => $row['current_step'],
            'status'      => $row['status'],
            'returnedTo'  => $row['returned_to'],
            'assignments' => json_decode($row['assignments'] ?: '{}', true) ?: [],
        ], $wf),
    ];
}

function formatFull(array $row, array $wf): array
{
    $r = formatSummary($row, $wf);
    $r['returnedTo']      = $row['returned_to'];
    $r['returnedFrom']    = $row['returned_from'];
    $r['returnReason']    = $row['return_reason'];
    $r['rejectionReason'] = $row['rejection_reason'];
    $r['overrideReason']  = $row['override_reason'];
    $r['formData']        = json_decode($row['form_data'] ?: '{}', true) ?: [];
    $r['media']           = listMediaFor($row['id']);
    return $r;
}

function loadRequestOrFail(string $id): array
{
    $stmt = getDB()->prepare('SELECT * FROM requests WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Request not found', 404);
    return $row;
}

function userInvolvedIn(array $req, array $user, array $wf): bool
{
    if ($req['initiated_by'] === $user['username']) return true;
    if (($req['returned_to'] ?? null) === $user['username']) return true;
    if (canActOnRequest([
        'status' => $req['status'], 'currentStep' => $req['current_step'],
        'assignments' => json_decode($req['assignments'] ?: '{}', true) ?: [],
    ], $user, $wf)) return true;
    $ss = json_decode($req['stage_status'] ?: '{}', true) ?: [];
    foreach ($ss as $v) if (($v['submittedBy'] ?? '') === $user['username']) return true;
    return false;
}

/* ==================== Required-field system ==================== */

/** Human-readable labels for error messages */
function fieldLabel(string $key): string
{
    static $labels = [
        'p1-addr'         => 'Address (Part-I)',
        'p1-name'         => 'Name of user (Part-I)',
        'p1-contact'      => 'Contact (Part-I)',
        'date-p1'         => 'Date of complaint (Part-I)',
        'p1-desc-a'       => 'Description line a (Part-I)',
        'date-p1-sign'    => 'Signature date (Part-I)',
        'sig-p1-user'     => 'Signature (Part-I)',
        'p2-details'      => 'Works details (Part-II)',
        'p2-fy'           => 'Financial year (Part-II)',
        'sig-p2-admin'    => 'Signature (Part-II)',
        'date-p3-insp'    => 'Inspection date (Part-III)',
        'p3-priority'     => 'Priority (Part-III)',
        'sig-p3-admin'    => 'Signature (Part-III)',
        'date-p4-receipt' => 'Receipt date (Part-IV)',
        'p4-approval'     => 'Approved / Not approved (Part-IV)',
        'p4-priority'     => 'Priority (Part-IV)',
        'p4-funds'        => 'State of funds (Part-IV)',
        'p4-rs-a'         => 'Amount a - allocated (Part-IV)',
        'p4-rs-b'         => 'Amount b - used (Part-IV)',
        'p4-rs-c'         => 'Amount c - cost (Part-IV)',
        'sig-p4-cfa'      => 'Signature (Part-IV)',
        'date-p5-receipt' => 'Receipt date (Part-V)',
        'p5-approval'     => 'Principal approval (Part-V)',
        'p5-funds'        => 'Funds status (Part-V)',
        'sig-p5-sso'      => 'Signature (Part-V)',
        'date-p6-init'    => 'Initiation date (Part-VI)',
        'date-p6-comp'    => 'Completion date (Part-VI)',
        'sig-p6-ge'       => 'Signature (Part-VI)',
        'date-p7-comp'    => 'Completion date (Part-VII)',
        'p7-satisfaction' => 'Satisfactory / Not (Part-VII)',
        'p7-remarks'      => 'Remarks (Part-VII)',
        'sig-p7-user'     => 'Signature (Part-VII)',
    ];
    return $labels[$key] ?? $key;
}

/** Required form fields per part/stage */
function requiredFieldsForStage(string $stage, array $wf): array
{
    $part = $wf[$stage]['part'] ?? $stage;
    $map = [
        // b, c optional → auto-filled with "-"
        'part1' => ['p1-addr','p1-name','p1-contact','date-p1','p1-desc-a','date-p1-sign','sig-p1-user'],
        'part2' => ['p2-details','p2-fy','sig-p2-admin'],
        'part3' => ['date-p3-insp','p3-priority','sig-p3-admin'],
        // d optional → auto-computed
        'part4' => ['date-p4-receipt','p4-approval','p4-priority','p4-funds','p4-rs-a','p4-rs-b','p4-rs-c','sig-p4-cfa'],
        'part5' => ['date-p5-receipt','p5-approval','p5-funds','sig-p5-sso'],
        'part6' => ['date-p6-init','date-p6-comp','sig-p6-ge'],
        'part7' => ['date-p7-comp','p7-satisfaction','p7-remarks','sig-p7-user'],
    ];
    return $map[$part] ?? [];
}

/** Auto-fill optional / computed fields before validation */
function autofillOptionalFields(array &$formData, string $part): void
{
    if ($part === 'part1') {
        foreach (['p1-desc-b','p1-desc-c'] as $k) {
            $v = trim(strip_tags((string) ($formData[$k] ?? '')));
            if ($v === '') $formData[$k] = '-';
        }
        $signDate = trim((string) ($formData['date-p1-sign'] ?? ''));
        if ($signDate === '') {
            $formData['date-p1-sign'] = $formData['date-p1'] ?? date('Y-m-d');
        }
    }
    if ($part === 'part4') {
        foreach (['p4-rs-a','p4-rs-b','p4-rs-c'] as $k) {
            $v = trim(strip_tags((string) ($formData[$k] ?? '')));
            if ($v === '') $formData[$k] = '0';
        }
        $a = (float) preg_replace('/[^\d.\-]/', '', (string) ($formData['p4-rs-a'] ?? 0));
        $b = (float) preg_replace('/[^\d.\-]/', '', (string) ($formData['p4-rs-b'] ?? 0));
        $c = (float) preg_replace('/[^\d.\-]/', '', (string) ($formData['p4-rs-c'] ?? 0));
        $formData['p4-rs-d'] = (string) ($a - $b - $c);
    }
}

/** Returns array of missing required field KEYS */
function validateRequiredFields(array $formData, string $stage, array $wf): array
{
    $missing = [];
    foreach (requiredFieldsForStage($stage, $wf) as $key) {
        $v = $formData[$key] ?? '';
        if (is_string($v)) {
            $plain = trim(strip_tags($v));
            if ($plain === '' || $plain === '&nbsp;') $missing[] = $key;
        } else {
            if (!$v) $missing[] = $key;
        }
    }
    return $missing;
}

/* ==================== Router ==================== */
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    switch ($action) {

        case 'setup-status':
            $count = (int) getDB()->query('SELECT COUNT(*) FROM users')->fetchColumn();
            jsonResponse(['needsSetup' => $count === 0]);

        case 'setup':
            $count = (int) getDB()->query('SELECT COUNT(*) FROM users')->fetchColumn();
            if ($count > 0) jsonError('Setup already complete', 409);
            $d = readJsonBody();
            $name     = trim((string) ($d['name'] ?? ''));
            $username = strtolower(trim((string) ($d['username'] ?? '')));
            $password = (string) ($d['password'] ?? '');
            $contact  = trim((string) ($d['contact'] ?? ''));
            if (!preg_match('/^[a-z0-9._-]{3,40}$/', $username)) jsonError('Username must be 3–40 characters.');
            if (strlen($password) < 12) jsonError('Password must be at least 12 characters.');
            if ($name === '') jsonError('Name required.');
            if ($contact === '') jsonError('Contact required.');
            $all = json_encode(['part1','part2','part3','part4','part5','part6','part7']);
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = getDB()->prepare("INSERT INTO users (username, password_hash, name, role, dept, designation, contact, allowed_parts, active) VALUES (?,?,?, 'sso', 'general', 'Super Admin', ?, ?, 1)");
            $stmt->execute([$username, $hash, $name, $contact, $all]);
            logAudit(null, $username, 'sso', 'INITIAL_ADMIN_CREATED', 'Initial SSO created');
            jsonResponse(['ok' => true]);

        case 'login':
            $d = readJsonBody();
            $username = strtolower(trim((string) ($d['username'] ?? '')));
            $password = (string) ($d['password'] ?? '');
            if ($username === '' || $password === '') jsonError('Username and password required.');
            $stmt = getDB()->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            if (!$user || !$user['active'] || !password_verify($password, $user['password_hash'])) jsonError('Invalid username or password.', 401);
            session_regenerate_id(true);
            $_SESSION['user'] = [
                'username' => $user['username'], 'name' => $user['name'],
                'role' => $user['role'], 'dept' => $user['dept'],
                'designation' => $user['designation'], 'contact' => $user['contact'],
                'allowedParts' => json_decode($user['allowed_parts'] ?: '[]', true) ?: [],
                'active' => (bool) $user['active'],
            ];
            logAudit(null, $user['username'], $user['role'], 'LOGIN', 'Successful login');
            jsonResponse(['user' => $_SESSION['user']]);

        case 'logout':
            $u = currentUser();
            if ($u) logAudit(null, $u['username'], $u['role'], 'LOGOUT', 'User logged out');
            $_SESSION = [];
            session_regenerate_id(true);
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            jsonResponse(['ok' => true]);

        case 'me':
            $u = requireUser();
            jsonResponse(['user' => $u]);

        case 'users':
            if ($method === 'GET') {
                requireAdmin();
                $rows = getDB()->query('SELECT * FROM users ORDER BY created_at DESC')->fetchAll();
                $out = array_map(static function ($r) {
                    return [
                        'username' => $r['username'], 'name' => $r['name'],
                        'role' => $r['role'], 'dept' => $r['dept'],
                        'designation' => $r['designation'], 'contact' => $r['contact'],
                        'allowedParts' => json_decode($r['allowed_parts'] ?: '[]', true) ?: [],
                        'active' => (bool) $r['active'], 'createdAt' => iso($r['created_at']),
                    ];
                }, $rows);
                jsonResponse(['users' => $out]);
            }
            $admin = requireAdmin();
            $d = readJsonBody();
            $username = strtolower(trim((string) ($d['username'] ?? '')));
            $password = (string) ($d['password'] ?? '');
            $name     = trim((string) ($d['name'] ?? ''));
            $role     = $d['role'] ?? 'officer';
            $dept     = $d['dept'] ?? 'general';
            $designation = trim((string) ($d['designation'] ?? $dept));
            $contact  = trim((string) ($d['contact'] ?? ''));
            $allowedParts = $d['allowedParts'] ?? [];
            if (!preg_match('/^[a-z0-9._-]{3,40}$/', $username)) jsonError('Username must be 3–40 chars.');
            if (strlen($password) < 8) jsonError('Password must be at least 8 chars.');
            if ($name === '' || $contact === '') jsonError('Name and contact required.');
            if (!in_array($role, ['sso', 'admin', 'officer'], true)) jsonError('Invalid role.');
            if (!is_array($allowedParts) || !$allowedParts) jsonError('At least one part required.');
            $check = getDB()->prepare('SELECT 1 FROM users WHERE username = ?');
            $check->execute([$username]);
            if ($check->fetchColumn()) jsonError('Username already exists.', 409);
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = getDB()->prepare('INSERT INTO users (username, password_hash, name, role, dept, designation, contact, allowed_parts, active) VALUES (?,?,?,?,?,?,?,?,1)');
            $stmt->execute([$username, $hash, $name, $role, $dept, $designation, $contact, json_encode(array_values($allowedParts))]);
            logAudit(null, $admin['username'], $admin['role'], 'CREATE_USER', "Created $role $username");
            jsonResponse(['ok' => true, 'username' => $username], 201);

        case 'user':
            $admin = requireAdmin();
            $username = strtolower(trim((string) ($_GET['username'] ?? '')));
            $op = $_GET['op'] ?? '';
            $stmt = getDB()->prepare('SELECT * FROM users WHERE username = ?');
            $stmt->execute([$username]);
            $u = $stmt->fetch();
            if (!$u) jsonError('User not found', 404);
            if ($op === 'toggle') {
                if ($u['role'] === 'sso' && $admin['role'] !== 'sso') jsonError('Only SSO can change SSO.', 403);
                $newActive = $u['active'] ? 0 : 1;
                getDB()->prepare('UPDATE users SET active = ? WHERE username = ?')->execute([$newActive, $username]);
                logAudit(null, $admin['username'], $admin['role'], $newActive ? 'ACTIVATE_USER' : 'DEACTIVATE_USER', $username);
                jsonResponse(['ok' => true, 'active' => (bool) $newActive]);
            }
            if ($op === 'reset-password') {
                $d = readJsonBody();
                $password = (string) ($d['password'] ?? '');
                if (strlen($password) < 8) jsonError('Password must be at least 8 chars.');
                $hash = password_hash($password, PASSWORD_DEFAULT);
                getDB()->prepare('UPDATE users SET password_hash = ? WHERE username = ?')->execute([$hash, $username]);
                logAudit(null, $admin['username'], $admin['role'], 'RESET_PASSWORD', $username);
                jsonResponse(['ok' => true]);
            }
            jsonError('Unknown op', 400);

        case 'requests':
            if ($method === 'GET') {
                $user = requireUser();
                $wf = buildWorkflow();
                $rows = getDB()->query('SELECT * FROM requests ORDER BY updated_at DESC')->fetchAll();
                if ($user['role'] !== 'sso') {
                    $rows = array_values(array_filter($rows, fn($r) => userInvolvedIn($r, $user, $wf)));
                }
                $status = trim((string) ($_GET['status'] ?? ''));
                $stage  = trim((string) ($_GET['stage'] ?? ''));
                $q      = trim((string) ($_GET['q'] ?? ''));
                $from   = trim((string) ($_GET['from'] ?? ''));
                $to     = trim((string) ($_GET['to'] ?? ''));
                if ($status !== '') $rows = array_filter($rows, fn($r) => $r['status'] === $status);
                if ($stage !== '')  $rows = array_filter($rows, fn($r) => $r['current_step'] === $stage);
                if ($q !== '')      $rows = array_filter($rows, fn($r) => stripos($r['id'].' '.$r['title'].' '.$r['initiated_by'], $q) !== false);
                if ($from !== '')   $rows = array_filter($rows, fn($r) => substr($r['updated_at'],0,10) >= $from);
                if ($to !== '')     $rows = array_filter($rows, fn($r) => substr($r['updated_at'],0,10) <= $to);
                $rows = array_values($rows);
                $total = count($rows);
                $limit = max(1, min((int) ($_GET['limit'] ?? 10), 100));
                $offset = max(0, (int) ($_GET['offset'] ?? 0));
                $paged = array_slice($rows, $offset, $limit);
                jsonResponse([
                    'requests' => array_map(fn($r) => formatSummary($r, $wf), $paged),
                    'total'    => $total, 'limit' => $limit, 'offset' => $offset,
                ]);
            }
            if ($method === 'POST') {
                $user = requireUser();
                $d = readJsonBody();
                $title = trim((string) ($d['title'] ?? ''));
                $formData = $d['formData'] ?? [];
                if (!is_array($formData)) $formData = [];
                if ($title === '') jsonError('Title is required.');
                $id = 'MW-' . date('Y') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
                $stmt = getDB()->prepare('INSERT INTO requests (id, title, initiated_by, current_step, status, form_data, stage_status, assignments) VALUES (?,?,?,?,?,?,?,?)');
                $stmt->execute([$id, $title, $user['username'], 'part1', 'In Progress', json_encode($formData), json_encode(new stdClass()), json_encode(new stdClass())]);
                logAudit($id, $user['username'], $user['role'], 'CREATE_REQUEST', $title);
                jsonResponse(['id' => $id], 201);
            }
            jsonError('Method not allowed', 405);

        case 'request':
            $id = (string) ($_GET['id'] ?? '');
            $row = loadRequestOrFail($id);
            $wf = buildWorkflow();
            $req = formatFull($row, $wf);

            if ($method === 'GET') {
                requireUser();
                $hs = getDB()->prepare('SELECT username, action, part, reason, timestamp FROM request_history WHERE request_id = ? ORDER BY timestamp ASC');
                $hs->execute([$id]);
                $req['history'] = array_map(static function ($h) {
                    return [
                        'user' => $h['username'], 'action' => $h['action'],
                        'part' => $h['part'], 'reason' => $h['reason'],
                        'timestamp' => iso($h['timestamp']),
                    ];
                }, $hs->fetchAll());
                jsonResponse(['request' => $req]);
            }

            $user = requireUser();
            $op = $_GET['op'] ?? '';
            $d = readJsonBody();
            $db = getDB();

            /* --- Draft --- */
            if ($op === 'draft') {
                if (!canActOnRequest($req, $user, $wf)) jsonError('Not assigned to this stage.', 403);
                $formData = $d['formData'] ?? $req['formData'];
                autofillOptionalFields($formData, stagePartOf($wf, $req['currentStep']));
                $db->beginTransaction();
                try {
                    $db->prepare('UPDATE requests SET form_data = ?, updated_at = NOW() WHERE id = ?')->execute([json_encode($formData), $id]);
                    addHistory($id, $user['username'], 'SAVE_DRAFT', $req['currentStep']);
                    $db->commit();
                } catch (Throwable $e) { $db->rollBack(); throw $e; }
                logAudit($id, $user['username'], $user['role'], 'SAVE_DRAFT', 'Draft saved');
                jsonResponse(['ok' => true]);
            }

            /* --- Submit --- */
            if ($op === 'submit') {
                if (!canActOnRequest($req, $user, $wf)) jsonError('Not assigned to this stage.', 403);
                $formData = $d['formData'] ?? $req['formData'];

                // 1) Auto-fill optional/computed fields
                autofillOptionalFields($formData, stagePartOf($wf, $req['currentStep']));

                // 2) Validate required
                $missing = validateRequiredFields($formData, $req['currentStep'], $wf);
                if ($missing) {
                    $labels = array_map('fieldLabel', $missing);
                    jsonError('Missing required fields: ' . implode(' | ', $labels), 422);
                }

                $oldStep = $req['currentStep'];
                $next    = $wf[$oldStep]['next'] ?? 'completed';
                $stageStatus = $req['stageStatus'];
                $stageStatus[$oldStep] = [
                    'status'      => 'Submitted',
                    'submittedBy' => $user['username'],
                    'submittedAt' => date('c'),
                    'snapshot'    => $formData,
                ];
                $newStatus = $next === 'completed' ? 'Completed' : 'In Progress';
                $autoReject = false;
                $rejReason = null;

                // Auto-reject if Part-IV says "not approved"
                if ($oldStep === 'part4') {
                    $apr = strtolower(trim((string) ($formData['p4-approval'] ?? '')));
                    $notWords = ['not', 'reject', 'denied', 'disapprov', 'नहीं', 'نہیں'];
                    foreach ($notWords as $w) if (str_contains($apr, $w)) { $autoReject = true; break; }
                    if ($autoReject) {
                        $next = 'completed';
                        $newStatus = 'Rejected';
                        $rejReason = 'Auto-rejected: Part-IV marked Not approved by ' . $user['username'];
                    }
                }

                $db->beginTransaction();
                try {
                    if ($autoReject) {
                        $db->prepare('UPDATE requests SET form_data = ?, stage_status = ?, current_step = ?, status = ?, rejection_reason = ?, updated_at = NOW() WHERE id = ?')
                           ->execute([json_encode($formData), json_encode($stageStatus), $next, $newStatus, $rejReason, $id]);
                        addHistory($id, $user['username'], 'AUTO_REJECT', $oldStep, $rejReason);
                    } else {
                        $db->prepare('UPDATE requests SET form_data = ?, stage_status = ?, current_step = ?, status = ?, returned_to = NULL, updated_at = NOW() WHERE id = ?')
                           ->execute([json_encode($formData), json_encode($stageStatus), $next, $newStatus, $id]);
                        addHistory($id, $user['username'], 'SUBMIT', $oldStep);
                    }
                    $db->commit();
                } catch (Throwable $e) { $db->rollBack(); throw $e; }
                logAudit($id, $user['username'], $user['role'], $autoReject ? 'AUTO_REJECT' : 'SUBMIT', $autoReject ? $rejReason : "$oldStep → $next");
                jsonResponse(['ok' => true, 'autoRejected' => $autoReject]);
            }

            /* --- Return --- */
            if ($op === 'return') {
                if (!canActOnRequest($req, $user, $wf)) jsonError('Permission denied', 403);
                $reason = trim((string) ($d['reason'] ?? ''));
                if ($reason === '') jsonError('Reason required.');
                $db->beginTransaction();
                try {
                    $db->prepare("UPDATE requests SET status='Returned', returned_to=?, returned_from=?, return_reason=?, updated_at=NOW() WHERE id=?")
                       ->execute([$req['initiatedBy'], $req['currentStep'], $reason, $id]);
                    addHistory($id, $user['username'], 'RETURN', $req['currentStep'], $reason);
                    $db->commit();
                } catch (Throwable $e) { $db->rollBack(); throw $e; }
                logAudit($id, $user['username'], $user['role'], 'RETURN', $reason);
                jsonResponse(['ok' => true]);
            }

            /* --- Reject --- */
            if ($op === 'reject') {
                if (!in_array($user['role'], ['sso', 'admin'], true)) jsonError('Permission denied', 403);
                $reason = trim((string) ($d['reason'] ?? ''));
                if ($reason === '') jsonError('Reason required.');
                $db->beginTransaction();
                try {
                    $db->prepare("UPDATE requests SET status='Rejected', rejection_reason=?, updated_at=NOW() WHERE id=?")
                       ->execute([$reason, $id]);
                    addHistory($id, $user['username'], 'REJECT', $req['currentStep'], $reason);
                    $db->commit();
                } catch (Throwable $e) { $db->rollBack(); throw $e; }
                logAudit($id, $user['username'], $user['role'], 'REJECT', $reason);
                jsonResponse(['ok' => true]);
            }

            jsonError('Unknown op', 400);

        /* --- Media --- */
        case 'request-media':
            if ($method === 'POST') {
                $user = requireUser();
                $id = (string) ($_GET['id'] ?? '');
                $row = loadRequestOrFail($id);
                $wf = buildWorkflow();
                $canAccess = $row['initiated_by'] === $user['username']
                    || in_array($user['role'], ['sso','admin'], true)
                    || canActOnRequest(['status'=>$row['status'],'currentStep'=>$row['current_step'],'assignments'=>json_decode($row['assignments'],true)?:[]], $user, $wf);
                if (!$canAccess) jsonError('Not allowed to attach media.', 403);
                if (empty($_FILES['file'])) jsonError('No file uploaded.');
                $f = $_FILES['file'];
                if ($f['error'] !== UPLOAD_ERR_OK) jsonError('Upload error code ' . $f['error']);
                $size = (int) $f['size'];
                $mime = mime_content_type($f['tmp_name']) ?: 'application/octet-stream';
                $isImage = str_starts_with($mime, 'image/');
                $isVideo = str_starts_with($mime, 'video/');
                if (!$isImage && !$isVideo) jsonError('Only images/videos allowed.');
                if ($isImage && $size > MAX_IMAGE_BYTES) jsonError('Image must be ≤ 1 MB.');
                if ($isVideo && $size > MAX_VIDEO_BYTES) jsonError('Video must be ≤ 2 MB.');
                $counts = getDB()->prepare('SELECT media_type, COUNT(*) c FROM request_media WHERE request_id=? GROUP BY media_type');
                $counts->execute([$id]);
                $c = ['image'=>0,'video'=>0];
                foreach ($counts->fetchAll() as $r) $c[$r['media_type']] = (int) $r['c'];
                if ($isImage && $c['image'] >= MAX_IMAGES_PER_REQ) jsonError('Max ' . MAX_IMAGES_PER_REQ . ' images.');
                if ($isVideo && $c['video'] >= MAX_VIDEOS_PER_REQ) jsonError('Max ' . MAX_VIDEOS_PER_REQ . ' video.');
                if (!is_dir(UPLOAD_DIR)) @mkdir(UPLOAD_DIR, 0775, true);
                $sub = UPLOAD_DIR . '/' . $id;
                if (!is_dir($sub)) @mkdir($sub, 0775, true);
                $ext = pathinfo($f['name'], PATHINFO_EXTENSION) ?: ($isImage ? 'jpg' : 'mp4');
                $safeExt = preg_replace('/[^a-z0-9]/i', '', $ext);
                $filename = bin2hex(random_bytes(8)) . '.' . $safeExt;
                $dest = $sub . '/' . $filename;
                if (!move_uploaded_file($f['tmp_name'], $dest)) jsonError('Could not save file.');
                $rel = UPLOAD_URL_PREFIX . '/' . $id . '/' . $filename;
                $stmt = getDB()->prepare('INSERT INTO request_media (request_id, stage, media_type, file_path, original_name, mime_type, size_bytes, uploaded_by) VALUES (?,?,?,?,?,?,?,?)');
                $stmt->execute([$id, $row['current_step'], $isImage?'image':'video', $rel, basename($f['name']), $mime, $size, $user['username']]);
                logAudit($id, $user['username'], $user['role'], 'UPLOAD_MEDIA', $isImage ? 'Image' : 'Video');
                jsonResponse(['ok' => true, 'id' => (int) getDB()->lastInsertId()], 201);
            }
            if ($method === 'DELETE') {
                $user = requireUser();
                $id = (string) ($_GET['id'] ?? '');
                $mediaId = (int) ($_GET['mediaId'] ?? 0);
                $stmt = getDB()->prepare('SELECT * FROM request_media WHERE id=? AND request_id=?');
                $stmt->execute([$mediaId, $id]);
                $m = $stmt->fetch();
                if (!$m) jsonError('Media not found', 404);
                if ($m['uploaded_by'] !== $user['username'] && !in_array($user['role'], ['sso','admin'], true)) jsonError('Not allowed', 403);
                $abs = dirname(__DIR__) . '/' . ltrim($m['file_path'], '/');
                if (is_file($abs)) @unlink($abs);
                getDB()->prepare('DELETE FROM request_media WHERE id=?')->execute([$mediaId]);
                jsonResponse(['ok' => true]);
            }
            jsonError('Method not allowed', 405);

        case 'request-media-list':
            requireUser();
            $id = (string) ($_GET['id'] ?? '');
            loadRequestOrFail($id);
            jsonResponse(['media' => listMediaFor($id)]);

        case 'settings':
            if ($method === 'GET') { requireUser(); jsonResponse(['workflow' => ['order' => loadWorkflowOrder()]]); }
            $admin = requireAdmin();
            $d = readJsonBody();
            $order = $d['order'] ?? null;
            if (!is_array($order) || !$order) jsonError('Order must be non-empty array.');
            foreach ($order as $s) if (!is_string($s) || !isset(STAGE_META[$s])) jsonError('Invalid stage: ' . (string)$s);
            if (count($order) !== count(array_unique($order))) jsonError('Duplicates not allowed.');
            $json = json_encode(['order' => array_values($order)]);
            getDB()->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('workflowConfig', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$json]);
            logAudit(null, $admin['username'], $admin['role'], 'UPDATE_WORKFLOW', 'Workflow updated');
            jsonResponse(['ok' => true]);

        case 'stats':
            requireUser();
            $wf = buildWorkflow();
            $user = currentUser();
            $rows = getDB()->query('SELECT * FROM requests')->fetchAll();
            if ($user['role'] !== 'sso') {
                $rows = array_values(array_filter($rows, fn($r) => userInvolvedIn($r, $user, $wf)));
            }
            $byStatus = ['In Progress'=>0, 'Returned'=>0, 'Rejected'=>0, 'Completed'=>0];
            $totalApproved = 0; $totalSpent = 0; $totalRemaining = 0; $approvedCount = 0;
            foreach ($rows as $r) {
                $s = $r['status'] ?: 'In Progress';
                if (isset($byStatus[$s])) $byStatus[$s]++;
                $fd = json_decode($r['form_data'] ?: '{}', true) ?: [];
                if (!empty($fd['p4-rs-c'])) { $totalApproved += (float) $fd['p4-rs-c']; $approvedCount++; }
                if (!empty($fd['p4-rs-b'])) $totalSpent += (float) $fd['p4-rs-b'];
                if (!empty($fd['p4-rs-d'])) $totalRemaining += (float) $fd['p4-rs-d'];
            }
            jsonResponse([
                'total'          => count($rows),
                'byStatus'       => $byStatus,
                'totalApproved'  => $totalApproved,
                'averageApproved'=> $approvedCount ? ($totalApproved / $approvedCount) : 0,
                'totalSpent'     => $totalSpent,
                'totalRemaining' => $totalRemaining,
                'approvedCount'  => $approvedCount,
            ]);

        case 'audit':
            requireAdmin();
            $rows = getDB()->query('SELECT * FROM audit_logs ORDER BY timestamp DESC LIMIT 1000')->fetchAll();
            jsonResponse(['logs' => array_map(fn($r) => [
                'requestId'=>$r['request_id'],'user'=>$r['username'],'role'=>$r['role'],
                'action'=>$r['action'],'details'=>$r['details'],'timestamp'=>iso($r['timestamp']),
            ], $rows)]);

        case 'export':
            requireAdmin();
            $out = ['exportedAt' => date('c'), 'tables' => []];
            foreach (['users','requests','request_history','audit_logs','settings','request_media'] as $t) {
                $out['tables'][$t] = getDB()->query("SELECT * FROM `$t`")->fetchAll();
            }
            jsonResponse($out);

        default:
            jsonError('Unknown action: ' . $action, 404);
    }
} catch (PDOException $e) {
    error_log('DB: ' . $e->getMessage());
    jsonError('Database error: ' . $e->getMessage(), 500);
} catch (Throwable $e) {
    error_log('Server: ' . $e->getMessage());
    jsonError('Server error: ' . $e->getMessage(), 500);
}