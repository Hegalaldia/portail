<?php
// DEBUG TEMPORAIRE — à supprimer après diagnostic
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
// FIN DEBUG

/**
 * api/index.php — Routeur principal pour toutes les routes /api/*
 *
 * Hébergement OVH mutualisé Apache + PHP.
 * Remplace server.py (Python) — logique métier fidèlement reproduite.
 */

// ── Bootstrap ──────────────────────────────────────────────────────────────────
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/benevoles.php';
require_once __DIR__ . '/rapatriements.php';
require_once __DIR__ . '/clinics.php';
require_once __DIR__ . '/messages.php';

// ── OPTIONS (préflight CORS) ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    exit;
}

// ── Déterminer la route ────────────────────────────────────────────────────────
// L'URL réécrite est soit /api/<reste> soit /api (sans slash).
// $_SERVER['REQUEST_URI'] = /api/benevoles, /api/benevoles/photo/123, etc.
// Le .htaccess passe tout via QSA, donc la query string est dans $_SERVER['QUERY_STRING'].

$uri    = $_SERVER['REQUEST_URI'] ?? '/';
$uri    = strtok($uri, '?');                  // retirer la query string
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// Enlever le préfixe /api si présent
if (str_starts_with($uri, '/api')) {
    $uri = substr($uri, 4); // retirer '/api'
}
$uri = '/' . ltrim($uri, '/');  // normaliser : commence par '/'

// Helper : extrait les paramètres de query string
$qs = [];
parse_str($_SERVER['QUERY_STRING'] ?? '', $qs);

// ── Routing ────────────────────────────────────────────────────────────────────

try {
    switch (true) {

        // ── Données Google Sheets ─────────────────────────────────────────────
        case $uri === '/data' && $method === 'GET':
            $cache_key = 'main_csv_' . get_sheet_id();
            $csv = cache_get($cache_key);
            if ($csv === null) {
                $csv = fetch_csv_tab(get_sheet_id());
                cache_set($cache_key, $csv);
            }
            http_response_code(200);
            header('Content-Type: text/csv; charset=utf-8');
            send_cors_headers();
            echo $csv;
            exit;

        // ── Villes ───────────────────────────────────────────────────────────
        case str_starts_with($uri, '/villes') && $method === 'GET':
            $cache_key = 'villes_' . get_sheet_id();
            $cached    = cache_get($cache_key);
            if ($cached !== null) {
                $villes = json_decode($cached, true) ?? [];
            } else {
                $csv = fetch_csv_tab(get_sheet_id(), 'Ville');
                $villes = [];
                $rows = array_map('str_getcsv', explode("\n", $csv));
                if (!empty($rows)) {
                    $headers = array_shift($rows);
                    $col_idx = array_search('Ville de decouverte', $headers);
                    if ($col_idx === false) {
                        // Chercher avec accents
                        foreach ($headers as $i => $h) {
                            if (strip_accents($h) === 'ville de decouverte') { $col_idx = $i; break; }
                        }
                    }
                    if ($col_idx !== false) {
                        foreach ($rows as $row) {
                            $v = trim($row[$col_idx] ?? '');
                            if ($v !== '') $villes[$v] = true;
                        }
                        $villes = array_keys($villes);
                        sort($villes);
                    }
                }
                cache_set($cache_key, json_encode($villes, JSON_UNESCAPED_UNICODE));
            }

            $q = trim($qs['q'] ?? '');
            if ($q !== '') {
                $qn = strip_accents($q);
                $starts   = array_values(array_filter($villes, fn($v) => str_starts_with(strip_accents($v), $qn)));
                $starts   = array_slice($starts, 0, 8);
                $contains = array_values(array_filter($villes, fn($v) => str_contains(strip_accents($v), $qn) && !in_array($v, $starts, true)));
                $contains = array_slice($contains, 0, max(0, 8 - count($starts)));
                $result = array_merge($starts, $contains);
            } else {
                $result = $villes;
            }
            json_response($result);

        // ── Espèces ───────────────────────────────────────────────────────────
        case str_starts_with($uri, '/especes') && $method === 'GET':
            $cache_key = 'especes_' . get_sheet_id();
            $cached    = cache_get($cache_key);
            if ($cached !== null) {
                $especes = json_decode($cached, true) ?? [];
            } else {
                $csv = fetch_csv_tab(get_sheet_id(), 'Divers');
                $especes = [];
                foreach (array_map('str_getcsv', explode("\n", $csv)) as $row) {
                    $v = trim($row[0] ?? '');
                    if ($v !== '') $especes[$v] = true;
                }
                $especes = array_keys($especes);
                sort($especes);
                cache_set($cache_key, json_encode($especes, JSON_UNESCAPED_UNICODE));
            }

            $q = trim($qs['q'] ?? '');
            if ($q !== '') {
                $qn = strip_accents($q);
                $starts   = array_slice(array_values(array_filter($especes, fn($e) => str_starts_with(strip_accents($e), $qn))), 0, 10);
                $contains = array_slice(array_values(array_filter($especes, fn($e) => str_contains(strip_accents($e), $qn) && !in_array($e, $starts, true))), 0, max(0, 10 - count($starts)));
                $result = array_merge($starts, $contains);
            } else {
                $result = $especes;
            }
            json_response($result);

        // ── Vétérinaires ──────────────────────────────────────────────────────
        case $uri === '/vetos' && $method === 'GET':
            route_vetos_get();

        // ── Rapatriements en-route ────────────────────────────────────────────
        case $uri === '/rapatriements/en-route' && $method === 'GET':
            route_rapatriements_en_route_get();

        // ── Rapatriements add/delete/confirm/update ───────────────────────────
        case $uri === '/rapatriements/add' && $method === 'POST':
            route_rapatriements_add_post();

        case $uri === '/rapatriements/delete' && $method === 'POST':
            route_rapatriements_delete_post();

        case $uri === '/rapatriements/confirm' && $method === 'POST':
            route_rapatriements_confirm_post();

        case $uri === '/rapatriements/update' && $method === 'POST':
            route_rapatriements_update_post();

        // ── Messages ──────────────────────────────────────────────────────────
        case $uri === '/messages' && $method === 'GET':
            route_messages_get();

        case $uri === '/messages' && $method === 'POST':
            route_messages_post();

        case $uri === '/messages/read' && $method === 'POST':
            route_messages_read_post();

        case $uri === '/messages/unread' && $method === 'POST':
            route_messages_unread_post();

        case $uri === '/messages/pin' && $method === 'POST':
            route_messages_pin_post();

        case $uri === '/messages/archive' && $method === 'POST':
            route_messages_archive_post();

        // ── Historique cliniques ──────────────────────────────────────────────
        case $uri === '/history' && $method === 'GET':
            route_history_get();

        // ── Stats ─────────────────────────────────────────────────────────────
        case str_starts_with($uri, '/stats') && $method === 'GET':
            $stats_code = $qs['code'] ?? null;
            route_stats_get($stats_code);

        // ── Clinic stats ──────────────────────────────────────────────────────
        case $uri === '/clinic-stats' && $method === 'GET':
            route_clinic_stats_get();

        case $uri === '/clinic-stats' && $method === 'POST':
            route_clinic_stats_post();

        case $uri === '/clinic-stats/delete-item' && $method === 'POST':
            route_clinic_stats_delete_item_post();

        // ── Clinics prefs ─────────────────────────────────────────────────────
        case $uri === '/clinics/prefs' && $method === 'GET':
            route_clinic_prefs_get();

        case $uri === '/clinics/prefs' && $method === 'POST':
            route_clinic_prefs_post();

        // ── Clinics codes ─────────────────────────────────────────────────────
        case $uri === '/clinics/codes' && $method === 'GET':
            route_clinic_codes_get();

        case $uri === '/clinics/regenerate-code' && $method === 'POST':
            route_clinic_regenerate_code_post();

        case $uri === '/clinics/verify-code' && $method === 'POST':
            route_clinic_verify_code_post();

        // ── Bénévoles ─────────────────────────────────────────────────────────
        case $uri === '/benevoles' && $method === 'GET':
            route_benevoles_get();

        case $uri === '/benevoles/drafts' && $method === 'GET':
            route_benevoles_drafts_get();

        case $uri === '/benevoles/drafts' && $method === 'POST':
            route_benevoles_drafts_post();

        case preg_match('#^/benevoles/drafts/([^/]+)$#', $uri, $m) && $method === 'DELETE':
            route_benevoles_drafts_delete($m[1]);

        case $uri === '/benevoles/add' && $method === 'POST':
            route_benevoles_add_post();

        case $uri === '/benevoles/update' && $method === 'POST':
            route_benevoles_update_post();

        case $uri === '/benevoles/delete' && $method === 'POST':
            route_benevoles_delete_post();

        case $uri === '/benevoles/reimport-xls' && $method === 'POST':
            route_benevoles_reimport_post();

        case $uri === '/benevoles/clear-rapatriements' && $method === 'POST':
            route_clear_rapatriements_post();

        // ── Export XLS/CSV ────────────────────────────────────────────────────
        case str_starts_with($uri, '/benevoles/export-xls') && $method === 'GET':
            $year = isset($qs['year']) ? (int)$qs['year'] : null;
            route_benevoles_export_get($year);

        case $uri === '/benevoles/export-xls' && $method === 'POST':
            route_benevoles_export_post();

        // ── Photos ────────────────────────────────────────────────────────────
        case preg_match('#^/benevoles/photo/([^/?]+)$#', $uri, $m) && $method === 'GET':
            route_benevoles_photo_get($m[1]);

        case preg_match('#^/benevoles/photo/([^/?]+)$#', $uri, $m) && $method === 'POST':
            route_benevoles_photo_post($m[1]);

        case preg_match('#^/benevoles/photo/([^/?]+)$#', $uri, $m) && $method === 'DELETE':
            route_benevoles_photo_delete($m[1]);

        // ── Centres de soins ──────────────────────────────────────────────────
        case $uri === '/centres_soins' && $method === 'GET':
            $data = json_read(BASE_DIR . '/centres_soins.json', []);
            json_response($data);

        // ── Geocache ──────────────────────────────────────────────────────────
        // En PHP on modifie heatmap.html comme en Python
        case $uri === '/geocache' && $method === 'POST':
            $body  = read_json_body();
            $ville = trim((string)($body['ville'] ?? ''));
            $lat   = (float)($body['lat'] ?? 0);
            $lon   = (float)($body['lon'] ?? 0);
            if ($ville === '' || $lat === 0.0 || $lon === 0.0) {
                json_response(['ok' => false, 'msg' => 'Données invalides'], 400);
            }
            [$ok, $msg] = add_to_geo_preloaded($ville, $lat, $lon);
            json_response(['ok' => $ok, 'msg' => $msg], $ok ? 200 : 400);

        // ── Config ────────────────────────────────────────────────────────────
        case $uri === '/config' && $method === 'GET':
            json_response(['sheet_id' => get_sheet_id()]);

        case $uri === '/config' && $method === 'POST':
            $body   = read_json_body();
            $new_id = trim((string)($body['sheet_id'] ?? ''));
            if ($new_id === '') {
                json_response(['ok' => false, 'error' => 'sheet_id manquant'], 400);
            }
            $cfg = load_config();
            $cfg['sheet_id'] = $new_id;
            // Invalider les caches
            invalidate_sheet_caches();
            save_config($cfg);
            json_response(['ok' => true, 'sheet_id' => $new_id]);

        // ── Version ───────────────────────────────────────────────────────────
        case $uri === '/version' && $method === 'GET':
            json_response(get_version_info());

        // ── Auth portail ──────────────────────────────────────────────────────
        case $uri === '/auth' && $method === 'POST':
            echo "ROUTE_REACHED\n";
            route_auth_post();

        case $uri === '/auth/verify' && $method === 'POST':
            route_auth_verify_post();

        // ── 404 ───────────────────────────────────────────────────────────────
        default:
            json_response(['error' => 'Route non trouvée', 'uri' => $uri, 'method' => $method], 404);
    }
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => $e->getMessage()], 500);
}

// ── Fonctions auxiliaires inline ──────────────────────────────────────────────

function add_to_geo_preloaded(string $ville, float $lat, float $lon): array
{
    $html_file = BASE_DIR . '/heatmap.html';
    if (!file_exists($html_file)) {
        return [false, 'heatmap.html introuvable'];
    }

    // Verrou exclusif sur le fichier HTML
    $fp = fopen($html_file, 'r+');
    if (!$fp) return [false, 'impossible d\'ouvrir heatmap.html'];
    flock($fp, LOCK_EX);

    $html = stream_get_contents($fp);
    if (preg_match('/const GEO_PRELOADED = (\{.*?\});/s', $html, $m, PREG_OFFSET_CAPTURE)) {
        $geo = json_decode($m[1][0], true) ?? [];
        if (isset($geo[$ville])) {
            flock($fp, LOCK_UN);
            fclose($fp);
            return [true, 'déjà présent'];
        }
        $geo[$ville] = ['lat' => $lat, 'lon' => $lon];
        $new_json = json_encode($geo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $start    = $m[1][1];
        $end      = $start + strlen($m[1][0]);
        $new_html = substr($html, 0, $start) . $new_json . substr($html, $end);
        fseek($fp, 0);
        ftruncate($fp, 0);
        fwrite($fp, $new_html);
        flock($fp, LOCK_UN);
        fclose($fp);
        return [true, 'ajouté'];
    }
    flock($fp, LOCK_UN);
    fclose($fp);
    return [false, 'GEO_PRELOADED introuvable dans le HTML'];
}

function invalidate_sheet_caches(): void
{
    $sheet_id = get_sheet_id();
    $patterns = [
        BASE_DIR . '/api/.cache_main_csv_*.json',
        BASE_DIR . '/api/.cache_villes_*.json',
        BASE_DIR . '/api/.cache_especes_*.json',
        BASE_DIR . '/api/.cache_vetos_*.json',
    ];
    foreach ($patterns as $pat) {
        foreach (glob($pat) ?: [] as $f) {
            @unlink($f);
        }
    }
}

function get_version_info(): array
{
    // Essayer git
    $cwd = BASE_DIR;
    $commit = @shell_exec("cd " . escapeshellarg($cwd) . " && git rev-parse --short HEAD 2>/dev/null");
    $date   = @shell_exec("cd " . escapeshellarg($cwd) . " && git log -1 --format=%cd --date=format:'%d/%m/%Y %H:%M' 2>/dev/null");
    $commit = $commit ? trim($commit) : 'unknown';
    $date   = $date   ? trim($date)   : '';
    $version = $commit !== 'unknown' ? "$commit — $date" : 'unknown';
    return ['commit' => $commit, 'date' => $date, 'version' => $version];
}
