<?php
/**
 * og-card - zero-dependency OG image generator
 * ---------------------------------------------
 * One PHP file + one JSON manifest. Every post on your site gets a
 * 1200x630 share card, rendered by the GD extension that is almost
 * certainly already enabled on your host.
 *
 *   og.php?a=<slug>   ->  image/png (cached to disk, ETag + 304 aware)
 *   php og.php        ->  CLI: pre-render every card in the manifest
 *                         to ./sample/
 *
 * Manifest (cards.json) schema:
 *   {
 *     "defaults": { "meta": "...", "author": "...", "accent": "#3b82f6" },
 *     "cards": {
 *       "my-post-slug": {
 *         "kicker": "Series or category",
 *         "title":  "The headline that shows up in the link preview",
 *         "accent": "#f59e0b",
 *         "meta":   "yoursite.com",       // optional, falls back
 *         "author": "Your Name"           // optional, falls back
 *       }
 *     }
 *   }
 *
 * MIT licensed. No framework, no font downloads, no build step.
 */

declare(strict_types=1);

const CARD_W      = 1200;
const CARD_H      = 630;
const MANIFEST    = __DIR__ . '/cards.json';
const CACHE_DIR   = __DIR__ . '/cache';
const SAMPLE_DIR  = __DIR__ . '/sample';
const MAX_WIDTH   = 980;   // title wrap width
const PADDING     = 90;    // left/right safe area

/* ------------------------------------------------------------------ *
 * Fonts: no downloads. Use whatever TTFs ship on the box.
 * ------------------------------------------------------------------ */
function pick_font(array $candidates): ?string
{
    foreach ($candidates as $path) {
        if (is_readable($path)) {
            return $path;
        }
    }
    return null;
}

$FONT_TITLE = pick_font([
    '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
    '/usr/share/fonts/truetype/freefont/FreeSansBold.ttf',
    '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
]);
$FONT_MONO = pick_font([
    '/usr/share/fonts/truetype/dejavu/DejaVuSansMono-Bold.ttf',
    '/usr/share/fonts/truetype/freefont/FreeMonoBold.ttf',
    '/usr/share/fonts/truetype/liberation/LiberationMono-Bold.ttf',
]);

/* ------------------------------------------------------------------ *
 * Small drawing helpers
 * ------------------------------------------------------------------ */
function hex2rgb(string $hex): array
{
    $hex = ltrim(trim($hex), '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
        $hex = '3b82f6'; // safe default blue
    }
    return [
        (int)hexdec(substr($hex, 0, 2)),
        (int)hexdec(substr($hex, 2, 2)),
        (int)hexdec(substr($hex, 4, 2)),
    ];
}

function allocate($im, string $hex, int $alpha = 0): int
{
    [$r, $g, $b] = hex2rgb($hex);
    return imagecolorallocatealpha($im, $r, $g, $b, $alpha);
}

/** Word-wrap text to a pixel width using the real font metrics. */
function wrap_text(string $text, string $font, int $size, int $maxWidth): array
{
    $lines = [];
    foreach (preg_split('/\n/', trim($text)) as $paragraph) {
        $cur = '';
        foreach (preg_split('/\s+/', trim($paragraph)) as $word) {
            $try = $cur === '' ? $word : $cur . ' ' . $word;
            $box = imagettfbbox($size, 0, $font, $try);
            if ($cur !== '' && abs($box[4] - $box[0]) > $maxWidth) {
                $lines[] = $cur;
                $cur = $word;
            } else {
                $cur = $try;
            }
        }
        if ($cur !== '') {
            $lines[] = $cur;
        }
    }
    return $lines;
}

/** Draw text with manual letter tracking (kicker/eyebrow style). */
function draw_tracked($im, string $font, int $size, int $x, int $y, string $text, int $color, int $tracking = 4): int
{
    $cx = $x;
    // preg_split handles UTF-8 without the mbstring extension
    $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    foreach ($chars as $ch) {
        imagettftext($im, $size, 0, $cx, $y, $color, $font, $ch);
        $box = imagettfbbox($size, 0, $font, $ch);
        $cx += (int)(abs($box[4] - $box[0]) + $tracking);
    }
    return (int)$cx;
}

/* ------------------------------------------------------------------ *
 * The card renderer
 * ------------------------------------------------------------------ */
function render_card(array $card): string
{
    global $FONT_TITLE, $FONT_MONO;

    $im = imagecreatetruecolor(CARD_W, CARD_H);
    imageantialias($im, true);

    // Palette - dark terminal aesthetic
    $bg     = allocate($im, '#0b0f17');
    $grid   = allocate($im, '#ffffff', 96);   // ~6% white
    $text   = allocate($im, '#e8edf5');
    $muted  = allocate($im, '#8b96a8');
    $accent = allocate($im, $card['accent']);
    $bar    = allocate($im, $card['accent']);

    imagefill($im, 0, 0, $bg);

    // Subtle background grid
    for ($x = 0; $x <= CARD_W; $x += 60) {
        imageline($im, $x, 0, $x, CARD_H, $grid);
    }
    for ($y = 0; $y <= CARD_H; $y += 60) {
        imageline($im, 0, $y, CARD_W, $y, $grid);
    }
    // Fade the grid out toward the edges with two solid-ish veils
    imagefilledrectangle($im, 0, 0, CARD_W, CARD_H, allocate($im, '#0b0f17', 100));

    // Accent bar, full height on the left edge
    imagefilledrectangle($im, 0, 0, 14, CARD_H, $bar);

    // Corner accent glow block (top-right)
    $glow = allocate($im, $card['accent'], 100);
    imagefilledrectangle($im, CARD_W - 220, 0, CARD_W, 8, $glow);

    // Kicker - tracked mono, uppercase, accent color
    $kickerY = 148;
    $kicker = strtoupper($card['kicker']);
    if ($kicker !== '') {
        draw_tracked($im, $FONT_MONO, 24, PADDING, $kickerY, $kicker, $accent, 6);
    }

    // Title - auto-shrink so long headlines still fit in 4 lines
    $size = 62;
    $lines = wrap_text($card['title'], $FONT_TITLE, $size, MAX_WIDTH);
    while (count($lines) > 4 && $size > 40) {
        $size -= 6;
        $lines = wrap_text($card['title'], $FONT_TITLE, $size, MAX_WIDTH);
    }
    $lineH = (int)($size * 1.24);
    $titleY = $kickerY + 78;
    $n = 0;
    foreach ($lines as $line) {
        imagettftext($im, $size, 0, PADDING, $titleY + $n * $lineH, $text, $FONT_TITLE, $line);
        $n++;
    }

    // Footer - meta left, author right
    $footY = CARD_H - 64;
    if ($card['meta'] !== '') {
        imagettftext($im, 24, 0, PADDING, $footY, $muted, $FONT_MONO, $card['meta']);
    }
    if ($card['author'] !== '') {
        $box = imagettfbbox(24, 0, $FONT_MONO, $card['author']);
        $w = abs($box[4] - $box[0]);
        imagettftext($im, 24, 0, CARD_W - PADDING - (int)$w, $footY, $muted, $FONT_MONO, $card['author']);
    }

    ob_start();
    imagepng($im, null, 6);
    $png = (string)ob_get_clean();
    imagedestroy($im);
    return $png;
}

/** The 404 card - a card for a slug that isn't in the manifest. */
function render_fallback(string $slug): string
{
    return render_card([
        'kicker' => 'OG-CARD',
        'title'  => "No share card is defined for \"$slug\" - add it to cards.json",
        'accent' => '#ef4444',
        'meta'   => 'og-card',
        'author' => '404',
    ]);
}

/* ------------------------------------------------------------------ *
 * Manifest loading
 * ------------------------------------------------------------------ */
function load_manifest(): array
{
    if (!is_readable(MANIFEST)) {
        fwrite(STDERR, "cards.json not found next to og.php\n");
        exit(1);
    }
    $json = json_decode((string)file_get_contents(MANIFEST), true);
    if (!is_array($json)) {
        fwrite(STDERR, "cards.json is not valid JSON\n");
        exit(1);
    }
    return array_merge([
        'defaults' => [],
        'cards' => [],
    ], $json);
}

function resolve_card(array $manifest, string $slug): ?array
{
    $entry = $manifest['cards'][$slug] ?? null;
    if (!is_array($entry)) {
        return null;
    }
    return array_merge([
        'kicker' => '',
        'title'  => ucfirst(str_replace('-', ' ', $slug)),
        'accent' => '#3b82f6',
        'meta'   => '',
        'author' => '',
    ], $manifest['defaults'], $entry);
}

/* ------------------------------------------------------------------ *
 * HTTP mode
 * ------------------------------------------------------------------ */
function serve(string $png, string $slug): void
{
    $etag = '"' . md5($slug . strlen($png)) . '"';
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=604800');
    header('ETag: ' . $etag);

    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
        http_response_code(304);
        return;
    }
    header('Content-Length: ' . strlen($png));
    echo $png;
}

/* ------------------------------------------------------------------ *
 * Entry point
 * ------------------------------------------------------------------ */
if (PHP_SAPI === 'cli') {
    // CLI: pre-render everything to ./sample/ - run once after editing the manifest
    $manifest = load_manifest();
    if (!is_dir(SAMPLE_DIR)) {
        mkdir(SAMPLE_DIR, 0775, true);
    }
    $count = 0;
    foreach ($manifest['cards'] as $slug => $unused) {
        $card = resolve_card($manifest, $slug);
        if ($card === null) {
            continue;
        }
        file_put_contents(SAMPLE_DIR . '/' . $slug . '.png', render_card($card));
        echo "rendered sample/{$slug}.png\n";
        $count++;
    }
    echo "{$count} card(s) rendered\n";
    exit(0);
}

$slug = (string)($_GET['a'] ?? '');
if (!preg_match('/^[a-z0-9-]{1,80}$/', $slug)) {
    http_response_code(400);
    header('Content-Type: text/plain');
    echo "bad slug; expected [a-z0-9-]\n";
    exit;
}

$manifest = load_manifest();
$card = resolve_card($manifest, $slug);

if ($card === null) {
    serve(render_fallback($slug), '404-' . $slug);
    exit;
}

// Disk cache - render once, serve files after that
if (!is_dir(CACHE_DIR)) {
    mkdir(CACHE_DIR, 0775, true);
}
$cachePath = CACHE_DIR . '/' . $slug . '.png';
if (!is_file($cachePath)) {
    file_put_contents($cachePath, render_card($card));
}
serve((string)file_get_contents($cachePath), $slug);
