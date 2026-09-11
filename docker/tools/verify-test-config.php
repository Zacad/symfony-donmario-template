<?php

declare(strict_types=1);

$config = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
foreach (['app', 'runner'] as $name) {
    $service = $config['services'][$name];
    if ('test' !== $service['environment']['APP_ENV'] || '0' !== $service['environment']['APP_DEBUG']
        || !str_contains($service['environment']['DATABASE_URL'], '@database:5432/app_test?')
        || empty($service['environment']['APP_SECRET']) || isset($service['environment']['POSTGRES_PASSWORD'])
        || !empty($service['ports']) || !empty($service['env_file']) || empty($service['read_only'])) {
        throw new RuntimeException('Unsafe test service configuration.');
    }
    foreach ($service['volumes'] as $volume) {
        if ('volume' !== $volume['type']) {
            throw new RuntimeException('Test source must be an image snapshot, not a host mount.');
        }
    }
}
if ('app_test' !== $config['services']['database']['environment']['APP_DATABASE_NAME'] || !empty($config['services']['database']['ports'])) {
    throw new RuntimeException('Unsafe test database configuration.');
}
fwrite(STDOUT, "Verified isolated Compose environment, mounts and ports.\n");
