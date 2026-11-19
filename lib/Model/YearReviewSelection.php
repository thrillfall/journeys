<?php
namespace OCA\Journeys\Model;

class YearReviewSelection {
    /**
     * @param Image[] $selectedImages chronological
     * @param array<int,string> $captionsByFileId chapter label per selected file
     * @param array<int,array{kind:string,label:string,candidates:int,quota:int,selected:int}> $chapters
     * @param YearReviewChapter[] $chapterObjects same order as $chapters
     * @param array<int,int> $chapterByFileId index into $chapterObjects per selected file
     */
    public function __construct(
        public int $year,
        public array $selectedImages,
        public array $captionsByFileId,
        public array $chapters,
        public array $chapterObjects = [],
        public array $chapterByFileId = [],
        public ?YearReviewStats $stats = null,
    ) {}
}
