<?php
namespace OCA\Journeys\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

class RenderedVideoRegistry {
    public function __construct(
        private IDBConnection $db,
        private IRootFolder $rootFolder,
        private LoggerInterface $logger,
    ) {}

    public function record(string $userId, int $albumId, string $userPath, string $orientation): void {
        $this->insert($userId, $albumId, null, $userPath, $orientation);
    }

    public function recordYearReview(string $userId, int $year, string $userPath, string $orientation): void {
        $this->insert($userId, null, $year, $userPath, $orientation);
    }

    private function insert(string $userId, ?int $albumId, ?int $year, string $userPath, string $orientation): void {
        try {
            $fileId = (int)$this->rootFolder->getUserFolder($userId)->get($userPath)->getId();
            $qb = $this->db->getQueryBuilder();
            $qb->insert('journeys_videos')->values([
                'user_id' => $qb->createNamedParameter($userId),
                'album_id' => $qb->createNamedParameter($albumId, $albumId === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT),
                'review_year' => $qb->createNamedParameter($year, $year === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT),
                'file_id' => $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
                'orientation' => $qb->createNamedParameter($orientation === 'landscape' ? 'landscape' : 'portrait'),
                'created_at' => $qb->createNamedParameter(new \DateTime(), IQueryBuilder::PARAM_DATE),
            ])->executeStatement();
        } catch (\Throwable $e) {
            $this->logger->warning('Journeys: could not record rendered video', [
                'userId' => $userId,
                'albumId' => $albumId,
                'year' => $year,
                'path' => $userPath,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<int, array{albumId:?int, reviewYear:?int, orientation:string}> keyed by file id
     */
    public function byFileId(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('album_id', 'review_year', 'file_id', 'orientation')
            ->from('journeys_videos')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $out = [];
        try {
            $res = $qb->executeQuery();
            while ($row = $res->fetch()) {
                $out[(int)$row['file_id']] = [
                    'albumId' => $row['album_id'] !== null ? (int)$row['album_id'] : null,
                    'reviewYear' => $row['review_year'] !== null ? (int)$row['review_year'] : null,
                    'orientation' => (string)$row['orientation'],
                ];
            }
            $res->closeCursor();
        } catch (\Throwable) {
            return [];
        }
        return $out;
    }
}
