<?php
/**
 * Pure-PHP ZIP reader used when PHP ZipArchive is not installed.
 * Supports stored and deflated ZIP entries, which covers DOCX/XLSX/ODT/ZIP imports.
 */
function qps_zip_entries(string $zipPath): array {
    $data = @file_get_contents($zipPath);
    if ($data === false) throw new RuntimeException('Unable to read ZIP container.');
    return qps_zip_entries_from_string($data);
}

function qps_zip_entries_from_string(string $data): array {
    $len = strlen($data);
    $eocd = strrpos($data, "\x50\x4b\x05\x06");
    if ($eocd === false) throw new RuntimeException('Invalid ZIP container: end record not found.');
    $cdSize = unpack('V', substr($data, $eocd + 12, 4))[1];
    $cdOffset = unpack('V', substr($data, $eocd + 16, 4))[1];
    $count = unpack('v', substr($data, $eocd + 10, 2))[1];

    $out = [];
    $p = $cdOffset;
    for ($i=0; $i<$count && $p+46 <= $len; $i++) {
        if (substr($data, $p, 4) !== "\x50\x4b\x01\x02") break;
        $method = unpack('v', substr($data, $p+10, 2))[1];
        $compSize = unpack('V', substr($data, $p+20, 4))[1];
        $uncompSize = unpack('V', substr($data, $p+24, 4))[1];
        $nameLen = unpack('v', substr($data, $p+28, 2))[1];
        $extraLen = unpack('v', substr($data, $p+30, 2))[1];
        $commentLen = unpack('v', substr($data, $p+32, 2))[1];
        $localOffset = unpack('V', substr($data, $p+42, 4))[1];
        $name = substr($data, $p+46, $nameLen);
        $name = str_replace('\\', '/', $name);
        $out[$name] = [
            'name'=>$name,'method'=>$method,'comp_size'=>$compSize,
            'uncomp_size'=>$uncompSize,'local_offset'=>$localOffset
        ];
        $p += 46 + $nameLen + $extraLen + $commentLen;
    }
    return [$data, $out];
}

function qps_zip_read_entry(array $zip, string $name): ?string {
    [$data, $entries] = $zip;
    if (!isset($entries[$name])) return null;
    $e = $entries[$name];
    $p = $e['local_offset'];
    if (substr($data, $p, 4) !== "\x50\x4b\x03\x04") return null;
    $nameLen = unpack('v', substr($data, $p+26, 2))[1];
    $extraLen = unpack('v', substr($data, $p+28, 2))[1];
    $start = $p + 30 + $nameLen + $extraLen;
    $raw = substr($data, $start, $e['comp_size']);
    if ($e['method'] === 0) return $raw;
    if ($e['method'] === 8) {
        $decoded = @gzinflate($raw);
        return $decoded === false ? null : $decoded;
    }
    throw new RuntimeException('Unsupported ZIP compression method for '.$name);
}

function qps_zip_list(array $zip): array {
    return array_keys($zip[1]);
}
?>
