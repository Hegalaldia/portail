<?php
// Diagnostic temporaire — à supprimer après vérification
$base = dirname(__DIR__ . '/../');
$root = dirname(__FILE__);

$files = [
    'benevoles.json',
    'benevoles_drafts.json',
    'messages.json',
    'tokens.json',
    'failed_logins.json',
    'config.json',
    'clinic_stats.json',
    'clinic_history.json',
    'geo_cache.json',
];

$dirs = ['photos', 'backups', 'api'];

echo "<h2>Diagnostic Hegalaldia</h2>";
echo "<h3>Chemin racine : " . $root . "</h3>";

echo "<h3>Fichiers JSON</h3><ul>";
foreach ($files as $f) {
    $path = $root . '/' . $f;
    $exists = file_exists($path);
    $writable = $exists && is_writable($path);
    $color = $exists ? ($writable ? 'green' : 'orange') : 'red';
    echo "<li style='color:$color'>$f : " . ($exists ? ($writable ? '✅ OK' : '⚠️ non accessible en écriture') : '❌ manquant') . "</li>";
}
echo "</ul>";

echo "<h3>Dossiers</h3><ul>";
foreach ($dirs as $d) {
    $path = $root . '/' . $d;
    $exists = is_dir($path);
    $writable = $exists && is_writable($path);
    $color = $exists ? ($writable ? 'green' : 'orange') : 'red';
    echo "<li style='color:$color'>$d/ : " . ($exists ? ($writable ? '✅ OK' : '⚠️ non accessible en écriture') : '❌ manquant') . "</li>";
}
echo "</ul>";

// Créer les fichiers manquants
$defaults = [
    'benevoles_drafts.json' => '[]',
    'messages.json'         => '[]',
    'tokens.json'           => '[]',
    'failed_logins.json'    => '[]',
    'config.json'           => '{}',
    'geo_cache.json'        => '{}',
];
echo "<h3>Création fichiers manquants</h3><ul>";
foreach ($defaults as $f => $content) {
    $path = $root . '/' . $f;
    if (!file_exists($path)) {
        $ok = file_put_contents($path, $content);
        echo "<li>" . ($ok !== false ? "✅" : "❌") . " $f créé</li>";
    }
}
echo "</ul>";

// Créer dossier photos
$photosDir = $root . '/photos';
if (!is_dir($photosDir)) {
    $ok = mkdir($photosDir, 0755, true);
    echo "<p>" . ($ok ? "✅" : "❌") . " Dossier photos/ créé</p>";
}

echo "<h3>PHP</h3><ul>";
echo "<li>Version : " . phpversion() . "</li>";
echo "</ul>";

echo "<h3>Test routing API</h3>";
echo "<pre>";
echo "REQUEST_URI : " . $_SERVER['REQUEST_URI'] . "\n";
echo "SCRIPT_NAME : " . $_SERVER['SCRIPT_NAME'] . "\n";
echo "HTTP_HOST : " . $_SERVER['HTTP_HOST'] . "\n";
echo "</pre>";

// Test direct include de api/index.php pour voir les erreurs PHP
echo "<h3>Test GET /api/benevoles</h3>";
ob_start();
$_SERVER['REQUEST_URI'] = '/api/benevoles';
$_SERVER['REQUEST_METHOD'] = 'GET';
try {
    include $root . '/api/index.php';
    $output = ob_get_clean();
    echo "<p>✅ Réponse reçue (" . strlen($output) . " octets)</p>";
    echo "<pre>" . htmlspecialchars(substr($output, 0, 200)) . "</pre>";
} catch (Throwable $e) {
    ob_get_clean();
    echo "<p style='color:red'>❌ Erreur : " . $e->getMessage() . " dans " . $e->getFile() . " ligne " . $e->getLine() . "</p>";
}

// Test POST /api/auth
echo "<h3>Test POST /api/auth</h3>";
ob_start();
// Simuler un POST avec un faux mot de passe
$_SERVER['REQUEST_URI'] = '/api/auth';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['CONTENT_TYPE'] = 'application/json';
// Patcher php://input via un fichier temporaire n'est pas possible, on va juste tester la route
try {
    // Tester la fonction check_portal_password directement
    require_once $root . '/api/utils.php';
    require_once $root . '/api/auth.php';
    ob_get_clean();
    $cfg = load_config();
    $pwd_cfg = $cfg['portal_password'] ?? '(non défini — défaut: Hegalaldia2026)';
    echo "<p>✅ auth.php chargé sans erreur</p>";
    echo "<p>Mot de passe configuré : <code>" . htmlspecialchars($pwd_cfg) . "</code></p>";
    $tokens_file = BASE_DIR . '/.auth_tokens.json';
    echo "<p>Fichier .auth_tokens.json : " . (file_exists($tokens_file) ? '✅ existe' : '⚠️ n\'existe pas encore (normal au premier login)') . "</p>";
    $fl_file = BASE_DIR . '/failed_logins.json';
    $fl = json_read($fl_file, []);
    $ip = $_SERVER['REMOTE_ADDR'] ?? '?';
    echo "<p>IP actuelle : <code>" . htmlspecialchars($ip) . "</code></p>";
    if (isset($fl[$ip])) {
        $entry = $fl[$ip];
        echo "<p style='color:orange'>⚠️ Tentatives échouées pour cette IP : " . ($entry['count'] ?? 0) . ", bloquée jusqu'à : " . ($entry['blocked_until'] ?? 0 > time() ? date('H:i:s', $entry['blocked_until']) : 'non bloquée') . "</p>";
    } else {
        echo "<p>✅ Aucun blocage pour cette IP</p>";
    }
} catch (Throwable $e) {
    ob_get_clean();
    echo "<p style='color:red'>❌ Erreur : " . htmlspecialchars($e->getMessage()) . " dans " . htmlspecialchars($e->getFile()) . " ligne " . $e->getLine() . "</p>";
}
