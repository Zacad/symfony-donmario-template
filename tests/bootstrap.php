<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

// Refuse destructive tests unless the disposable-stack contract is satisfied.
$url = parse_url((string) getenv('DATABASE_URL'));
if ('test' !== getenv('APP_ENV') || false === $url || 'database' !== ($url['host'] ?? null) || '/app_test' !== ($url['path'] ?? null)) {
    throw new RuntimeException('Run tests through ./bin/dev test; an isolated PostgreSQL database is required.');
}
