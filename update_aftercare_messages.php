<?php
require __DIR__ . '/config.php';

// Update aftercare messages to Latin only - matched by name
$updates = [
    ['name' => '%цэвэрлэгээ%', 'msg' => 'Sain baina uu! Shudnii tseverlegee hiigesnees 6 sar bolloo. Dahin tsag avna uu.'],
    ['name' => '%Суулгац%', 'msg' => 'Sain baina uu! Suulgats emchilgeenii daraa 3 sar bolloo. Shalgalt hiigene uu.'],
    ['name' => '%Сувгийн%', 'msg' => 'Sain baina uu! Suvgin emchilgeenii shalgalt hiigeh tsag bolloo.'],
    ['name' => '%Ердийн%', 'msg' => 'Sain baina uu! Jiliin shudnii uzleg hiigeh tsag bolloo.'],
    ['name' => '%Гажиг%', 'msg' => ''],
    ['name' => '%filler%', 'msg' => 'Sain baina uu! Filler emchilgeenii daraa 6 sar bolloo. Dahin tsag avna uu.'],
];

$st = db()->prepare("UPDATE treatments SET aftercare_message = ? WHERE name LIKE ?");

foreach ($updates as $u) {
    $st->execute([$u['msg'], $u['name']]);
    echo "Updated: {$u['name']}\n";
}

// Show all current messages
echo "\n--- Current aftercare messages ---\n";
$all = db()->query("SELECT id, name, aftercare_message FROM treatments ORDER BY id")->fetchAll();
foreach ($all as $row) {
    echo "{$row['id']}. {$row['name']}: {$row['aftercare_message']}\n";
}

echo "\n✅ Done!\n";
