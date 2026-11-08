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

/** Relative time ("5m ago") wrapped in a span whose tooltip holds the absolute time. */
function ago(?int $t): string
{
    if (!$t) {
        return '—';
    }
    $d = time() - $t;
    $txt = 'just now';
    if ($d >= 60) {
        foreach ([2592000 => 'mo', 86400 => 'd', 3600 => 'h', 60 => 'm'] as $sec => $unit) {
            if ($d >= $sec) {
                $txt = intdiv($d, $sec) . $unit . ' ago';
                break;
            }
        }
    }
    return '<span title="' . h(fmt_time($t)) . '">' . h($txt) . '</span>';
}

function badge(string $text, string $kind = ''): string
{
    return '<span class="badge ' . ($kind ? 'b-' . $kind : '') . '">' . h($text) . '</span>';
}

/** Inline SVG icon (stroke-based, 24×24 grid). */
function icon(string $name, string $cls = ''): string
{
    static $p = [
        'dashboard' => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
        'deploy'    => '<path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"/><path d="m12 15-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"/><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0"/><path d="M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"/>',
        'artisan'   => '<path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/>',
        'terminal'  => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m6 9 3 3-3 3M12 15h5"/>',
        'env'       => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h5"/>',
        'keys'      => '<circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6M15.5 7.5l3 3L22 7l-3-3"/>',
        'users'     => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        'security'  => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/>',
        'settings'  => '<path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6"/>',
        'audit'     => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
        'profile'   => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'logout'    => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
        'menu'      => '<path d="M3 6h18M3 12h18M3 18h18"/>',
        'theme'     => '<circle cx="12" cy="12" r="9"/><path d="M12 3a9 9 0 0 0 0 18z" fill="currentColor"/>',
        'check'     => '<path d="M20 6 9 17l-5-5"/>',
        'alert'     => '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0zM12 9v4M12 17h.01"/>',
        'x'         => '<path d="M18 6 6 18M6 6l12 12"/>',
        'copy'      => '<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
        'branch'    => '<path d="M6 3v12"/><circle cx="18" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><path d="M18 9a9 9 0 0 1-9 9"/>',
        'power'     => '<path d="M18.36 6.64a9 9 0 1 1-12.73 0M12 2v10"/>',
        'clock'     => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        'disk'      => '<path d="M22 12H2M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11zM6 16h.01M10 16h.01"/>',
        'refresh'   => '<path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/>',
        'back'      => '<path d="M19 12H5M12 19l-7-7 7-7"/>',
        'arrow'     => '<path d="M5 12h14M12 5l7 7-7 7"/>',
        'search'    => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'files'     => '<path d="M12 20h9M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z"/>',
        'lock'      => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'plus'      => '<path d="M12 5v14M5 12h14"/>',
        'download'  => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/>',
        'sync'      => '<path d="M21 2v6h-6M3 22v-6h6"/><path d="M3 12a9 9 0 0 1 15-6.7L21 8M21 12a9 9 0 0 1-15 6.7L3 16"/>',
        'folder'    => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
        'projects'  => '<path d="M2 9a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2z"/><path d="M6 7V5a2 2 0 0 1 2-2h4l2 2h6a2 2 0 0 1 2 2v7"/>',
        'chevrons'  => '<path d="m7 15 5 5 5-5M7 9l5-5 5 5"/>',
        'list'      => '<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
    ];
    return '<svg class="ic' . ($cls ? ' ' . $cls : '') . '" viewBox="0 0 24 24" aria-hidden="true">' . ($p[$name] ?? '') . '</svg>';
}

/** Page header: title, optional subtitle, optional right-aligned actions. $title/$actions are HTML; $sub is escaped. */
function page_head(string $title, string $sub = '', string $actions = ''): string
{
    return '<div class="phead"><div class="phead-t"><h1>' . $title . '</h1>' . ($sub !== '' ? '<p>' . h($sub) . '</p>' : '') . '</div>'
        . ($actions !== '' ? '<div class="row">' . $actions . '</div>' : '') . '</div>';
}

/** Dashboard stat tile. $value/$sub are HTML. */
function stat_tile(string $icon, string $label, string $value, string $sub = '', string $tone = ''): string
{
    return '<div class="stat"><div class="stat-ic ' . h($tone) . '">' . icon($icon) . '</div><div class="stat-t"><small>' . h($label) . '</small>'
        . '<b>' . $value . '</b>' . ($sub !== '' ? '<span>' . $sub . '</span>' : '') . '</div></div>';
}

function avatar(string $name): string
{
    return '<span class="avatar" aria-hidden="true">' . h(mb_strtoupper(mb_substr($name, 0, 1))) . '</span>';
}

function empty_state(string $icon, string $text): string
{
    return '<div class="empty">' . icon($icon) . '<p>' . h($text) . '</p></div>';
}

function layout_css(): string
{
    $dark = '--bg:#0a0d12;--side:#0d1117;--panel:#11161e;--panel2:#171d27;--line:#1f2732;--line2:#2a3441;--text:#e6ebf2;--mute:#8d98aa;--faint:#5e697a;'
        . '--acc:#34d399;--acc2:#22d3ee;--acc-ink:#03140d;--acc-soft:rgba(52,211,153,.12);--ring:rgba(52,211,153,.35);'
        . '--warn:#fbbf24;--warn-soft:rgba(251,191,36,.12);--err:#f87171;--err-soft:rgba(248,113,113,.12);--info:#60a5fa;--info-soft:rgba(96,165,250,.12);'
        . '--link:#7cc4ff;--input:#0b0f15;--code:#0b0f15;--shadow:0 1px 2px rgba(0,0,0,.4),0 10px 30px -12px rgba(0,0,0,.5);color-scheme:dark;';
    $light = '--bg:#f4f6f9;--side:#ffffff;--panel:#ffffff;--panel2:#f2f5f8;--line:#e4e9ef;--line2:#d2d9e2;--text:#0f172a;--mute:#5a6576;--faint:#8b95a5;'
        . '--acc:#059669;--acc2:#0891b2;--acc-ink:#ffffff;--acc-soft:rgba(5,150,105,.09);--ring:rgba(5,150,105,.28);'
        . '--warn:#b45309;--warn-soft:rgba(217,119,6,.1);--err:#dc2626;--err-soft:rgba(220,38,38,.08);--info:#2563eb;--info-soft:rgba(37,99,235,.08);'
        . '--link:#1d6fd8;--input:#ffffff;--code:#f5f7fa;--shadow:0 1px 2px rgba(15,23,42,.05),0 8px 24px -14px rgba(15,23,42,.18);color-scheme:light;';
    $svg = fn(string $body, string $stroke = 'black') => 'url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 24 24\' fill=\'none\' stroke=\''
        . $stroke . '\' stroke-width=\'2\' stroke-linecap=\'round\' stroke-linejoin=\'round\'%3E' . $body . '%3C/svg%3E")';
    $iOk = $svg("%3Ccircle cx='12' cy='12' r='10'/%3E%3Cpath d='m8 12 3 3 5-6'/%3E");
    $iErr = $svg("%3Ccircle cx='12' cy='12' r='10'/%3E%3Cpath d='M15 9l-6 6M9 9l6 6'/%3E");
    $iWarn = $svg("%3Cpath d='M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0zM12 9v4M12 17h.01'/%3E");
    $chev = $svg("%3Cpath d='m6 9 6 6 6-6'/%3E", '%238d98aa');

    return <<<CSS
:root{{$light}--sans:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",sans-serif;--mono:"JetBrains Mono",ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
--term:#080b10;--term-line:#1c232d;--term-text:#d5dce6;--r:14px}
@media (prefers-color-scheme:dark){:root:not([data-theme=light]){{$dark}}}
:root[data-theme=dark]{{$dark}}
*{box-sizing:border-box}html{-webkit-text-size-adjust:100%}
body{margin:0;background:var(--bg);color:var(--text);font:14px/1.55 var(--sans);-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale}
a{color:var(--link);text-decoration:none}a:hover{text-decoration:underline}
::selection{background:var(--ring)}
.ic{width:18px;height:18px;flex:none;stroke:currentColor;fill:none;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}

/* ---- shell ---- */
.app{display:flex;min-height:100vh}
.side{position:sticky;top:0;height:100vh;width:252px;flex:none;background:var(--side);border-right:1px solid var(--line);display:flex;flex-direction:column;padding:18px 12px 14px;overflow-y:auto;z-index:30}
.brand{display:flex;align-items:center;gap:11px;padding:2px 8px 14px;color:var(--text)}
.brand:hover{text-decoration:none}
.logo{width:36px;height:36px;border-radius:11px;flex:none;display:grid;place-items:center;color:#fff;background:linear-gradient(135deg,var(--acc),var(--acc2));box-shadow:0 6px 18px -6px var(--acc)}
.logo .ic{width:19px;height:19px;stroke-width:2}
.brand b{display:block;font-size:15px;letter-spacing:.08em;line-height:1.2}.brand small{display:block;color:var(--faint);font-size:11.5px}
.nav-g{margin-top:16px}.nav-l{font-size:10.5px;font-weight:650;text-transform:uppercase;letter-spacing:.1em;color:var(--faint);padding:0 10px 6px}
.side nav a{position:relative;display:flex;align-items:center;gap:11px;padding:8px 10px;margin:1px 0;border-radius:9px;color:var(--mute);font-weight:500;transition:background .15s,color .15s}
.side nav a:hover{background:var(--panel2);color:var(--text);text-decoration:none}
.side nav a.on{background:var(--acc-soft);color:var(--text);font-weight:600}.side nav a.on .ic{color:var(--acc)}
.side nav a.on::before{content:"";position:absolute;left:-12px;top:9px;bottom:9px;width:3px;border-radius:0 3px 3px 0;background:var(--acc)}
.side-foot{margin-top:auto;padding-top:14px;border-top:1px solid var(--line)}
.me{display:flex;align-items:center;gap:10px;padding:8px;border-radius:10px;color:var(--text);min-width:0}
.me:hover{background:var(--panel2);text-decoration:none}.me>div{min-width:0}
.me b{display:block;font-size:13.5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.me small{display:block;color:var(--faint);font-size:11.5px}
.side-act{display:flex;gap:6px;margin-top:8px}.side-act>*{flex:1}.side-act form{display:flex}.side-act button{width:100%}
.avatar{width:34px;height:34px;border-radius:50%;flex:none;display:grid;place-items:center;font-weight:700;font-size:13.5px;color:var(--acc);background:var(--acc-soft);border:1px solid var(--ring)}
.content{flex:1;min-width:0;display:flex;flex-direction:column}
.topbar{display:none}
main{width:100%;max-width:1240px;margin:0 auto;padding:30px 34px 56px}
.scrim{display:none}

/* ---- project switcher ---- */
.proj{margin:2px 0 4px}
.proj-box{position:relative;display:flex;align-items:center;gap:10px;margin:0;padding:8px 10px;border:1px solid var(--line2);border-radius:11px;background:var(--panel);cursor:pointer;transition:border-color .15s,box-shadow .15s}
.proj-box:hover{border-color:var(--faint)}.proj-box:focus-within{border-color:var(--acc);box-shadow:0 0 0 3px var(--ring)}
.proj-box.static{cursor:default}.proj-box>.ic{color:var(--acc)}
.proj-t{flex:1;min-width:0;display:block}.proj-t small{display:block;font-size:10.5px;font-weight:650;text-transform:uppercase;letter-spacing:.08em;color:var(--faint);margin:0}
.proj-t b{display:block;font-size:13.5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.proj-box select,.proj-box select:hover,.proj-box select:focus{border:0;box-shadow:none;background:none;height:auto;padding:0;margin:0;font-weight:650;font-size:13.5px;color:var(--text);cursor:pointer;width:100%;
 position:static;text-overflow:ellipsis}
.proj-chev{width:15px;height:15px;color:var(--faint);pointer-events:none}
.proj-add{display:grid;place-items:center;width:26px;height:26px;border-radius:7px;color:var(--mute)}.proj-add:hover{background:var(--panel2);color:var(--text)}.proj-add .ic{width:15px;height:15px}
.topbar-proj{margin-left:auto;display:inline-flex;align-items:center;gap:6px;max-width:50%;padding:4px 10px;border-radius:99px;background:var(--acc-soft);font-size:12.5px;font-weight:600;overflow:hidden;white-space:nowrap;text-overflow:ellipsis}
.topbar-proj .ic{width:14px;height:14px;color:var(--acc)}
.pgrid{display:grid;gap:14px;grid-template-columns:repeat(auto-fill,minmax(280px,1fr))}
.pcard{display:flex;flex-direction:column;gap:12px;padding:18px;border:1px solid var(--line);border-radius:var(--r);background:var(--panel);box-shadow:var(--shadow);min-width:0}
.pcard.on{border-color:color-mix(in srgb,var(--acc) 50%,transparent);box-shadow:0 0 0 3px var(--acc-soft),var(--shadow)}
.pcard-h{display:flex;align-items:center;gap:12px;min-width:0}.pcard-h .n{flex:1;min-width:0}
.pcard-h b{display:block;font-size:15px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.pcard-h small{display:block;color:var(--faint);font-size:12px;font-family:var(--mono)}
.pmeta{display:flex;flex-wrap:wrap;gap:6px 14px;color:var(--mute);font-size:12.5px}.pmeta span{display:inline-flex;align-items:center;gap:5px;min-width:0}
.pmeta .ic{width:14px;height:14px;color:var(--faint)}
.pcard .row{margin-top:auto}
.pmore{position:relative}.pmore summary{list-style:none}.pmore summary::-webkit-details-marker{display:none}
.pmore-b{position:absolute;right:0;top:calc(100% + 6px);z-index:20;width:min(300px,80vw);padding:16px;background:var(--panel);border:1px solid var(--line2);border-radius:12px;box-shadow:0 20px 50px -18px rgba(0,0,0,.45)}
.pmore-b label{margin-bottom:12px}.pmore-b .divider{margin:14px 0}
.sub-h{display:flex;align-items:center;gap:7px}.sub-h .ic{width:15px;height:15px}
.hint .ic.small,.hint .ic{width:14px;height:14px;vertical-align:-2px}
.access{border:1px solid var(--line);border-radius:12px;padding:12px;margin-bottom:16px}
.access .chks{margin:10px 0 0}.access .chk{margin:0}
.access:has(input[value=all]:checked) .chks{opacity:.45;pointer-events:none}

/* ---- typography ---- */
.phead{display:flex;flex-wrap:wrap;align-items:flex-end;justify-content:space-between;gap:14px 20px;margin-bottom:24px}
.phead-t{min-width:0}
h1{font-size:25px;line-height:1.25;font-weight:700;letter-spacing:-.02em;margin:0;display:flex;flex-wrap:wrap;align-items:center;gap:10px}
.phead p{margin:6px 0 0;color:var(--mute);max-width:72ch}
h2{font-size:15px;font-weight:650;margin:0 0 16px;color:var(--text);display:flex;flex-wrap:wrap;align-items:center;gap:8px;letter-spacing:-.005em}
h2 .ic{color:var(--faint)}
.sub-h{font-size:12px;font-weight:650;color:var(--faint);text-transform:uppercase;letter-spacing:.08em;margin:22px 0 10px}
.mute{color:var(--mute)}.small{font-size:12.5px}.break{word-break:break-all}
p{margin:0 0 12px}

/* ---- cards & grids ---- */
.card{background:var(--panel);border:1px solid var(--line);border-radius:var(--r);padding:22px;margin-bottom:18px;min-width:0;box-shadow:var(--shadow)}
.card>:last-child{margin-bottom:0}
.card-h{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:10px;margin-bottom:16px}.card-h h2{margin:0}
.grid{display:grid;gap:18px;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));margin-bottom:18px}
.grid>.card,.grid>form.card,.grid>.stack>.card:last-child{margin-bottom:0}.grid .grid{margin-bottom:0}
.grid-main{display:grid;gap:18px;grid-template-columns:minmax(0,1.55fr) minmax(0,1fr);align-items:start;margin-bottom:18px}
.grid-main>*{margin-bottom:0}.grid-main .card{margin-bottom:18px}.grid-main>.stack>.card:last-child,.grid-main>.card{margin-bottom:0}
.stack>*+*{margin-top:18px}.stack>.card{margin-bottom:0}
.stats{display:grid;gap:14px;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));margin-bottom:18px}
.stat{display:flex;gap:14px;align-items:center;background:var(--panel);border:1px solid var(--line);border-radius:var(--r);padding:16px 18px;box-shadow:var(--shadow);min-width:0}
.stat-ic{width:42px;height:42px;border-radius:12px;flex:none;display:grid;place-items:center;background:var(--panel2);color:var(--mute)}
.stat-ic .ic{width:20px;height:20px}
.stat-ic.ok{background:var(--acc-soft);color:var(--acc)}.stat-ic.warn{background:var(--warn-soft);color:var(--warn)}.stat-ic.err{background:var(--err-soft);color:var(--err)}.stat-ic.info{background:var(--info-soft);color:var(--info)}
.stat-t{min-width:0}.stat small{display:block;color:var(--mute);font-size:12px;font-weight:500}
.stat-t>b{display:block;font-size:17px;font-weight:650;letter-spacing:-.01em;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.stat-t>span{display:block;color:var(--faint);font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.empty{display:flex;flex-direction:column;align-items:center;gap:8px;text-align:center;padding:26px 10px;color:var(--faint)}
.empty .ic{width:30px;height:30px;stroke-width:1.5}.empty p{margin:0}

/* ---- tables ---- */
.tbl{overflow-x:auto}
table{width:100%;border-collapse:collapse}
th,td{text-align:left;padding:11px 12px;border-bottom:1px solid var(--line);vertical-align:middle}
th{color:var(--faint);font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.07em;white-space:nowrap;padding-top:8px;padding-bottom:8px}
tr:last-child>td{border-bottom:0}tr:hover>td{background:color-mix(in srgb,var(--panel2) 70%,transparent)}
td.k{color:var(--mute);width:170px;white-space:nowrap;font-weight:500}
.card>.tbl{margin:0 -22px -10px}.card.tbl{padding:4px 0}
.card>.tbl tr>:first-child,.card.tbl tr>:first-child{padding-left:22px}.card>.tbl tr>:last-child,.card.tbl tr>:last-child{padding-right:22px}
td.row{flex-wrap:nowrap}td .row{flex-wrap:nowrap}

/* ---- forms ---- */
label{display:block;margin:0 0 16px}
label>span{display:block;color:var(--mute);font-size:12.5px;font-weight:500;margin-bottom:6px;line-height:1.45}
input[type=text],input[type=password],input[type=number],select,textarea{width:100%;background:var(--input);color:var(--text);border:1px solid var(--line2);border-radius:10px;padding:9px 12px;font:inherit;transition:border-color .15s,box-shadow .15s}
input[type=text],input[type=password],input[type=number],select{height:40px}
input::placeholder,textarea::placeholder{color:var(--faint)}
input:hover,select:hover,textarea:hover{border-color:var(--faint)}
input:focus,select:focus,textarea:focus{outline:none;border-color:var(--acc);box-shadow:0 0 0 3px var(--ring)}
input[readonly],textarea[readonly]{background:var(--code);color:var(--mute)}
select{appearance:none;-webkit-appearance:none;padding-right:36px;background-image:{$chev};background-repeat:no-repeat;background-position:right 11px center;background-size:16px;cursor:pointer}
textarea{font:12.5px/1.6 var(--mono);resize:vertical;min-height:80px}
input[type=checkbox],input[type=radio]{width:16px;height:16px;margin:2px 0 0;flex:none;accent-color:var(--acc);cursor:pointer}
.chk{display:flex;gap:11px;align-items:flex-start;margin:0 0 6px;padding:9px 12px;border:1px solid var(--line);border-radius:10px;cursor:pointer;transition:background .15s,border-color .15s;line-height:1.45}
.chk:hover{background:var(--panel2)}.chk:has(input:checked){border-color:color-mix(in srgb,var(--acc) 45%,transparent);background:var(--acc-soft)}
.chk:has(input:disabled){opacity:.65;cursor:not-allowed}
.chk>span{color:var(--text);font-size:13.5px;margin:0;font-weight:400}
.chks{display:grid;gap:6px;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));margin-bottom:16px}.chks .chk{margin:0}
.field-row{display:grid;gap:0 14px;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));align-items:end}
.hint{color:var(--faint);font-size:12.5px;margin:8px 0 0}

/* ---- buttons ---- */
button,.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;height:38px;padding:0 15px;border-radius:10px;border:1px solid var(--line2);background:var(--panel);color:var(--text);
 font:550 13.5px/1 var(--sans);cursor:pointer;text-decoration:none;white-space:nowrap;transition:background .15s,border-color .15s,box-shadow .15s,filter .15s,transform .06s;user-select:none}
button:hover,.btn:hover{background:var(--panel2);border-color:var(--faint);text-decoration:none}
button:active,.btn:active{transform:translateY(1px)}
button:focus-visible,.btn:focus-visible,a:focus-visible{outline:none;box-shadow:0 0 0 3px var(--ring)}
button .ic,.btn .ic{width:16px;height:16px}
.primary{background:linear-gradient(180deg,color-mix(in srgb,var(--acc) 92%,#fff),var(--acc));border-color:var(--acc);color:var(--acc-ink);font-weight:650;box-shadow:0 6px 16px -8px var(--acc)}
.primary:hover{background:var(--acc);border-color:var(--acc);filter:brightness(1.07)}
.danger{background:transparent;color:var(--err);border-color:color-mix(in srgb,var(--err) 40%,transparent)}
.danger:hover{background:var(--err-soft);border-color:var(--err)}
.danger.solid{background:var(--err);color:#fff;border-color:var(--err)}.danger.solid:hover{filter:brightness(1.08);background:var(--err)}
.ghost{background:transparent;border-color:transparent;color:var(--mute)}.ghost:hover{color:var(--text);background:var(--panel2);border-color:transparent}
button.sm,.btn.sm{height:30px;padding:0 11px;font-size:12.5px;border-radius:8px}
.btn-lg{height:46px;padding:0 24px;font-size:15px;border-radius:12px}
.icon-btn{width:38px;padding:0}.icon-btn.sm{width:30px}
button:disabled{opacity:.55;cursor:not-allowed}
button.busy{pointer-events:none;opacity:.8}
button.busy::before{content:"";width:14px;height:14px;flex:none;border:2px solid currentColor;border-right-color:transparent;border-radius:50%;animation:spin .7s linear infinite}
button.busy .ic{display:none}
@keyframes spin{to{transform:rotate(360deg)}}
.row{display:flex;flex-wrap:wrap;gap:8px;align-items:center}.row form{margin:0}.row label{margin:0}
.row.end{justify-content:flex-end}.spread{justify-content:space-between}
.actions{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-top:20px;padding-top:18px;border-top:1px solid var(--line)}

/* ---- feedback ---- */
.flash{position:relative;padding:13px 44px 13px 46px;border-radius:12px;margin-bottom:16px;border:1px solid;color:var(--text);line-height:1.5;animation:drop .25s ease-out}
.flash::before{content:"";position:absolute;left:15px;top:14px;width:19px;height:19px;-webkit-mask:var(--fi) center/contain no-repeat;mask:var(--fi) center/contain no-repeat;background:var(--fc)}
.flash.ok{--fc:var(--acc);--fi:{$iOk};border-color:color-mix(in srgb,var(--acc) 35%,transparent);background:var(--acc-soft)}
.flash.err{--fc:var(--err);--fi:{$iErr};border-color:color-mix(in srgb,var(--err) 35%,transparent);background:var(--err-soft)}
.flash.warn{--fc:var(--warn);--fi:{$iWarn};border-color:color-mix(in srgb,var(--warn) 35%,transparent);background:var(--warn-soft)}
.flash:not([data-x]){padding-right:16px}
.flash pre{margin:10px 0 0}.flash a{font-weight:600}
.flash-x{position:absolute;right:8px;top:8px;width:28px;height:28px;padding:0;border:0;background:transparent;color:var(--mute);border-radius:8px}
.flash-x:hover{background:color-mix(in srgb,var(--fc) 15%,transparent);color:var(--text)}
@keyframes drop{from{opacity:0;transform:translateY(-4px)}}
.badge{display:inline-flex;align-items:center;gap:6px;height:22px;padding:0 9px;border-radius:99px;font-size:11.5px;font-weight:600;line-height:1;letter-spacing:.01em;
 background:var(--panel2);color:var(--mute);border:1px solid var(--line);white-space:nowrap;vertical-align:middle}
.b-ok,.b-err,.b-warn,.b-run{border-color:transparent}
.b-ok{color:var(--acc);background:var(--acc-soft)}.b-err{color:var(--err);background:var(--err-soft)}.b-warn{color:var(--warn);background:var(--warn-soft)}.b-run{color:var(--info);background:var(--info-soft)}
.b-ok::before,.b-err::before,.b-warn::before,.b-run::before{content:"";width:6px;height:6px;border-radius:50%;background:currentColor}
.b-run::before{animation:pulse 1.2s ease-in-out infinite}
@keyframes pulse{50%{opacity:.25}}
.dot{width:8px;height:8px;border-radius:50%;flex:none;background:var(--faint)}.dot.ok{background:var(--acc)}.dot.err{background:var(--err)}.dot.warn{background:var(--warn)}
.live{display:inline-flex;align-items:center;gap:8px;height:28px;padding:0 12px;border-radius:99px;font-size:12.5px;font-weight:600;color:var(--info);background:var(--info-soft)}
.live i{width:12px;height:12px;border:2px solid currentColor;border-right-color:transparent;border-radius:50%;animation:spin .8s linear infinite}
.live.done{color:var(--mute);background:var(--panel2)}.live.done i{animation:none;border:0;width:7px;height:7px;background:currentColor}

/* ---- code & logs ---- */
pre,.log{position:relative;background:var(--code);border:1px solid var(--line);border-radius:12px;padding:14px 16px;overflow:auto;white-space:pre-wrap;word-break:break-word;font:12.5px/1.65 var(--mono);max-height:70vh;margin:0}
.log{background:var(--term);color:var(--term-text);border-color:var(--term-line);min-height:180px;padding:18px 20px}
code{font-family:var(--mono);background:var(--panel2);border:1px solid var(--line);padding:1px 6px;border-radius:6px;font-size:12px;word-break:break-word}
pre code{background:none;border:0;padding:0}
.copywrap{position:relative}
.copy-btn{position:absolute;top:8px;right:8px;height:28px;padding:0 9px;font-size:12px;border-radius:8px;opacity:0;transition:opacity .15s;background:var(--panel);z-index:1}
.copywrap:hover .copy-btn,.copy-btn:focus-visible{opacity:1}.copy-btn.done{color:var(--acc)}
@media (hover:none){.copy-btn{opacity:1}}

/* ---- dashboard bits ---- */
.qa{display:grid;gap:8px}
.qa a,.qa button{justify-content:flex-start;width:100%;height:44px;gap:12px}
.qa .ic{width:17px;height:17px;color:var(--mute)}.qa .primary .ic{color:inherit}
.qa .go{margin-left:auto;opacity:.5}
.deps{list-style:none;margin:0;padding:0}
.deps li{display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--line)}.deps li:last-child{border-bottom:0}
.deps .who{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.deps .when{color:var(--faint);font-size:12.5px;white-space:nowrap}
.pipeline{display:flex;gap:0;list-style:none;margin:0 0 20px;padding:0;counter-reset:s}
.pipeline li{flex:1;position:relative;padding:0 6px;text-align:center;font-size:12.5px;color:var(--mute);counter-increment:s}
.pipeline li::before{content:counter(s);display:grid;place-items:center;width:28px;height:28px;margin:0 auto 6px;border-radius:50%;background:var(--acc-soft);color:var(--acc);font-weight:700;font-size:12.5px;position:relative;z-index:1}
.pipeline li+li::after{content:"";position:absolute;top:14px;right:calc(50% + 18px);width:calc(100% - 36px);height:2px;background:var(--line2)}
.pipeline b{display:block;color:var(--text);font-weight:600;font-size:13px}
.kv{display:grid;grid-template-columns:auto 1fr;gap:10px 18px;margin:0}
.kv dt{color:var(--mute);font-weight:500}.kv dd{margin:0;min-width:0;word-break:break-word}

/* ---- artisan ---- */
.agrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:8px}
.agrid form{margin:0}
.agrid button{width:100%;height:auto;min-height:42px;padding:10px 12px;justify-content:flex-start;text-align:left;white-space:normal;font:500 12.5px/1.35 var(--mono)}
.agrid button .ic{width:15px;height:15px;color:var(--faint)}
.agrid button.danger .ic{color:var(--err)}
.searchbox{position:relative;flex:1;min-width:220px}.searchbox .ic{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--faint);width:16px;height:16px;pointer-events:none}
.searchbox input{padding-left:38px}
kbd{font:11px var(--mono);padding:2px 6px;border:1px solid var(--line2);border-bottom-width:2px;border-radius:5px;color:var(--mute);background:var(--panel2)}
.cmdline{display:flex;align-items:center;border:1px solid var(--line2);border-radius:10px;background:var(--input);overflow:hidden;flex:1;min-width:240px;transition:border-color .15s,box-shadow .15s}
.cmdline:focus-within{border-color:var(--acc);box-shadow:0 0 0 3px var(--ring)}
.cmdline span{padding:0 4px 0 13px;font:12.5px var(--mono);color:var(--acc);white-space:nowrap}
.cmdline input{border:0;box-shadow:none!important;background:transparent;font:13px var(--mono);padding-left:6px}

/* ---- terminal ---- */
.term{background:var(--term);border:1px solid var(--term-line);border-radius:var(--r);font:13px/1.6 var(--mono);color:var(--term-text);overflow:hidden;box-shadow:var(--shadow)}
.term-bar{display:flex;align-items:center;gap:12px;padding:10px 14px;border-bottom:1px solid var(--term-line);background:rgba(255,255,255,.025);font:12px var(--sans);color:#8d98aa}
.term-dots{display:flex;gap:7px}.term-dots i{width:11px;height:11px;border-radius:50%;background:#ff5f57}.term-dots i+i{background:#febc2e}.term-dots i+i+i{background:#28c840}
.term-bar .t{flex:1;text-align:center;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.term-out{height:60vh;overflow:auto;padding:16px 18px;white-space:pre-wrap;word-break:break-word}
.term-out:empty::before{content:"Type a command below and press Enter.";color:#5e697a}
.term-in{display:flex;gap:8px;align-items:center;border-top:1px solid var(--term-line);padding:10px 18px;background:rgba(255,255,255,.02)}
.term-in input,.term-in input:focus,.term-in input:hover{border:0;background:transparent;padding:4px;height:auto;font:inherit;color:var(--term-text);box-shadow:none;outline:none}
.t-cmd{color:#34d399}.t-err{color:#f87171}.cwd{color:#7cc4ff;white-space:nowrap;max-width:45%;overflow:hidden;text-overflow:ellipsis}
details.card summary{cursor:pointer;font-weight:600;list-style:none;display:flex;align-items:center;gap:8px}
details.card summary::-webkit-details-marker{display:none}
details.card summary::before{content:"";width:7px;height:7px;border-right:2px solid var(--faint);border-bottom:2px solid var(--faint);transform:rotate(-45deg);transition:transform .15s;margin-right:4px}
details.card[open] summary::before{transform:rotate(45deg)}details.card[open] summary{margin-bottom:14px}
.tips{display:flex;flex-wrap:wrap;gap:6px 16px;color:var(--mute);font-size:12.5px;margin:14px 2px 18px}
.tips span{display:inline-flex;align-items:center;gap:6px}

/* ---- users / keys ---- */
.ucard-h{display:flex;flex-wrap:wrap;align-items:center;gap:14px;margin-bottom:18px}
.ucard-h .avatar{width:44px;height:44px;font-size:17px}
.ucard-h .n{flex:1;min-width:0}.ucard-h .n b{font-size:16px;display:flex;flex-wrap:wrap;align-items:center;gap:8px}.ucard-h .n small{color:var(--faint);font-size:12.5px}
.key-ic{width:40px;height:40px;border-radius:11px;display:grid;place-items:center;background:var(--panel2);color:var(--mute);flex:none}
.key-ic.on{background:var(--acc-soft);color:var(--acc)}
.divider{border:0;border-top:1px solid var(--line);margin:20px 0}

/* ---- modal ---- */
dialog.modal{border:1px solid var(--line);border-radius:18px;background:var(--panel);color:var(--text);padding:0;width:min(460px,calc(100vw - 32px));box-shadow:0 30px 80px -20px rgba(0,0,0,.55)}
dialog.modal[open]{animation:pop .18s ease-out}
dialog::backdrop{background:rgba(4,7,12,.55);backdrop-filter:blur(3px)}
@keyframes pop{from{opacity:0;transform:translateY(8px) scale(.98)}}
.modal-b{display:flex;gap:16px;padding:24px 24px 8px}
.modal-ic{width:42px;height:42px;border-radius:50%;flex:none;display:grid;place-items:center;background:var(--warn-soft);color:var(--warn)}
.modal-ic.danger{background:var(--err-soft);color:var(--err)}
.modal h3{margin:2px 0 6px;font-size:16.5px;font-weight:650}.modal p{margin:0;color:var(--mute);line-height:1.55}
.modal-f{display:flex;justify-content:flex-end;gap:8px;padding:20px 24px 22px}

/* ---- auth pages ---- */
body.auth{background:radial-gradient(900px 520px at 0% -10%,var(--acc-soft),transparent 65%),radial-gradient(800px 500px at 110% 110%,var(--info-soft),transparent 60%),var(--bg);background-attachment:fixed}
main.bare{min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:32px 16px}
main.bare>*{width:100%;max-width:440px}
.auth-brand{display:flex;align-items:center;justify-content:center;gap:11px;margin-bottom:22px}
.auth-brand .logo{width:42px;height:42px;border-radius:13px}.auth-brand b{font-size:19px;letter-spacing:.1em}
main.bare .card{padding:30px;margin:0;border-radius:18px}
main.bare h1{font-size:21px;margin-bottom:6px}
.login{max-width:440px;margin:6vh auto}
.login .lead{color:var(--mute);margin:0 0 22px}
.login button.primary{width:100%;height:44px;margin-top:4px}
.auth-foot{text-align:center;color:var(--faint);font-size:12px;margin-top:18px}.auth-foot .ic{width:13px;height:13px;vertical-align:-2px}
.otp{font:600 22px var(--mono)!important;letter-spacing:.4em;text-align:center;height:52px!important}

/* ---- responsive ---- */
@media (max-width:1100px){.grid-main{grid-template-columns:minmax(0,1fr)}}
@media (max-width:900px){
 .side{position:fixed;left:0;top:0;bottom:0;transform:translateX(-100%);transition:transform .22s ease;box-shadow:none}
 body.nav-open .side{transform:none;box-shadow:0 0 60px rgba(0,0,0,.4)}
 body.nav-open .scrim{display:block;position:fixed;inset:0;background:rgba(4,7,12,.5);z-index:20}
 .topbar{display:flex;align-items:center;gap:10px;position:sticky;top:0;z-index:10;padding:10px 16px;background:color-mix(in srgb,var(--side) 88%,transparent);backdrop-filter:blur(10px);border-bottom:1px solid var(--line)}
 .topbar .brand{padding:0}.topbar .logo{width:30px;height:30px;border-radius:9px}.topbar .logo .ic{width:16px;height:16px}
 main{padding:22px 16px 40px}
}
@media (max-width:640px){
 td.k{width:auto;white-space:normal}h1{font-size:21px}.card{padding:18px}.card>.tbl{margin:0 -18px -8px}
 .card>.tbl tr>:first-child,.card.tbl tr>:first-child{padding-left:18px}.card>.tbl tr>:last-child,.card.tbl tr>:last-child{padding-right:18px}
 .pipeline li{font-size:11.5px}.phead{align-items:flex-start}
 .stats{grid-template-columns:1fr 1fr;gap:10px}.stat{flex-direction:column;align-items:flex-start;gap:10px;padding:14px}.stat-ic{width:36px;height:36px}
}
@media (prefers-reduced-motion:reduce){*,*::before{animation:none!important;transition:none!important}}
CSS;
}

function nav_items(): array
{
    return [
        'Overview'       => ['dashboard' => ['Dashboard', null]],
        'Operations'     => ['deploy' => ['Deploy', 'deploy'], 'artisan' => ['Artisan', 'artisan'], 'terminal' => ['Terminal', 'terminal']],
        'Configuration'  => ['env' => ['Environment', 'env'], 'keys' => ['SSH keys', 'keys']],
        'Administration' => ['projects' => ['Projects', 'admin'], 'users' => ['Users', 'admin'], 'security' => ['Security', 'admin'], 'settings' => ['Settings', 'admin'], 'audit' => ['Audit log', 'admin']],
    ];
}

function layout_head(string $title, bool $nav = true): string
{
    $u = $nav ? current_user() : null;
    $nonce = csp_nonce();
    $logo = '<span class="logo">' . icon('deploy') . '</span>';
    $shell = '';
    if ($u) {
        $cur = (string) ($_GET['a'] ?? 'dashboard');
        if ($cur === 'deploy_log') {
            $cur = 'deploy';
        }
        $navHtml = '';
        foreach (nav_items() as $group => $items) {
            $links = '';
            foreach ($items as $k => [$label, $perm]) {
                if ($perm === null || can($perm)) {
                    $on = $cur === $k;
                    $links .= '<a href="?a=' . $k . '"' . ($on ? ' class="on" aria-current="page"' : '') . '>' . icon($k) . '<span>' . h($label) . '</span></a>';
                }
            }
            if ($links !== '') {
                $navHtml .= '<div class="nav-g"><div class="nav-l">' . h($group) . '</div>' . $links . '</div>';
            }
        }
        $role = in_array('admin', $u['perm_list'] ?? [], true) ? 'Administrator' : 'Member';
        $proj = current_project();
        $plist = accessible_projects($u);
        $switch = '';
        if ($proj && count($plist) > 1) {
            $opts = '';
            foreach ($plist as $p) {
                $opts .= '<option value="' . $p['id'] . '"' . ($p['id'] === $proj['id'] ? ' selected' : '') . '>' . h($p['name']) . '</option>';
            }
            $switch = '<form method="post" action="?a=project_switch" class="proj">' . csrf_field() . '<input type="hidden" name="return" value="' . h($cur) . '">'
                . '<label class="proj-box" title="Switch project">' . icon('folder') . '<span class="proj-t"><small>Project</small>'
                . '<select name="id" data-autosubmit aria-label="Switch project">' . $opts . '</select></span>' . icon('chevrons', 'proj-chev') . '</label>'
                . '<noscript><button class="sm" type="submit" style="width:100%;margin-top:6px">Switch</button></noscript></form>';
        } elseif ($proj) {
            $switch = '<div class="proj"><div class="proj-box static">' . icon('folder') . '<span class="proj-t"><small>Project</small><b>' . h($proj['name']) . '</b></span>'
                . (can('admin') ? '<a class="proj-add" href="?a=projects#new-project" title="Add another project">' . icon('plus') . '</a>' : '') . '</div></div>';
        }
        $shell = '<div class="app"><aside class="side" id="side"><a class="brand" href="?a=dashboard">' . $logo
            . '<span><b>DPLY</b><small>Deploy console</small></span></a>' . $switch . '<nav aria-label="Main">' . $navHtml . '</nav>'
            . '<div class="side-foot"><a class="me' . ($cur === 'profile' ? ' on' : '') . '" href="?a=profile" title="Your profile">' . avatar($u['username'])
            . '<div><b>' . h($u['username']) . '</b><small>' . h($role) . '</small></div></a>'
            . '<div class="side-act"><button type="button" class="sm ghost" id="themeBtn" title="Toggle light / dark theme">' . icon('theme') . 'Theme</button>'
            . '<form method="post" action="?a=logout">' . csrf_field() . '<button class="sm ghost" type="submit">' . icon('logout') . 'Sign out</button></form></div></div></aside>'
            . '<div class="scrim" id="scrim"></div><div class="content"><header class="topbar"><button type="button" class="icon-btn ghost" id="navBtn" aria-label="Open menu">'
            . icon('menu') . '</button><a class="brand" href="?a=dashboard">' . $logo . '<span><b>DPLY</b></span></a>'
            . ($proj ? '<span class="topbar-proj">' . icon('folder') . h($proj['name']) . '</span>' : '') . '</header><main>';
    } else {
        $shell = '<main class="bare"><div class="auth-brand">' . $logo . '<b>DPLY</b></div>';
    }
    shell_open($u !== null);
    $themeJs = "try{var t=localStorage.getItem('dply-theme');if(t)document.documentElement.setAttribute('data-theme',t);}catch(e){}";
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex,nofollow"><meta name="color-scheme" content="light dark">'
        . '<link rel="icon" href="data:image/svg+xml,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#34d399"/><stop offset="1" stop-color="#22d3ee"/></linearGradient></defs><rect width="32" height="32" rx="8" fill="url(#g)"/><path d="M10 22l4-4m2-8c3-2 6-2 7-2 0 1 0 4-2 7l-5 4-4-4z" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>') . '"><title>' . h($title) . (resolved_project_id() ? ' · ' . h(current_project()['name']) : '') . ' · DPLY</title>'
        . '<script nonce="' . $nonce . '">' . $themeJs . '</script><style>' . layout_css() . '</style></head>'
        . '<body' . ($u ? '' : ' class="auth"') . '>' . $shell;
}

function layout_foot(): string
{
    $js = <<<'JS'
(function(){
var d=document,dlg=d.getElementById('cfm'),pending=null;
function $(id){return d.getElementById(id);}
// Confirmation dialog (replaces window.confirm)
d.addEventListener('submit',function(e){
 var f=e.target,s=e.submitter,m=(s&&s.getAttribute('data-confirm'))||f.getAttribute('data-confirm');
 if(m&&f.__ok!==true&&dlg&&dlg.showModal){
  e.preventDefault();pending={f:f,s:s};
  var danger=!!(s&&s.classList.contains('danger'));
  $('cfmMsg').textContent=m;$('cfmIc').className='modal-ic'+(danger?' danger':'');
  var go=$('cfmGo');go.className=danger?'danger solid':'primary';go.textContent=(s&&s.textContent.trim())||'Confirm';
  dlg.showModal();go.focus();return;
 }
 f.__ok=false;
 if(s){s.classList.add('busy');if(f.getAttribute('data-once')!==null){setTimeout(function(){s.disabled=true;},0);}}
},false);
if(dlg){
 $('cfmGo').addEventListener('click',function(){dlg.close();if(!pending)return;var p=pending;pending=null;p.f.__ok=true;
  if(p.f.requestSubmit){p.f.requestSubmit(p.s||undefined);}else{p.f.submit();}});
 $('cfmNo').addEventListener('click',function(){pending=null;dlg.close();});
 dlg.addEventListener('click',function(e){if(e.target===dlg){pending=null;dlg.close();}});
}
// Remove spinners when the page is restored from bfcache
window.addEventListener('pageshow',function(){d.querySelectorAll('button.busy').forEach(function(b){b.classList.remove('busy');b.disabled=false;});});
// Dismissible flashes
d.addEventListener('click',function(e){var x=e.target.closest('.flash-x');if(x){var f=x.parentNode;f.style.transition='opacity .2s';f.style.opacity='0';setTimeout(function(){f.remove();},200);}});
// Copy buttons on logs, code blocks and read-only fields
function copyText(t,btn){function ok(){btn.classList.add('done');btn.lastChild.textContent='Copied';setTimeout(function(){btn.classList.remove('done');btn.lastChild.textContent='Copy';},1600);}
 if(navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(t).then(ok);return;}
 var ta=d.createElement('textarea');ta.value=t;ta.style.position='fixed';ta.style.opacity='0';d.body.appendChild(ta);ta.select();try{d.execCommand('copy');ok();}catch(e){}ta.remove();}
d.querySelectorAll('main pre, main textarea[readonly]').forEach(function(el){
 if(el.closest('.flash'))return;var w=d.createElement('div');w.className='copywrap';el.parentNode.insertBefore(w,el);w.appendChild(el);
 var b=d.createElement('button');b.type='button';b.className='copy-btn';b.innerHTML=$('icCopy').innerHTML+'<span>Copy</span>';
 b.addEventListener('click',function(){copyText(el.value!==undefined&&el.tagName==='TEXTAREA'?el.value:el.textContent,b);});w.appendChild(b);});
// Theme toggle
var tb=$('themeBtn');if(tb)tb.addEventListener('click',function(){var r=d.documentElement,c=r.getAttribute('data-theme')||(matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light'),n=c==='dark'?'light':'dark';
 r.setAttribute('data-theme',n);try{localStorage.setItem('dply-theme',n);}catch(e){}});
// Mobile navigation
var nb=$('navBtn'),sc=$('scrim');if(nb)nb.addEventListener('click',function(){d.body.classList.add('nav-open');});
if(sc)sc.addEventListener('click',function(){d.body.classList.remove('nav-open');});
d.addEventListener('keydown',function(e){if(e.key==='Escape')d.body.classList.remove('nav-open');
 if(e.key==='/'&&!/INPUT|TEXTAREA|SELECT/.test((d.activeElement||{}).tagName||'')){var s=d.querySelector('[data-search]');if(s){e.preventDefault();s.focus();}}});
// Live client-side filter: <input data-search="selector of items">
d.querySelectorAll('[data-search]').forEach(function(inp){var sel=inp.getAttribute('data-search');inp.addEventListener('input',function(){var q=inp.value.toLowerCase().trim();
 d.querySelectorAll(sel).forEach(function(it){it.hidden=q!==''&&it.textContent.toLowerCase().indexOf(q)<0;});
 d.querySelectorAll('[data-group]').forEach(function(g){g.hidden=!g.querySelector(sel+':not([hidden])');});});});
// Auto-submitting selects (project switcher)
d.querySelectorAll('select[data-autosubmit]').forEach(function(s){s.addEventListener('change',function(){var f=s.form;if(f.requestSubmit){f.requestSubmit();}else{f.submit();}});});
// Streaming pages: mark as finished
var lv=$('live');if(lv){lv.classList.add('done');lv.lastChild.textContent='Finished';}
})();
JS;
    $close = shell_open() ? '</main></div></div>' : '</main>';
    $modal = '<dialog class="modal" id="cfm"><div class="modal-b"><div class="modal-ic" id="cfmIc">' . icon('alert') . '</div><div><h3>Are you sure?</h3><p id="cfmMsg"></p></div></div>'
        . '<div class="modal-f"><button type="button" id="cfmNo">Cancel</button><button type="button" id="cfmGo" class="primary">Confirm</button></div></dialog>'
        . '<template id="icCopy">' . icon('copy') . '</template>';
    return $close . $modal . '<script nonce="' . csp_nonce() . '">' . $js . '</script></body></html>';
}

/** Remembers whether layout_head() rendered the app shell so layout_foot() closes the same wrappers. */
function shell_open(?bool $set = null): bool
{
    static $shell = false;
    if ($set !== null) {
        $shell = $set;
    }
    return $shell;
}

function flash_html(): string
{
    $out = '';
    foreach ($_SESSION['flash'] ?? [] as [$type, $msg, $pre]) {
        $out .= '<div class="flash ' . h($type) . '" data-x role="' . ($type === 'err' ? 'alert' : 'status') . '">' . h($msg)
            . ($pre !== null && $pre !== '' ? '<pre>' . h(strip_ansi($pre)) . '</pre>' : '')
            . '<button type="button" class="flash-x" aria-label="Dismiss">' . icon('x') . '</button></div>';
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
        '<!--', str_repeat(' ', 2048), '-->',
        page_head(h($title), (resolved_project_id() ? current_project()['name'] . ' · ' : '') . 'Output streams live below — keep this tab open until it finishes.', '<span class="live" id="live"><i></i><span>Running…</span></span>'),
        '<pre class="log" id="log">';
    flush();
}

function stream_out(?string $s): void
{
    echo $s === null ? '<!-- -->' : h(strip_ansi($s));
    flush();
}

function stream_end(string $html): void
{
    echo '</pre><div style="margin-top:16px">', $html, '</div>', layout_foot();
    flush();
}

function back_link(string $href, string $label): string
{
    return '<p class="row" style="margin-top:4px"><a class="btn" href="' . h($href) . '">' . icon('back') . h($label) . '</a></p>';
}

function steps_checkboxes(array $selected, string $name = 'steps[]'): string
{
    $o = '<div class="chks">';
    foreach (DPLY_STEPS as $k => $label) {
        $o .= '<label class="chk"><input type="checkbox" name="' . h($name) . '" value="' . h($k) . '"'
            . (in_array($k, $selected, true) ? ' checked' : '') . '><span>' . h($label) . '</span></label>';
    }
    return $o . '</div>';
}

function laravel_version(): string
{
    $f = project_root() . '/vendor/laravel/framework/src/Illuminate/Foundation/Application.php';
    if (is_file($f) && preg_match("/const VERSION = '([^']+)'/", (string) file_get_contents($f), $m)) {
        return $m[1];
    }
    return 'unknown';
}
