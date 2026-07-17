<?php
/**
 * benevoles.php — CRUD bénévoles, photos, drafts, export XLS/CSV
 */

require_once __DIR__ . '/utils.php';

define('BENEVOLES_FILE',       BASE_DIR . '/benevoles.json');
define('BENEVOLES_DRAFTS_FILE', BASE_DIR . '/benevoles_drafts.json');
define('PHOTOS_DIR',           BASE_DIR . '/photos');

// Champs supplémentaires (extra fields) présents dans server.py
const EXTRA_FIELDS = [
    'telephone2', 'adresse', 'urg_nom', 'urg_prenom', 'urg_tel',
    'forme', 'forme_soin', 'forme_rapat',
    'adhesion', 'admin', 'ca', 'veteran', 'anc_sc', 'anc_salarie', 'anc_ca',
    'premier_jour', 'disponibilites',
    'comp_menage', 'comp_oisillons', 'comp_marins', 'comp_vautour', 'comp_exterieurs', 'comp_heris',
    'caisses', 'caisses_nb', 'caisses_taille', 'gants', 'garde_domicile', 'recu_fiscal',
    'zone_pb', 'zone_bearn', 'zone_landes', 'zone_hp',
    'comp_brico', 'comp_info', 'comp_commu', 'comp_compta', 'comp_photo', 'comp_couture',
    'act_caddie', 'act_papier_cadeau', 'act_espaces_verts',
    'autres_competences',
    'devenir', 'veterinaire',
];

// ── Lecture/écriture bénévoles ────────────────────────────────────────────────

function load_benevoles(): array
{
    return json_read(BENEVOLES_FILE, []);
}

function save_benevoles(array $data): void
{
    json_write(BENEVOLES_FILE, $data);
}

// ── CRUD ──────────────────────────────────────────────────────────────────────

function add_benevole(array $data): string
{
    $now = date('d/m/y à H:i');
    $roles = [];
    foreach (['centre', 'rapatrieur', 'autre', 'former'] as $r) {
        if (!empty($data[$r])) $roles[] = $r;
    }

    $new_b = [
        '_id'           => generate_uuid(),
        'prenom'        => (string)($data['prenom'] ?? ''),
        'nom'           => (string)($data['nom'] ?? ''),
        'roles'         => $roles,
        'date_creation' => $now,
        'modif'         => $now,
        'derniers_rapat'=> '',
    ];

    $all_fields = [
        'telephone', 'telephone2', 'email', 'adresse', 'commune', 'rayon', 'naissance',
        'urg_nom', 'urg_prenom', 'urg_tel',
        'forme', 'forme_soin', 'forme_rapat',
        'adhesion', 'admin', 'ca', 'veteran', 'anc_sc', 'anc_salarie', 'anc_ca',
        'premier_jour', 'disponibilites',
        'comp_menage', 'comp_oisillons', 'comp_marins', 'comp_vautour', 'comp_exterieurs', 'comp_heris',
        'immat', 'puissance', 'moteur',
        'caisses', 'caisses_nb', 'caisses_taille', 'gants', 'garde_domicile', 'recu_fiscal',
        'zone_pb', 'zone_bearn', 'zone_landes', 'zone_hp',
        'comp_brico', 'comp_info', 'comp_commu', 'comp_compta', 'comp_photo', 'comp_couture',
        'act_caddie', 'act_papier_cadeau', 'act_espaces_verts',
        'autres_competences', 'commentaire', 'info_temp', 'competences',
        'indisponible',
    ];
    foreach ($all_fields as $k) {
        $new_b[$k] = (string)($data[$k] ?? '');
    }
    $new_b['vehicules'] = $data['vehicules'] ?? [];

    $volunteers = load_benevoles();
    $nom_new    = strtolower(trim($new_b['nom']));
    $prenom_new = strtolower(trim($new_b['prenom']));
    foreach ($volunteers as $b) {
        if (strtolower(trim($b['nom'] ?? '')) === $nom_new
            && strtolower(trim($b['prenom'] ?? '')) === $prenom_new) {
            throw new RuntimeException("Un bénévole nommé {$new_b['prenom']} {$new_b['nom']} existe déjà.");
        }
    }

    $volunteers[] = $new_b;
    save_benevoles($volunteers);
    return $new_b['_id'];
}

function update_benevole(string $orig_nom, string $orig_prenom, array $data): void
{
    $now = date('d/m/y à H:i');
    $roles = [];
    foreach (['centre', 'rapatrieur', 'autre', 'former'] as $r) {
        if (!empty($data[$r])) $roles[] = $r;
    }

    $volunteers = load_benevoles();
    foreach ($volunteers as $i => $b) {
        if (trim($b['nom'] ?? '') === trim($orig_nom)
            && trim($b['prenom'] ?? '') === trim($orig_prenom)) {

            $all_fields = [
                'telephone', 'telephone2', 'email', 'adresse', 'commune', 'rayon', 'naissance',
                'urg_nom', 'urg_prenom', 'urg_tel',
                'forme', 'forme_soin', 'forme_rapat',
                'adhesion', 'ca', 'veteran', 'anc_sc', 'anc_salarie', 'anc_ca',
                'premier_jour', 'disponibilites',
                'comp_menage', 'comp_oisillons', 'comp_marins', 'comp_vautour', 'comp_exterieurs', 'comp_heris',
                'immat', 'puissance', 'moteur',
                'caisses', 'caisses_nb', 'caisses_taille', 'gants', 'garde_domicile', 'recu_fiscal',
                'zone_pb', 'zone_bearn', 'zone_landes', 'zone_hp',
                'act_caddie', 'act_papier_cadeau', 'act_espaces_verts',
                'comp_brico', 'comp_info', 'comp_commu', 'comp_compta', 'comp_photo', 'comp_couture',
                'autres_competences', 'commentaire', 'info_temp', 'competences',
                'indisponible',
            ];

            $updated = [
                '_id'            => $b['_id'] ?? generate_uuid(),
                'derniers_rapat' => $b['derniers_rapat'] ?? '',
                'date_creation'  => $b['date_creation'] ?? '',
                'prenom'         => (string)($data['prenom'] ?? $orig_prenom),
                'nom'            => (string)($data['nom'] ?? $orig_nom),
                'roles'          => $roles,
                'modif'          => $now,
                // admin est protegé : toujours preserver la valeur existante
                'admin'          => $b['admin'] ?? '',
                // info_temp toujours videe a la mise a jour
                'info_temp'      => '',
                'vehicules'      => $data['vehicules'] ?? ($b['vehicules'] ?? []),
            ];

            foreach ($all_fields as $k) {
                if ($k === 'admin') continue; // déjà protégé
                $updated[$k] = $data[$k] ?? ($b[$k] ?? '');
            }
            $updated['info_temp'] = '';

            $volunteers[$i] = $updated;
            save_benevoles($volunteers);
            return;
        }
    }
    throw new RuntimeException("Bénévole non trouvé : $orig_prenom $orig_nom");
}

function delete_benevole(string $benv_id): int
{
    $volunteers = load_benevoles();
    $before = count($volunteers);
    $volunteers = array_values(array_filter($volunteers, fn($b) => ($b['_id'] ?? '') !== $benv_id));
    $deleted = $before - count($volunteers);
    if ($deleted > 0) {
        save_benevoles($volunteers);
    }
    return $deleted;
}

// ── UUID ──────────────────────────────────────────────────────────────────────

function generate_uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

// ── Rapatriements : année ─────────────────────────────────────────────────────

function rapat_year(array $r): ?int
{
    $date_str = $r['date'] ?? '';
    if ($date_str === '') return null;
    $parts = preg_split('/[-\/]/', $date_str);
    if (count($parts) < 3) return null;
    $y = trim($parts[2]);
    if (strlen($y) === 2) $y = '20' . $y;
    return is_numeric($y) ? (int)$y : null;
}

function clear_rapatriements_year(int $year): array
{
    $volunteers = load_benevoles();
    $cleared = 0;
    foreach ($volunteers as &$b) {
        $raw = $b['derniers_rapat'] ?? '';
        if (is_array($raw)) {
            $rows = $raw;
        } elseif (is_string($raw) && $raw !== '') {
            $rows = parse_rapat_text($raw);
        } else {
            $rows = [];
        }
        $before = count($rows);
        $kept   = array_values(array_filter($rows, fn($r) => rapat_year($r) !== $year));
        $cleared += $before - count($kept);
        if (is_array($b['derniers_rapat'])) {
            $b['derniers_rapat'] = $kept;
        } else {
            $b['derniers_rapat'] = implode("\n", array_filter(array_map(function($r) {
                $line = ($r['date'] ?? '');
                $t = $r['trajet'] ?? '';
                if ($t !== '') $line .= ($line !== '' ? ' : ' : '') . $t;
                return trim($line);
            }, $kept)));
        }
    }
    unset($b);
    save_benevoles($volunteers);
    return ['cleared' => $cleared, 'year' => $year];
}

function parse_rapat_text(string $raw): array
{
    $result = [];
    foreach (explode("\n", $raw) as $ligne) {
        $ligne = trim($ligne);
        if ($ligne === '') continue;
        if (strpos($ligne, ' : ') !== false) {
            [$d, $t] = explode(' : ', $ligne, 2);
        } else {
            $d = '';
            $t = $ligne;
        }
        $result[] = ['date' => trim($d), 'trajet' => trim($t)];
    }
    return $result;
}

// ── Reimport XLS ──────────────────────────────────────────────────────────────
// Le fichier listingbenevole.xls est un HTML, on le parse comme Python le fait.

function reimport_from_xls(): array
{
    $xls_path = BASE_DIR . '/listingbenevole.xls';
    if (!file_exists($xls_path)) {
        throw new RuntimeException('Fichier listingbenevole.xls introuvable.');
    }
    $content  = file_get_contents($xls_path);
    $xls_data = parse_xls_html($content);

    $existing = load_benevoles();
    $idx = [];
    foreach ($existing as $i => $b) {
        $key = strtolower(trim($b['nom'] ?? '')) . '|' . strtolower(trim($b['prenom'] ?? ''));
        $idx[$key] = $i;
    }

    $added = 0;
    $updated = 0;

    foreach ($xls_data as $xb) {
        $key = strtolower(trim($xb['nom'] ?? '')) . '|' . strtolower(trim($xb['prenom'] ?? ''));
        if (isset($idx[$key])) {
            $i = $idx[$key];
            foreach (['roles','telephone','email','commune','rayon','naissance','immat','puissance','moteur',
                      'competences','commentaire','info_temp','derniers_rapat','date_creation','modif'] as $k) {
                $existing[$i][$k] = $xb[$k] ?? ($existing[$i][$k] ?? '');
            }
            $updated++;
        } else {
            $xb['_id'] = generate_uuid();
            foreach (EXTRA_FIELDS as $k) {
                if (!isset($xb[$k])) $xb[$k] = '';
            }
            $existing[] = $xb;
            $added++;
        }
    }

    save_benevoles($existing);
    return ['added' => $added, 'updated' => $updated];
}

function parse_xls_html(string $html): array
{
    // Parser les tableaux HTML du fichier XLS (format HTML)
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8">' . $html);
    $tables = $dom->getElementsByTagName('table');
    if ($tables->length === 0) return [];

    $table = $tables->item(0);
    $rows  = [];
    foreach ($table->getElementsByTagName('tr') as $tr) {
        $cells = [];
        foreach ($tr->childNodes as $node) {
            if ($node->nodeName === 'td' || $node->nodeName === 'th') {
                $cells[] = trim($node->textContent);
            }
        }
        if (array_filter($cells)) {
            $rows[] = $cells;
        }
    }

    if (count($rows) < 2) return [];
    $headers = $rows[0];
    $result  = [];

    foreach (array_slice($rows, 1) as $row) {
        while (count($row) < count($headers)) $row[] = '';
        $d = array_combine($headers, $row);

        $roles = [];
        if (!empty(trim($d['Bénévole sur centre'] ?? ''))) $roles[] = 'centre';
        if (!empty(trim($d['Bénévole rapatrieur'] ?? ''))) $roles[] = 'rapatrieur';
        if (!empty(trim($d['Autre bénévole'] ?? ''))) $roles[] = 'autre';
        if (!empty(trim($d['À former'] ?? ''))) $roles[] = 'former';

        $prenom = trim($d['Prénom'] ?? '');
        $nom    = trim($d['Nom'] ?? '');
        if ($prenom === '' && $nom === '') continue;

        $result[] = [
            'prenom'        => $prenom,
            'nom'           => $nom,
            'roles'         => $roles,
            'telephone'     => trim($d['Téléphone(s)'] ?? ''),
            'email'         => trim($d['Email'] ?? ''),
            'commune'       => trim($d["Commune d'habitation"] ?? ''),
            'rayon'         => trim($d['Rayon intervention (en kms)'] ?? ''),
            'naissance'     => trim($d['Date de naissance'] ?? ''),
            'immat'         => trim($d['Immatriculation du véhicule'] ?? ''),
            'puissance'     => trim($d['Puissance du véhicule'] ?? ''),
            'moteur'        => trim($d['Moteur du véhicule'] ?? ''),
            'competences'   => trim($d['Compétences'] ?? ''),
            'commentaire'   => trim($d['Commentaire'] ?? ''),
            'info_temp'     => trim($d['Information temporaire'] ?? ''),
            'derniers_rapat'=> trim($d['Derniers rapatriements'] ?? ''),
            'date_creation' => trim($d['Date de création'] ?? ''),
            'modif'         => trim($d['Dernière modification'] ?? ''),
            'devenir'       => trim($d['Devenir'] ?? ''),
            'veterinaire'   => trim($d['Vétérinaire'] ?? ''),
        ];
    }
    return $result;
}

// ── Export XLS/CSV ────────────────────────────────────────────────────────────

/*
 * Si PhpSpreadsheet est disponible via Composer (vendor/autoload.php), on l'utilise.
 * Sinon on génère un CSV de fallback.
 * Pour installer PhpSpreadsheet sur OVH :
 *   cd <racine_projet> && composer require phpoffice/phpspreadsheet
 */

function export_benevoles(?int $year = null, ?array $ids = null): void
{
    $data = load_benevoles();
    if ($ids !== null) {
        $id_set = array_flip($ids);
        $data = array_values(array_filter($data, fn($b) => isset($id_set[$b['_id'] ?? ''])));
    }
    if ($year !== null) {
        foreach ($data as &$b) {
            $raw = $b['derniers_rapat'] ?? '';
            if (is_array($raw)) {
                $rows = $raw;
            } elseif ($raw !== '') {
                $rows = parse_rapat_text($raw);
            } else {
                $rows = [];
            }
            $kept = array_values(array_filter($rows, fn($r) => rapat_year($r) === $year));
            if (is_array($b['derniers_rapat'])) {
                $b['derniers_rapat'] = $kept;
            } else {
                $b['derniers_rapat'] = implode("\n", array_filter(array_map(function($r) {
                    $line = ($r['date'] ?? '');
                    $t = $r['trajet'] ?? '';
                    if ($t !== '') $line .= ($line !== '' ? ' : ' : '') . $t;
                    return trim($line);
                }, $kept)));
            }
        }
        unset($b);
    }

    $autoload = BASE_DIR . '/vendor/autoload.php';
    if (file_exists($autoload)) {
        require_once $autoload;
        if (class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet')) {
            export_xlsx_phpspreadsheet($data, $year);
            return;
        }
    }

    // Fallback CSV
    export_csv_fallback($data, $year);
}

function fmt_rapat_val($val): string
{
    if (!$val) return '';
    if (is_array($val)) {
        $rows = $val;
    } else {
        $rows = parse_rapat_text((string)$val);
    }
    $lines = [];
    foreach ($rows as $r) {
        $line      = $r['date'] ?? '';
        $trajet_raw = $r['trajet'] ?? ($r['lieu'] ?? ($r['commune'] ?? ''));
        $trajet    = is_array($trajet_raw) ? implode(' > ', $trajet_raw) : $trajet_raw;
        $dist      = $r['distance_km'] ?? '';
        $animal    = $r['animal'] ?? ($r['espece'] ?? '');
        if ($trajet !== '') $line .= ($line !== '' ? ' : ' : '') . $trajet;
        if ($dist !== '')   $line .= " ($dist km)";
        if ($animal !== '') $line .= " [$animal]";
        $lines[] = $line;
    }
    return implode("\n", $lines);
}

function yn($val): string
{
    return ($val === 'on') ? 'Oui' : '';
}

function export_csv_fallback(array $data, ?int $year): void
{
    $fname = $year ? "benevoles_{$year}.csv" : 'benevoles_export.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    send_cors_headers();

    $out = fopen('php://output', 'w');
    // BOM UTF-8 pour Excel
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

    $headers = [
        'Nom','Prénom','Naissance','Commune','Adresse',
        'Téléphone','Téléphone 2','Email',
        'Urg. Nom','Urg. Prénom','Urg. Tél.',
        'Sur centre','Rapatrieur','Autre',
        'Formé','Formé soin','Formé rapat.',
        'Adhésion','1er jour','Disponibilités',
        'Comp. Ménage','Comp. Oisillons','Comp. Marins','Comp. Vautour','Comp. Extérieurs','Comp. Hérissons',
        'Immatriculation','Puissance (CV)','Moteur',
        'Caisses transp.','Nb caisses','Taille caisses','Gants','Garde domicile','Reçu fiscal',
        'Zone PB','Zone Béarn','Zone Landes','Zone HP','Rayon (km)',
        'Comp. Bricolage','Comp. Informatique','Comp. Communication','Comp. Comptabilité','Comp. Photo','Comp. Couture','Autres comp.',
        'Rapatriements','Commentaire','Bénévole depuis','Dernière modif.',
    ];
    fputcsv($out, $headers, ';');

    $yn_fields = ['centre','rapatrieur','autre','forme','forme_soin','forme_rapat',
                  'adhesion','caisses','gants','garde_domicile','recu_fiscal',
                  'zone_pb','zone_bearn','zone_landes','zone_hp',
                  'comp_menage','comp_oisillons','comp_marins','comp_vautour','comp_exterieurs','comp_heris',
                  'comp_brico','comp_info','comp_commu','comp_compta','comp_photo','comp_couture'];

    foreach ($data as $b) {
        $vehs = $b['vehicules'] ?? [];
        if (empty($vehs) && (!empty($b['immat']) || !empty($b['puissance']) || !empty($b['moteur']))) {
            $vehs = [['immat' => $b['immat'] ?? '', 'puissance' => $b['puissance'] ?? '', 'moteur' => $b['moteur'] ?? '']];
        }
        $immat     = implode(' | ', array_filter(array_column($vehs, 'immat')));
        $puissance = implode(' | ', array_filter(array_column($vehs, 'puissance')));
        $moteur    = implode(' | ', array_filter(array_column($vehs, 'moteur')));

        $row = [
            $b['nom'] ?? '', $b['prenom'] ?? '', $b['naissance'] ?? '', $b['commune'] ?? '', $b['adresse'] ?? '',
            $b['telephone'] ?? '', $b['telephone2'] ?? '', $b['email'] ?? '',
            $b['urg_nom'] ?? '', $b['urg_prenom'] ?? '', $b['urg_tel'] ?? '',
            yn($b['centre'] ?? ''), yn($b['rapatrieur'] ?? ''), yn($b['autre'] ?? ''),
            yn($b['forme'] ?? ''), yn($b['forme_soin'] ?? ''), yn($b['forme_rapat'] ?? ''),
            yn($b['adhesion'] ?? ''), $b['premier_jour'] ?? '', $b['disponibilites'] ?? '',
            yn($b['comp_menage'] ?? ''), yn($b['comp_oisillons'] ?? ''), yn($b['comp_marins'] ?? ''),
            yn($b['comp_vautour'] ?? ''), yn($b['comp_exterieurs'] ?? ''), yn($b['comp_heris'] ?? ''),
            $immat, $puissance, $moteur,
            yn($b['caisses'] ?? ''), $b['caisses_nb'] ?? '', $b['caisses_taille'] ?? '',
            yn($b['gants'] ?? ''), yn($b['garde_domicile'] ?? ''), yn($b['recu_fiscal'] ?? ''),
            yn($b['zone_pb'] ?? ''), yn($b['zone_bearn'] ?? ''), yn($b['zone_landes'] ?? ''), yn($b['zone_hp'] ?? ''),
            $b['rayon'] ?? '',
            yn($b['comp_brico'] ?? ''), yn($b['comp_info'] ?? ''), yn($b['comp_commu'] ?? ''),
            yn($b['comp_compta'] ?? ''), yn($b['comp_photo'] ?? ''), yn($b['comp_couture'] ?? ''),
            $b['autres_competences'] ?? '',
            fmt_rapat_val($b['derniers_rapat'] ?? ''),
            $b['commentaire'] ?? '',
            explode(' ', $b['date_creation'] ?? '')[0] ?? '',
            explode(' ', $b['modif'] ?? '')[0] ?? '',
        ];
        fputcsv($out, $row, ';');
    }
    fclose($out);
    exit;
}

function export_xlsx_phpspreadsheet(array $data, ?int $year): void
{
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $ws = $spreadsheet->getActiveSheet();
    $ws->setTitle('Bénévoles');

    // Couleurs
    $C_GREEN = 'FF0C3D22'; $C_LGREE = 'FFE8F5E9';
    $C_ORANG = 'FFEC6724'; $C_LORAN = 'FFFFF3EC';
    $C_GRAY  = 'FF5A6672'; $C_LGRAY = 'FFF2F4F5';
    $C_HEAD  = 'FF263238';

    $COLS = [
        ['Nom','nom',18,'id'], ['Prénom','prenom',16,'id'], ['Naissance','naissance',14,'id'],
        ['Commune','commune',18,'id'], ['Adresse','adresse',28,'id'],
        ['Téléphone','telephone',16,'contact'], ['Téléphone 2','telephone2',16,'contact'], ['Email','email',28,'contact'],
        ['Urg. Nom','urg_nom',16,'urg'], ['Urg. Prénom','urg_prenom',16,'urg'], ['Urg. Tél.','urg_tel',16,'urg'],
        ['Sur centre','centre',12,'role'], ['Rapatrieur','rapatrieur',12,'role'], ['Autre','autre',10,'role'],
        ['Formé','forme',10,'form'], ['Formé soin','forme_soin',12,'form'], ['Formé rapat.','forme_rapat',12,'form'],
        ['Adhésion','adhesion',10,'centre_g'], ['1er jour','premier_jour',16,'centre_g'], ['Disponibilités','disponibilites',28,'centre_g'],
        ['Comp. Ménage','comp_menage',14,'centre_g'], ['Comp. Oisillons','comp_oisillons',14,'centre_g'],
        ['Comp. Marins','comp_marins',14,'centre_g'], ['Comp. Vautour','comp_vautour',14,'centre_g'],
        ['Comp. Extérieurs','comp_exterieurs',14,'centre_g'], ['Comp. Hérissons','comp_heris',14,'centre_g'],
        ['Immatriculation','immat',16,'rapat'], ['Puissance (CV)','puissance',14,'rapat'], ['Moteur','moteur',12,'rapat'],
        ['Caisses transp.','caisses',12,'rapat'], ['Nb caisses','caisses_nb',10,'rapat'],
        ['Taille caisses','caisses_taille',14,'rapat'], ['Gants','gants',10,'rapat'],
        ['Garde domicile','garde_domicile',14,'rapat'], ['Reçu fiscal','recu_fiscal',12,'rapat'],
        ['Zone PB','zone_pb',10,'rapat'], ['Zone Béarn','zone_bearn',12,'rapat'],
        ['Zone Landes','zone_landes',12,'rapat'], ['Zone HP','zone_hp',10,'rapat'], ['Rayon (km)','rayon',12,'rapat'],
        ['Comp. Bricolage','comp_brico',14,'autre_g'], ['Comp. Informatique','comp_info',14,'autre_g'],
        ['Comp. Communication','comp_commu',14,'autre_g'], ['Comp. Comptabilité','comp_compta',14,'autre_g'],
        ['Comp. Photo','comp_photo',12,'autre_g'], ['Comp. Couture','comp_couture',12,'autre_g'],
        ['Autres comp.','autres_competences',28,'autre_g'],
        ['Rapatriements','derniers_rapat',30,'hist'], ['Commentaire','commentaire',36,'hist'],
        ['Bénévole depuis','date_creation',18,'hist'], ['Dernière modif.','modif',18,'hist'],
    ];

    $GROUP_LABELS = [
        'id'       => ['Identité',             $C_HEAD],
        'contact'  => ['Contact',              $C_GREEN],
        'urg'      => ['Urgence',              $C_GRAY],
        'role'     => ['Rôles',               $C_HEAD],
        'form'     => ['Formation',            $C_HEAD],
        'centre_g' => ['Sur centre',           'FF019939'],
        'rapat'    => ['Rapatrieur',           $C_ORANG],
        'autre_g'  => ['Autre / Compétences', $C_GRAY],
        'hist'     => ['Historique',           $C_HEAD],
    ];

    // Ligne 1 : groupes
    $group_ranges = [];
    foreach ($COLS as $ci => [$h, $f, $w, $grp]) {
        $col = $ci + 1;
        if (!isset($group_ranges[$grp])) $group_ranges[$grp] = [$col, $col];
        else $group_ranges[$grp][1] = $col;
    }
    foreach ($group_ranges as $grp => [$start, $end]) {
        [$label, $color] = $GROUP_LABELS[$grp];
        if ($start !== $end) {
            $startCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($start);
            $endCol   = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($end);
            $ws->mergeCells("{$startCol}1:{$endCol}1");
        }
        $cell = $ws->getCellByColumnAndRow($start, 1);
        $cell->setValue($label);
        $cell->getStyle()->applyFromArray([
            'font'      => ['bold'=>true,'size'=>9,'color'=>['argb'=>'FFFFFFFF']],
            'fill'      => ['fillType'=>'solid','startColor'=>['argb'=>$color]],
            'alignment' => ['horizontal'=>'center','vertical'=>'center'],
        ]);
    }

    // Ligne 2 : en-têtes
    $LIGHT = [
        'id'=>$C_LGRAY,'contact'=>$C_LGREE,'urg'=>$C_LGRAY,'role'=>$C_LGRAY,'form'=>$C_LGRAY,
        'centre_g'=>'FFE8F5E9','rapat'=>$C_LORAN,'autre_g'=>$C_LGRAY,'hist'=>$C_LGRAY,
    ];
    foreach ($COLS as $ci => [$header, $field, $width, $grp]) {
        $col = $ci + 1;
        $cell = $ws->getCellByColumnAndRow($col, 2);
        $cell->setValue($header);
        $cell->getStyle()->applyFromArray([
            'font'      => ['bold'=>true,'size'=>9,'color'=>['argb'=>'FF263238']],
            'fill'      => ['fillType'=>'solid','startColor'=>['argb'=>$LIGHT[$grp] ?? $C_LGRAY]],
            'alignment' => ['horizontal'=>'center','vertical'=>'center','wrapText'=>true],
            'borders'   => ['allBorders'=>['borderStyle'=>'thin','color'=>['argb'=>'FFCCCCCC']]],
        ]);
        $ws->getColumnDimensionByColumn($col)->setWidth($width);
    }
    $ws->getRowDimension(1)->setRowHeight(18);
    $ws->getRowDimension(2)->setRowHeight(30);
    $ws->freezePane('A3');

    // Données
    $yn_fields = ['centre','rapatrieur','autre','forme','forme_soin','forme_rapat',
                  'adhesion','caisses','gants','garde_domicile','recu_fiscal',
                  'zone_pb','zone_bearn','zone_landes','zone_hp',
                  'comp_menage','comp_oisillons','comp_marins','comp_vautour','comp_exterieurs','comp_heris',
                  'comp_brico','comp_info','comp_commu','comp_compta','comp_photo','comp_couture'];

    foreach ($data as $ri => $b) {
        $row = $ri + 3;
        $vehs = $b['vehicules'] ?? [];
        if (empty($vehs) && (!empty($b['immat']) || !empty($b['puissance']) || !empty($b['moteur']))) {
            $vehs = [['immat'=>$b['immat']??'','puissance'=>$b['puissance']??'','moteur'=>$b['moteur']??'']];
        }
        $immat_str     = implode(' | ', array_filter(array_column($vehs, 'immat')));
        $puissance_str = implode(' | ', array_filter(array_column($vehs, 'puissance')));
        $moteur_str    = implode(' | ', array_filter(array_column($vehs, 'moteur')));

        foreach ($COLS as $ci => [$header, $field, $width, $grp]) {
            $col = $ci + 1;
            if ($field === 'immat') $val = $immat_str;
            elseif ($field === 'puissance') $val = $puissance_str;
            elseif ($field === 'moteur') $val = $moteur_str;
            else $val = $b[$field] ?? '';

            if (in_array($field, $yn_fields, true)) {
                $val = yn($val);
            } elseif ($field === 'derniers_rapat') {
                $val = fmt_rapat_val($val);
            } elseif (in_array($field, ['date_creation','modif'], true)) {
                $val = explode(' ', (string)$val)[0] ?? '';
            } else {
                $val = (string)($val ?? '');
            }

            $cell = $ws->getCellByColumnAndRow($col, $row);
            $cell->setValue($val);
            $cell->getStyle()->applyFromArray([
                'font'      => ['size'=>9],
                'alignment' => ['horizontal'=> ($col > 3 ? 'left' : 'center'), 'vertical'=>'center','wrapText'=>true],
                'borders'   => ['allBorders'=>['borderStyle'=>'thin','color'=>['argb'=>'FFEEEEEE']]],
            ]);
            if ($row % 2 === 0) {
                $cell->getStyle()->getFill()->setFillType('solid')
                     ->getStartColor()->setARGB('FFF9F9F9');
            }
        }
        $ws->getRowDimension($row)->setRowHeight(15);
    }

    // Feuille rapatriements
    $ws2 = $spreadsheet->createSheet();
    $ws2->setTitle('Rapatriements');
    $rap_headers = ['Nom','Prénom','Date','H. départ','H. arrivée','Distance (km)','Trajet / Détail','Animal / Espèce','Notes'];
    $rap_widths  = [18,16,14,12,12,14,40,22,30];
    foreach ($rap_headers as $ci => $h) {
        $col = $ci + 1;
        $cell = $ws2->getCellByColumnAndRow($col, 1);
        $cell->setValue($h);
        $cell->getStyle()->applyFromArray([
            'font'      => ['bold'=>true,'size'=>9,'color'=>['argb'=>'FFFFFFFF']],
            'fill'      => ['fillType'=>'solid','startColor'=>['argb'=>$C_ORANG]],
            'alignment' => ['horizontal'=>'center','vertical'=>'center'],
            'borders'   => ['allBorders'=>['borderStyle'=>'thin','color'=>['argb'=>'FFCCCCCC']]],
        ]);
        $ws2->getColumnDimensionByColumn($col)->setWidth($rap_widths[$ci]);
    }
    $ws2->getRowDimension(1)->setRowHeight(22);
    $ws2->freezePane('A2');

    $rap_row = 2;
    foreach ($data as $b) {
        $raps = $b['derniers_rapat'] ?? '';
        $rapat_list = is_array($raps) ? $raps : parse_rapat_text((string)$raps);
        foreach ($rapat_list as $r) {
            $trajet_raw = $r['trajet'] ?? '';
            $trajet = is_array($trajet_raw) ? implode(' > ', $trajet_raw) : ($trajet_raw ?: ($r['lieu'] ?? ($r['commune'] ?? '')));
            $animal = $r['animal'] ?? ($r['espece'] ?? '');
            $notes  = $r['notes'] ?? ($r['commentaire'] ?? '');
            $dist   = $r['distance_km'] ?? '';
            $dist_val = is_numeric($dist) ? (int)$dist : $dist;
            $row_vals = [
                $b['nom'] ?? '', $b['prenom'] ?? '', $r['date'] ?? '',
                $r['heure_depart'] ?? '', $r['heure_arrivee'] ?? '',
                $dist_val, $trajet, $animal, $notes,
            ];
            foreach ($row_vals as $ci => $v) {
                $cell = $ws2->getCellByColumnAndRow($ci+1, $rap_row);
                $cell->setValue($v);
                $cell->getStyle()->applyFromArray([
                    'font'      => ['size'=>9],
                    'alignment' => ['horizontal'=> ($ci===5?'center':'left'), 'vertical'=>'center','wrapText'=>true],
                    'borders'   => ['allBorders'=>['borderStyle'=>'thin','color'=>['argb'=>'FFEEEEEE']]],
                ]);
                if ($rap_row % 2 === 0) {
                    $cell->getStyle()->getFill()->setFillType('solid')
                         ->getStartColor()->setARGB('FFFFF8F4');
                }
            }
            $ws2->getRowDimension($rap_row)->setRowHeight(14);
            $rap_row++;
        }
    }

    $fname = $year ? "benevoles_{$year}.xlsx" : 'benevoles_export.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    send_cors_headers();

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

// ── Photos ────────────────────────────────────────────────────────────────────

function get_photo_path(string $bev_id): ?string
{
    foreach (['jpg','jpeg','png','gif','webp'] as $ext) {
        $p = PHOTOS_DIR . "/{$bev_id}.{$ext}";
        if (is_file($p)) return $p;
    }
    return null;
}

function photo_mime(string $path): string
{
    if (str_ends_with($path, '.jpg') || str_ends_with($path, '.jpeg')) return 'image/jpeg';
    if (str_ends_with($path, '.png'))  return 'image/png';
    if (str_ends_with($path, '.gif'))  return 'image/gif';
    return 'image/webp';
}

// ── Routes ────────────────────────────────────────────────────────────────────

function route_benevoles_get(): void
{
    json_response(load_benevoles());
}

function route_benevoles_drafts_get(): void
{
    json_response(json_read(BENEVOLES_DRAFTS_FILE, []));
}

function route_benevoles_drafts_post(): void
{
    $draft  = read_json_body();
    $drafts = json_read(BENEVOLES_DRAFTS_FILE, []);
    $draft_id = $draft['_draftId'] ?? null;
    $found = false;
    foreach ($drafts as $i => $d) {
        if (($d['_draftId'] ?? null) === $draft_id) {
            $drafts[$i] = $draft;
            $found = true;
            break;
        }
    }
    if (!$found) $drafts[] = $draft;
    json_write(BENEVOLES_DRAFTS_FILE, $drafts);
    json_response(['ok' => true]);
}

function route_benevoles_drafts_delete(string $draft_id): void
{
    $drafts = json_read(BENEVOLES_DRAFTS_FILE, []);
    $drafts = array_values(array_filter($drafts, fn($d) => ($d['_draftId'] ?? '') !== $draft_id));
    json_write(BENEVOLES_DRAFTS_FILE, $drafts);
    json_response(['ok' => true]);
}

function route_benevoles_add_post(): void
{
    $body = read_json_body();
    try {
        $new_id = add_benevole($body);
        maybe_run_backup();
        json_response(['ok' => true, 'id' => $new_id]);
    } catch (RuntimeException $e) {
        json_response(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

function route_benevoles_update_post(): void
{
    $body       = read_json_body();
    $orig_nom   = trim((string)($body['_orig_nom'] ?? ''));
    $orig_prenom= trim((string)($body['_orig_prenom'] ?? ''));
    unset($body['_orig_nom'], $body['_orig_prenom']);
    try {
        update_benevole($orig_nom, $orig_prenom, $body);
        maybe_run_backup();
        json_response(['ok' => true]);
    } catch (RuntimeException $e) {
        json_response(['ok' => false, 'error' => $e->getMessage()], 404);
    }
}

function route_benevoles_delete_post(): void
{
    $body    = read_json_body();
    $benv_id = (string)($body['benevole_id'] ?? '');
    $deleted = delete_benevole($benv_id);
    maybe_run_backup();
    json_response(['ok' => $deleted > 0, 'deleted' => $deleted]);
}

function route_benevoles_export_get(?int $year = null): void
{
    export_benevoles($year, null);
}

function route_benevoles_export_post(): void
{
    $body = read_json_body();
    $ids  = isset($body['ids']) && is_array($body['ids']) ? $body['ids'] : null;
    export_benevoles(null, $ids);
}

function route_benevoles_photo_get(string $bev_id): void
{
    $path = get_photo_path($bev_id);
    if (!$path) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: ' . photo_mime($path));
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: no-cache');
    readfile($path);
    exit;
}

function route_benevoles_photo_post(string $bev_id): void
{
    if ($bev_id === '' || strpos($bev_id, '/') !== false || strpos($bev_id, '..') !== false) {
        json_response(['ok' => false, 'error' => 'id invalide'], 400);
    }

    $content_type = $_SERVER['CONTENT_TYPE'] ?? '';
    $max = 5 * 1024 * 1024;
    $raw = file_get_contents('php://input');
    if (strlen($raw) > $max) {
        json_response(['ok' => false, 'error' => 'fichier trop grand (max 5 Mo)'], 400);
    }

    // Déterminer l'extension
    if (str_contains($content_type, 'jpeg') || str_contains($content_type, 'jpg')) {
        $ext = 'jpg';
    } elseif (str_contains($content_type, 'png')) {
        $ext = 'png';
    } elseif (str_contains($content_type, 'gif')) {
        $ext = 'gif';
    } elseif (str_contains($content_type, 'webp')) {
        $ext = 'webp';
    } elseif (strlen($raw) >= 4 && substr($raw, 0, 4) === "\x89PNG") {
        $ext = 'png';
    } elseif (strlen($raw) >= 2 && substr($raw, 0, 2) === "\xff\xd8") {
        $ext = 'jpg';
    } else {
        $ext = 'jpg';
    }

    if (!is_dir(PHOTOS_DIR)) mkdir(PHOTOS_DIR, 0755, true);

    // Supprimer l'ancienne photo
    foreach (['jpg','jpeg','png','gif','webp'] as $old_ext) {
        $old = PHOTOS_DIR . "/{$bev_id}.{$old_ext}";
        if (is_file($old)) unlink($old);
    }

    $dest = PHOTOS_DIR . "/{$bev_id}.{$ext}";
    file_put_contents($dest, $raw);
    json_response(['ok' => true, 'url' => "/api/benevoles/photo/{$bev_id}"]);
}

function route_benevoles_photo_delete(string $bev_id): void
{
    $deleted = false;
    foreach (['jpg','jpeg','png','gif','webp'] as $ext) {
        $p = PHOTOS_DIR . "/{$bev_id}.{$ext}";
        if (is_file($p)) {
            unlink($p);
            $deleted = true;
        }
    }
    json_response(['ok' => true, 'deleted' => $deleted]);
}

function route_benevoles_reimport_post(): void
{
    try {
        $result = reimport_from_xls();
        maybe_run_backup();
        json_response(['ok' => true, ...$result]);
    } catch (RuntimeException $e) {
        json_response(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

function route_clear_rapatriements_post(): void
{
    $body = read_json_body();
    $year = (int)($body['year'] ?? 0);
    if (!$year) {
        json_response(['ok' => false, 'error' => 'year manquant'], 400);
    }
    $result = clear_rapatriements_year($year);
    maybe_run_backup();
    json_response(['ok' => true, ...$result]);
}
