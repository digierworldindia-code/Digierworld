<?php
/**
 * CSRF protection: one random token per session. Every page gets a masked copy
 * (different on every view); forms send it as "csrf_qms", JavaScript as the
 * X-CSRF-TOKEN header. init.php refuses every POST without a valid token.
 */
defined('QMS') || exit;

function csrf_raw(): string
{
    if (! is_string($_SESSION['_csrf'] ?? null) || strlen($_SESSION['_csrf']) !== 64) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['_csrf'];
}

/** Masked token for this page (random mask + token XOR mask). */
function csrf_token(): string
{
    $raw  = (string) hex2bin(csrf_raw());
    $mask = random_bytes(32);

    return bin2hex(($raw ^ $mask) . $mask);
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_qms" value="' . csrf_token() . '">';
}

function csrf_valid(): bool
{
    $sent = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($sent === '') {
        $sent = is_string($_POST['csrf_qms'] ?? null) ? $_POST['csrf_qms'] : '';
    }
    if (! preg_match('/^[a-f0-9]{128}$/', $sent) || ! is_string($_SESSION['_csrf'] ?? null)) {
        return false;
    }
    $bytes = (string) hex2bin($sent);

    return hash_equals($_SESSION['_csrf'], bin2hex(substr($bytes, 0, 32) ^ substr($bytes, 32)));
}

/** Called for every POST before the page runs. */
function csrf_check(): void
{
    if (csrf_valid()) {
        return;
    }
    $message = 'Your session expired or the page was out of date. Please try again.';
    if (wants_json()) {
        json_out(['ok' => false, 'error' => $message, 'csrf' => true], 403);
    }
    flash('error', $message);
    redirect(current_page() ?: 'index.php');
}
