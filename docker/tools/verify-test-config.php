<?php

declare(strict_types=1);

$config = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$eventTransportDsn = $argv[1] ?? 'sync://';
if (!in_array($eventTransportDsn, ['sync://', 'doctrine://default'], true)) {
    throw new RuntimeException('Unrecognized expected test event transport.');
}
// Plain Compose config omits inactive profiles; include --profile worker to verify it.
$services = isset($config['services']['worker']) ? ['app', 'runner', 'worker'] : ['app', 'runner'];
foreach ($services as $name) {
    $service = $config['services'][$name];
    if ('test' !== $service['environment']['APP_ENV'] || '0' !== $service['environment']['APP_DEBUG']
        || $eventTransportDsn !== ($service['environment']['EVENT_TRANSPORT_DSN'] ?? null)
        || !str_contains($service['environment']['DATABASE_URL'], '@database:5432/app_test?')
        || empty($service['environment']['APP_SECRET']) || isset($service['environment']['POSTGRES_PASSWORD'])
        || ($config['name'] ?? null) !== ($service['environment']['APP_INSTANCE_ID'] ?? null)
        || !empty($service['ports']) || !empty($service['env_file']) || empty($service['read_only'])
        || empty($service['init']) || !in_array('ALL', $service['cap_drop'] ?? [], true)
        || !in_array('no-new-privileges:true', $service['security_opt'] ?? [], true)
        || $config['services']['app']['image'] !== $service['image']) {
        throw new RuntimeException('Unsafe test service configuration.');
    }
    foreach ($service['volumes'] as $volume) {
        if ('volume' !== $volume['type']) {
            throw new RuntimeException('Test source must be an image snapshot, not a host mount.');
        }
    }
    $runtime = match ($name) {
        'app' => 'runtime',
        'runner' => 'runner',
        'worker' => 'worker_runtime',
    };
    $expectedMounts = 'worker' === $name ? 1 : 2;
    if ($expectedMounts !== count($service['volumes']) || $runtime !== $service['volumes'][0]['source']
        || '/app/var' !== $service['volumes'][0]['target'] || !empty($service['volumes'][0]['read_only'])) {
        throw new RuntimeException('Session and limiter storage must remain private to each service.');
    }
    if ('worker' !== $name) {
        $keys = $service['volumes'][1];
        if ('jwt_keys' !== $keys['source'] || '/app/var/jwt' !== $keys['target'] || true !== ($keys['read_only'] ?? false)) {
            throw new RuntimeException('App and negative-token fixtures require only read-only isolated test JWT keys.');
        }
    }
}
if (($config['name'] ?? '').'_jwt_keys' !== ($config['volumes']['jwt_keys']['name'] ?? null)
    || !empty($config['volumes']['jwt_keys']['external'])) {
    throw new RuntimeException('JWT keys must belong to this unique test project.');
}
if (isset($config['services']['worker'])) {
    $worker = $config['services']['worker'];
    if (['worker'] !== ($worker['profiles'] ?? []) || 'unless-stopped' !== ($worker['restart'] ?? null)
        || !preg_match('/^[1-9][0-9]*:[1-9][0-9]*$/D', $worker['user'] ?? '')
        || $config['services']['app']['environment']['DATABASE_URL'] !== $worker['environment']['DATABASE_URL']
        || $config['services']['app']['environment']['APP_SECRET'] !== $worker['environment']['APP_SECRET']
        || 1 !== count($worker['volumes']) || 'worker_runtime' !== $worker['volumes'][0]['source']
        || '/app/var' !== $worker['volumes'][0]['target'] || !empty($worker['volumes'][0]['read_only'])
        || !in_array('/tmp', $worker['tmpfs'] ?? [], true)
        || $config['services']['runner']['tmpfs'] !== ($worker['tmpfs'] ?? [])
        || ['php', 'bin/console', 'messenger:consume', 'events', '--time-limit=3600', '--memory-limit=128M', '--limit=1000', '--sleep=1', '--no-interaction'] !== $worker['command']) {
        throw new RuntimeException('Unsafe test worker configuration.');
    }
    foreach (['app', 'runner'] as $name) {
        foreach ($config['services'][$name]['volumes'] as $volume) {
            if ('worker_runtime' === $volume['source']) {
                throw new RuntimeException('Test worker runtime must be isolated from app and runner.');
            }
        }
    }
}
if ('app_test' !== $config['services']['database']['environment']['APP_DATABASE_NAME'] || !empty($config['services']['database']['ports'])) {
    throw new RuntimeException('Unsafe test database configuration.');
}
fwrite(STDOUT, "Verified isolated Compose environment, mounts, ports and event transport: $eventTransportDsn.\n");
