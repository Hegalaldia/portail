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

echo "<h3>PHP</h3><ul>";
echo "<li>Version : " . phpversion() . "</li>";
echo "<li>mod_rewrite : " . (in_array('mod_rewrite', apache_get_modules() ?? []) ? '✅ actif' : '⚠️ non détectable') . "</li>";
echo "</ul>";

echo "<h3>Test route API</h3>";
$test = file_get_contents('http://' . $_SERVER['HTTP_HOST'] . '/api/benevoles');
echo $test !== false ? "✅ /api/benevoles répond" : "❌ /api/benevoles ne répond pas";
