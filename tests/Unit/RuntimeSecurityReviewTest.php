<?php

namespace Tests\Unit;

use App\Repositories\Database\RuntimeSecurityReview;
use PHPUnit\Framework\TestCase;

final class RuntimeSecurityReviewTest extends TestCase
{
    public function test_unrecognized_roles_global_permissions_and_wildcard_scopes_do_not_pass(): void
    {
        $review = new RuntimeSecurityReview;
        $valid = ['GRANT USAGE ON *.* TO `example`@`localhost` IDENTIFIED BY PASSWORD \'synthetic-hash\'',
            'GRANT SELECT, INSERT, UPDATE, DELETE ON `nova\\_test`.* TO `example`@`localhost`'];
        self::assertTrue($review->directGrantsAreDmlOnly($valid, 'nova_test'));
        foreach ([
            'GRANT `admin_role` TO `example`@`localhost`',
            'GRANT SELECT ON *.* TO `example`@`localhost`',
            'GRANT UPDATE ON `other`.* TO `example`@`localhost`',
            'GRANT SELECT ON `nova_test`.* TO `example`@`localhost`',
            'GRANT SELECT ON `nova\\_test`.* TO `example`@`localhost` WITH GRANT OPTION',
        ] as $unsafe) {
            self::assertFalse($review->directGrantsAreDmlOnly([...$valid, $unsafe], 'nova_test'));
        }
        self::assertFalse($review->directGrantsAreDmlOnly([], 'nova_test'));
        self::assertFalse($review->directGrantsAreDmlOnly(['GRANT SELECT ON `nova`.* TO `example`@`localhost`'], 'nova'));
    }
}
