<?php
namespace OCA\Journeys\Service;

use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\Files\File;

class ClustererSystemTagHelper {
    private ISystemTagManager $tagManager;
    private ISystemTagObjectMapper $tagObjectMapper;
    private string $tagName = 'journeys-clustered-album';
    private ?int $tagId = null;

    public function __construct(ISystemTagManager $tagManager, ISystemTagObjectMapper $tagObjectMapper) {
        $this->tagManager = $tagManager;
        $this->tagObjectMapper = $tagObjectMapper;
    }

    /**
     * Get or create the clusterer marker tag ID
     */
    public function getOrCreateTagId(): int {
        if ($this->tagId !== null) return $this->tagId;
        $tags = $this->tagManager->getTagsByName($this->tagName);
        if (!empty($tags)) {
            $tag = array_shift($tags);
            $this->tagId = (int)$tag->getId();
            return $this->tagId;
        }
        $tag = $this->tagManager->createTag($this->tagName, true, false);
        $this->tagId = (int)$tag->getId();
        return $this->tagId;
    }

    /**
     * Assign the marker tag to a file
     */
    public function tagFile(File $file): void {
        $tagId = $this->getOrCreateTagId();
        $this->tagObjectMapper->assignTags($file->getId(), [$tagId], 'files');
    }

    /**
     * Get all file IDs tagged with the marker tag
     */
    public function getTaggedFileIds(): array {
        $tagId = $this->getOrCreateTagId();
        return $this->tagObjectMapper->getObjectIdsForTags([$tagId], 'files');
    }

    /**
     * Remove the marker tag from a file
     */
    public function untagFile(File $file): void {
        $tagId = $this->getOrCreateTagId();
        $this->tagObjectMapper->unassignTags($file->getId(), [$tagId], 'files');
    }
}
