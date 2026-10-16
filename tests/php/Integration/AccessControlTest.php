<?php
declare(strict_types=1);
namespace Tests\Integration;

use Tests\Support\IntegrationTestCase;

final class AccessControlTest extends IntegrationTestCase
{
    public function testAdminWritesAndRefereeReads(): void {
        $acl = $this->di->getShared('acl');
        $this->assertTrue($acl->isAllowed('admin', 'tournament', 'write'));
        $this->assertTrue($acl->isAllowed('referee', 'tournament', 'read'));
        $this->assertFalse($acl->isAllowed('referee', 'tournament', 'write'));
    }
}
