<?php
/**
 * Sign in.
 */
require __DIR__ . '/includes/init.php';

if (current_user() !== null) {
    redirect('index.php');
}

if (is_post()) {
    $username = post('username');
    $password = (string) ($_POST['password'] ?? '');

    if (login_throttled()) {
        login_log(null, $username, 'LOGIN_FAILED', 'THROTTLED');
        http_response_code(429);
        header('Retry-After: 60');
        show_error(429, 'Too many login attempts from this device. Please wait a few minutes and try again.');
    }
    if ($username === '' || $password === '' || mb_strlen($username) > 50 || mb_strlen($password) > 200) {
        flash('error', 'Enter your username and password.');
        keep_input();
        redirect('login.php');
    }

    $result = auth_attempt($username, $password);
    if (! $result['ok']) {
        flash('error', $result['message']);
        keep_input();
        redirect('login.php');
    }

    // Back to the page that asked for the login (same site only).
    $intended = (string) ($_SESSION['qms_intended'] ?? '');
    unset($_SESSION['qms_intended']);
    if ($intended !== '' && ! str_contains($intended, '//') && ! str_contains($intended, '\\') && preg_match('#^[A-Za-z0-9_./?=&%-]*$#', $intended)) {
        redirect($intended);
    }
    redirect('index.php');
}

header('Cache-Control: no-store'); // the form carries a per-session token
$page_title = 'Sign in';
require QMS_ROOT . '/includes/layout/auth_header.php';
?>
<form method="post" action="<?= url('login.php') ?>" novalidate>
    <?= csrf_field() ?>
    <div class="mb-3">
        <label class="form-label" for="username">Username</label>
        <input class="form-control form-control-lg" id="username" name="username" type="text" autocomplete="username"
               autocapitalize="none" spellcheck="false" maxlength="50" required autofocus value="<?= e((string) old('username', '')) ?>">
    </div>
    <div class="mb-4">
        <label class="form-label" for="password">Password</label>
        <div class="qms-pw-wrap">
            <input class="form-control form-control-lg" id="password" name="password" type="password" autocomplete="current-password" maxlength="200" required>
            <button class="btn btn-outline-secondary" type="button" data-toggle-password="#password" aria-label="Show password"><?= qms_icon('bi-eye') ?></button>
        </div>
    </div>
    <button class="btn btn-primary btn-lg w-100" type="submit" data-once><?= qms_icon('bi-box-arrow-in-right') ?> Sign in</button>
    <p class="text-muted small mt-3 mb-0 text-center">Forgot your password? Ask your QMS administrator to reset it.</p>
</form>
<?php require QMS_ROOT . '/includes/layout/auth_footer.php';
