<?php
namespace OCA\Journeys\Service;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\AppFramework\Http\Response;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IPreview;

/**
 * Streams an image preview for a fileid, resolved under the first of the given
 * candidate users whose storage actually contains the file. Shared by the
 * authenticated diary endpoint and the public share endpoint so the
 * owner-fallback, size mapping and cache headers live in one place.
 */
class PhotoPreviewResponder {

    public function __construct(
        private IRootFolder $rootFolder,
        private IPreview $previewManager,
    ) {}

    /**
     * @param array<int,?string> $candidateUids tried in order (e.g. [owner, trip owner])
     */
    public function serve(array $candidateUids, int $fileid, ?string $sizeParam): Response {
        $size = $sizeParam === 'large' ? 1280 : 512;
        foreach (array_unique(array_filter($candidateUids)) as $uid) {
            try {
                $node = $this->rootFolder->getUserFolder($uid)->getById($fileid)[0] ?? null;
                if (!$node instanceof File) {
                    continue;
                }
                $preview = $this->previewManager->getPreview($node, $size, $size, false);
                $resp = new DataDisplayResponse($preview->getContent(), Http::STATUS_OK, [
                    'Content-Type' => $preview->getMimeType(),
                ]);
                $resp->cacheFor(3600, false, true);
                return $resp;
            } catch (\Throwable $e) {
                // try the next candidate
            }
        }
        return new NotFoundResponse();
    }
}
