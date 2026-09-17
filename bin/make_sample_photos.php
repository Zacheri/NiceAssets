<?php

require __DIR__ . '/_bootstrap.php';

use App\Core\Config;
use App\Core\Database;

$samples = [
    ['blue laptop.png', 'Dell Latitude 7490 (sample)', [37, 99, 235]],
    ['black monitor.png', 'Dell UltraSharp (sample)', [15, 23, 42]],
    ['grey chair.png', 'Aeron Chair (sample)', [100, 116, 139]],
];

$dir = Config::get('storage.uploads');
if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
}

$created = 0;
foreach ($samples as [$name, $variety, $rgb]) {
    $exists = Database::fetchOne('SELECT id FROM photos WHERE original_name = :n', ['n' => $name]);
    if ($exists !== null) {
        continue;
    }
    $img = imagecreatetruecolor(320, 200);
    $base = imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);
    $light = imagecolorallocate($img, min(255, $rgb[0] + 40), min(255, $rgb[1] + 40), min(255, $rgb[2] + 40));
    imagefill($img, 0, 0, $base);
    imagerectangle($img, 20, 20, 300, 180, $light);
    imagestring($img, 5, 90, 95, 'NAIMS sample', $light);
    $file = $dir . '/' . date('Ymd_His') . '_' . md5($name) . '.png';
    imagepng($img, $file);
    $filename = basename($file);
    Database::insert(
        'INSERT INTO photos (filename, original_name, variety, mime, size)
         VALUES (:f, :o, :v, :m, :s)',
        ['f' => $filename, 'o' => $name, 'v' => $variety, 'm' => 'image/png', 's' => filesize($file)]
    );
    $created++;
}
echo $created . " sample photo(s) added to the gallery.\n";
