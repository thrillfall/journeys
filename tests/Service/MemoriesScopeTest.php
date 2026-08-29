<?php
namespace OCA\Journeys\Tests\Service;

use OCA\Journeys\Service\MemoriesScope;
use PHPUnit\Framework\TestCase;

class MemoriesScopeTest extends TestCase {

    public function testNoAllowedRootsMeansNoPredicate(): void {
        $this->assertNull(MemoriesScope::buildPredicate([], [], 'f'));
    }

    public function testSingleRootScopesStorageAndSubtree(): void {
        $predicate = MemoriesScope::buildPredicate(
            [['storage' => 3, 'fileid' => 42, 'like' => 'files/Photos/%']],
            [],
            'f',
        );

        $this->assertNotNull($predicate);
        $this->assertSame(
            ' AND ((f.storage = ? AND (f.fileid = ? OR f.path LIKE ?)))',
            $predicate['sql'],
        );
        $this->assertSame([3, 42, 'files/Photos/%'], $predicate['params']);
    }

    public function testSeveralRootsAreOred(): void {
        $predicate = MemoriesScope::buildPredicate(
            [
                ['storage' => 3, 'fileid' => 42, 'like' => 'files/Photos/%'],
                ['storage' => 7, 'fileid' => 99, 'like' => '__groupfolders/2/%'],
            ],
            [],
            'f',
        );

        $this->assertNotNull($predicate);
        $this->assertSame(
            ' AND ((f.storage = ? AND (f.fileid = ? OR f.path LIKE ?))'
                . ' OR (f.storage = ? AND (f.fileid = ? OR f.path LIKE ?)))',
            $predicate['sql'],
        );
        $this->assertSame([3, 42, 'files/Photos/%', 7, 99, '__groupfolders/2/%'], $predicate['params']);
    }

    public function testExclusionsAreNegatedAfterTheAllowedRoots(): void {
        $predicate = MemoriesScope::buildPredicate(
            [['storage' => 3, 'fileid' => 42, 'like' => 'files/Photos/%']],
            [
                ['storage' => 3, 'fileid' => 51, 'like' => 'files/Photos/Backup/%'],
                ['storage' => 3, 'fileid' => 52, 'like' => 'files/Photos/.archive/%'],
            ],
            'f',
        );

        $this->assertNotNull($predicate);
        $this->assertSame(
            ' AND ((f.storage = ? AND (f.fileid = ? OR f.path LIKE ?)))'
                . ' AND NOT ((f.storage = ? AND (f.fileid = ? OR f.path LIKE ?))'
                . ' OR (f.storage = ? AND (f.fileid = ? OR f.path LIKE ?)))',
            $predicate['sql'],
        );
        // Params must stay in SQL order: allowed roots first, then exclusions.
        $this->assertSame([
            3, 42, 'files/Photos/%',
            3, 51, 'files/Photos/Backup/%',
            3, 52, 'files/Photos/.archive/%',
        ], $predicate['params']);
    }

    public function testExclusionOnlyPredicateHasNoAllowedRootRestriction(): void {
        $predicate = MemoriesScope::buildExclusionPredicate(
            [['storage' => 9, 'fileid' => 5, 'like' => '__groupfolders/4/Private/%']],
            'f',
        );

        $this->assertNotNull($predicate);
        $this->assertSame(
            ' AND NOT ((f.storage = ? AND (f.fileid = ? OR f.path LIKE ?)))',
            $predicate['sql'],
        );
        $this->assertSame([9, 5, '__groupfolders/4/Private/%'], $predicate['params']);
    }

    public function testNothingExcludedMeansNoPredicate(): void {
        $this->assertNull(MemoriesScope::buildExclusionPredicate([], 'f'));
    }

    public function testAliasIsHonoured(): void {
        $predicate = MemoriesScope::buildPredicate(
            [['storage' => 1, 'fileid' => 2, 'like' => 'files/%']],
            [],
            'fc',
        );

        $this->assertNotNull($predicate);
        $this->assertStringContainsString('fc.storage', $predicate['sql']);
        $this->assertStringNotContainsString('f.storage', str_replace('fc.storage', '', $predicate['sql']));
    }
}
