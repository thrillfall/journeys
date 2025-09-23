<?php
namespace OCA\Journeys\Command;

use OCA\Journeys\Service\ImageFetcher;
use OCA\Journeys\Service\Clusterer;
use OCA\Journeys\Service\VideoStorySelector;
use OCP\Files\IRootFolder;
use Symfony\Component\Process\Process;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RenderClusterVideoCommand extends Command {
    protected static $defaultName = 'journeys:render-cluster-video';

    public function __construct(
        private ImageFetcher $imageFetcher,
        private Clusterer $clusterer,
        private VideoStorySelector $selector,
        private IRootFolder $rootFolder,
    ) {
        parent::__construct(static::$defaultName);
    }

    protected function configure(): void {
        $this
            ->setDescription('Render a video (mp4) for a specific cluster using ffmpeg. Images only for now. If --output is omitted, the file is saved to the user\'s Documents/Journeys Movies folder.')
            ->addArgument('user', InputArgument::REQUIRED, 'User ID')
            ->addArgument('cluster', InputArgument::REQUIRED, 'Cluster number (1-based, as listed by journeys:list-clusters)')
            ->addOption('min-gap-seconds', null, InputOption::VALUE_REQUIRED, 'Minimum seconds between selected images (burst dedupe)', 5)
            ->addOption('duration-seconds', null, InputOption::VALUE_REQUIRED, 'Per-image duration (seconds)', 2.5)
            ->addOption('width', null, InputOption::VALUE_REQUIRED, 'Output width (height auto, maintains aspect)', 1920)
            ->addOption('fps', null, InputOption::VALUE_REQUIRED, 'Output frames per second', 30)
            ->addOption('max-images', null, InputOption::VALUE_REQUIRED, 'Maximum number of images to include (faster render)', 80)
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output mp4 path (absolute inside server)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $user = (string)$input->getArgument('user');
        $clusterIndex = max(1, (int)$input->getArgument('cluster')) - 1;
        $minGap = max(0, (int)$input->getOption('min-gap-seconds'));
        $duration = (float)$input->getOption('duration-seconds');
        $width = (int)$input->getOption('width');
        $fps = (int)$input->getOption('fps');
        $outPath = (string)$input->getOption('output');
        $maxImages = (int)$input->getOption('max-images');

        // Fetch & sort
        $images = $this->imageFetcher->fetchImagesForUser($user);
        if (empty($images)) {
            $output->writeln('<comment>No images found for user.</comment>');
            return Command::SUCCESS;
        }
        usort($images, fn($a,$b) => strtotime($a->datetaken) <=> strtotime($b->datetaken));

        // Cluster with defaults (24h, 50km)
        $clusters = $this->clusterer->clusterImages($images, 24*3600, 50.0);
        if ($clusterIndex < 0 || $clusterIndex >= count($clusters)) {
            $output->writeln(sprintf('<error>Cluster %d not found. Found %d clusters.</error>', $clusterIndex+1, count($clusters)));
            return Command::FAILURE;
        }
        $cluster = $clusters[$clusterIndex];

        // Select images
        $selected = $this->selector->selectImages($user, $cluster, $minGap, $maxImages > 0 ? $maxImages : 80);
        if (empty($selected)) {
            $output->writeln('<comment>No suitable images found for this cluster.</comment>');
            return Command::SUCCESS;
        }

        // Prepare temp dir and copy files
        $tmpBase = sys_get_temp_dir() . '/journeys_video_' . uniqid();
        if (!@mkdir($tmpBase, 0770, true) && !is_dir($tmpBase)) {
            $output->writeln('<error>Failed to create temp directory.</error>');
            return Command::FAILURE;
        }

        $userFolder = $this->rootFolder->getUserFolder($user);
        $listPath = $tmpBase . '/list.ffconcat';
        $list = fopen($listPath, 'w');
        fwrite($list, "ffconcat version 1.0\n");

        $i = 0;
        $copied = 0;
        foreach ($selected as $img) {
            $rel = $img->path;
            if (strpos($rel, 'files/') === 0) {
                $rel = substr($rel, 6);
            }
            try {
                $node = $userFolder->get($rel);
                if (!($node instanceof \OCP\Files\File)) { continue; }
                // Only include images for now. Videos in the selection can cause concat/duration issues.
                $mime = strtolower($node->getMimeType() ?? '');
                if (strpos($mime, 'image/') !== 0) {
                    // skip non-image files (e.g. video clips)
                    continue;
                }
                $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION) ?: 'jpg');
                if ($ext === '' || $ext === 'jpeg') { $ext = 'jpg'; }
                $dest = sprintf('%s/%05d.%s', $tmpBase, $i+1, $ext);
                $in = $node->fopen('r');
                $out = fopen($dest, 'w');
                if ($in && $out) {
                    stream_copy_to_stream($in, $out);
                    fclose($in); fclose($out);
                    // write ffconcat entries
                    fwrite($list, sprintf("file '%s'\n", str_replace("'", "'\\''", $dest)));
                    fwrite($list, sprintf("duration %.3f\n", max(0.1, $duration)));
                    $copied++;
                    $i++;
                }
            } catch (\Throwable $e) {
                // skip
            }
        }
        fclose($list);

        if ($copied === 0) {
            @unlink($listPath);
            @rmdir($tmpBase);
            $output->writeln('<comment>No readable files to render.</comment>');
            return Command::SUCCESS;
        }

        // Concat requires last file entry repeated without duration
        // Append it now
        $lastFile = sprintf('%s/%05d.%s', $tmpBase, $i, strtolower(pathinfo($selected[$i-1]->path, PATHINFO_EXTENSION) ?: 'jpg'));
        file_put_contents($listPath, sprintf("file '%s'\n", str_replace("'", "'\\''", $lastFile)), FILE_APPEND);

        // Build ffmpeg command
        // If no explicit output path is provided, write to a temporary mp4 and later copy into the user's Files
        $tmpOut = $outPath ?: ($tmpBase . '/output.mp4');
        $vf = sprintf('scale=%d:-2,format=yuv420p', max(320, $width));
        $cmd = [
            'ffmpeg','-y',
            '-f','concat','-safe','0','-i', $listPath,
            // disable audio to avoid DTS warnings for image-only slideshows
            '-an',
            // output settings
            '-r', (string)$fps,
            '-vf', $vf,
            '-pix_fmt','yuv420p',
            '-movflags','+faststart',
            $tmpOut,
        ];

        $output->writeln('<info>Starting ffmpeg...</info>');
        // Run ffmpeg and stream its stdout/stderr live to the OCC output
        $process = new Process($cmd);
        // Allow long-running renders; if needed a hard timeout can be set by replacing null.
        $process->setTimeout(null);
        $process->setIdleTimeout(null);
        $process->run(function (string $type, string $buffer) use ($output) {
            // Forward both STDOUT and STDERR to the console in real time
            $output->write($buffer);
        });
        $output->writeln('<info>ffmpeg finished.</info>');

        if (!$process->isSuccessful()) {
            // Cleanup temp dir
            foreach (glob($tmpBase . '/*') as $f) { @unlink($f); }
            @rmdir($tmpBase);
            $output->writeln('<error>ffmpeg failed</error>');
            // Provide the last lines of error output for context
            $err = trim($process->getErrorOutput());
            if ($err !== '') {
                $lines = explode("\n", $err);
                $tail = implode("\n", array_slice($lines, -50));
                $output->writeln($tail);
            }
            return Command::FAILURE;
        }

        $virtualDest = null;
        if (!$outPath) {
            // Copy temp mp4 into the user's Documents/Journeys Movies folder within Nextcloud Files
            try {
                $userFolder = $this->rootFolder->getUserFolder($user);
                // Ensure Documents folder exists
                try {
                    $docs = $userFolder->get('Documents');
                } catch (\Throwable $e) {
                    $docs = $userFolder->newFolder('Documents');
                }
                // Ensure subfolder exists
                try {
                    $movies = $docs->get('Journeys Movies');
                } catch (\Throwable $e) {
                    $movies = $docs->newFolder('Journeys Movies');
                }
                $fileName = sprintf('Journey-%02d.mp4', $clusterIndex + 1);
                // If file exists, overwrite
                try {
                    $existing = $movies->get($fileName);
                    if ($existing instanceof \OCP\Files\File) {
                        $existing->delete();
                    }
                } catch (\Throwable $e) { /* ignore */ }
                $output->writeln('<info>Saving video into Nextcloud Files...</info>');
                $destFile = $movies->newFile($fileName);
                $data = @file_get_contents($tmpOut);
                if ($data === false) {
                    throw new \RuntimeException('Failed to read temporary video output');
                }
                $destFile->putContent($data);
                $virtualDest = '/Documents/Journeys Movies/' . $fileName;
            } catch (\Throwable $e) {
                // Fall back to leaving the temp file if copy failed
                $output->writeln('<comment>Rendered video, but failed to copy into Nextcloud Files. Temp path:</comment> ' . $tmpOut);
                $output->writeln('<comment>Reason:</comment> ' . $e->getMessage());
            }
        }

        // Cleanup temp dir
        foreach (glob($tmpBase . '/*') as $f) { @unlink($f); }
        @rmdir($tmpBase);

        if ($virtualDest !== null) {
            $output->writeln(sprintf('<info>Video created:</info> %s (%d images)', $virtualDest, $copied));
        } else {
            $output->writeln(sprintf('<info>Video created:</info> %s (%d images)', $outPath ?: $tmpOut, $copied));
        }
        return Command::SUCCESS;
    }
}
