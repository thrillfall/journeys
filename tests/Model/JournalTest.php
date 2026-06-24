<?php
namespace OCA\Journeys\Tests\Model;

use OCA\Journeys\Model\Journal;
use PHPUnit\Framework\TestCase;

class JournalTest extends TestCase {

    public function testSanitizeTitleTrimsAndCollapsesWhitespace(): void {
        $this->assertSame('Italy 2026', Journal::sanitizeTitle("  Italy\n\t  2026  "));
    }

    public function testSanitizeTitleFallsBackWhenEmpty(): void {
        $this->assertSame('Untitled journal', Journal::sanitizeTitle(''));
        $this->assertSame('Untitled journal', Journal::sanitizeTitle('   '));
        $this->assertSame('Untitled journal', Journal::sanitizeTitle(null));
    }

    public function testSanitizeTitleClampsLength(): void {
        $long = str_repeat('x', 300);
        $this->assertSame(255, mb_strlen(Journal::sanitizeTitle($long)));
    }

    public function testFromRowMapsColumnsAndTypes(): void {
        $journal = Journal::fromRow([
            'id' => '7',
            'user_id' => 'alice',
            'title' => 'Italy',
            'description' => null,
            'cover_fileid' => '42',
            'start_date' => '2026-06-03',
            'end_date' => '2026-06-09',
            'public_token' => null,
            'created_at' => '2026-06-23 10:00:00',
            'updated_at' => '2026-06-23 10:00:00',
        ]);
        $this->assertSame(7, $journal->id);
        $this->assertSame('alice', $journal->userId);
        $this->assertSame(42, $journal->coverFileid);
        $this->assertNull($journal->description);
        $this->assertFalse($journal->isPublic());
    }

    public function testIsPublicWhenTokenPresent(): void {
        $journal = Journal::fromRow([
            'id' => 1, 'user_id' => 'a', 'title' => 't', 'public_token' => 'aB3xY9',
        ]);
        $this->assertTrue($journal->isPublic());
    }
}
