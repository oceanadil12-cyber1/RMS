<?php
/**
 * A small QR code maker for the table cards (Admin → Table QR codes): byte mode,
 * error correction level M (still scans with about 15% of the code scuffed or
 * covered), versions 1–10 (up to 213 characters, far more than a table link needs),
 * the mask with the lowest penalty, drawn as an SVG. No outside service or library.
 * Follows ISO/IEC 18004; the structure mirrors Project Nayuki's reference encoder.
 */
if (!defined('ADMIN') && PHP_SAPI !== 'cli') { http_response_code(403); exit('Forbidden'); }

// Per version 1–10 at level M: [error correction codewords per block, number of blocks].
const QR_M_BLOCKS = [1 => [10, 1], [16, 1], [26, 1], [18, 2], [24, 2], [16, 4], [18, 4], [22, 4], [22, 5], [26, 5]];
const QR_ALIGN = [1 => [], [6, 18], [6, 22], [6, 26], [6, 30], [6, 34], [6, 22, 38], [6, 24, 42], [6, 26, 46], [6, 28, 50]];

/** Codewords (data + error correction) in a version, before splitting into blocks. */
function qr_raw_codewords(int $ver): int
{
    $bits = (16 * $ver + 128) * $ver + 64;
    if ($ver >= 2) {
        $n = intdiv($ver, 7) + 2;
        $bits -= (25 * $n - 10) * $n - 55;
        if ($ver >= 7) $bits -= 36;
    }
    return intdiv($bits, 8);
}

function qr_gf_mul(int $x, int $y): int
{
    $z = 0;
    for ($i = 7; $i >= 0; $i--) {
        $z = (($z << 1) ^ (($z >> 7) * 0x11D)) & 0xFF;
        $z ^= (($y >> $i) & 1) * $x;
    }
    return $z;
}

/** Reed–Solomon error correction codewords for $data. */
function qr_rs(array $data, int $degree): array
{
    $gen = array_fill(0, $degree, 0);
    $gen[$degree - 1] = 1;
    $root = 1;
    for ($i = 0; $i < $degree; $i++) {
        for ($j = 0; $j < $degree; $j++) {
            $gen[$j] = qr_gf_mul($gen[$j], $root);
            if ($j + 1 < $degree) $gen[$j] ^= $gen[$j + 1];
        }
        $root = qr_gf_mul($root, 0x02);
    }
    $rem = array_fill(0, $degree, 0);
    foreach ($data as $b) {
        $factor = $b ^ array_shift($rem);
        $rem[] = 0;
        foreach ($gen as $k => $coef) $rem[$k] ^= qr_gf_mul($coef, $factor);
    }
    return $rem;
}

/** The QR code for $text as rows of booleans (true = dark), or null if it's too long. */
function qr_matrix(string $text): ?array
{
    $bytes = array_values(unpack('C*', $text) ?: []);
    $len = count($bytes);
    for ($ver = 1; $ver <= 10; $ver++) {
        [$eccLen, $numBlocks] = QR_M_BLOCKS[$ver];
        $dataCap = qr_raw_codewords($ver) - $eccLen * $numBlocks;
        $ccBits = $ver <= 9 ? 8 : 16;
        if (4 + $ccBits + 8 * $len <= $dataCap * 8) break;
    }
    if ($ver > 10) return null;

    // Data bits: byte mode, length, the bytes, terminator, then pad bytes 0xEC 0x11.
    $bits = '0100' . str_pad(decbin($len), $ccBits, '0', STR_PAD_LEFT);
    foreach ($bytes as $b) $bits .= str_pad(decbin($b), 8, '0', STR_PAD_LEFT);
    $bits .= str_repeat('0', min(4, $dataCap * 8 - strlen($bits)));
    $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);
    $data = array_map('bindec', str_split($bits, 8));
    for ($pad = 0xEC; count($data) < $dataCap; $pad ^= 0xEC ^ 0x11) $data[] = $pad;

    // Split into blocks, add error correction, interleave.
    $raw = qr_raw_codewords($ver);
    $numShort = $numBlocks - $raw % $numBlocks;
    $shortLen = intdiv($raw, $numBlocks);
    $blocks = [];
    $k = 0;
    for ($i = 0; $i < $numBlocks; $i++) {
        $n = $shortLen - $eccLen + ($i < $numShort ? 0 : 1);
        $dat = array_slice($data, $k, $n);
        $k += $n;
        $ecc = qr_rs($dat, $eccLen);
        if ($i < $numShort) $dat[] = 0; // placeholder so every block lines up
        $blocks[] = array_merge($dat, $ecc);
    }
    $codewords = [];
    for ($i = 0, $blockLen = count($blocks[0]); $i < $blockLen; $i++) {
        foreach ($blocks as $j => $blk) {
            if ($i !== $shortLen - $eccLen || $j >= $numShort) $codewords[] = $blk[$i];
        }
    }

    // Function patterns: timing, finders (with separators), alignment, format and version areas.
    $size = 17 + 4 * $ver;
    $m = array_fill(0, $size, array_fill(0, $size, false));
    $fn = array_fill(0, $size, array_fill(0, $size, false));
    $set = function (int $x, int $y, bool $dark) use (&$m, &$fn) { $m[$y][$x] = $dark; $fn[$y][$x] = true; };
    for ($i = 0; $i < $size; $i++) { $set(6, $i, $i % 2 === 0); $set($i, 6, $i % 2 === 0); }
    foreach ([[3, 3], [$size - 4, 3], [3, $size - 4]] as [$cx, $cy]) {
        for ($dy = -4; $dy <= 4; $dy++) for ($dx = -4; $dx <= 4; $dx++) {
            $x = $cx + $dx; $y = $cy + $dy;
            if ($x >= 0 && $x < $size && $y >= 0 && $y < $size) { $d = max(abs($dx), abs($dy)); $set($x, $y, $d !== 2 && $d !== 4); }
        }
    }
    $pos = QR_ALIGN[$ver];
    $last = count($pos) - 1;
    foreach ($pos as $i => $ax) foreach ($pos as $j => $ay) {
        if (($i === 0 && $j === 0) || ($i === 0 && $j === $last) || ($i === $last && $j === 0)) continue;
        for ($dy = -2; $dy <= 2; $dy++) for ($dx = -2; $dx <= 2; $dx++) $set($ax + $dx, $ay + $dy, max(abs($dx), abs($dy)) !== 1);
    }
    $format = function (int $mask) use ($set, $size) {
        $data = (0 << 3) | $mask; // level M = 00
        $rem = $data;
        for ($i = 0; $i < 10; $i++) $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        $bits = (($data << 10) | $rem) ^ 0x5412;
        $bit = fn($i) => (($bits >> $i) & 1) === 1;
        for ($i = 0; $i <= 5; $i++) $set(8, $i, $bit($i));
        $set(8, 7, $bit(6)); $set(8, 8, $bit(7)); $set(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) $set(14 - $i, 8, $bit($i));
        for ($i = 0; $i < 8; $i++) $set($size - 1 - $i, 8, $bit($i));
        for ($i = 8; $i < 15; $i++) $set(8, $size - 15 + $i, $bit($i));
        $set(8, $size - 8, true); // the dark module
    };
    $format(0); // reserve the area; the real bits go in once the mask is chosen
    if ($ver >= 7) {
        $rem = $ver;
        for ($i = 0; $i < 12; $i++) $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
        $vbits = ($ver << 12) | $rem;
        for ($i = 0; $i < 18; $i++) {
            $dark = (($vbits >> $i) & 1) === 1;
            $a = $size - 11 + $i % 3; $b = intdiv($i, 3);
            $set($a, $b, $dark); $set($b, $a, $dark);
        }
    }

    // Data, in the zigzag from the bottom-right corner, two columns at a time.
    $total = count($codewords) * 8;
    $i = 0;
    for ($right = $size - 1; $right >= 1; $right -= 2) {
        if ($right === 6) $right = 5; // skip the vertical timing line
        for ($vert = 0; $vert < $size; $vert++) {
            for ($j = 0; $j < 2; $j++) {
                $x = $right - $j;
                $y = (($right + 1) & 2) === 0 ? $size - 1 - $vert : $vert;
                if (!$fn[$y][$x] && $i < $total) {
                    $m[$y][$x] = (($codewords[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                    $i++;
                }
            }
        }
    }

    // Try all eight masks; keep the one that scans most reliably (lowest penalty).
    $masks = [
        fn($x, $y) => ($x + $y) % 2 === 0,
        fn($x, $y) => $y % 2 === 0,
        fn($x, $y) => $x % 3 === 0,
        fn($x, $y) => ($x + $y) % 3 === 0,
        fn($x, $y) => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
        fn($x, $y) => $x * $y % 2 + $x * $y % 3 === 0,
        fn($x, $y) => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
        fn($x, $y) => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
    ];
    $base = $m;
    $best = null;
    foreach ($masks as $mi => $f) {
        $m = $base;
        for ($y = 0; $y < $size; $y++) for ($x = 0; $x < $size; $x++) {
            if (!$fn[$y][$x] && $f($x, $y)) $m[$y][$x] = !$m[$y][$x];
        }
        $format($mi);
        $score = qr_penalty($m);
        if ($best === null || $score < $best[0]) $best = [$score, $m];
    }
    return $best[1];
}

/** How hard a finished code is to scan (lower is better): long runs, 2×2 blocks, finder look-alikes, dark balance. */
function qr_penalty(array $m): int
{
    $size = count($m);
    $p = 0;
    $lines = [];
    for ($i = 0; $i < $size; $i++) { $lines[] = $m[$i]; $lines[] = array_column($m, $i); }
    foreach ($lines as $line) {
        $run = 1;
        for ($i = 1; $i <= $size; $i++) {
            if ($i < $size && $line[$i] === $line[$i - 1]) { $run++; continue; }
            if ($run >= 5) $p += $run - 2;
            $run = 1;
        }
        $s = implode('', array_map(fn($b) => $b ? '1' : '0', $line));
        $p += 40 * (substr_count($s, '10111010000') + substr_count($s, '00001011101'));
    }
    $dark = 0;
    for ($y = 0; $y < $size; $y++) for ($x = 0; $x < $size; $x++) {
        if ($m[$y][$x]) $dark++;
        if ($x < $size - 1 && $y < $size - 1 && $m[$y][$x] === $m[$y][$x + 1] && $m[$y][$x] === $m[$y + 1][$x] && $m[$y][$x] === $m[$y + 1][$x + 1]) $p += 3;
    }
    $p += 10 * intdiv(abs($dark * 20 - $size * $size * 10), $size * $size);
    return $p;
}

/** The QR code as an SVG (dark squares on white, with the 4-module quiet zone scanners need). */
function qr_svg(string $text, string $label = ''): string
{
    $m = qr_matrix($text);
    if ($m === null) return '';
    $size = count($m);
    $full = $size + 8;
    $path = '';
    foreach ($m as $y => $row) {
        $x = 0;
        while ($x < $size) {
            if (!$row[$x]) { $x++; continue; }
            $start = $x;
            while ($x < $size && $row[$x]) $x++;
            $path .= 'M' . ($start + 4) . ' ' . ($y + 4) . 'h' . ($x - $start) . 'v1h-' . ($x - $start) . 'z';
        }
    }
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $full . ' ' . $full . '" shape-rendering="crispEdges"'
        . ($label !== '' ? ' role="img" aria-label="' . htmlspecialchars($label, ENT_QUOTES) . '"' : '') . '>'
        . '<rect width="' . $full . '" height="' . $full . '" fill="#fff"/><path fill="#000" d="' . $path . '"/></svg>';
}
