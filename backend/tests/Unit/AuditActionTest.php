<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Audit\AuditAction;
use Tests\TestCase;

final class AuditActionTest extends TestCase
{
    public function test_audit_action_category_returns_first_segment(): void
    {
        $this->assertSame('auth', AuditAction::AuthLoginSuccess->category());
        $this->assertSame('user', AuditAction::UserCreated->category());
        $this->assertSame('vm', AuditAction::VmCreateRequested->category());
        $this->assertSame('ip', AuditAction::IpAllocated->category());
        $this->assertSame('ip_pool', AuditAction::IpPoolCreated->category());
        $this->assertSame('host', AuditAction::HostCreated->category());
        $this->assertSame('cert', AuditAction::CertGenerated->category());
        $this->assertSame('settings', AuditAction::SettingsUpdated->category());
        $this->assertSame('quota', AuditAction::QuotaGroupCreated->category());
        $this->assertSame('console', AuditAction::ConsoleTicketIssued->category());
        $this->assertSame('impersonation', AuditAction::ImpersonationStarted->category());
        $this->assertSame('audit', AuditAction::AuditExported->category());
    }

    public function test_all_audit_actions_have_english_and_bulgarian_translations(): void
    {
        $enTranslations = include resource_path('../lang/en/audit.php');
        $bgTranslations = include resource_path('../lang/bg/audit.php');

        foreach (AuditAction::cases() as $action) {
            $key = $action->value;

            $this->assertArrayHasKey($key, $enTranslations, "Missing EN translation for {$key}");
            $this->assertNotEmpty($enTranslations[$key], "Empty EN translation for {$key}");

            $this->assertArrayHasKey($key, $bgTranslations, "Missing BG translation for {$key}");
            $this->assertNotEmpty($bgTranslations[$key], "Empty BG translation for {$key}");

            // Verify __() helper translates properly
            app()->setLocale('en');
            $this->assertNotSame($action->translationKey(), __($action->translationKey()));

            app()->setLocale('bg');
            $this->assertNotSame($action->translationKey(), __($action->translationKey()));
        }
    }
}
