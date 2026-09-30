<?php
/**
 * Shown instead of a bare 500 when the installation is not finished.
 *
 * Deliberately self-contained: no helpers, no config, no database, no session,
 * no base_url(). The branded error page needs all of those, which is exactly
 * why it cannot be trusted to render at the moment the installation is broken.
 *
 * @var list<string> $problems
 */
$problems = $problems ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Setup not finished &mdash; POLYFIX MATTRESS</title>
<style>
    :root { color-scheme: light dark; }
    * { box-sizing: border-box; }
    body {
        margin: 0; padding: 2rem 1rem;
        font: 16px/1.6 system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        background: #f6f7f9; color: #1c2024;
    }
    .card {
        max-width: 44rem; margin: 0 auto; padding: 1.75rem;
        background: #fff; border: 1px solid #e3e6ea; border-radius: 10px;
    }
    h1 { margin: 0 0 .25rem; font-size: 1.4rem; }
    .brand { font-size: .8rem; letter-spacing: .12em; text-transform: uppercase; color: #6b7280; margin: 0 0 1.25rem; }
    ul { margin: 0 0 1.5rem; padding-left: 1.25rem; }
    li { margin-bottom: .5rem; }
    h2 { font-size: 1rem; margin: 1.5rem 0 .5rem; }
    pre {
        margin: 0; padding: .85rem 1rem; overflow-x: auto;
        background: #11151a; color: #e6edf3; border-radius: 6px;
        font: 13px/1.55 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    }
    p.note { color: #4b5563; font-size: .9rem; }
    @media (prefers-color-scheme: dark) {
        body { background: #14171a; color: #e6e8ea; }
        .card { background: #1c2024; border-color: #2c3238; }
        p.note, .brand { color: #9aa4b2; }
    }
</style>
</head>
<body>
<div class="card">
    <p class="brand">POLYFIX MATTRESS</p>
    <h1>Setup is not finished yet</h1>
    <p>The application stopped rather than run half-configured. Nothing is broken
       and no data has been touched &mdash; the items below just need attention.</p>

    <ul>
<?php foreach ($problems as $problem): ?>
        <li><?= htmlspecialchars($problem, ENT_QUOTES, 'UTF-8') ?></li>
<?php endforeach; ?>
    </ul>

    <h2>If the list mentions secrets</h2>
    <p class="note">Generate them once, then paste them into <code>.env</code>.
       Over SSH the command below does it for you:</p>
    <pre>php spark polyfix:keys --write</pre>
    <p class="note">Without SSH, run <code>php spark polyfix:keys</code> anywhere
       (or use your host&rsquo;s terminal) and copy the three values into
       <code>.env</code> by hand with the File Manager.</p>

    <h2>To check everything at once</h2>
    <pre>php spark polyfix:doctor</pre>
    <p class="note">It tests the PHP build, the writable folders, the secrets,
       the database connection and the migrations, and says which of them fail.
       <code>docs/HOSTINGER-DEPLOYMENT.md</code> walks through each step.</p>

    <p class="note">This page appears only while the installation is unfinished.
       It is never shown once the application can serve a request, and it never
       prints a password, a key or a server path.</p>
</div>
</body>
</html>
