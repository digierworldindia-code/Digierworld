<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\BCTestCase;

/**
 * Account recovery without email: an admin with users.manage can issue a
 * one-time password; other staff cannot; the action is audited.
 */
final class AdminPasswordRecoveryTest extends BCTestCase
{
    public function testTemporaryPasswordIsIssuedAuditedAndPermissionChecked(): void
    {
        $buyer   = $this->registerCompany('buyer');
        $support = $this->staff('support_executive');
        $email   = $buyer['user']->email;

        // Support executives lack users.manage.
        $this->postAs($support, 'admin/users/' . $buyer['user']->id . '/temporary-password');
        $this->assertTrue(auth()->check(['email' => $email, 'password' => self::PASSWORD])->isOK());

        $admin = $this->staff('superadmin');
        $res   = $this->postAs($admin, 'admin/users/' . $buyer['user']->id . '/temporary-password');
        $res->assertRedirect();
        $temp = $_SESSION['temp_password'] ?? null;
        $this->assertIsString($temp);

        $this->assertFalse(auth()->check(['email' => $email, 'password' => self::PASSWORD])->isOK());
        $this->assertTrue(auth()->check(['email' => $email, 'password' => $temp])->isOK());
        $this->assertSame(1, db_connect()->table('audit_logs')->where('event', 'user.temporary_password_issued')->where('entity_id', $buyer['user']->id)->countAllResults());
    }
}
