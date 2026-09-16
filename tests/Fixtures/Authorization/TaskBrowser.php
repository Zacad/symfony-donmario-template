<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Authorization;

use App\Tests\Fixtures\Authenticating\Browser;
use Doctrine\DBAL\Connection;
use Symfony\Component\BrowserKit\HttpBrowser;
use Symfony\Component\Uid\Uuid;

/** SQL fixture setup emits no events; admission uses real grants and native HTTP login. */
final class TaskBrowser
{
    public static function login(Connection $connection, Uuid $account, ?Uuid $task = null): HttpBrowser
    {
        $password = Browser::secret();
        $email = 'task-browser-'.$account->toRfc4122().'@example.test';
        $connection->executeStatement('INSERT INTO authenticating_account (id, email, password_hash) VALUES (?, ?, ?)', [$account->toRfc4122(), $email, password_hash($password, PASSWORD_BCRYPT, ['cost' => 4])]);
        if (null === $task) {
            $connection->executeStatement('INSERT INTO authorizing_global_permission_grant (id, account_id, permission_key) VALUES (?, ?, ?)', [Uuid::v7()->toRfc4122(), $account->toRfc4122(), 'task_tracking.task.create']);
        } else {
            $connection->executeStatement('INSERT INTO authorizing_resource_permission_grant (id, account_id, permission_key, resource_type, resource_id) VALUES (?, ?, ?, ?, ?)', [Uuid::v7()->toRfc4122(), $account->toRfc4122(), 'task_tracking.task.view', 'task_tracking.task', $task->toRfc4122()]);
        }
        $browser = Browser::create();
        Browser::login($browser, $email, $password);
        if (!Browser::redirectedTo($browser, '/account')) {
            throw new \RuntimeException('Task fixture native login failed.');
        }
        $browser->request('GET', '/account');
        if (200 !== $browser->getResponse()->getStatusCode()) {
            throw new \RuntimeException('Task fixture native session failed.');
        }

        return $browser;
    }

    public static function cleanup(Connection $connection, Uuid $account): void
    {
        foreach (['authorizing_global_permission_grant', 'authorizing_resource_permission_grant'] as $table) {
            $connection->executeStatement('DELETE FROM '.$table.' WHERE account_id = ?', [$account->toRfc4122()]);
        }
        $connection->executeStatement('DELETE FROM authenticating_account WHERE id = ?', [$account->toRfc4122()]);
    }
}
