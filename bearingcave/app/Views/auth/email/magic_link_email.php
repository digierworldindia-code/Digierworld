<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Your BearingCave sign-in link</title></head>
<body style="font-family:Arial,Helvetica,sans-serif;color:#18212f;background:#f5f7fa;padding:24px">
<table role="presentation" width="100%" style="max-width:560px;margin:auto;background:#fff;border:1px solid #e2e7ee;border-radius:10px">
<tr><td style="padding:20px 24px;background:#0b1f3a;color:#fff;border-radius:10px 10px 0 0;font-weight:bold">BearingCave</td></tr>
<tr><td style="padding:24px">
<p>Use the button below to sign in. The link can be used once and expires in one hour.</p>
<p><a href="<?= url_to('verify-magic-link') ?>?token=<?= esc($token, 'url') ?>" style="background:#1f5fbf;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none;display:inline-block">Sign in to BearingCave</a></p>
<p style="color:#5f6b7a;font-size:13px">If you did not request this, you can ignore this email. Request IP: <?= esc($ipAddress ?? '') ?></p>
</td></tr></table></body></html>
