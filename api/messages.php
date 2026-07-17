<?php
/**
 * messages.php — Gestion des messages inter-cliniques
 */

require_once __DIR__ . '/utils.php';

define('MESSAGES_FILE', BASE_DIR . '/messages.json');

function load_messages(): array
{
    return json_read(MESSAGES_FILE, []);
}

function save_messages(array $msgs): void
{
    json_write(MESSAGES_FILE, $msgs);
}

// ── Routes ────────────────────────────────────────────────────────────────────

function route_messages_get(): void
{
    json_response(load_messages());
}

function route_messages_post(): void
{
    $body = read_json_body();
    $text = trim(sanitize((string)($body['text'] ?? ''), 2000));
    if ($text === '') {
        json_response(['ok' => false, 'error' => 'Message vide'], 400);
    }

    $msg = [
        'id'          => bin2hex(random_bytes(8)),
        'clinic_code' => sanitize((string)($body['clinic_code'] ?? ''), 20),
        'clinic_name' => sanitize((string)($body['clinic_name'] ?? ''), 100),
        'text'        => $text,
        'timestamp'   => sanitize((string)($body['timestamp'] ?? ''), 50),
        'read'        => false,
    ];

    $msgs = load_messages();
    array_unshift($msgs, $msg);
    save_messages($msgs);
    json_response(['ok' => true]);
}

function route_messages_read_post(): void
{
    $body   = read_json_body();
    $msg_id = (string)($body['id'] ?? '');
    $msgs   = load_messages();
    foreach ($msgs as &$m) {
        if (($m['id'] ?? '') === $msg_id) {
            $m['read'] = true;
            break;
        }
    }
    unset($m);
    save_messages($msgs);
    json_response(['ok' => true]);
}

function route_messages_unread_post(): void
{
    $body   = read_json_body();
    $msg_id = (string)($body['id'] ?? '');
    $msgs   = load_messages();
    foreach ($msgs as &$m) {
        if (($m['id'] ?? '') === $msg_id) {
            $m['read'] = false;
            break;
        }
    }
    unset($m);
    save_messages($msgs);
    json_response(['ok' => true]);
}

function route_messages_pin_post(): void
{
    $body   = read_json_body();
    $msg_id = (string)($body['id'] ?? '');
    $pinned = (bool)($body['pinned'] ?? true);
    $msgs   = load_messages();
    foreach ($msgs as &$m) {
        if (($m['id'] ?? '') === $msg_id) {
            $m['pinned'] = $pinned;
            break;
        }
    }
    unset($m);
    save_messages($msgs);
    json_response(['ok' => true]);
}

function route_messages_archive_post(): void
{
    $body   = read_json_body();
    $msg_id = (string)($body['id'] ?? '');
    $msgs   = load_messages();
    $msgs   = array_values(array_filter($msgs, fn($m) => ($m['id'] ?? '') !== $msg_id));
    save_messages($msgs);
    json_response(['ok' => true]);
}
