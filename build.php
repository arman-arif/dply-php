<?php
/**
 * Compiles src/ into the single deployable public/index.php.
 *
 *   php app/tool/dply/build.php           build public/index.php
 *   php app/tool/dply/build.php --check   exit 1 if public/index.php is out of date (for CI / pre-commit)
 *
 * Layout and concatenation order:
 *   src/bootstrap.php      header docblock + constants (always first)
 *   src/<group>/*.php      one folder per use case, in the order of SRC_GROUPS below,
 *                          files within a folder in filename order
 *   src/run.php            entry point (always last)
 *
 * Module rules: every src file starts with "<?php" (+ optional declare(strict_types=1);)
 * and only declares constants/functions, so the order inside a group does not matter.
 * Add a new folder to SRC_GROUPS; unknown folders or stray files fail the build.
 */
declare(strict_types=1);

const SRC_GROUPS = [
    'core',     // storage/settings/crypto, projects, process runner
    'security', // IP blocking, authentication
    'web',      // request handling, layout/rendering helpers
    'deploy',   // deployment engine
    'pages',    // one file per web page
    'cli',      // command-line interface
];

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$dir = __DIR__;
$src = "$dir/src";
$fail = static function (string $msg): void {
    fwrite(STDERR, $msg . "\n");
    exit(1);
};

foreach (scandir($src) ?: [] as $entry) {
    if ($entry === '.' || $entry === '..') {
        continue;
    }
    if (is_dir("$src/$entry") ? !in_array($entry, SRC_GROUPS, true) : !in_array($entry, ['bootstrap.php', 'run.php'], true)) {
        $fail("src/$entry: unknown entry — add the folder to SRC_GROUPS in build.php or move the file into a group folder");
    }
}
foreach (['bootstrap.php', 'run.php'] as $required) {
    if (!is_file("$src/$required")) {
        $fail("src/$required is missing");
    }
}

$files = ["$src/bootstrap.php"];
foreach (SRC_GROUPS as $group) {
    $groupFiles = glob("$src/$group/*.php") ?: [];
    sort($groupFiles, SORT_STRING);
    array_push($files, ...$groupFiles);
}
$files[] = "$src/run.php";

$out = '';
foreach ($files as $i => $file) {
    $rel = substr($file, strlen($src) + 1);
    $code = (string) file_get_contents($file);
    if (!str_starts_with($code, '<?php')) {
        $fail("src/$rel: must start with <?php");
    }
    if (preg_match('/\?>\s*$/', $code)) {
        $fail("src/$rel: remove the closing ?> tag");
    }
    if ($i === 0) {
        // Keep the bootstrap header (docblock + declare) and mark the file as generated.
        $code = preg_replace('/^<\?php\n/', "<?php\n// GENERATED FILE — do not edit. Edit app/tool/dply/src/ and run: php app/tool/dply/build.php\n", $code, 1);
        $out .= rtrim((string) $code) . "\n";
        continue;
    }
    $code = preg_replace('/^<\?php\s*(declare\(strict_types=1\);\s*)?/', '', $code, 1);
    $out .= "\n// ---- src/$rel " . str_repeat('-', max(4, 60 - strlen($rel))) . "\n\n" . rtrim((string) $code) . "\n";
}

$publicDir = "$dir/public";
if (!is_dir($publicDir) && !mkdir($publicDir, 0755, true) && !is_dir($publicDir)) {
    $fail("Cannot create $publicDir");
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
    $fail("Lint failed — public/index.php NOT written:\n" . implode("\n", $lint));
}
rename($tmp, $target);
printf("Built public/index.php from %d modules (%d lines).\n", count($files), substr_count($out, "\n"));
