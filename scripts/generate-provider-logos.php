<?php

declare(strict_types=1);

if (! extension_loaded('gd')) {
    fwrite(STDERR, "The GD extension is required to generate provider logos.\n");
    exit(1);
}

$root = dirname(__DIR__);
$outputDirectory = $root.'/public/images/provider-logos';
$previewPath = $root.'/storage/app/provider-logo-preview.png';

if (! is_dir($outputDirectory) && ! mkdir($outputDirectory, 0775, true) && ! is_dir($outputDirectory)) {
    fwrite(STDERR, "Unable to create {$outputDirectory}.\n");
    exit(1);
}

$fontCandidates = [
    'bold' => [
        'C:/Windows/Fonts/georgiab.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSerif-Bold.ttf',
    ],
    'sans' => [
        'C:/Windows/Fonts/segoeuib.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
    ],
];

$fonts = [];
foreach ($fontCandidates as $key => $candidates) {
    $fonts[$key] = null;
    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            $fonts[$key] = $candidate;
            break;
        }
    }
}

$logos = [
    ['file' => 'araw-education-foundation.png', 'name' => 'Araw Education Foundation', 'mark' => 'AE', 'style' => 'book-sun', 'background' => '#0B1F3A', 'accent' => '#F7C948'],
    ['file' => 'buklod-scholars-network.png', 'name' => 'Buklod Scholars Network', 'mark' => 'BS', 'style' => 'bridge', 'background' => '#123B46', 'accent' => '#F2B84B'],
    ['file' => 'gabay-aral-foundation.png', 'name' => 'Gabay Aral Foundation', 'mark' => 'GA', 'style' => 'torch', 'background' => '#7A2E2E', 'accent' => '#FFD166'],
    ['file' => 'haraya-learning-trust.png', 'name' => 'Haraya Learning Trust', 'mark' => 'HL', 'style' => 'sprout', 'background' => '#174C3C', 'accent' => '#F4C95D'],
    ['file' => 'lakbay-dunong-foundation.png', 'name' => 'Lakbay Dunong Foundation', 'mark' => 'LD', 'style' => 'shield', 'background' => '#243B64', 'accent' => '#F4B942'],
    ['file' => 'liwanag-youth-foundation.png', 'name' => 'Liwanag Youth Foundation', 'mark' => 'LY', 'style' => 'lighthouse', 'background' => '#0B5563', 'accent' => '#FFCC4D'],
    ['file' => 'pag-asa-academic-trust.png', 'name' => 'Pag-asa Academic Trust', 'mark' => 'PA', 'style' => 'mountain', 'background' => '#3D405B', 'accent' => '#F2CC8F'],
    ['file' => 'sulong-scholars-foundation.png', 'name' => 'Sulong Scholars Foundation', 'mark' => 'SS', 'style' => 'path', 'background' => '#24514A', 'accent' => '#F6BD60'],
    ['file' => 'talino-community-fund.png', 'name' => 'Talino Community Fund', 'mark' => 'TC', 'style' => 'columns', 'background' => '#63372C', 'accent' => '#FFD166'],
    ['file' => 'tulay-karunungan-foundation.png', 'name' => 'Tulay Karunungan Foundation', 'mark' => 'TK', 'style' => 'cap', 'background' => '#14213D', 'accent' => '#FCA311'],
    ['file' => 'usbong-education-network.png', 'name' => 'Usbong Education Network', 'mark' => 'UE', 'style' => 'network', 'background' => '#1B4965', 'accent' => '#CAE9FF'],
    ['file' => 'wika-at-agham-scholars.png', 'name' => 'Wika at Agham Scholars', 'mark' => 'WA', 'style' => 'compass', 'background' => '#4A2545', 'accent' => '#F4D35E'],
];

function hexColor(GdImage $image, string $hex): int
{
    $hex = ltrim($hex, '#');

    return imagecolorallocate(
        $image,
        hexdec(substr($hex, 0, 2)),
        hexdec(substr($hex, 2, 2)),
        hexdec(substr($hex, 4, 2)),
    );
}

function roundedRectangle(GdImage $image, int $x1, int $y1, int $x2, int $y2, int $radius, int $color): void
{
    imagefilledrectangle($image, $x1 + $radius, $y1, $x2 - $radius, $y2, $color);
    imagefilledrectangle($image, $x1, $y1 + $radius, $x2, $y2 - $radius, $color);
    imagefilledellipse($image, $x1 + $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
    imagefilledellipse($image, $x2 - $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
    imagefilledellipse($image, $x1 + $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
    imagefilledellipse($image, $x2 - $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
}

function centeredText(GdImage $image, string $text, int $size, int $centerX, int $baselineY, int $color, ?string $font): void
{
    if ($font !== null && function_exists('imagettftext')) {
        $box = imagettfbbox($size, 0, $font, $text);
        $width = $box[2] - $box[0];
        imagettftext($image, $size, 0, (int) round($centerX - ($width / 2)), $baselineY, $color, $font, $text);

        return;
    }

    $fontId = 5;
    imagestring($image, $fontId, (int) round($centerX - (imagefontwidth($fontId) * strlen($text) / 2)), $baselineY - 16, $text, $color);
}

function thickLine(GdImage $image, int $x1, int $y1, int $x2, int $y2, int $color, int $thickness): void
{
    imagesetthickness($image, $thickness);
    imageline($image, $x1, $y1, $x2, $y2, $color);
    imagesetthickness($image, 1);
}

function drawSymbol(GdImage $image, string $style, int $light, int $accent, int $background): void
{
    $cx = 256;

    if ($style === 'book-sun') {
        imagefilledellipse($image, $cx, 150, 74, 74, $accent);
        for ($angle = 0; $angle < 360; $angle += 45) {
            $radians = deg2rad($angle);
            thickLine($image, $cx + (int) (50 * cos($radians)), 150 + (int) (50 * sin($radians)), $cx + (int) (67 * cos($radians)), 150 + (int) (67 * sin($radians)), $accent, 6);
        }
        imagefilledpolygon($image, [142, 220, 244, 239, 244, 312, 142, 288], 4, $light);
        imagefilledpolygon($image, [268, 239, 370, 220, 370, 288, 268, 312], 4, $light);
        thickLine($image, 256, 242, 256, 312, $accent, 7);
    } elseif ($style === 'bridge') {
        thickLine($image, 130, 282, 382, 282, $light, 12);
        thickLine($image, 158, 202, 158, 308, $accent, 15);
        thickLine($image, 354, 202, 354, 308, $accent, 15);
        imagesetthickness($image, 11);
        imagearc($image, $cx, 287, 196, 170, 190, 350, $light);
        imagesetthickness($image, 1);
        foreach ([200, 242, 284, 326] as $x) {
            thickLine($image, $x, 242, $x, 283, $accent, 6);
        }
    } elseif ($style === 'torch') {
        imagefilledpolygon($image, [256, 102, 304, 162, 278, 220, 234, 220, 208, 162], 5, $accent);
        imagefilledellipse($image, 256, 174, 50, 76, $light);
        imagefilledpolygon($image, [225, 225, 287, 225, 274, 308, 238, 308], 4, $light);
        imagefilledrectangle($image, 218, 300, 294, 318, $accent);
    } elseif ($style === 'sprout') {
        thickLine($image, 256, 176, 256, 306, $light, 13);
        imagefilledellipse($image, 207, 200, 108, 62, $accent);
        imagefilledellipse($image, 305, 168, 108, 62, $accent);
        thickLine($image, 256, 229, 205, 201, $light, 7);
        thickLine($image, 256, 207, 307, 168, $light, 7);
        imagesetthickness($image, 10);
        imagearc($image, 256, 316, 178, 62, 190, 350, $light);
        imagesetthickness($image, 1);
    } elseif ($style === 'shield') {
        imagefilledpolygon($image, [256, 102, 352, 136, 338, 250, 256, 318, 174, 250, 160, 136], 6, $accent);
        imagefilledpolygon($image, [256, 123, 326, 148, 315, 235, 256, 287, 197, 235, 186, 148], 6, $background);
        imagefilledpolygon($image, [205, 190, 248, 199, 248, 246, 205, 237], 4, $light);
        imagefilledpolygon($image, [264, 199, 307, 190, 307, 237, 264, 246], 4, $light);
    } elseif ($style === 'lighthouse') {
        imagefilledpolygon($image, [222, 302, 240, 170, 272, 170, 290, 302], 4, $light);
        imagefilledrectangle($image, 218, 142, 294, 178, $accent);
        imagefilledellipse($image, 256, 141, 48, 48, $light);
        imagefilledpolygon($image, [199, 138, 313, 138, 284, 108, 228, 108], 4, $accent);
        imagefilledpolygon($image, [174, 153, 74, 122, 74, 184], 3, $accent);
        imagefilledpolygon($image, [338, 153, 438, 122, 438, 184], 3, $accent);
        imagefilledrectangle($image, 198, 300, 314, 319, $accent);
    } elseif ($style === 'mountain') {
        imagefilledellipse($image, 330, 140, 74, 74, $accent);
        imagefilledpolygon($image, [116, 300, 225, 142, 334, 300], 3, $light);
        imagefilledpolygon($image, [226, 300, 309, 196, 396, 300], 3, $accent);
        imagefilledpolygon($image, [191, 190, 225, 142, 260, 193, 226, 180], 4, $background);
        thickLine($image, 112, 307, 400, 307, $light, 10);
    } elseif ($style === 'path') {
        imagefilledellipse($image, 256, 137, 72, 72, $accent);
        imagefilledpolygon($image, [214, 316, 240, 186, 272, 186, 298, 316], 4, $light);
        imagefilledpolygon($image, [246, 316, 252, 186, 260, 186, 266, 316], 4, $accent);
        thickLine($image, 148, 316, 364, 316, $accent, 10);
    } elseif ($style === 'columns') {
        imagefilledpolygon($image, [256, 104, 382, 166, 130, 166], 3, $accent);
        imagefilledrectangle($image, 132, 174, 380, 194, $light);
        foreach ([158, 218, 278, 338] as $x) {
            imagefilledrectangle($image, $x, 194, $x + 24, 292, $light);
        }
        imagefilledrectangle($image, 122, 294, 390, 316, $accent);
    } elseif ($style === 'cap') {
        imagefilledpolygon($image, [256, 116, 394, 184, 256, 252, 118, 184], 4, $accent);
        imagefilledpolygon($image, [177, 215, 256, 253, 335, 215, 325, 284, 256, 315, 187, 284], 6, $light);
        thickLine($image, 394, 184, 394, 276, $light, 7);
        imagefilledellipse($image, 394, 291, 22, 32, $accent);
    } elseif ($style === 'network') {
        $points = [[256, 116], [160, 188], [352, 188], [195, 294], [317, 294], [256, 218]];
        foreach ([[0, 1], [0, 2], [1, 3], [2, 4], [3, 4], [1, 5], [2, 5], [3, 5], [4, 5]] as [$from, $to]) {
            thickLine($image, $points[$from][0], $points[$from][1], $points[$to][0], $points[$to][1], $light, 6);
        }
        foreach ($points as $index => [$x, $y]) {
            imagefilledellipse($image, $x, $y, $index === 5 ? 58 : 40, $index === 5 ? 58 : 40, $index === 5 ? $accent : $light);
        }
    } elseif ($style === 'compass') {
        imagefilledellipse($image, $cx, 210, 218, 218, $accent);
        imagefilledellipse($image, $cx, 210, 176, 176, $background);
        imagefilledpolygon($image, [256, 112, 287, 220, 256, 202, 225, 220], 4, $light);
        imagefilledpolygon($image, [256, 308, 225, 200, 256, 218, 287, 200], 4, $accent);
        imagefilledellipse($image, $cx, 210, 30, 30, $light);
    }
}

foreach ($logos as $logo) {
    $image = imagecreatetruecolor(512, 512);
    imageantialias($image, true);

    $paper = hexColor($image, '#F8FAFC');
    $light = hexColor($image, '#FFFFFF');
    $ink = hexColor($image, '#0F172A');
    $muted = hexColor($image, '#64748B');
    $background = hexColor($image, $logo['background']);
    $accent = hexColor($image, $logo['accent']);

    imagefill($image, 0, 0, $paper);
    roundedRectangle($image, 24, 24, 488, 488, 42, $light);
    roundedRectangle($image, 48, 48, 464, 348, 34, $background);
    drawSymbol($image, $logo['style'], $light, $accent, $background);
    centeredText($image, $logo['mark'], 35, 256, 407, $ink, $fonts['bold']);
    centeredText($image, strtoupper($logo['name']), 11, 256, 445, $muted, $fonts['sans']);
    imagepng($image, $outputDirectory.'/'.$logo['file'], 8);
    imagedestroy($image);
}

$preview = imagecreatetruecolor(1280, 1050);
$previewBackground = hexColor($preview, '#E8EEF5');
$previewInk = hexColor($preview, '#0F172A');
imagefill($preview, 0, 0, $previewBackground);
imageantialias($preview, true);

foreach ($logos as $index => $logo) {
    $source = imagecreatefrompng($outputDirectory.'/'.$logo['file']);
    $column = $index % 4;
    $row = intdiv($index, 4);
    $x = 38 + ($column * 310);
    $y = 34 + ($row * 340);
    imagecopyresampled($preview, $source, $x, $y, 0, 0, 260, 260, 512, 512);
    centeredText($preview, $logo['name'], 12, $x + 130, $y + 292, $previewInk, $fonts['sans']);
    imagedestroy($source);
}

imagepng($preview, $previewPath, 8);
imagedestroy($preview);

fwrite(STDOUT, sprintf("Generated %d provider logos in %s\n", count($logos), $outputDirectory));
fwrite(STDOUT, "Preview: {$previewPath}\n");
