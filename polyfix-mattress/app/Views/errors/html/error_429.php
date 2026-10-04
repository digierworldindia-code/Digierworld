<?php
echo view('errors/html/_page', [
    'code'  => 429,
    'title' => 'Please wait a moment',
    'body'  => 'That was a lot of requests at once. Try again in '
        . (int) ($wait ?? 60) . ' seconds — nothing is wrong with your account.',
]);
