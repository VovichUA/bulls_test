<?php

namespace Tests\Unit\Deployment;

use App\Support\UbuntuSetup;
use Dotenv\Dotenv;
use PHPUnit\Framework\TestCase;

class UbuntuSetupTest extends TestCase
{
    public function test_initialization_and_second_run_preserve_identity_and_custom_settings(): void
    {
        $template = file_get_contents(__DIR__.'/../../../.env.example');
        $first = UbuntuSetup::environment($template, null, ['email' => 'admin@example.test', 'name' => 'Admin', 'password' => 'Example $safe "password" 123'], str_repeat('a', 64), 'localhost');
        $values = Dotenv::parse($first);
        $this->assertSame('production', $values['APP_ENV']);
        $this->assertSame('false', $values['APP_DEBUG']);
        $this->assertSame('storage/app/private/google-service-account.json', $values['GOOGLE_SHEETS_CREDENTIALS']);
        $this->assertSame('Example $safe "password" 123', $values['ADMIN_PASSWORD']);
        $first = str_replace('APP_KEY=', 'APP_KEY=base64:test-key', UbuntuSetup::withoutPassword($first))."\nCUSTOM_SETTING=keep\n";
        $again = UbuntuSetup::environment($template, $first, ['email' => 'admin@example.test', 'name' => 'Admin', 'password' => ''], str_repeat('b', 64), 'localhost');
        $values = Dotenv::parse($again);
        $this->assertSame(str_repeat('a', 64), $values['DB_PASSWORD']);
        $this->assertSame('base64:test-key', $values['APP_KEY']);
        $this->assertSame('keep', $values['CUSTOM_SETTING']);
        $this->assertArrayNotHasKey('ADMIN_PASSWORD', $values);
    }

    public function test_unmanaged_environment_is_not_overwritten(): void
    {
        $this->expectException(\RuntimeException::class);
        UbuntuSetup::environment('', 'APP_KEY=important', ['email' => 'a@example.test', 'name' => 'A', 'password' => 'LongPassword123'], str_repeat('a', 64), 'localhost');
    }

    public function test_password_is_removed_before_cache_and_even_when_seed_fails(): void
    {
        $env = tempnam(sys_get_temp_dir(), 'bulls-env-');
        try {
            file_put_contents($env, "ADMIN_EMAIL=a@example.test\nADMIN_PASSWORD=SensitiveBootstrap123\n");
            $events = [];
            UbuntuSetup::seedAndCache($env, function () use (&$events, $env): void {
                $this->assertStringContainsString('SensitiveBootstrap123', file_get_contents($env));
                $events[] = 'seed';
            }, function () use (&$events, $env): void {
                $this->assertArrayNotHasKey('ADMIN_PASSWORD', Dotenv::parse(file_get_contents($env)));
                $events[] = 'cache';
            });
            $this->assertSame(['seed', 'cache'], $events);
            file_put_contents($env, "ADMIN_PASSWORD=SensitiveBootstrap123\n");
            try {
                UbuntuSetup::seedAndCache($env, fn () => throw new \RuntimeException('seed failed'), fn () => $this->fail('Cache must not run after failed seed'));
                $this->fail('Seed failure must propagate');
            } catch (\RuntimeException) {
                $this->assertArrayNotHasKey('ADMIN_PASSWORD', Dotenv::parse(file_get_contents($env)));
            }
        } finally {
            unlink($env);
        }
    }

    public function test_cached_bootstrap_secret_is_rejected_and_unsafe_cache_removed(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'bulls-cache-');
        file_put_contents($file, "<?php return ['admin'=>['password'=>'SensitiveBootstrap123']];");
        try {
            UbuntuSetup::assertSafeCache($file);
            $this->fail('Cached password must be rejected');
        } catch (\RuntimeException) {
            $this->assertFileDoesNotExist($file);
        } finally {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function test_templates_pin_php_scope_cron_and_permissions(): void
    {
        $templates = UbuntuSetup::templates('/var/www/bulls', 'example.test');
        $this->assertStringContainsString('root /var/www/bulls/src/public;', $templates['nginx']);
        $this->assertStringContainsString('location = /index.php', $templates['nginx']);
        $this->assertStringContainsString('user = www-data', $templates['fpm']);
        $this->assertStringNotContainsString('umask =', $templates['fpm']);
        $this->assertStringContainsString('UMask=0002', $templates['fpm-umask']);
        $this->assertStringContainsString('/usr/bin/php8.4', $templates['supervisor']);
        $this->assertStringContainsString('stopwaitsecs=660', $templates['supervisor']);
        $this->assertStringContainsString('numprocs=1', $templates['supervisor']);
        $this->assertSame(1, substr_count($templates['cron'], 'schedule:run'));
        $this->assertStringContainsString('umask 0002', $templates['cron']);
        $this->assertStringContainsString('bulls /bin/sh', $templates['cron']);
    }

    public function test_template_parameters_reject_configuration_injection(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        UbuntuSetup::templates('/var/www/bulls', "bad;\nroot /etc;");
    }
}
