<?php
/**
 * Compiles src/*.php (in filename order) into the single deployable public/index.php.
 *
 *   php app/tool/dply/build.php           build public/index.php
 *   php app/tool/dply/build.php --check   exit 1 if public/index.php is out of date (for CI / pre-commit)
 *
 * Module rules: every src file starts with "<?php" (+ optional declare(strict_types=1);)
 * and only declares constants/functions; src/99-run.php holds the entry point and must stay last.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$dir = __DIR__;
$files = glob("$dir/src/*.php") ?: [];
sort($files, SORT_STRING);
if (!$files || basename($files[0]) !== '00-bootstrap.php' || basename(end($files)) !== '99-run.php') {
    fwrite(STDERR, "src/ must start with 00-bootstrap.php and end with 99-run.php\n");
    exit(1);
}

$out = '';
foreach ($files as $i => $file) {
    $code = (string) file_get_contents($file);
    if (!str_starts_with($code, '<?php')) {
        fwrite(STDERR, basename($file) . ": must start with <?php\n");
        exit(1);
    }
    if (preg_match('/\?>\s*$/', $code)) {
        fwrite(STDERR, basename($file) . ": remove the closing ?> tag\n");
        exit(1);
    }
    if ($i === 0) {
        // Keep the bootstrap header (docblock + declare) and mark the file as generated.
        $code = preg_replace('/^<\?php\n/', "<?php\n// GENERATED FILE — do not edit. Edit app/tool/dply/src/*.php and run: php app/tool/dply/build.php\n", $code, 1);
        $out .= rtrim((string) $code) . "\n";
        continue;
    }
    $code = preg_replace('/^<\?php\s*(declare\(strict_types=1\);\s*)?/', '', $code, 1);
    $out .= "\n// ---- src/" . basename($file) . " " . str_repeat('-', max(4, 60 - strlen(basename($file)))) . "\n\n" . rtrim((string) $code) . "\n";
}

$publicDir = "$dir/public";
if (!is_dir($publicDir) && !mkdir($publicDir, 0755, true) && !is_dir($publicDir)) {
    fwrite(STDERR, "Cannot create $publicDir\n");
    exit(1);
}
$target = "$publicDir/index.php";
$check = in_array('--check', $argv, true);
if ($check) {
    $same = is_file($target) && file_get_contents($target) === $out;
    echo $same ? "public/index.php is up to date.\n" : "public/index.php is OUT OF DATE — run: php build.php\n";
    exit($same ? 0 : 1);
}

$tmp = "$publicDir/.index.php.build";
file_put_contents($tmp, $out);
exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($tmp) . ' 2>&1', $lint, $rc);
if ($rc !== 0) {
    @unlink($tmp);
    fwrite(STDERR, "Lint failed — public/index.php NOT written:\n" . implode("\n", $lint) . "\n");
    exit(1);
}
rename($tmp, $target);
printf("Built public/index.php from %d modules (%d lines).\n", count($files), substr_count($out, "\n"));
