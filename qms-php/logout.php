<?php
/**
 * Log out (POST only, with CSRF token).
 */
require __DIR__ . '/includes/init.php';

if (! is_post()) {
    redirect('index.php');
}
auth_logout();
flash('success', 'You have been logged out.');
redirect('login.php');
