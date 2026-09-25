<?php
// Production: no message, no trace, no path — only an apology and a way on.
echo view('errors/html/_page', [
    'code'  => 500,
    'title' => 'Something went wrong on our side',
    'body'  => 'The problem has been logged. Please try again in a few minutes.',
]);
