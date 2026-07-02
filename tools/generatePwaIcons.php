<?php
/**
 * FORK (beevee85): generátor PWA ikon z tvarů styles/logo.svg pomocí GD.
 *
 * Logo se skládá jen z jednoduchých tvarů (zaoblený čtverec s vertikálním
 * gradientem, polygon „M", kruh, fajfka), takže ho lze vykreslit v GD 1:1
 * bez SVG rasterizeru. Kreslí se ve 4× supersamplingu a zmenšuje
 * imagecopyresampled → hladké hrany. Při změně logo.svg uprav i tvary tady.
 *
 * Použití (PNG jde na stdout):
 *   php tools/generatePwaIcons.php <size> <plain|apple|maskable> > icon.png
 *
 *   plain    — logo jak je, průhledné rohy (manifest icon)
 *   apple    — plné pozadí #3B2D83, bez průhlednosti (apple-touch-icon)
 *   maskable — plné pozadí + logo zmenšené do 80% safe zóny (maskable icon)
 */

declare(strict_types=1);

if (!extension_loaded('gd')) {
    fwrite(STDERR, "PHP nemá rozšíření 'gd'.\n");
    exit(1);
}

$size = (int) ($argv[1] ?? 0);
$mode = (string) ($argv[2] ?? 'plain');
if ($size < 16 || $size > 2048 || !in_array($mode, ['plain', 'apple', 'maskable'], true)) {
    fwrite(STDERR, "Použití: php tools/generatePwaIcons.php <size 16-2048> <plain|apple|maskable>\n");
    exit(1);
}

const SS = 4; // supersampling faktor

/** Vykreslí logo (souřadný systém 64×64 → $px pixelů) na truecolor plátno. */
function renderLogo(int $px, bool $solidBg): \GdImage
{
    $img = imagecreatetruecolor($px, $px);
    imagealphablending($img, false);
    imagesavealpha($img, true);
    $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
    imagefilledrectangle($img, 0, 0, $px, $px, $transparent);
    imagealphablending($img, true);

    $s = $px / 64.0;

    if ($solidBg) {
        $bg = imagecolorallocate($img, 0x3B, 0x2D, 0x83);
        imagefilledrectangle($img, 0, 0, $px, $px, $bg);
    }

    // ── Zaoblený čtverec x=2 y=2 w=60 h=60 rx=12, vertikální gradient #6753AE → #3B2D83 ──
    $x0 = 2 * $s; $y0 = 2 * $s; $w = 60 * $s; $r = 12 * $s;
    [$r1, $g1, $b1] = [0x67, 0x53, 0xAE];
    [$r2, $g2, $b2] = [0x3B, 0x2D, 0x83];
    $yTop = (int) round($y0);
    $yBot = (int) round($y0 + $w);
    for ($y = $yTop; $y < $yBot; $y++) {
        $t = ($y - $y0) / $w; // 0..1 podél gradientu
        $col = imagecolorallocate(
            $img,
            (int) round($r1 + ($r2 - $r1) * $t),
            (int) round($g1 + ($g2 - $g1) * $t),
            (int) round($b1 + ($b2 - $b1) * $t),
        );
        // šířka řádku s ohledem na zaoblené rohy
        $dy = 0.0;
        if ($y < $y0 + $r)          $dy = ($y0 + $r) - $y;
        elseif ($y > $y0 + $w - $r) $dy = $y - ($y0 + $w - $r);
        $inset = $r - sqrt(max(0.0, $r * $r - $dy * $dy));
        imageline($img, (int) round($x0 + $inset), $y, (int) round($x0 + $w - $inset), $y, $col);
    }

    $white = imagecolorallocate($img, 0xFF, 0xFF, 0xFF);
    $ink   = imagecolorallocate($img, 0x3B, 0x2D, 0x83);

    // ── Monogram „M" — polygon z path (jen rovné úseky) ──
    $pts = [14,46, 14,18, 24,18, 32,34, 40,18, 50,18, 50,46, 43,46, 43,28, 34,44, 30,44, 21,28, 21,46];
    $poly = [];
    foreach ($pts as $v) $poly[] = (int) round($v * $s);
    imagefilledpolygon($img, $poly, $white);

    // ── Badge kruh cx=50 cy=14 r=9 ──
    imagefilledellipse($img, (int) round(50 * $s), (int) round(14 * $s), (int) round(18 * $s), (int) round(18 * $s), $white);

    // ── Fajfka (46,14)→(49,17)→(54,11), tloušťka 2.5, kulaté konce ──
    $thick = 2.5 * $s;
    $segs = [[46, 14, 49, 17], [49, 17, 54, 11]];
    foreach ($segs as [$ax, $ay, $bx, $by]) {
        drawThickLine($img, $ax * $s, $ay * $s, $bx * $s, $by * $s, $thick, $ink);
    }

    return $img;
}

/** Tlustá čára s kulatými konci: obdélníkový polygon + kruhy na koncích. */
function drawThickLine(\GdImage $img, float $ax, float $ay, float $bx, float $by, float $t, int $col): void
{
    $dx = $bx - $ax; $dy = $by - $ay;
    $len = sqrt($dx * $dx + $dy * $dy);
    if ($len < 0.001) return;
    $nx = -$dy / $len * $t / 2; $ny = $dx / $len * $t / 2;
    imagefilledpolygon($img, [
        (int) round($ax + $nx), (int) round($ay + $ny),
        (int) round($bx + $nx), (int) round($by + $ny),
        (int) round($bx - $nx), (int) round($by - $ny),
        (int) round($ax - $nx), (int) round($ay - $ny),
    ], $col);
    imagefilledellipse($img, (int) round($ax), (int) round($ay), (int) round($t), (int) round($t), $col);
    imagefilledellipse($img, (int) round($bx), (int) round($by), (int) round($t), (int) round($t), $col);
}

// ── Render v supersamplingu, pak downscale ──
$big = renderLogo($size * SS, $mode !== 'plain');

if ($mode === 'maskable') {
    // logo znovu, zmenšené do safe zóny 80 %, na plném pozadí
    $bigMask = imagecreatetruecolor($size * SS, $size * SS);
    $bg = imagecolorallocate($bigMask, 0x3B, 0x2D, 0x83);
    imagefilledrectangle($bigMask, 0, 0, $size * SS, $size * SS, $bg);
    $logo = renderLogo((int) round($size * SS * 0.8), false);
    $pad = (int) round($size * SS * 0.1);
    imagecopy($bigMask, $logo, $pad, $pad, 0, 0, (int) round($size * SS * 0.8), (int) round($size * SS * 0.8));
    $big = $bigMask;
}

$out = imagecreatetruecolor($size, $size);
imagealphablending($out, false);
imagesavealpha($out, true);
$transparent = imagecolorallocatealpha($out, 0, 0, 0, 127);
imagefilledrectangle($out, 0, 0, $size, $size, $transparent);
imagecopyresampled($out, $big, 0, 0, 0, 0, $size, $size, $size * SS, $size * SS);

imagepng($out, null, 9);
