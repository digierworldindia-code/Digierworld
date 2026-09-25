<?php
// The framework's message can name a controller or a file; it is shown only
// outside production.
echo view('errors/html/_page', [
    'code'  => 404,
    'title' => 'We could not find that page',
    'body'  => ENVIRONMENT !== 'production' && ! empty($message) && $message !== '(null)'
        ? $message
        : 'The address may have changed, or the page may have been removed.',
]);
