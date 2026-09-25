<?php
echo view('errors/html/_page', [
    'code'  => 403,
    'title' => 'You do not have access to this',
    'body'  => $message ?? 'Your account is not permitted to open this page. If you think it should be, ask an administrator.',
]);
