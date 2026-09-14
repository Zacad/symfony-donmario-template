<?php

declare(strict_types=1);

// Only verify-setup's disposable consumer calls this CLI helper.
use App\Kernel;
use App\Tests\Fixtures\Authenticating\Browser;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;

require dirname(__DIR__, 2).'/vendor/autoload.php';

if ('dev' !== getenv('APP_ENV') || !in_array($argv[1] ?? '', ['create', 'read', 'verify-password', 'namespace'], true)) {
    throw new RuntimeException('Consumer authentication helper requires a disposable development checkout.');
}
$kernel = new Kernel('dev', true);
$kernel->boot();
try {
    $registry = $kernel->getContainer()->get('doctrine');
    assert($registry instanceof ManagerRegistry);
    $connection = $registry->getConnection();
    if ('app' !== $connection->fetchOne('SELECT current_database()')) {
        throw new RuntimeException('Consumer authentication helper requires its disposable development database.');
    }
    if ('verify-password' === $argv[1]) {
        $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
        $hash = $connection->fetchOne('SELECT password_hash FROM public.authenticating_account WHERE email = ?', [$input['email']]);
        if (!is_string($hash) || !password_verify($input['password'], $hash)) {
            throw new RuntimeException('Terminal/pipe provisioning failed to preserve its hidden password.');
        }
        fwrite(STDOUT, "Verified actual provisioned terminal/pipe password against PostgreSQL hash.\n");
        exit(0);
    }
    if ('namespace' === $argv[1]) {
        $browser = Browser::create(base: 'http://localhost:8080');
        Browser::token($browser);
        Browser::session($browser);
        // Cookie name only: no credential/token/session ID leaves the container.
        fwrite(STDOUT, Browser::cookieName()."\n");
        exit(0);
    }
    $file = '/app/var/consumer-authenticating.json';
    if ('create' === $argv[1]) {
        if (file_exists($file)) {
            throw new RuntimeException('Consumer authentication marker already exists.');
        }
        $password = Browser::secret();
        $email = 'consumer-'.bin2hex(random_bytes(8)).'@example.test';
        $process = new Process(['php', 'bin/console', 'app:account:provision', $email, '--password-stdin', '--no-interaction'], '/app', timeout: 30);
        $process->setInput($password."\n");
        if (0 !== $process->run() || !Uuid::isValid(trim($process->getOutput()))
            || str_contains($process->getOutput().$process->getErrorOutput(), $password)) {
            throw new RuntimeException('Consumer account provisioning failed.');
        }
        $id = trim($process->getOutput());
        $browser = Browser::create(base: 'http://localhost:8080');
        Browser::login($browser, $email, $password);
        if (!Browser::redirectedTo($browser, '/account')) {
            throw new RuntimeException('Consumer login failed.');
        }
        $hash = $connection->fetchOne('SELECT password_hash FROM public.authenticating_account WHERE id = ?', [$id]);
        $mask = umask(0077);
        try {
            file_put_contents($file, json_encode(['id' => $id, 'email' => $email, 'password' => $password, 'hash' => $hash, 'session' => Browser::session($browser), 'cookie' => Browser::cookieName()], JSON_THROW_ON_ERROR));
        } finally {
            umask($mask);
        }
    }
    $state = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    $row = $connection->fetchAssociative('SELECT id, email, password_hash FROM public.authenticating_account WHERE id = ?', [$state['id']]);
    if (false === $row || $row['email'] !== $state['email'] || !hash_equals($state['hash'], $row['password_hash'])
        || !password_verify($state['password'], $row['password_hash']) || Browser::cookieName() !== $state['cookie']) {
        throw new RuntimeException('Consumer account/password/cookie namespace changed.');
    }
    $browser = Browser::create($state['session'], 'http://localhost:8080');
    $browser->request('GET', '/account');
    if (200 !== $browser->getResponse()->getStatusCode() || !str_contains($browser->getResponse()->getContent(), $state['id'])) {
        throw new RuntimeException('Consumer authenticated session did not survive setup/test/recreation.');
    }
    fwrite(STDOUT, "Verified consumer account UUID, email, unchanged salted hash and persistent native session.\n");
} finally {
    $kernel->shutdown();
}
