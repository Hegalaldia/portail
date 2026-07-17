<?php
/**
 * rapatriements.php — Ajout, suppression, confirmation, mise à jour des rapatriements
 */

require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/benevoles.php';

function to_display_date(string $d): string
{
    $parts = explode('-', $d);
    if (count($parts) === 3 && strlen($parts[0]) === 4) {
        return "{$parts[2]}/{$parts[1]}/{$parts[0]}";
    }
    return $d;
}

function build_date_label(array $dates): string
{
    if (count($dates) === 1) return to_display_date($dates[0]);
    if (count($dates) > 1)  return to_display_date($dates[0]) . ' - ' . to_display_date(end($dates));
    return '';
}

// ── En-route ──────────────────────────────────────────────────────────────────

function route_rapatriements_en_route_get(): void
{
    $today_iso  = date('Y-m-d');
    $counts     = [];
    $volunteers = load_benevoles();
    foreach ($volunteers as $b) {
        $raw = $b['derniers_rapat'] ?? '';
        $rows = is_array($raw) ? $raw : [];
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $dates = $r['dates'] ?? [];
            if (is_string($dates)) {
                $dates = array_filter(array_map('trim', explode(',', $dates)));
            }
            if (!in_array($today_iso, $dates, true)) continue;
            $trajet = $r['trajet'] ?? [];
            if (is_string($trajet)) {
                $trajet = array_filter(array_map('trim', explode('>', $trajet)));
            }
            $trajet = array_values($trajet);
            $dest = trim(end($trajet) ?: '');
            if ($dest !== '') {
                $counts[$dest] = ($counts[$dest] ?? 0) + 1;
            }
        }
    }
    json_response($counts);
}

// ── Ajout ─────────────────────────────────────────────────────────────────────

function route_rapatriements_add_post(): void
{
    $body        = read_json_body();
    $benv_id     = trim((string)($body['benevole_id'] ?? ''));
    $benv_nom    = trim((string)($body['benevole_nom'] ?? ''));
    $benv_prenom = trim((string)($body['benevole_prenom'] ?? ''));
    $dates       = is_array($body['dates'] ?? null) ? $body['dates'] : [];

    $rapat_entry = [
        'date'          => build_date_label($dates),
        'dates'         => $dates,
        'heure_depart'  => (string)($body['heure_depart'] ?? ''),
        'heure_arrivee' => (string)($body['heure_arrivee'] ?? ''),
        'trajet'        => $body['trajet'] ?? [],
        'distance_km'   => (string)($body['distance_km'] ?? ''),
        'notes'         => (string)($body['notes'] ?? ''),
    ];

    $volunteers = load_benevoles();
    $found = false;
    foreach ($volunteers as &$b) {
        $match = ($benv_id !== '' && ($b['_id'] ?? '') === $benv_id)
               || ($benv_id === '' && trim($b['nom'] ?? '') === $benv_nom
                                  && trim($b['prenom'] ?? '') === $benv_prenom);
        if (!$match) continue;

        $raw = $b['derniers_rapat'] ?? '';
        if (is_string($raw)) {
            $b['derniers_rapat'] = $raw !== '' ? parse_rapat_text($raw) : [];
        } elseif (!is_array($b['derniers_rapat'])) {
            $b['derniers_rapat'] = [];
        }
        $b['derniers_rapat'][] = $rapat_entry;
        $found = true;
        break;
    }
    unset($b);

    if (!$found) {
        json_response(['ok' => false, 'error' => "Bénévole non trouvé : $benv_prenom $benv_nom"], 404);
    }

    save_benevoles($volunteers);
    json_response(['ok' => true]);
}

// ── Suppression ───────────────────────────────────────────────────────────────

function route_rapatriements_delete_post(): void
{
    $body    = read_json_body();
    $benv_id = (string)($body['benevole_id'] ?? '');
    $idx     = (int)($body['index'] ?? -1);

    $volunteers = load_benevoles();
    $found = false;
    foreach ($volunteers as &$b) {
        if (($b['_id'] ?? '') !== $benv_id) continue;
        $raw = $b['derniers_rapat'] ?? '';
        if (is_string($raw)) {
            $b['derniers_rapat'] = parse_rapat_text($raw);
        }
        if (is_array($b['derniers_rapat']) && isset($b['derniers_rapat'][$idx])) {
            array_splice($b['derniers_rapat'], $idx, 1);
            $found = true;
        }
        break;
    }
    unset($b);

    if ($found) save_benevoles($volunteers);
    json_response(['ok' => $found]);
}

// ── Confirmation ──────────────────────────────────────────────────────────────

function route_rapatriements_confirm_post(): void
{
    $body    = read_json_body();
    $benv_id = (string)($body['benevole_id'] ?? '');
    $idx     = (int)($body['index'] ?? -1);
    $done    = (bool)($body['done'] ?? false);

    $volunteers = load_benevoles();
    $found = false;
    foreach ($volunteers as &$b) {
        if (($b['_id'] ?? '') !== $benv_id) continue;
        $raw = $b['derniers_rapat'] ?? [];
        if (is_array($raw) && isset($raw[$idx])) {
            $b['derniers_rapat'][$idx]['confirme']    = $done;
            $b['derniers_rapat'][$idx]['confirme_at'] = date('d/m/y à H:i');
            $found = true;
        }
        break;
    }
    unset($b);

    if ($found) save_benevoles($volunteers);
    json_response(['ok' => $found]);
}

// ── Mise à jour ───────────────────────────────────────────────────────────────

function route_rapatriements_update_post(): void
{
    $body    = read_json_body();
    $benv_id = (string)($body['benevole_id'] ?? '');
    $idx     = (int)($body['index'] ?? -1);
    $dates   = is_array($body['dates'] ?? null) ? $body['dates'] : [];

    $volunteers = load_benevoles();
    $found = false;
    foreach ($volunteers as &$b) {
        if (($b['_id'] ?? '') !== $benv_id) continue;
        $raw = $b['derniers_rapat'] ?? [];
        if (is_array($raw) && isset($raw[$idx])) {
            $b['derniers_rapat'][$idx]['date']          = build_date_label($dates);
            $b['derniers_rapat'][$idx]['dates']         = $dates;
            $b['derniers_rapat'][$idx]['heure_depart']  = (string)($body['heure_depart'] ?? '');
            $b['derniers_rapat'][$idx]['heure_arrivee'] = (string)($body['heure_arrivee'] ?? '');
            $b['derniers_rapat'][$idx]['trajet']        = $body['trajet'] ?? [];
            $b['derniers_rapat'][$idx]['distance_km']   = (string)($body['distance_km'] ?? '');
            $b['derniers_rapat'][$idx]['notes']         = (string)($body['notes'] ?? '');
            $found = true;
        }
        break;
    }
    unset($b);

    if ($found) save_benevoles($volunteers);
    json_response(['ok' => $found]);
}
