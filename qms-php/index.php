<?php
/**
 * Start page: dashboard, operator home or inspection list, depending on the role.
 */
require __DIR__ . '/includes/init.php';
require_login();

if (can('dashboard.view')) {
    redirect('dashboard.php');
}
if (can('inspection.create')) {
    redirect('home.php');
}
if (can('inspection.view_own', 'inspection.view_all')) {
    redirect('inspections.php');
}
flash('info', 'Your role has no pages assigned yet. Ask the administrator.');
redirect('account.php');
