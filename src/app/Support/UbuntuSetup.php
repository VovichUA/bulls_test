<?php

namespace App\Support;

use Dotenv\Dotenv;
use InvalidArgumentException;
use RuntimeException;

/** Pure configuration helpers for the opt-in native Ubuntu installer. */
class UbuntuSetup
{
    public const MARKER = '# bulls-ubuntu-setup:v1';

    /** @return array<string, string> */
    public static function parse(string $env): array
    {
        try {
            return Dotenv::parse($env);
        } catch (\Throwable) {
            throw new RuntimeException('Cannot parse the environment file; values have not been printed.');
        }
    }

    /** @param array{email:string,name:string,password:string} $admin */
    public static function environment(string $template, ?string $existing, array $admin, string $password, string $host): string
    {
        self::validateHost($host);
        if (! filter_var($admin['email'], FILTER_VALIDATE_EMAIL) || $admin['email'] !== strtolower($admin['email']) || $admin['name'] === '' || preg_match('/[\r\n\x00]/', implode('', $admin))) {
            throw new InvalidArgumentException('Use a lowercase administrator email and single-line name/password.');
        }
        if ($admin['password'] !== '' && (mb_strlen($admin['password']) < 12 || strlen($admin['password']) > 72)) {
            throw new InvalidArgumentException('Administrator password must have at least 12 characters and at most 72 bytes.');
        }
        if ($existing !== null) {
            if (! str_starts_with($existing, self::MARKER."\n")) {
                throw new RuntimeException('Refusing to overwrite an unmanaged .env.');
            }
            $values = self::parse($existing);
            if (($values['DB_DATABASE'] ?? '') !== 'bulls' || ($values['DB_USERNAME'] ?? '') !== 'bulls_app' || ($values['DB_HOST'] ?? '') !== 'localhost' || ! preg_match('/\A[a-f0-9]{64}\z/', $values['DB_PASSWORD'] ?? '')) {
                throw new RuntimeException('Managed database identity was changed; configure this installation manually.');
            }
            if (($values['ADMIN_EMAIL'] ?? '') !== $admin['email'] || ($values['APP_URL'] ?? '') !== 'http://'.$host) {
                throw new RuntimeException('Administrator email or server name differs from the existing deployment.');
            }
            $env = self::withoutPassword($existing);
        } else {
            if (! preg_match('/\A[a-f0-9]{64}\z/', $password)) {
                throw new InvalidArgumentException('Invalid generated database password.');
            }
            $env = self::MARKER."\n".self::withoutPassword($template);
            $settings = ['APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'APP_URL' => 'http://'.$host, 'DB_CONNECTION' => 'mysql', 'DB_HOST' => 'localhost', 'DB_PORT' => '3306', 'DB_DATABASE' => 'bulls', 'DB_USERNAME' => 'bulls_app', 'DB_PASSWORD' => $password, 'DB_URL' => '', 'DB_SOCKET' => '', 'QUEUE_CONNECTION' => 'database', 'DB_QUEUE_RETRY_AFTER' => '720', 'CACHE_STORE' => 'database', 'SESSION_SECURE_COOKIE' => 'false', 'ADMIN_EMAIL' => $admin['email'], 'ADMIN_NAME' => $admin['name'], 'GOOGLE_SHEETS_CREDENTIALS' => 'storage/app/private/google-service-account.json'];
            foreach ($settings as $key => $value) {
                $env = self::set($env, $key, $value);
            }
        }
        if ($admin['password'] !== '') {
            $env = self::set($env, 'ADMIN_PASSWORD', $admin['password']);
        }

        return $env;
    }

    private static function set(string $env, string $key, string $value): string
    {
        $quoted = '"'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value).'"';
        $line = $key.'='.$quoted;
        $env = preg_replace('/^'.preg_quote($key, '/').'=.*\R?/m', '', $env);

        return rtrim($env)."\n".$line."\n";
    }

    public static function withoutPassword(string $env): string
    {
        return preg_replace('/^(?:export\s+)?ADMIN_PASSWORD\s*=.*(?:\R|\z)/m', '', $env);
    }

    public static function seedAndCache(string $envFile, callable $seed, callable $cache): void
    {
        try {
            $seed();
        } finally {
            self::write($envFile, self::withoutPassword(file_get_contents($envFile)));
        }
        $cache();
    }

    public static function write(string $file, string $contents): void
    {
        $original = is_file($file) ? stat($file) : null;
        $temporary = tempnam(dirname($file), '.bulls-setup-');
        try {
            if (file_put_contents($temporary, $contents) === false || ! chmod($temporary, 0640)) {
                throw new RuntimeException('Unable to write deployment configuration.');
            }
            if ($original !== null && (! chown($temporary, $original['uid']) || ! chgrp($temporary, $original['gid']))) {
                throw new RuntimeException('Unable to preserve configuration ownership.');
            }
            if (! rename($temporary, $file)) {
                throw new RuntimeException('Unable to write deployment configuration.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    public static function assertSafeCache(string $file): void
    {
        $config = is_file($file) ? require $file : null;
        if (! is_array($config) || ! empty($config['admin']['password'])) {
            if (is_file($file)) {
                unlink($file);
            }
            throw new RuntimeException('Unsafe or missing config cache; cache removed.');
        }
    }

    private static function validateHost(string $host): void
    {
        if (! filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            throw new InvalidArgumentException('Invalid server name. Use a hostname or IPv4 address without a scheme or port.');
        }
    }

    /** @return array<string,string> */
    public static function templates(string $repo, string $host): array
    {
        self::validateHost($host);
        if (! preg_match('~\A/var/www/[a-zA-Z0-9/_-]+\z~', $repo) || str_contains($repo, '//')) {
            throw new InvalidArgumentException('Deployment path must be a simple absolute path under /var/www.');
        }
        $app = $repo.'/src';

        return [
            'nginx' => <<<CONF
# Managed by bulls setup v1
server {
    listen 80;
    server_name {$host};
    root {$app}/public;
    index index.php;
    charset utf-8;
    server_tokens off;
    location / { try_files \$uri \$uri/ /index.php?\$query_string; }
    location = /index.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$document_root/index.php;
        fastcgi_pass unix:/run/php/bulls-php84.sock;
        fastcgi_hide_header X-Powered-By;
    }
    location ~ \\.php$ { return 404; }
    location ~ /\\.(?!well-known) { deny all; }
}

CONF,
            'fpm' => <<<CONF
; Managed by bulls setup v1
[bulls]
user = www-data
group = www-data
listen = /run/php/bulls-php84.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = ondemand
pm.max_children = 5
pm.process_idle_timeout = 10s
chdir = {$app}
clear_env = yes
php_admin_flag[expose_php] = off

CONF,
            'fpm-umask' => "# Managed by bulls setup v1\n[Service]\nUMask=0002\n",
            'supervisor' => <<<CONF
; Managed by bulls setup v1
[program:bulls-sheets-worker]
command=/bin/sh {$repo}/deploy/queue-worker.sh {$app} /usr/bin/php8.4
directory={$app}
user=bulls
umask=002
numprocs=1
autostart=true
autorestart=true
startsecs=1
stopasgroup=true
killasgroup=true
stopwaitsecs=660
redirect_stderr=true
stdout_logfile={$app}/storage/logs/queue-worker.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=3

CONF,
            'cron' => <<<CONF
# Managed by bulls setup v1
SHELL=/bin/sh
PATH=/usr/local/bin:/usr/bin:/bin
* * * * * bulls /bin/sh -c 'umask 0002; cd {$app} && /usr/bin/flock -n storage/framework/schedule-run.lock /usr/bin/php8.4 artisan schedule:run >> storage/logs/scheduler.log 2>&1'

CONF,
        ];
    }
}
