<?php
$path = __DIR__ . '/SQL-File.sql';
if (! file_exists($path)) {
    fwrite(STDERR, "Missing SQL file: $path\n");
    exit(1);
}
$text = file_get_contents($path);
if (! mb_check_encoding($text, 'UTF-8')) {
    $enc = mb_detect_encoding($text, ['UTF-8', 'CP1256', 'ISO-8859-1', 'CP1252', 'ASCII'], true);
    if ($enc && $enc !== 'UTF-8') {
        $text = mb_convert_encoding($text, 'UTF-8', $enc);
    } else {
        $text = mb_convert_encoding($text, 'UTF-8', 'CP1256');
        $enc = 'CP1256';
    }
} else {
    $enc = 'UTF-8';
}
$text = str_replace(["\r\n", "\r"], "\n", $text);
$governorates = parseInsertSection($text, 'governorates');
$cities = parseInsertSection($text, 'cities');
$output = ['governorates' => $governorates, 'cities' => $cities];
$meta = ['encoding' => $enc, 'gov_count' => count($governorates), 'city_count' => count($cities)];
file_put_contents(__DIR__ . '/database/seeders/egypt-address-data.json', json_encode($output, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
file_put_contents(__DIR__ . '/database/seeders/egypt-address-data-meta.json', json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo sprintf("Created egypt-address-data.json with %d governorates and %d cities. Detected encoding: %s\n", count($governorates), count($cities), $enc);

function parseInsertSection(string $text, string $table): array
{
    $needle = "INSERT INTO `$table`";
    $pos = stripos($text, $needle);
    if ($pos === false) {
        fwrite(STDERR, "Missing INSERT statement for table $table.\n");
        return [];
    }

    $valuesPos = stripos($text, 'VALUES', $pos);
    if ($valuesPos === false) {
        fwrite(STDERR, "Missing VALUES clause for table $table.\n");
        return [];
    }

    $content = substr($text, $valuesPos + strlen('VALUES'));
    $lines = explode("\n", $content);
    $rows = [];
    $finished = false;

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (str_starts_with($line, '/*')) {
            continue;
        }
        if ($line === ');' || $line === ');') {
            break;
        }
        if (substr($line, -1) === ';') {
            $line = substr($line, 0, -1);
            $finished = true;
        }

        if (preg_match('/^\(\s*([0-9]+)\s*,\s*\'([^\']*)\'\s*,\s*\'([^\']*)\'\s*\),?$/u', $line, $match)) {
            if ($table === 'governorates') {
                $rows[] = ['id' => (int) $match[1], 'name_ar' => stripslashes($match[2]), 'name_en' => stripslashes($match[3])];
            } else {
                $rows[] = ['governorate_id' => (int) $match[1], 'name_ar' => stripslashes($match[2]), 'name_en' => stripslashes($match[3])];
            }
        }

        if ($finished) {
            break;
        }
    }

    return $rows;
}
