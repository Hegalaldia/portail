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

// Test POST /api/auth via curl HTTP réel
echo "<h3>Test POST /api/auth (curl HTTP)</h3>";
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$api_url = $scheme . '://' . $host . '/api/auth';
echo "<p>URL testée : <code>" . htmlspecialchars($api_url) . "</code></p>";

// Test 1 : mauvais mot de passe
$ch = curl_init($api_url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode(['password' => 'WRONG_TEST_PASSWORD_DIAGNOSTIC']),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_HEADER         => true,
]);
$raw = curl_exec($ch);
$err = curl_error($ch);
$info = curl_getinfo($ch);
curl_close($ch);

if ($err) {
    echo "<p style='color:red'>❌ curl error : " . htmlspecialchars($err) . "</p>";
} else {
    $header_size = $info['header_size'];
    $headers_raw = substr($raw, 0, $header_size);
    $body = substr($raw, $header_size);
    $http_code = $info['http_code'];
    echo "<p>HTTP status : <strong>" . $http_code . "</strong></p>";
    echo "<p>Content-Type reçu : <code>" . htmlspecialchars($info['content_type'] ?? '') . "</code></p>";
    echo "<p>Réponse brute (200 premiers octets) :</p>";
    echo "<pre style='background:#f0f0f0;padding:8px;overflow-x:auto'>" . htmlspecialchars(substr($body, 0, 200)) . "</pre>";
    $decoded = json_decode($body, true);
    if ($decoded !== null) {
        echo "<p style='color:green'>✅ JSON valide — ok=" . ($decoded['ok'] ? 'true' : 'false') . "</p>";
    } else {
        echo "<p style='color:red'>❌ Réponse non-JSON ! C'est la cause de l'erreur réseau côté JS.</p>";
        echo "<p>Headers reçus :</p><pre style='background:#f0f0f0;padding:8px;font-size:11px'>" . htmlspecialchars(substr($headers_raw, 0, 500)) . "</pre>";
    }
}

// Vérification config auth directe (sans include qui fait exit)
echo "<h3>Vérification config auth (sans include)</h3>";
require_once $root . '/api/utils.php';
require_once $root . '/api/auth.php';
$cfg = load_config();
$pwd_cfg = $cfg['portal_password'] ?? '(non défini — défaut: Hegalaldia2026)';
echo "<p>✅ auth.php chargé sans erreur</p>";
echo "<p>Mot de passe configuré : <code>" . htmlspecialchars($pwd_cfg) . "</code></p>";
$tokens_file = BASE_DIR . '/.auth_tokens.json';
echo "<p>Fichier .auth_tokens.json : " . (file_exists($tokens_file) ? '✅ existe' : '⚠️ n\'existe pas encore (normal au 1er login)') . "</p>";
$fl = json_read(BASE_DIR . '/failed_logins.json', []);
$ip = $_SERVER['REMOTE_ADDR'] ?? '?';
echo "<p>IP actuelle : <code>" . htmlspecialchars($ip) . "</code></p>";
if (isset($fl[$ip])) {
    $entry = $fl[$ip];
    $blocked = ($entry['blocked_until'] ?? 0) > time();
    echo "<p style='color:orange'>⚠️ Tentatives échouées pour cette IP : " . ($entry['count'] ?? 0) . ($blocked ? ', BLOQUÉE jusqu\'à ' . date('H:i:s', $entry['blocked_until']) : ', non bloquée') . "</p>";
    echo "<form method='post' action=''><input type='hidden' name='reset_ip' value='" . htmlspecialchars($ip) . "'><button type='submit'>🔓 Réinitialiser le blocage pour cette IP</button></form>";
} else {
    echo "<p>✅ Aucun blocage pour cette IP</p>";
}

// Traitement reset si demandé
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_ip'])) {
    $target_ip = $_POST['reset_ip'];
    $fl2 = json_read(BASE_DIR . '/failed_logins.json', []);
    unset($fl2[$target_ip]);
    json_write(BASE_DIR . '/failed_logins.json', $fl2);
    echo "<p style='color:green'>✅ Blocage réinitialisé pour " . htmlspecialchars($target_ip) . "</p>";
}
