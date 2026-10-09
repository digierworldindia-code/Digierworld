<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title><?= esc($title) ?></title></head>
<body style="font-family:Arial,Helvetica,sans-serif;color:#18212f;background:#f5f7fa;padding:24px">
<table role="presentation" width="100%" style="max-width:560px;margin:auto;background:#fff;border:1px solid #e2e7ee;border-radius:10px">
<tr><td style="padding:18px 24px;background:#0b1f3a;color:#fff;border-radius:10px 10px 0 0;font-weight:bold">BearingCave</td></tr>
<tr><td style="padding:24px">
<h2 style="margin:0 0 12px;font-size:18px;color:#0b1f3a"><?= esc($title) ?></h2>
<?php if ($body !== ''): ?><p style="line-height:1.5"><?= nl2br(esc($body)) ?></p><?php endif ?>
<?php if ($link): ?><p><a href="<?= esc($link, 'attr') ?>" style="background:#1f5fbf;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none;display:inline-block">Open in BearingCave</a></p><?php endif ?>
<p style="color:#5f6b7a;font-size:12px;margin-top:24px">You receive this because you have a BearingCave business account.</p>
</td></tr></table></body></html>
