<?php
namespace OCA\Journeys\Command;

use OCA\Journeys\Exception\NoImagesFoundException;
use OCA\Journeys\Service\ClusterVideoJobRunner;
use OCA\Journeys\Service\YearReviewImageProvider;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RenderYearReviewCommand extends Command {
    protected static $defaultName = 'journeys:render-year-review';

    public function __construct(
        private ClusterVideoJobRunner $runner,
        private YearReviewImageProvider $provider,
    ) {
        parent::__construct(static::$defaultName);
    }

    protected function configure(): void {
        $this
            ->setDescription('Render a year-in-review video: one chapter per journey away from home plus the everyday photos of each month. Saved to the user\'s Documents/Journeys Movies folder unless --output is given.')
            ->addArgument('user', InputArgument::REQUIRED, 'User ID')
            ->addArgument('year', InputArgument::REQUIRED, 'Year, e.g. 2026')
            ->addOption('landscape', null, InputOption::VALUE_NONE, 'Render landscape instead of portrait')
            ->addOption('max-images', null, InputOption::VALUE_REQUIRED, 'Image budget for the whole video (2.5 s each)', YearReviewImageProvider::DEFAULT_MAX_IMAGES)
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output mp4 path (absolute inside server)')
            ->addOption('no-motion', null, InputOption::VALUE_NONE, 'Disable inclusion of GCam Motion Photos')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only show how the images are split across chapters')
            ->addOption('ffmpeg-verbose', null, InputOption::VALUE_NONE, 'Enable verbose FFmpeg output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $user = (string)$input->getArgument('user');
        $year = (int)$input->getArgument('year');
        if ($year < 1900) {
            $output->writeln('<error>Invalid year</error>');
            return Command::FAILURE;
        }
        $orientation = $input->getOption('landscape') ? 'landscape' : 'portrait';
        $maxImages = max(1, (int)$input->getOption('max-images'));

        try {
            if ($input->getOption('dry-run')) {
                $selection = $this->provider->select($user, $year, $orientation, $maxImages);
                $this->printChapters($output, $selection->chapters);
                $output->writeln(sprintf('%d images selected.', count($selection->selectedImages)));
                return Command::SUCCESS;
            }

            $outputOption = $input->getOption('output');
            $result = $this->runner->renderYearReview(
                $user,
                $year,
                $orientation,
                $maxImages,
                is_string($outputOption) && $outputOption !== '' ? $outputOption : null,
                function (string $type, string $buffer) use ($output): void {
                    $output->write($buffer);
                },
                !(bool)$input->getOption('no-motion'),
                (bool)$input->getOption('ffmpeg-verbose'),
            );
        } catch (NoImagesFoundException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $this->printChapters($output, $result['chapters']);
        $output->writeln(sprintf('<info>Video created (%d images):</info> %s', $result['imageCount'], $result['path']));
        return Command::SUCCESS;
    }

    private function printChapters(OutputInterface $output, array $chapters): void {
        $table = new Table($output);
        $table->setHeaders(['Chapter', 'Kind', 'Candidates', 'Quota', 'Selected']);
        foreach ($chapters as $c) {
            $table->addRow([$c['label'], $c['kind'], $c['candidates'], $c['quota'], $c['selected']]);
        }
        $table->render();
    }
}
