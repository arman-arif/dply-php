<?php

declare(strict_types=1);

// ============================================================================
//  Terminal
// ============================================================================

function page_terminal(): void
{
    $full = can('terminal_full');
    $cwd = $_SESSION['term_cwd'] ?? project_root();
    $js = <<<'JS'
(function(){var out=document.getElementById('tout'),inp=document.getElementById('tin'),cwd=document.getElementById('tcwd'),
csrf=document.getElementById('tcsrf').value,hist=[],hi=0;
function add(t,c){var s=document.createElement('span');if(c)s.className=c;s.textContent=t;out.appendChild(s);out.scrollTop=out.scrollHeight;}
inp.addEventListener('keydown',function(e){
 if(e.key==='ArrowUp'){if(hi>0){hi--;inp.value=hist[hi];}e.preventDefault();return;}
 if(e.key==='ArrowDown'){if(hi<hist.length-1){hi++;inp.value=hist[hi];}else{hi=hist.length;inp.value='';}e.preventDefault();return;}
 if(e.key==='l'&&e.ctrlKey){out.textContent='';e.preventDefault();return;}
 if(e.key!=='Enter')return;
 var cmd=inp.value;if(!cmd.trim())return;hist.push(cmd);hi=hist.length;inp.value='';
 if(cmd.trim()==='clear'){out.textContent='';return;}
 add(cwd.textContent+' $ '+cmd+'\n','t-cmd');inp.disabled=true;
 fetch('?a=term_exec',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({cmd:cmd})})
 .then(function(r){return r.json();}).then(function(j){
   if(j.reauth){location.href='?a=reauth&return='+encodeURIComponent('?a=terminal');return;}
   if(j.output)add(j.output);if(j.error)add(j.error+'\n','t-err');
   if(typeof j.exit==='number'&&j.exit!==0)add('[exit '+j.exit+']\n','t-err');if(j.cwd)cwd.textContent=j.cwd;
 }).catch(function(err){add('Request failed: '+err+'\n','t-err');})
 .then(function(){inp.disabled=false;inp.focus();});
});
document.getElementById('tbox').addEventListener('click',function(){if(!window.getSelection().toString())inp.focus();});inp.focus();})();
JS;
    $allow = $full ? '' : '<details class="card"><summary>Allowed commands in restricted mode</summary><pre class="small">' . h(implode("\n", lines(setting('term_allow')))) . '</pre></details>';
    render('Terminal', page_head('Terminal ' . ($full ? badge('unrestricted shell', 'warn') : badge('restricted', 'ok')),
        'Non-interactive: each command runs to completion (timeout 5 min). git automatically uses the configured token / SSH key.'
        . ($full ? ' The active SSH key path is in $DPLY_SSH_KEY.' : ' No shell: pipes, redirects and variables are not interpreted; ".." is not allowed.'))
        . '<div class="term" id="tbox"><div class="term-bar"><span class="term-dots"><i></i><i></i><i></i></span><span class="t">' . h(proc_user()) . '@' . h((string) (gethostname() ?: 'server'))
        . '</span><span>' . ($full ? 'sh' : 'restricted') . '</span></div><div class="term-out" id="tout" aria-live="polite"></div><div class="term-in"><span class="cwd" id="tcwd" title="' . h($cwd) . '">' . h($cwd) . '</span><span style="color:#5e697a">$</span>'
        . '<input id="tin" type="text" autocomplete="off" autocapitalize="off" spellcheck="false" aria-label="Command"></div></div>'
        . '<div class="tips"><span><kbd>↑</kbd><kbd>↓</kbd> history</span><span><kbd>Ctrl</kbd>+<kbd>L</kbd> or <code>clear</code> to clear</span><span><code>cd</code> is remembered</span></div>'
        . '<input type="hidden" id="tcsrf" value="' . h(csrf_token()) . '">' . $allow
        . '<script nonce="' . csp_nonce() . '">' . $js . '</script>');
}

function term_restricted_check(string $cmd): ?string
{
    if (str_contains($cmd, '..')) {
        return 'Restricted mode: ".." is not allowed.';
    }
    $c = (string) preg_replace('/\s+/', ' ', trim($cmd));
    foreach (lines(setting('term_allow')) as $p) {
        $p = (string) preg_replace('/\s+/', ' ', $p);
        if ($c === $p || str_starts_with($c, $p . ' ')) {
            return null;
        }
    }
    return 'Restricted mode: command is not in the allow-list.';
}

function do_term_exec(): void
{
    $in = json_decode((string) file_get_contents('php://input'), true);
    $cmd = is_array($in) ? trim((string) ($in['cmd'] ?? '')) : '';
    if ($cmd === '' || strlen($cmd) > 4000) {
        json_out(['error' => 'Empty or too long command.']);
    }
    $full = can('terminal_full');
    $root = project_root();
    $cwd = (string) ($_SESSION['term_cwd'] ?? $root);
    if (!is_dir($cwd) || (!$full && !path_within($cwd, $root))) {
        $cwd = $root;
    }

    if (preg_match('/^cd(?:\s+(.*))?$/s', $cmd, $m)) {
        $t = trim(trim($m[1] ?? ''), "\"'");
        $new = ($t === '' || $t === '~') ? $root : realpath($t[0] === '/' ? $t : "$cwd/$t");
        if ($new === false || !is_dir($new)) {
            json_out(['output' => "cd: no such directory: $t\n", 'exit' => 1, 'cwd' => $cwd]);
        }
        if (!$full && !path_within($new, $root)) {
            json_out(['error' => 'Restricted mode: cannot leave the project root.', 'exit' => 1, 'cwd' => $cwd]);
        }
        $_SESSION['term_cwd'] = $new;
        json_out(['output' => '', 'exit' => 0, 'cwd' => $new]);
    }

    if ($full) {
        $argv = ['/bin/sh', '-c', $cmd];
    } else {
        if ($err = term_restricted_check($cmd)) {
            audit('terminal.denied', $cmd);
            json_out(['error' => $err, 'exit' => 1, 'cwd' => $cwd]);
        }
        $argv = parse_args($cmd);
        if (!$argv) {
            json_out(['error' => 'Could not parse the command (unbalanced quotes?).', 'exit' => 1, 'cwd' => $cwd]);
        }
        $bin = $argv[0] === 'php' ? php_bin() : find_bin($argv[0]);
        if (!$bin) {
            json_out(['error' => "{$argv[0]}: command not found", 'exit' => 127, 'cwd' => $cwd]);
        }
        $argv[0] = $bin;
    }
    audit('terminal.exec', $cmd);
    session_write_close();
    $env = proc_env(true);
    if ($full && ($k = active_key_path())) {
        $env['DPLY_SSH_KEY'] = $k;
    }
    $r = run_proc($argv, $cwd, $env, 300);
    json_out(['output' => strip_ansi($r['out']) . ($r['timeout'] ? "\n[timed out after 300s]\n" : ''), 'exit' => $r['code'], 'cwd' => $cwd]);
}
