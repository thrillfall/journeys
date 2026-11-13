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
        try {
            $fileId = (int)$this->rootFolder->getUserFolder($userId)->get($userPath)->getId();
            $qb = $this->db->getQueryBuilder();
            $qb->insert('journeys_videos')->values([
                'user_id' => $qb->createNamedParameter($userId),
                'album_id' => $qb->createNamedParameter($albumId, IQueryBuilder::PARAM_INT),
                'file_id' => $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
                'orientation' => $qb->createNamedParameter($orientation === 'landscape' ? 'landscape' : 'portrait'),
                'created_at' => $qb->createNamedParameter(new \DateTime(), IQueryBuilder::PARAM_DATE),
            ])->executeStatement();
        } catch (\Throwable $e) {
            $this->logger->warning('Journeys: could not record rendered video', [
                'userId' => $userId,
                'albumId' => $albumId,
                'path' => $userPath,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<int, array{albumId:int, orientation:string}> keyed by file id
     */
    public function byFileId(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('album_id', 'file_id', 'orientation')
            ->from('journeys_videos')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $out = [];
        try {
            $res = $qb->executeQuery();
            while ($row = $res->fetch()) {
                $out[(int)$row['file_id']] = [
                    'albumId' => (int)$row['album_id'],
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
