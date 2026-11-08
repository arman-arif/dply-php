<?php

declare(strict_types=1);

// ============================================================================
//  HTML layout
// ============================================================================

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function fmt_time(?int $t): string
{
    return $t ? date('Y-m-d H:i:s', $t) : '—';
}

function badge(string $text, string $kind = ''): string
{
    return '<span class="badge ' . ($kind ? 'b-' . $kind : '') . '">' . h($text) . '</span>';
}

function layout_head(string $title, bool $nav = true): string
{
    $u = $nav ? current_user() : null;
    $navHtml = '';
    if ($u) {
        $items = [
            'dashboard' => ['Dashboard', null], 'deploy' => ['Deploy', 'deploy'], 'artisan' => ['Artisan', 'artisan'],
            'terminal'  => ['Terminal', 'terminal'], 'env' => ['.env', 'env'], 'keys' => ['SSH keys', 'keys'],
            'users'     => ['Users', 'admin'], 'security' => ['Security', 'admin'], 'settings' => ['Settings', 'admin'],
            'audit'     => ['Audit log', 'admin'], 'profile' => ['Profile', null],
        ];
        $cur = (string) ($_GET['a'] ?? 'dashboard');
        $navHtml = '<nav>';
        foreach ($items as $k => [$label, $perm]) {
            if ($perm === null || can($perm)) {
                $navHtml .= '<a href="?a=' . $k . '"' . ($cur === $k || ($cur === 'deploy_log' && $k === 'deploy') ? ' class="on"' : '') . '>' . h($label) . '</a>';
            }
        }
        $navHtml .= '</nav><form method="post" action="?a=logout" class="row">' . csrf_field()
            . '<span class="mute">' . h($u['username']) . '</span><button class="sm" type="submit">Sign out</button></form>';
    }
    $css = <<<'CSS'
:root{--bg:#0e1217;--panel:#151b23;--line:#253041;--text:#d7dee8;--mute:#8693a5;--acc:#3fb68b;--warn:#e0a84a;--err:#e5616a;--link:#6cb6ff;--mono:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:14px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
header{display:flex;flex-wrap:wrap;align-items:center;gap:6px 14px;padding:10px 16px;background:var(--panel);border-bottom:1px solid var(--line)}
.brand{font-weight:700;color:var(--acc);letter-spacing:.05em}header nav{display:flex;flex-wrap:wrap;gap:2px;flex:1}
header nav a{color:var(--mute);text-decoration:none;padding:5px 10px;border-radius:6px}header nav a.on,header nav a:hover{color:var(--text);background:#1f2935}
main{max-width:1200px;margin:0 auto;padding:20px 16px}h1{font-size:20px;margin:0 0 14px}
h2{font-size:12px;margin:0 0 12px;color:var(--mute);text-transform:uppercase;letter-spacing:.06em}
.card{background:var(--panel);border:1px solid var(--line);border-radius:10px;padding:16px;margin-bottom:16px;min-width:0}
.grid{display:grid;gap:16px;grid-template-columns:repeat(auto-fit,minmax(300px,1fr))}.grid>.card{margin-bottom:0}.stack>*+*{margin-top:16px}
.tbl{overflow-x:auto}table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:6px 8px;border-bottom:1px solid var(--line);vertical-align:top}
th{color:var(--mute);font-weight:600;font-size:12px;white-space:nowrap}td.k{color:var(--mute);width:180px;white-space:nowrap}
input[type=text],input[type=password],input[type=number],select,textarea{width:100%;background:#0a0e13;color:var(--text);border:1px solid var(--line);border-radius:6px;padding:7px 9px;font:inherit}
input:focus,select:focus,textarea:focus{outline:2px solid #2d6a55;border-color:#2d6a55}
textarea{font:13px/1.45 var(--mono);resize:vertical}label{display:block;margin:0 0 12px}label>span{display:block;color:var(--mute);font-size:12px;margin-bottom:3px}
.chk{display:flex;gap:8px;align-items:flex-start;margin:5px 0}.chk input{margin-top:3px}
button,.btn{background:#212d3c;color:var(--text);border:1px solid #2f3e52;border-radius:6px;padding:7px 14px;font:inherit;cursor:pointer;text-decoration:none;display:inline-block;line-height:1.3}
button:hover{border-color:#4a5d77}button.primary{background:var(--acc);border-color:var(--acc);color:#04130d;font-weight:600}
button.danger{background:transparent;border-color:var(--err);color:var(--err)}button.sm{padding:3px 9px;font-size:12px}button:disabled{opacity:.5;cursor:wait}
.row{display:flex;flex-wrap:wrap;gap:8px;align-items:center}.row form{margin:0}
.flash{padding:10px 14px;border-radius:8px;margin-bottom:14px;border:1px solid}.flash pre{margin:8px 0 0}
.flash.ok{border-color:var(--acc);background:#0f241c}.flash.err{border-color:var(--err);background:#28141a}.flash.warn{border-color:var(--warn);background:#282012}
pre,.log{background:#0a0e13;border:1px solid var(--line);border-radius:8px;padding:12px;overflow:auto;white-space:pre-wrap;word-break:break-word;font:12.5px/1.45 var(--mono);max-height:70vh;margin:0}
code{font-family:var(--mono);background:#0a0e13;padding:1px 5px;border-radius:4px;font-size:12.5px}
.badge{display:inline-block;padding:0 8px;border-radius:99px;font-size:12px;border:1px solid var(--line);white-space:nowrap}
.b-ok{color:var(--acc);border-color:var(--acc)}.b-err{color:var(--err);border-color:var(--err)}.b-warn{color:var(--warn);border-color:var(--warn)}
.mute{color:var(--mute)}a{color:var(--link)}.small{font-size:12px}.login{max-width:380px;margin:10vh auto}
.agrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:8px}.agrid button{width:100%;text-align:left}
.term{background:#06080b;border:1px solid var(--line);border-radius:8px;font:13px/1.45 var(--mono)}
.term-out{height:60vh;overflow:auto;padding:12px;white-space:pre-wrap;word-break:break-word}
.term-in{display:flex;gap:8px;align-items:center;border-top:1px solid var(--line);padding:8px 12px}.term-in input{border:0;background:transparent;padding:4px;font:inherit;outline:none}
.t-cmd{color:var(--acc)}.t-err{color:var(--err)}.cwd{color:var(--link);white-space:nowrap}
@media (max-width:640px){td.k{width:auto;white-space:normal}main{padding:16px}}
CSS;
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex,nofollow"><title>' . h($title) . ' · DPLY</title><style>' . $css . '</style></head><body>'
        . ($u ? '<header><span class="brand">DPLY</span>' . $navHtml . '</header>' : '') . '<main>';
}

function layout_foot(): string
{
    $js = <<<'JS'
document.addEventListener('submit',function(e){var f=e.target,s=e.submitter,m=(s&&s.getAttribute('data-confirm'))||f.getAttribute('data-confirm');
if(m&&!confirm(m)){e.preventDefault();return;}if(s&&f.getAttribute('data-once')!==null){setTimeout(function(){s.disabled=true;},0);}},true);
JS;
    return '</main><script nonce="' . csp_nonce() . '">' . $js . '</script></body></html>';
}

function flash_html(): string
{
    $out = '';
    foreach ($_SESSION['flash'] ?? [] as [$type, $msg, $pre]) {
        $out .= '<div class="flash ' . h($type) . '">' . h($msg) . ($pre !== null && $pre !== '' ? '<pre>' . h(strip_ansi($pre)) . '</pre>' : '') . '</div>';
    }
    unset($_SESSION['flash']);
    return $out;
}

function render(string $title, string $body): void
{
    header('Content-Type: text/html; charset=utf-8');
    echo layout_head($title), flash_html(), $body, layout_foot();
}

function render_bare(string $title, string $body, bool $withFlash = false): void
{
    header('Content-Type: text/html; charset=utf-8');
    echo layout_head($title, false), ($withFlash ? flash_html() : ''), $body, layout_foot();
}

function stream_begin(string $title): void
{
    session_write_close();
    @ini_set('zlib.output_compression', '0');
    @ini_set('implicit_flush', '1');
    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
    header('Content-Type: text/html; charset=utf-8');
    header('X-Accel-Buffering: no');
    $js = "new MutationObserver(function(){var l=document.getElementById('log');if(l)l.scrollTop=l.scrollHeight;})"
        . ".observe(document.documentElement,{childList:true,subtree:true,characterData:true});";
    echo layout_head($title), '<script nonce="', csp_nonce(), '">', $js, '</script>',
        '<!--', str_repeat(' ', 2048), '--><h1>', h($title), '</h1><pre class="log" id="log">';
    flush();
}

function stream_out(?string $s): void
{
    echo $s === null ? '<!-- -->' : h(strip_ansi($s));
    flush();
}

function stream_end(string $html): void
{
    echo '</pre>', $html, layout_foot();
    flush();
}

function steps_checkboxes(array $selected, string $name = 'steps[]'): string
{
    $o = '';
    foreach (DPLY_STEPS as $k => $label) {
        $o .= '<label class="chk"><input type="checkbox" name="' . h($name) . '" value="' . h($k) . '"'
            . (in_array($k, $selected, true) ? ' checked' : '') . '><span>' . h($label) . '</span></label>';
    }
    return $o;
}

function laravel_version(): string
{
    $f = project_root() . '/vendor/laravel/framework/src/Illuminate/Foundation/Application.php';
    if (is_file($f) && preg_match("/const VERSION = '([^']+)'/", (string) file_get_contents($f), $m)) {
        return $m[1];
    }
    return 'unknown';
}
