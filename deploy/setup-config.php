<?php

// Called only by setup.sh, not exposed by nginx's public document root.
require dirname(__DIR__).'/src/vendor/autoload.php';

use App\Support\UbuntuSetup;
use Symfony\Component\Process\Process;

$repo = dirname(__DIR__);
$app = $repo.'/src';
$env = $app.'/.env';
$mode = $argv[1] ?? '';
try {
    switch ($mode) {
        case 'credentials':
            $parts = explode("\0", stream_get_contents(STDIN));
            if (count($parts) !== 3) {
                throw new RuntimeException('Invalid credential input.');
            }
            UbuntuSetup::write($argv[2], json_encode(['email' => $parts[0], 'name' => $parts[1], 'password' => $parts[2]], JSON_THROW_ON_ERROR));
            chmod($argv[2], 0600);
            break;
        case 'env':
            $file = $argv[3];
            if (is_link($file) || ! is_file($file) || fileowner($file) !== 0 || (fileperms($file) & 0777) !== 0600) {
                throw new RuntimeException('Administrator JSON must be a root-owned regular file with mode0600.');
            }
            $admin = json_decode(file_get_contents($file), true, 4, JSON_THROW_ON_ERROR);
            if (! is_array($admin) || array_diff(array_keys($admin), ['email', 'name', 'password']) !== []) {
                throw new RuntimeException('Invalid administrator JSON keys.');
            }
            foreach (['email', 'name', 'password'] as $key) {
                if (! isset($admin[$key]) || ! is_string($admin[$key])) {
                    throw new RuntimeException('Administrator JSON requires email, name and password strings.');
                }
            }
            $text = UbuntuSetup::environment(file_get_contents($app.'/.env.example'), is_file($env) ? file_get_contents($env) : null, $admin, trim(stream_get_contents(STDIN)), $argv[2]);
            UbuntuSetup::write($env, $text);
            break;
        case 'sql':
            $values = UbuntuSetup::parse(file_get_contents($env));
            $password = $values['DB_PASSWORD'] ?? '';
            if (($values['DB_DATABASE'] ?? '') !== 'bulls' || ($values['DB_USERNAME'] ?? '') !== 'bulls_app' || ! preg_match('/\A[a-f0-9]{64}\z/', $password)) {
                throw new RuntimeException('Invalid managed database credentials.');
            }
            echo "CREATE DATABASE IF NOT EXISTS `bulls` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\nCREATE USER IF NOT EXISTS 'bulls_app'@'localhost' IDENTIFIED BY '$password';\nGRANT ALL PRIVILEGES ON `bulls`.* TO 'bulls_app'@'localhost';\n";
            break;
        case 'google-path':
            $path = UbuntuSetup::parse(file_get_contents($env))['GOOGLE_SHEETS_CREDENTIALS'] ?? 'storage/app/private/google-service-account.json';
            if ($path === '' || preg_match('/[\x00\r\n]/', $path)) {
                throw new RuntimeException('Invalid credentials path.');
            }
            echo str_starts_with($path, '/') ? $path : $app.'/'.$path;
            break;
        case 'key-needed':
            exit(empty(UbuntuSetup::parse(file_get_contents($env))['APP_KEY']) ? 0 : 1);
        case 'clean-secret':
            if (is_file($env)) {
                UbuntuSetup::write($env, UbuntuSetup::withoutPassword(file_get_contents($env)));
            }
            break;
        case 'bootstrap':
            $run = function (array $arguments) use ($app): void {
                $process = new Process(['/usr/bin/php8.4', 'artisan', ...$arguments, '--no-interaction'], $app, ['ADMIN_PASSWORD' => false]);
                $process->setTimeout(300);
                $process->mustRun(function ($type, $buffer): void {
                    echo $buffer;
                });
            };
            UbuntuSetup::seedAndCache($env, fn () => $run(['db:seed', '--class=AdminSeeder', '--force']), fn () => $run(['config:cache']));
            UbuntuSetup::assertSafeCache($app.'/bootstrap/cache/config.php');
            break;
        case 'template':
            $templates = UbuntuSetup::templates($repo, $argv[2]);
            if (! isset($templates[$argv[3]])) {
                throw new RuntimeException('Unknown template.');
            }
            echo $templates[$argv[3]];
            break;
        default:
            throw new RuntimeException('Unknown setup configuration operation.');
    }
} catch (Throwable $exception) {
    // Never echo parser/process diagnostics that may contain an environment line.
    fwrite(STDERR, 'Setup configuration step failed ('.$mode.'). Check input format, managed .env and administrator credentials; values were not printed.'.PHP_EOL);
    exit(1);
}
