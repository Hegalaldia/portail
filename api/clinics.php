<?php
/**
 * clinics.php — Statistiques, historique, préférences et codes des cliniques
 */

require_once __DIR__ . '/utils.php';

define('CLINIC_STATS_FILE',   BASE_DIR . '/clinic_stats.json');
define('CLINIC_HISTORY_FILE', BASE_DIR . '/clinic_history.json');
define('CLINIC_PREFS_FILE',   BASE_DIR . '/clinic_prefs.json');
define('CLINIC_CODES_FILE',   BASE_DIR . '/clinic_codes.json');

// ── Clinic stats ──────────────────────────────────────────────────────────────

function load_clinic_stats(): array
{
    return json_read(CLINIC_STATS_FILE, []);
}

function save_clinic_stats(array $data): void
{
    json_write(CLINIC_STATS_FILE, $data);
}

// ── Clinic history ────────────────────────────────────────────────────────────

function load_clinic_history(): array
{
    return json_read(CLINIC_HISTORY_FILE, [
        'animals_log'        => [],
        'outcomes_log'       => [],
        'logged_animal_ids'  => [],
        'logged_outcome_ids' => [],
    ]);
}

function save_clinic_history(array $data): void
{
    json_write(CLINIC_HISTORY_FILE, $data);
}

function log_new_entries(string $code, array $incoming): void
{
    $now_str  = date('Y-m-d');
    $week_str = date('Y-W');

    $history            = load_clinic_history();
    $logged_animal_ids  = array_flip($history['logged_animal_ids'] ?? []);
    $logged_outcome_ids = array_flip($history['logged_outcome_ids'] ?? []);
    $changed = false;

    foreach (($incoming['animalsList'] ?? []) as $a) {
        $aid = (string)($a['id'] ?? '');
        if ($aid !== '' && !isset($logged_animal_ids[$aid])) {
            $history['animals_log'][] = [
                'id'          => $aid,
                'date'        => $now_str,
                'week'        => $week_str,
                'species'     => $a['species'] ?? '',
                'commune'     => $a['commune'] ?? '',
                'clinic_code' => $code,
            ];
            $logged_animal_ids[$aid] = true;
            $changed = true;
        }
    }

    foreach (($incoming['outcomesList'] ?? []) as $o) {
        $oid = (string)($o['id'] ?? '');
        if ($oid !== '' && !isset($logged_outcome_ids[$oid])) {
            $history['outcomes_log'][] = [
                'id'          => $oid,
                'date'        => $now_str,
                'week'        => $week_str,
                'species'     => $o['species'] ?? '',
                'type'        => $o['type'] ?? 'deces',
                'commune'     => $o['commune'] ?? '',
                'clinic_code' => $code,
            ];
            $logged_outcome_ids[$oid] = true;
            $changed = true;
        }
    }

    if ($changed) {
        $history['logged_animal_ids']  = array_keys($logged_animal_ids);
        $history['logged_outcome_ids'] = array_keys($logged_outcome_ids);
        save_clinic_history($history);
    }
}

// ── Clinic prefs ──────────────────────────────────────────────────────────────

function load_clinic_prefs(): array
{
    return json_read(CLINIC_PREFS_FILE, []);
}

function save_clinic_prefs(array $data): void
{
    json_write(CLINIC_PREFS_FILE, $data);
}

// ── Clinic codes ──────────────────────────────────────────────────────────────

function load_clinic_codes(): array
{
    return json_read(CLINIC_CODES_FILE, []);
}

function save_clinic_codes(array $data): void
{
    json_write(CLINIC_CODES_FILE, $data);
}

function generate_clinic_code(string $nom, array $existing_codes): string
{
    // Préfixe = 3 lettres de la ville (sans accents, sans parenthèses)
    $city = trim(explode('(', $nom)[0]);
    $city_clean = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $city);
    $city_clean = preg_replace('/[^A-Za-z]/', '', $city_clean);
    $city_clean = strtoupper($city_clean);
    $prefix = str_pad(substr($city_clean, 0, 3), 3, 'X');

    // Alphabet sans O/0/I/1 pour éviter confusion
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $alpha_len = strlen($alphabet);
    $existing_vals = array_flip(array_values($existing_codes));

    for ($i = 0; $i < 100; $i++) {
        $suffix = '';
        for ($j = 0; $j < 6; $j++) {
            $suffix .= $alphabet[random_int(0, $alpha_len - 1)];
        }
        $code = "{$prefix}-{$suffix}";
        if (!isset($existing_vals[$code])) {
            return $code;
        }
    }
    throw new RuntimeException("Impossible de générer un code unique pour $nom");
}

// ── Vetos depuis Google Sheets ─────────────────────────────────────────────────
// Lit l'onglet Vétérinaires et assigne les codes

function get_vetos_with_codes(): array
{
    $cache_key = 'vetos_' . get_sheet_id();
    $cached    = cache_get($cache_key);
    if ($cached !== null) {
        return json_decode($cached, true) ?? [];
    }

    $csv  = fetch_csv_tab(get_sheet_id(), 'Vétérinaires');
    $rows = array_map('str_getcsv', explode("\n", $csv));
    $vetos = [];

    foreach (array_slice($rows, 1) as $row) {
        if (empty($row) || trim($row[0] ?? '') === '') continue;
        $nom    = trim($row[0]);
        $statut = strtolower(trim($row[1] ?? ''));
        if (str_contains($statut, 'partenaire')) $statut = 'Partenaire';
        elseif (str_contains($statut, 'eviter') || str_contains($statut, 'éviter')) $statut = 'Eviter';
        else $statut = '';
        $vetos[] = ['nom' => $nom, 'statut' => $statut];
    }

    // Assigner des codes pour les partenaires (non-Eviter) sans code existant
    $codes   = load_clinic_codes();
    $changed = false;
    foreach ($vetos as $v) {
        if ($v['statut'] !== 'Eviter' && !isset($codes[$v['nom']])) {
            $codes[$v['nom']] = generate_clinic_code($v['nom'], $codes);
            $changed = true;
        }
    }
    if ($changed) save_clinic_codes($codes);

    cache_set($cache_key, json_encode($vetos, JSON_UNESCAPED_UNICODE));
    return $vetos;
}

// ── Stats ─────────────────────────────────────────────────────────────────────

function compute_stats(?string $stats_code): array
{
    $h            = load_clinic_history();
    $animals_log  = $h['animals_log'] ?? [];
    $outcomes_log = $h['outcomes_log'] ?? [];

    // Per-clinic summary (toutes données)
    $clinic_summary = [];
    foreach ($animals_log as $a) {
        $c = $a['clinic_code'] ?? '';
        if (!isset($clinic_summary[$c])) $clinic_summary[$c] = ['total' => 0, 'outcomes' => []];
        $clinic_summary[$c]['total']++;
    }
    foreach ($outcomes_log as $o) {
        $c = $o['clinic_code'] ?? '';
        if (!isset($clinic_summary[$c])) $clinic_summary[$c] = ['total' => 0, 'outcomes' => []];
        $t = $o['type'] ?? 'deces';
        $clinic_summary[$c]['outcomes'][$t] = ($clinic_summary[$c]['outcomes'][$t] ?? 0) + 1;
    }

    // Filtre par code clinique si demandé
    if ($stats_code !== null && $stats_code !== '') {
        $animals_log  = array_values(array_filter($animals_log,  fn($a) => ($a['clinic_code'] ?? '') === $stats_code));
        $outcomes_log = array_values(array_filter($outcomes_log, fn($o) => ($o['clinic_code'] ?? '') === $stats_code));
    }

    $year_now  = date('Y');
    $month_now = date('Y-m');

    $total_year  = count(array_filter($animals_log, fn($a) => str_starts_with($a['date'] ?? '', $year_now)));
    $total_month = count(array_filter($animals_log, fn($a) => str_starts_with($a['date'] ?? '', $month_now)));
    $total_all   = count($animals_log);

    // Sorties par type (cette année)
    $outcomes_by_type = [];
    $outcomes_all     = [];
    foreach ($outcomes_log as $o) {
        $t = $o['type'] ?? 'deces';
        $outcomes_all[$t] = ($outcomes_all[$t] ?? 0) + 1;
        if (str_starts_with($o['date'] ?? '', $year_now)) {
            $outcomes_by_type[$t] = ($outcomes_by_type[$t] ?? 0) + 1;
        }
    }

    // Top espèces (cette année)
    $species_count = [];
    foreach ($animals_log as $a) {
        if (str_starts_with($a['date'] ?? '', $year_now)) {
            $s = ($a['species'] ?? '') ?: 'Non précisée';
            $species_count[$s] = ($species_count[$s] ?? 0) + 1;
        }
    }
    arsort($species_count);
    $top_species = array_slice($species_count, 0, 10, true);

    // Animaux par semaine (16 dernières semaines)
    $weekly = [];
    foreach ($animals_log as $a) {
        $w = $a['week'] ?? '';
        if ($w !== '') $weekly[$w] = ($weekly[$w] ?? 0) + 1;
    }
    $weekly_ordered = [];
    for ($i = 15; $i >= 0; $i--) {
        $d = new DateTime("now -$i weeks");
        $w = $d->format('Y-W');
        $weekly_ordered[$w] = $weekly[$w] ?? 0;
    }

    // Sorties récentes (100 dernières)
    $recent_outcomes = $outcomes_log;
    usort($recent_outcomes, fn($a, $b) => strcmp($b['date'] ?? '', $a['date'] ?? ''));
    $recent_outcomes = array_slice($recent_outcomes, 0, 100);

    // Rapatriements par mois (depuis benevoles.json)
    $rapat_by_month = [];
    $benvs = json_read(BASE_DIR . '/benevoles.json', []);
    foreach ($benvs as $b) {
        $raps = $b['derniers_rapat'] ?? [];
        if (is_string($raps)) {
            foreach (explode("\n", $raps) as $line) {
                $line = trim($line);
                if ($line === '') continue;
                $date_str = explode(' : ', $line)[0];
                if (str_contains($date_str, '/')) {
                    try {
                        $d = DateTime::createFromFormat('d/m/Y', trim($date_str));
                        if ($d) {
                            $m = $d->format('Y-m');
                            $rapat_by_month[$m] = ($rapat_by_month[$m] ?? 0) + 1;
                        }
                    } catch (Exception $e) {}
                }
            }
        } elseif (is_array($raps)) {
            foreach ($raps as $r) {
                $date_str = $r['date'] ?? '';
                if (str_contains($date_str, '/')) {
                    try {
                        $d = DateTime::createFromFormat('d/m/Y', $date_str);
                        if ($d) {
                            $m = $d->format('Y-m');
                            $rapat_by_month[$m] = ($rapat_by_month[$m] ?? 0) + 1;
                        }
                    } catch (Exception $e) {}
                }
            }
        }
    }

    // 12 derniers mois dans l'ordre
    $months_ordered = [];
    for ($i = 11; $i >= 0; $i--) {
        $d = new DateTime("first day of -$i months");
        $key = $d->format('Y-m');
        $months_ordered[$key] = $rapat_by_month[$key] ?? 0;
    }

    // code→nom depuis clinic_codes.json
    $raw_codes  = load_clinic_codes(); // {nom => code}
    $code_names = array_flip($raw_codes);

    return [
        'total_year'      => $total_year,
        'total_month'     => $total_month,
        'total_all'       => $total_all,
        'outcomes_by_type'=> $outcomes_by_type,
        'outcomes_all'    => $outcomes_all,
        'top_species'     => $top_species,
        'weekly_animals'  => $weekly_ordered,
        'recent_outcomes' => $recent_outcomes,
        'rapat_by_month'  => $months_ordered,
        'clinic_summary'  => $clinic_summary,
        'filtered_code'   => $stats_code,
        'code_names'      => $code_names,
    ];
}

// ── Routes ────────────────────────────────────────────────────────────────────

function route_clinic_stats_get(): void
{
    json_response(load_clinic_stats());
}

function route_clinic_stats_post(): void
{
    $body = read_json_body();
    $code = strtoupper(trim((string)($body['code'] ?? '')));
    if ($code !== '') {
        $stats = load_clinic_stats();
        $stats[$code] = [
            'animaux'      => (int)($body['animaux'] ?? 0),
            'deces'        => (int)($body['deces'] ?? 0),
            'depots'       => (int)($body['depots'] ?? 0),
            'enRoute'      => (int)($body['enRoute'] ?? 0),
            'lastSeen'     => (int)($body['lastSeen'] ?? 0),
            'sheetArrived' => is_array($body['sheetArrived'] ?? null) ? $body['sheetArrived'] : [],
            'animalsList'  => is_array($body['animalsList'] ?? null) ? $body['animalsList'] : [],
            'outcomesList' => is_array($body['outcomesList'] ?? null) ? $body['outcomesList'] : [],
            'depotsList'   => is_array($body['depotsList'] ?? null) ? $body['depotsList'] : [],
            'ts'           => time(),
        ];
        save_clinic_stats($stats);
        log_new_entries($code, $body);
        maybe_run_backup();
        maybe_clear_sorties();
    }
    json_response(['ok' => true]);
}

function route_clinic_stats_delete_item_post(): void
{
    $body    = read_json_body();
    $code    = strtoupper(trim((string)($body['code'] ?? '')));
    $liste   = (string)($body['liste'] ?? '');
    $item_id = $body['id'] ?? null;

    $allowed = ['animalsList', 'outcomesList', 'depotsList'];
    if ($code !== '' && in_array($liste, $allowed, true) && $item_id !== null) {
        $stats = load_clinic_stats();
        if (isset($stats[$code][$liste])) {
            $stats[$code][$liste] = array_values(array_filter(
                $stats[$code][$liste],
                fn($x) => (string)($x['id'] ?? '') !== (string)$item_id
            ));
            // Recalculer le compteur
            $count_map = ['animalsList' => 'animaux', 'outcomesList' => 'deces', 'depotsList' => 'depots'];
            $key = $count_map[$liste] ?? null;
            if ($key) $stats[$code][$key] = count($stats[$code][$liste]);
            save_clinic_stats($stats);
        }
    }
    json_response(['ok' => true]);
}

function route_history_get(): void
{
    json_response(load_clinic_history());
}

function route_stats_get(?string $stats_code): void
{
    json_response(compute_stats($stats_code ?: null));
}

function route_clinic_prefs_get(): void
{
    json_response(load_clinic_prefs());
}

function route_clinic_prefs_post(): void
{
    $body = read_json_body();
    $nom  = trim((string)($body['nom'] ?? ''));
    $val  = $body['val'] ?? null;
    $prefs = load_clinic_prefs();
    if ($val === null) {
        unset($prefs[$nom]);
    } else {
        $prefs[$nom] = (bool)$val;
    }
    save_clinic_prefs($prefs);
    json_response(['ok' => true]);
}

function route_clinic_codes_get(): void
{
    $codes = load_clinic_codes();
    $cfg   = load_config();

    // Essayer de récupérer les statuts depuis le cache/GSheets
    try {
        $vetos = get_vetos_with_codes();
        $statut_map = array_column($vetos, 'statut', 'nom');
    } catch (Exception $e) {
        $statut_map = [];
    }

    $clinics = [];
    $sorted  = $codes;
    ksort($sorted);
    foreach ($sorted as $nom => $code) {
        $clinics[] = ['nom' => $nom, 'code' => $code, 'statut' => $statut_map[$nom] ?? ''];
    }

    json_response([
        'clinics'    => $clinics,
        'admin_code' => $cfg['admin_code'] ?? '—',
    ]);
}

function route_clinic_regenerate_code_post(): void
{
    $body = read_json_body();
    $nom  = trim((string)($body['nom'] ?? ''));
    $codes = load_clinic_codes();
    if (!isset($codes[$nom])) {
        json_response(['ok' => false, 'error' => 'Clinique inconnue'], 404);
    }
    $other_codes = array_filter($codes, fn($k) => $k !== $nom, ARRAY_FILTER_USE_KEY);
    try {
        $new_code = generate_clinic_code($nom, $other_codes);
    } catch (RuntimeException $e) {
        json_response(['ok' => false, 'error' => $e->getMessage()], 500);
    }
    $codes[$nom] = $new_code;
    save_clinic_codes($codes);
    json_response(['ok' => true, 'code' => $new_code]);
}

function route_vetos_get(): void
{
    try {
        $vetos = get_vetos_with_codes();
        json_response($vetos);
    } catch (Exception $e) {
        http_response_code(502);
        header('Content-Type: text/plain');
        send_cors_headers();
        echo $e->getMessage();
        exit;
    }
}
