<?php
echo view('errors/html/_page', [
    'code'  => 429,
    'title' => 'Too many attempts',
    'body'  => 'Please wait ' . (int) ($wait ?? 60) . ' seconds and try again.',
]);
