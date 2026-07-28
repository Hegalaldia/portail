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
