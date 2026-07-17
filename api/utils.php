<?php
/**
 * utils.php — Fonctions utilitaires partagees
 * Lecture/ecriture JSON avec verrou fichier, sanitisation, backup automatique.
 */

define('BASE_DIR', dirname(__DIR__));
define('BACKUP_DIR', BASE_DIR . '/backups');
define('TOKEN_TTL', 30 * 24 * 3600);   // 30 jours (server.py = 7j, consigne = 30j)
define('MAX_LOGIN_ATTEMPTS', 5);
define('BLOCK_DURATION', 15 * 60);     // 15 min en secondes
define('CACHE_TTL', 300);              // 5 min

// ── Lecture JSON avec verrou partagé ──────────────────────────────────────────
function json_read(string $path, $default = [])
{
    if (!file_exists($path)) {
        return $default;
    }
    $fp = fopen($path, 'r');
    if (!$fp) return $default;
    flock($fp, LOCK_SH);
    $content = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    if ($content === '' || $content === false) return $default;
    $data = json_decode($content, true);
    return ($data !== null) ? $data : $default;
}

// ── Ecriture JSON avec verrou exclusif ───────────────────────────────────────
function json_write(string $path, $data): bool
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $tmp = $path . '.tmp.' . getmypid();
    $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($encoded === false) return false;
    if (file_put_contents($tmp, $encoded, LOCK_EX) === false) return false;
    return rename($tmp, $path);
}

// ── Sanitisation XSS (equivalent de _sanitize() Python) ─────────────────────
function sanitize(string $s, int $max_len = 2000): string
{
    return htmlspecialchars(mb_substr($s, 0, $max_len), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ── CORS headers ─────────────────────────────────────────────────────────────
function send_cors_headers(): void
{
    $cfg = load_config();
    $origin = $cfg['allowed_origin'] ?? '*';
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
}

// ── Réponse JSON ─────────────────────────────────────────────────────────────
function json_response($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    send_cors_headers();
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Lire le body POST JSON ────────────────────────────────────────────────────
function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

// ── Config ────────────────────────────────────────────────────────────────────
function load_config(): array
{
    return json_read(BASE_DIR . '/config.json', []);
}

function save_config(array $cfg): bool
{
    return json_write(BASE_DIR . '/config.json', $cfg);
}

function get_sheet_id(): string
{
    $cfg = load_config();
    return $cfg['sheet_id'] ?? '1VpN669xp2u34Itapwz5UWuEIi2UmoxTTWfrPQP83rhE';
}

// ── Télécharger un onglet CSV depuis Google Sheets ────────────────────────────
function fetch_csv_tab(string $sheet_id, ?string $sheet_name = null): string
{
    if ($sheet_name !== null) {
        $url = 'https://docs.google.com/spreadsheets/d/' . $sheet_id
             . '/gviz/tq?tqx=out:csv&sheet=' . rawurlencode($sheet_name);
    } else {
        $url = 'https://docs.google.com/spreadsheets/d/' . $sheet_id . '/export?format=csv';
    }

    $ctx = stream_context_create([
        'http' => [
            'header'  => 'User-Agent: heatmap-server/1.0-php',
            'timeout' => 15,
        ],
        'ssl' => ['verify_peer' => true],
    ]);
    $data = @file_get_contents($url, false, $ctx);
    if ($data === false) {
        // Fallback avec curl
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_USERAGENT      => 'heatmap-server/1.0-php',
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $data = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($data === false) {
            throw new RuntimeException("Impossible de télécharger le CSV Google Sheets : $err");
        }
    }
    return $data;
}

// ── Cache fichier simple (TTL 5 min) ─────────────────────────────────────────
function cache_get(string $key): ?string
{
    $path = BASE_DIR . '/api/.cache_' . preg_replace('/[^a-z0-9_]/', '_', $key) . '.json';
    if (!file_exists($path)) return null;
    $data = json_read($path);
    if (!isset($data['ts']) || (time() - $data['ts']) > CACHE_TTL) return null;
    return $data['content'] ?? null;
}

function cache_set(string $key, string $content): void
{
    $path = BASE_DIR . '/api/.cache_' . preg_replace('/[^a-z0-9_]/', '_', $key) . '.json';
    json_write($path, ['ts' => time(), 'content' => $content]);
}

// ── Supprimer les accents (équivalent strip_accents Python) ──────────────────
function strip_accents(string $s): string
{
    $s = mb_strtolower($s, 'UTF-8');
    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    return $s;
}

// ── Backup automatique journalier ─────────────────────────────────────────────
// En PHP pas de thread, on vérifie à chaque requête POST importante
// si le backup du jour existe déjà. Si non, on le crée.
function maybe_run_backup(): void
{
    $backup_flag = BASE_DIR . '/backup_last.json';
    $today = date('Y-m-d');

    $flag = json_read($backup_flag, ['date' => '']);
    if (($flag['date'] ?? '') === $today) {
        return; // Backup déjà fait aujourd'hui
    }

    // Créer le backup
    if (!is_dir(BACKUP_DIR)) {
        mkdir(BACKUP_DIR, 0755, true);
    }

    $files = [
        'clinic_stats'   => BASE_DIR . '/clinic_stats.json',
        'clinic_history' => BASE_DIR . '/clinic_history.json',
        'messages'       => BASE_DIR . '/messages.json',
        'benevoles'      => BASE_DIR . '/benevoles.json',
        'config'         => BASE_DIR . '/config.json',
    ];

    $count = 0;
    foreach ($files as $name => $src) {
        if (file_exists($src)) {
            $dest = BACKUP_DIR . "/{$today}_{$name}.json";
            if (copy($src, $dest)) {
                $count++;
            }
        }
    }

    // Garder seulement les 30 derniers jours
    $all = glob(BACKUP_DIR . '/*.json');
    if ($all) {
        $days = [];
        foreach ($all as $f) {
            $base = basename($f);
            $day  = substr($base, 0, 10);
            $days[$day][] = $f;
        }
        krsort($days); // tri décroissant
        $kept = 0;
        foreach ($days as $day => $dfiles) {
            $kept++;
            if ($kept > 30) {
                foreach ($dfiles as $df) {
                    @unlink($df);
                }
            }
        }
    }

    // Marquer le backup comme fait
    json_write($backup_flag, ['date' => $today, 'count' => $count]);
}

// ── Effacement hebdomadaire des sorties cliniques ─────────────────────────────
// En PHP on ne peut pas faire de thread, on s'appuie sur un flag
// pour vérifier si l'effacement dominical doit être effectué.
function maybe_clear_sorties(): void
{
    $flag_path = BASE_DIR . '/api/.sorties_cleared.json';
    $flag = json_read($flag_path, ['week' => '']);
    $current_week = date('Y-W');

    // Vérifier si on est dimanche (0) et semaine non encore effacée
    if (date('w') != 0 || ($flag['week'] ?? '') === $current_week) {
        return;
    }

    $stats = json_read(BASE_DIR . '/clinic_stats.json', []);
    foreach ($stats as $code => &$entry) {
        $entry['outcomesList'] = [];
        $entry['deces'] = 0;
    }
    unset($entry);
    json_write(BASE_DIR . '/clinic_stats.json', $stats);
    json_write($flag_path, ['week' => $current_week, 'cleared_at' => date('Y-m-d H:i:s')]);
}
