<?php

declare(strict_types=1);

// Verification transport only. Called via an internal runner->app stdin pipe;
// no mounted/shared session volume and no HTTP endpoint is introduced.
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Tests\Fixtures\Authenticating\JwtState;
use App\Tests\Fixtures\Authenticating\State;

if ('test' !== getenv('APP_ENV')) {
    throw new RuntimeException('Authentication storage inspection requires isolated test mode.');
}
if ('export' === ($argv[1] ?? null)) {
    $state = State::load();
    $state['jwt'] = JwtState::load();
    fwrite(STDOUT, json_encode($state, JSON_THROW_ON_ERROR));
    exit(0);
}
if ('inspect' !== ($argv[1] ?? null)) {
    throw new RuntimeException('Unknown authentication storage operation.');
}
$state = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
foreach (['session', 'password', 'hash'] as $key) {
    if (!is_string($state[$key] ?? null) || '' === $state[$key]) {
        throw new RuntimeException('Incomplete private authentication inspection input.');
    }
}
if (!preg_match('/^[a-zA-Z0-9,-]+$/D', $state['session'])) {
    throw new RuntimeException('Invalid session inspection identifier.');
}
$session = file_get_contents('/app/var/sessions/test/sess_'.$state['session']);
if (false === $session || !str_contains($session, hash('crc32c', $state['hash']))
    || str_contains($session, $state['hash']) || str_contains($session, $state['password'])) {
    throw new RuntimeException('Session fingerprint/storage secrecy contract failed.');
}
$files = 0;
$jwtSecrets = [];
foreach (['password', 'hash', 'token', 'original_token'] as $field) {
    if (!is_string($state['jwt'][$field] ?? null) || '' === $state['jwt'][$field]) {
        throw new RuntimeException('Incomplete JWT storage inspection input.');
    }
    $jwtSecrets[] = $state['jwt'][$field];
}
foreach (explode("\n", trim(file_get_contents('/app/var/jwt/active/private.pem'))) as $line) {
    if (strlen($line) >= 40 && !str_starts_with($line, '-----')) {
        $jwtSecrets[] = $line;
    }
}
foreach (['/app/var/sessions/test', '/app/var/security/test'] as $directory) {
    $rootCategory = str_contains($directory, '/sessions/') ? 'session-root' : 'security-root';
    $rootMode = fileperms($directory) & 07777;
    if (is_link($directory) || 0 !== ($rootMode & 0077)) {
        throw new RuntimeException(sprintf('Authentication storage root is not owner-only: category=%s mode=%04o.', $rootCategory, $rootMode));
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($iterator as $file) {
        // Native FlockStore deliberately chmods its empty lock inodes to 0666.
        // Their owner-only parent protects access; data files retain mode 0600.
        // FlockStore sanitizes the resource prefix but replaces only '/' (not
        // '+') in the seven-character base64 hash suffix.
        $mode = $file->getPerms() & 07777;
        $lockDirectory = '/app/var/security/test/locks' === $file->getPath();
        $nativeEmptyLock = $file->isFile() && 0 === $file->getSize()
            && $lockDirectory
            && 1 === preg_match('/^sf\.[a-zA-Z0-9._-]{1,50}\.[a-zA-Z0-9_+]{7}\.lock$/D', $file->getFilename())
            && 0666 === $mode;
        if ($file->isLink() || (!$nativeEmptyLock && 0 !== ($mode & 0077))) {
            $category = $file->isLink() ? 'symlink' : ($file->isDir() ? 'directory' : ($lockDirectory ? 'lock-file' : ('session-root' === $rootCategory ? 'session-file' : 'security-data-file')));
            throw new RuntimeException(sprintf('Authentication runtime storage is not private: category=%s mode=%04o.', $category, $mode));
        }
        if ($file->isFile()) {
            ++$files;
            $content = file_get_contents($file->getPathname());
            if (false === $content || str_contains($content, $state['password']) || str_contains($content, $state['hash'])) {
                throw new RuntimeException('Authentication runtime storage exposed credential material.');
            }
            foreach ($jwtSecrets as $secret) {
                if (str_contains($content, $secret) || str_contains($content, rawurlencode($secret))) {
                    throw new RuntimeException('Native session/limiter storage exposed JWT or signing-key canaries.');
                }
            }
        }
    }
}
if ($files < 3 || !is_dir('/app/var/security/test/locks') || !is_dir('/app/var/security/test/limiter')) {
    throw new RuntimeException('Expected native session, limiter and lock storage.');
}
fwrite(STDOUT, "Verified app-private native session fingerprint, limiter/locks, owner-only storage and absence of JWT/password/private-key canaries.\n");
