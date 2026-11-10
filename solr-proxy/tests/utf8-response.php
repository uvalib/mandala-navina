<?php
/**
 * Regression test: Searcher::search() must return Solr's UTF-8 body byte-for-byte.
 *
 * Background: the proxy used to run the response through
 *   mb_convert_encoding($r, 'UTF-8', mb_detect_encoding($r, 'UTF-8, ISO-8859-1', true))
 * On PHP >= 8.1 mb_detect_encoding() ranks candidates heuristically and can pick
 * ISO-8859-1 for a perfectly valid UTF-8 body (large or Tibetan-heavy ones), so the
 * whole response was re-encoded and Tibetan text came out double-encoded.
 * Short single-document responses survived, which hid it from casual checks.
 *
 * Run inside the image (see pipeline/buildspec.yml, smoke test 8):
 *   docker run --rm -v "$PWD/solr-proxy/tests:/tests:ro" \
 *     -e SOLR_BASEURL=http://smoke.invalid:8080 -e DEFAULT_RETURL=https://smoke.invalid/home \
 *     <image> php /tests/utf8-response.php
 * Exit 0 = pass. Needs only the fixtures below and a loopback `php -S` server.
 */

chdir('/var/www/html');
require 'Searcher.php';

function fail(string $m): void { fwrite(STDERR, "TEST FAIL: $m\n"); exit(1); }

/** A Solr-shaped JSON body full of Tibetan, big enough to trip the heuristic. */
function tibetan_body(): string {
    $syl = ["\u{0F40}\u{0F7C}", "\u{0F56}\u{0F51}\u{0F7A}\u{0F0B}", "\u{0F58}\u{0F72}\u{0F0B}", "\u{0F63}\u{0F7C}\u{0F0B}",
            "\u{0F51}\u{0F42}\u{0F7C}\u{0F0B}", "\u{0F66}\u{0F90}\u{0F51}\u{0F0B}", "\u{0F61}\u{0F72}\u{0F42}\u{0F0B}"];
    $docs = [];
    for ($i = 0; $i < 300; $i++) {
        $name = '';
        for ($j = 0; $j < 12; $j++) { $name .= $syl[($i * 7 + $j * 3) % count($syl)]; }
        $docs[] = ['uid' => "terms-$i", 'name_tibt' => [$name], 'names' => [$name, "name $i"]];
    }
    return json_encode(['response' => ['numFound' => count($docs), 'docs' => $docs]], JSON_UNESCAPED_UNICODE);
}

$tibetan = tibetan_body();
if (!mb_check_encoding($tibetan, 'UTF-8')) { fail('fixture is not valid UTF-8 (test bug)'); }

// Canary: does the OLD idiom actually corrupt this fixture on this PHP? If not, the
// test cannot prove anything on this runtime. Loud warning, not a failure: PHP's
// heuristic is not ours to pin.
$old = mb_convert_encoding($tibetan, 'UTF-8', mb_detect_encoding($tibetan, 'UTF-8, ISO-8859-1', true));
$canary = ($old !== $tibetan);
fwrite(STDERR, $canary
    ? "note: old mb_detect_encoding idiom corrupts the fixture on PHP " . PHP_VERSION . " (test is a live guard)\n"
    : "WARNING: old idiom does NOT corrupt the fixture on PHP " . PHP_VERSION . "; this run guards little\n");

$cases = [
    // path prefix => [body served, expected string returned by Searcher]
    'tibetan' => [$tibetan, $tibetan],                                      // valid UTF-8 must pass through untouched
    'ascii'   => ['{"response":{"numFound":0,"docs":[]}}', '{"response":{"numFound":0,"docs":[]}}'],
    'latin1'  => ["{\"n\":\"caf\xE9\"}", "{\"n\":\"caf\u{00E9}\"}"],          // genuinely non-UTF-8 still gets transcoded
];

$dir = sys_get_temp_dir() . '/utf8test-' . getmypid();
mkdir($dir);
foreach ($cases as $k => [$served, $_]) { file_put_contents("$dir/$k.body", $served); }
file_put_contents("$dir/router.php", <<<'PHP'
<?php
$k = explode('/', ltrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/'))[0];
$f = __DIR__ . "/$k.body";
if (!is_file($f)) { http_response_code(404); exit; }
header('Content-Type: application/json');
readfile($f);
PHP);

$port = 18000 + (getmypid() % 1000);
$proc = proc_open(['php', '-S', "127.0.0.1:$port", "$dir/router.php"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
register_shutdown_function(function () use ($proc, $dir) {
    proc_terminate($proc);
    array_map('unlink', glob("$dir/*"));
    @rmdir($dir);
});
for ($i = 0, $up = false; $i < 50 && !$up; $i++) {
    $up = (bool) @fsockopen('127.0.0.1', $port, $en, $es, 0.2);
    if (!$up) { usleep(100000); }
}
if (!$up) { fail('local fixture server did not start'); }

foreach ($cases as $k => [$_, $expected]) {
    $s = new Searcher(3600, false, "http://127.0.0.1:$port/$k");
    $got = $s->search('q=*:*', false);
    if ($got !== $expected) {
        fail("case '$k': response was altered (" . strlen($got) . " bytes returned, " . strlen($expected) . " expected)");
    }
    echo "ok: $k\n";
}
echo "smoke: Searcher returns UTF-8 responses unaltered (Tibetan, ASCII, Latin-1 fallback)\n";
