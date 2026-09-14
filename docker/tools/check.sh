#!/bin/sh
set -eu
composer validate --strict
composer check-platform-reqs
composer audit
php bin/console lint:yaml config --parse-tags
php bin/console lint:yaml src/Module --parse-tags
php bin/console lint:twig templates
php bin/console lint:twig src/Module
php bin/console lint:container
php tools/architecture.php
php vendor/bin/deptrac analyse --no-cache --report-uncovered --fail-on-uncovered
php bin/console app:architecture:check
php bin/console app:migrations:check
php vendor/bin/phpunit tests/Architecture
php vendor/bin/phpunit tests/Unit
php vendor/bin/phpstan analyse --no-progress --memory-limit=256M
php vendor/bin/php-cs-fixer fix --dry-run --diff --sequential
find src tests docker/tools config -name '*.php' ! -name reference.php -exec php -l '{}' +
for script in bin/dev docker/entrypoint.sh docker/postgres/*.sh docker/tools/*.sh; do
    sh -n "$script"
done
sh docker/tools/test-shell-contracts.sh
