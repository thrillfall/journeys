<?php
namespace OCA\Journeys\Command;

use OCA\Journeys\Command\Traits\FaceBoostLoggingTrait;
use OCA\Journeys\Exception\ClusterNotFoundException;
use OCA\Journeys\Exception\NoImagesFoundException;
use OCA\Journeys\Model\ClusterVideoSelection;
use OCA\Journeys\Service\ClusterVideoFilePreparer;
use OCA\Journeys\Service\ClusterVideoImageProvider;
use OCA\Journeys\Service\ClusterVideoRendererLandscape;
use OCP\IConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RenderClusterVideoLandscapeCommand extends Command {
    use FaceBoostLoggingTrait;
    protected static $defaultName = 'journeys:render-cluster-video-landscape';

    public function __construct(
        private ClusterVideoImageProvider $imageProvider,
        private ClusterVideoFilePreparer $filePreparer,
        private ClusterVideoRendererLandscape $videoRenderer,
        private IConfig $config,
    ) {
        parent::__construct(static::$defaultName);
    }

    protected function configure(): void {
        $this
            ->setDescription('Render a landscape video (mp4) for a specific cluster using ffmpeg. If --output is omitted, the file is saved to the user\'s Documents/Journeys Movies folder.')
            ->addArgument('user', InputArgument::REQUIRED, 'User ID')
            ->addArgument('album-id', InputArgument::REQUIRED, 'Photos album id for the cluster (as listed by journeys:list-clusters)')
            ->addOption('min-gap-seconds', null, InputOption::VALUE_REQUIRED, 'Minimum seconds between selected images (burst dedupe)', 5)
            ->addOption('duration-seconds', null, InputOption::VALUE_REQUIRED, 'Per-image duration (seconds)', 2.5)
            ->addOption('width', null, InputOption::VALUE_REQUIRED, 'Output width (height is 16:9)', 1920)
            ->addOption('fps', null, InputOption::VALUE_REQUIRED, 'Output frames per second', 30)
            ->addOption('max-images', null, InputOption::VALUE_REQUIRED, 'Maximum number of images to include (faster render)', 80)
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output mp4 path (absolute inside server)')
            ->addOption('no-audio', null, InputOption::VALUE_NONE, 'Disable soundtrack')
            ->addOption('no-motion', null, InputOption::VALUE_NONE, 'Disable GCam motion video inclusion')
            ->addOption('no-title', null, InputOption::VALUE_NONE, 'Disable cluster name title overlay (enabled by default)')
            ->addOption('no-face-boost', null, InputOption::VALUE_NONE, 'Disable preferring images with faces when selecting frames')
            ->addOption('ffmpeg-verbose', null, InputOption::VALUE_NONE, 'Enable verbose FFmpeg output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $user = (string)$input->getArgument('user');
        $albumIdArg = $input->getArgument('album-id');
        $albumId = is_scalar($albumIdArg) ? (string)$albumIdArg : '';
        if ($albumId === '') {
            $output->writeln('<error>Missing album id</error>');
            return Command::FAILURE;
        }

        $minGap = max(0, (int)$input->getOption('min-gap-seconds'));
        $duration = (float)$input->getOption('duration-seconds');
        $width = (int)$input->getOption('width');
        $fps = (int)$input->getOption('fps');
        $outputOption = $input->getOption('output');
        $outPath = is_string($outputOption) && $outputOption !== '' ? $outputOption : null;
        $maxImagesOption = (int)$input->getOption('max-images');
        $maxImages = $maxImagesOption > 0 ? $maxImagesOption : 80;
        $disableAudio = (bool)$input->getOption('no-audio');
        $includeMotion = !(bool)$input->getOption('no-motion');
        $verbose = (bool)$input->getOption('ffmpeg-verbose');

        // Determine if title should be shown: CLI flag overrides user setting
        $showTitle = true;
        if ($input->getOption('no-title')) {
            $showTitle = false;
        } else {
            // Read user's preference (default: true)
            $showTitle = (bool)((int)$this->config->getUserValue($user, 'journeys', 'showVideoTitle', 1));
        }

        $boostFacesOverride = $input->getOption('no-face-boost') ? false : null;

        try {
            $selection = $this->imageProvider->getSelectedImagesForAlbumId($user, (int)$albumId, $minGap, $maxImages, $boostFacesOverride);
        } catch (NoImagesFoundException) {
            $output->writeln('<comment>No images found for user.</comment>');
            return Command::SUCCESS;
        } catch (ClusterNotFoundException $e) {
            $output->writeln(sprintf('<error>Cluster for album id %s not found. %s</error>', $albumId, $e->getMessage()));
            return Command::FAILURE;
        } catch (\Throwable $e) {
            $output->writeln('<error>Failed to select images: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        if (empty($selection->selectedImages)) {
            $output->writeln('<comment>No suitable images found for this cluster.</comment>');
            return Command::SUCCESS;
        }

        $this->logFaceBoost($output, $selection);

        try {
            $preparation = $this->filePreparer->prepare($user, $selection->selectedImages);
        } catch (\Throwable $e) {
            $output->writeln('<error>Failed to prepare media files: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $workingDir = $preparation['workingDir'];
        $filePaths = $preparation['files'];
        $copied = (int)($preparation['copied'] ?? count($filePaths));
        $preferredFileName = $this->buildPreferredFileName($selection, true);

        // If audio is disabled, temporarily point music provider to a null path by environment override
        if ($disableAudio) {
            putenv('JOURNEYS_DISABLE_AUDIO=1');
        }

        $result = null;
        try {
            if ($copied === 0 || empty($filePaths)) {
                $output->writeln('<comment>No readable files to render.</comment>');
                return Command::SUCCESS;
            }

            $output->writeln('<info>Starting ffmpeg (landscape)...</info>');
            $result = $this->videoRenderer->render(
                $user,
                $outPath,
                $duration,
                $width,
                $fps,
                $workingDir,
                $filePaths,
                function (string $type, string $buffer) use ($output): void {
                    $output->write($buffer);
                },
                $preferredFileName,
                $includeMotion,
                $verbose,
                $showTitle ? $selection->clusterName : null,
            );
            $output->writeln('<info>ffmpeg finished.</info>');
        } catch (\Throwable $e) {
            $output->writeln('<error>ffmpeg failed</error>');
            $output->writeln('<comment>Reason:</comment> ' . $e->getMessage());
            return Command::FAILURE;
        } finally {
            $this->filePreparer->cleanup($workingDir);
            if ($disableAudio) { putenv('JOURNEYS_DISABLE_AUDIO'); }
        }

        if (!is_array($result) || !isset($result['path'])) {
            $output->writeln('<error>Video rendering did not return a path.</error>');
            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            '<info>Landscape video created:</info> %s (%d images, cluster "%s")',
            $result['path'],
            $copied,
            $selection->clusterName
        ));
        return Command::SUCCESS;
    }

    private function buildPreferredFileName(ClusterVideoSelection $selection, bool $landscape): string {
        $clusterId = $selection->clusterIndex + 1;
        $clusterName = trim($selection->clusterName);
        if ($clusterName === '') { $clusterName = 'Untitled'; }
        $base = sprintf('%02d - %s', $clusterId, $clusterName);
        return $landscape ? $base . ' (landscape)' : $base;
    }
}
