<?php
/**
 * auth.php — Gestion authentification portail + codes cliniques
 *
 * Tokens persistants dans .auth_tokens.json (TTL 30 jours)
 * Rate limiting anti-bruteforce dans failed_logins.json
 */

require_once __DIR__ . '/utils.php';

define('TOKENS_FILE',       BASE_DIR . '/.auth_tokens.json');
define('FAILED_LOGINS_FILE', BASE_DIR . '/failed_logins.json');

// ── Tokens ────────────────────────────────────────────────────────────────────

function load_tokens(): array
{
    return json_read(TOKENS_FILE, []);
}

function save_tokens(array $tokens): void
{
    json_write(TOKENS_FILE, $tokens);
}

function add_token(string $token): void
{
    $tokens = load_tokens();
    $tokens[$token] = time() + TOKEN_TTL;
    save_tokens($tokens);
}

function check_token(string $token): bool
{
    if ($token === '') return false;
    $tokens = load_tokens();
    if (!isset($tokens[$token])) return false;
    if (time() > $tokens[$token]) {
        // Token expiré : supprimer
        unset($tokens[$token]);
        save_tokens($tokens);
        return false;
    }
    return true;
}

function purge_expired_tokens(): void
{
    $tokens = load_tokens();
    $now = time();
    $changed = false;
    foreach ($tokens as $t => $exp) {
        if ($now > $exp) {
            unset($tokens[$t]);
            $changed = true;
        }
    }
    if ($changed) save_tokens($tokens);
}

// ── Rate limiting ─────────────────────────────────────────────────────────────

function load_failed_logins(): array
{
    return json_read(FAILED_LOGINS_FILE, []);
}

function save_failed_logins(array $data): void
{
    json_write(FAILED_LOGINS_FILE, $data);
}

/**
 * Retourne [allowed: bool, retry_after: int]
 */
function check_rate_limit(string $ip): array
{
    // Jamais bloquer le réseau local
    if (in_array($ip, ['127.0.0.1', '::1', 'localhost'], true)) {
        return [true, 0];
    }
    $data  = load_failed_logins();
    $entry = $data[$ip] ?? ['count' => 0, 'blocked_until' => 0];
    $now   = time();
    if ($now < ($entry['blocked_until'] ?? 0)) {
        return [false, (int)($entry['blocked_until'] - $now)];
    }
    return [true, 0];
}

function record_login_failure(string $ip): void
{
    if (in_array($ip, ['127.0.0.1', '::1', 'localhost'], true)) return;
    $data  = load_failed_logins();
    $entry = $data[$ip] ?? ['count' => 0, 'blocked_until' => 0];
    $entry['count']++;
    if ($entry['count'] >= MAX_LOGIN_ATTEMPTS) {
        $entry['blocked_until'] = time() + BLOCK_DURATION;
        $entry['count'] = 0;
    }
    $data[$ip] = $entry;
    save_failed_logins($data);
}

function record_login_success(string $ip): void
{
    $data = load_failed_logins();
    if (isset($data[$ip])) {
        unset($data[$ip]);
        save_failed_logins($data);
    }
}

function get_remaining_attempts(string $ip): int
{
    $data  = load_failed_logins();
    $entry = $data[$ip] ?? ['count' => 0];
    return max(0, MAX_LOGIN_ATTEMPTS - (int)($entry['count'] ?? 0));
}

// ── Mot de passe portail ──────────────────────────────────────────────────────

function check_portal_password(string $pwd): bool
{
    $cfg = load_config();
    $expected = $cfg['portal_password'] ?? 'Hegalaldia2026';
    return $pwd === $expected;
}

// ── Vérification code clinique ────────────────────────────────────────────────

function verify_clinic_code(string $code): array
{
    if ($code === '') return ['type' => null, 'nom' => ''];

    $cfg        = load_config();
    $admin_code = $cfg['admin_code'] ?? '';

    if ($admin_code !== '' && strtoupper($code) === strtoupper($admin_code)) {
        return ['type' => 'admin', 'nom' => ''];
    }

    $codes   = json_read(BASE_DIR . '/clinic_codes.json', []); // {nom => code}
    $reverse = [];
    foreach ($codes as $nom => $c) {
        $reverse[strtoupper($c)] = $nom;
    }

    $nom = $reverse[strtoupper($code)] ?? null;
    if ($nom !== null) {
        return ['type' => 'clinic', 'nom' => $nom];
    }

    return ['type' => null, 'nom' => ''];
}

// ── Obtenir l'IP client ───────────────────────────────────────────────────────

function get_client_ip(): string
{
    // Derriere un reverse proxy OVH
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

// ── Routes auth ───────────────────────────────────────────────────────────────

function route_auth_post(): void
{
    $ip = get_client_ip();
    [$allowed, $retry_after] = check_rate_limit($ip);
    if (!$allowed) {
        json_response(['ok' => false, 'error' => 'too_many_attempts', 'retry_after' => $retry_after], 429);
    }

    $body = read_json_body();
    $pwd  = (string)($body['password'] ?? '');

    if (check_portal_password($pwd)) {
        $token = bin2hex(random_bytes(32));
        add_token($token);
        record_login_success($ip);
        json_response(['ok' => true, 'token' => $token]);
    } else {
        record_login_failure($ip);
        $remaining = get_remaining_attempts($ip);
        json_response(['ok' => false, 'remaining' => $remaining], 401);
    }
}

function route_auth_verify_post(): void
{
    $body  = read_json_body();
    $token = (string)($body['token'] ?? '');
    $ok    = check_token($token);
    json_response(['ok' => $ok]);
}

function route_clinic_verify_code_post(): void
{
    $ip = get_client_ip();
    [$allowed, $retry_after] = check_rate_limit($ip);
    if (!$allowed) {
        json_response(['ok' => false, 'error' => 'too_many_attempts', 'retry_after' => $retry_after], 429);
    }

    $body   = read_json_body();
    $code   = trim((string)($body['code'] ?? ''));
    $result = verify_clinic_code($code);

    if ($result['type'] !== null) {
        record_login_success($ip);
        json_response(['ok' => true, 'type' => $result['type'], 'nom' => $result['nom']]);
    } else {
        record_login_failure($ip);
        $remaining = get_remaining_attempts($ip);
        json_response(['ok' => false, 'remaining' => $remaining], 401);
    }
}
