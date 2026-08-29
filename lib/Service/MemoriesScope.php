<?php
namespace OCA\Journeys\Service;

use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Mount\IMountManager;
use OCP\Files\Mount\IMountPoint;
use OCP\IConfig;
use OCP\IDBConnection;

/**
 * The subset of oc_memories a user would actually see in Memories, expressed as
 * a SQL predicate over a filecache alias.
 *
 * "Indexed by Memories" and "shown by Memories" are not the same set. The
 * indexer walks the whole home tree by default (`memories.index.mode` = 1) and
 * the timeline is narrowed at *query* time to the user's configured timeline
 * paths, minus .nomedia / .nomemories subtrees and dot-folders (see Memories'
 * FsManager::populateRoot and TimelineQueryCTE). Reading oc_memories raw
 * therefore picks up photos the user has deliberately kept out of Memories -- a
 * /Documents scan folder clustered into a journey, an excluded backup folder
 * turning up in a video.
 *
 * Two predicates, because the app's reads are not all equivalent:
 *
 *  - filterFor() restricts to the timeline paths and their exclusions. This is
 *    the user's own library: everything Journeys reads from it must be something
 *    Memories would show.
 *  - exclusionFilterFor() applies only the .nomedia / dot-folder exclusions,
 *    over the whole files tree. It is for the Group Folder and share branches,
 *    which the user opts into by name and which already scope themselves by
 *    mount; a Group Folder mounted next to (rather than inside) the timeline
 *    path must not be silently dropped from a setting that promises to include
 *    it, but an explicit .nomedia in it still counts.
 */
final class MemoriesScope {

    private const MEMORIES_APP = 'memories';
    private const MARKER_FILES = ['.nomedia', '.nomemories'];
    private const DIRECTORY_MIME = 'httpd/unix-directory';

    /** @var array<string,?array{sql:string,params:array<int,mixed>}> */
    private array $cache = [];

    public function __construct(
        private IConfig $config,
        private IDBConnection $db,
        private IRootFolder $rootFolder,
        private IMountManager $mountManager,
    ) {}

    /**
     * @param string $alias the filecache alias to constrain
     * @return array{sql:string,params:array<int,mixed>}|null null when no
     *         timeline path resolves, in which case the caller must fall back to
     *         its own storage scoping (there is nothing narrower to say)
     */
    public function filterFor(string $user, string $alias = 'f'): ?array {
        return $this->cached('timeline', $user, $alias, function () use ($user, $alias): ?array {
            $allowed = $this->timelineRoots($user);
            if (!$allowed) {
                return null;
            }
            return self::buildPredicate($allowed, $this->exclusionsUnder($allowed), $alias);
        });
    }

    /**
     * The .nomedia / dot-folder exclusions alone, across the user's whole files
     * tree. For reads that carry their own scoping -- see the class docblock.
     *
     * @return array{sql:string,params:array<int,mixed>}|null
     */
    public function exclusionFilterFor(string $user, string $alias = 'f'): ?array {
        return $this->cached('exclusions', $user, $alias, function () use ($user, $alias): ?array {
            $excluded = $this->exclusionsUnder($this->fileTreeRoots($user));
            return $excluded ? self::buildExclusionPredicate($excluded, $alias) : null;
        });
    }

    /**
     * @param \Closure():?array{sql:string,params:array<int,mixed>} $compute
     * @return array{sql:string,params:array<int,mixed>}|null
     */
    private function cached(string $kind, string $user, string $alias, \Closure $compute): ?array {
        $key = $kind . "\0" . $user . "\0" . $alias;
        if (!array_key_exists($key, $this->cache)) {
            $this->cache[$key] = $compute();
        }
        return $this->cache[$key];
    }

    /**
     * @param array<int,array{storage:int,fileid:int,path:string,like:string}> $roots
     * @return array<int,array{storage:int,fileid:int,like:string}>
     */
    private function exclusionsUnder(array $roots): array {
        $excluded = [];
        foreach ($roots as $root) {
            foreach ($this->excludedSubtrees($root) as $subtree) {
                $excluded[$subtree['storage'] . ':' . $subtree['fileid']] = $subtree;
            }
        }
        return array_values($excluded);
    }

    /**
     * Roots of the user's timeline: each configured timeline path plus every
     * mount inside it, as (numeric storage, root fileid, filecache path).
     *
     * @return array<int,array{storage:int,fileid:int,path:string,like:string}>
     */
    private function timelineRoots(string $user): array {
        $paths = $this->configuredTimelinePaths($user);
        return $paths ? $this->rootsForPaths($user, $paths) : [];
    }

    /**
     * Roots of everything mounted into the user's own files tree.
     *
     * @return array<int,array{storage:int,fileid:int,path:string,like:string}>
     */
    private function fileTreeRoots(string $user): array {
        return $this->rootsForPaths($user, ['/']);
    }

    /**
     * @param string[] $paths paths relative to the user's files root
     * @return array<int,array{storage:int,fileid:int,path:string,like:string}>
     */
    private function rootsForPaths(string $user, array $paths): array {
        try {
            $userFolder = $this->rootFolder->getUserFolder($user);
        } catch (\Throwable $e) {
            return [];
        }

        $roots = [];
        foreach ($paths as $path) {
            try {
                $node = $userFolder->get($path);
            } catch (\Throwable $e) {
                continue;
            }
            if (!$node instanceof Folder) {
                continue;
            }
            $this->addRoot($roots, $node->getMountPoint()->getNumericStorageId(), $node->getId(), $node->getInternalPath());

            try {
                $inner = $this->mountManager->findIn($node->getPath());
            } catch (\Throwable $e) {
                $inner = [];
            }
            foreach ($inner as $mount) {
                if (!$mount instanceof IMountPoint) {
                    continue;
                }
                $this->addRoot(
                    $roots,
                    $mount->getNumericStorageId(),
                    $mount->getStorageRootId(),
                    $mount->getInternalPath($mount->getMountPoint()),
                );
            }
        }
        return array_values($roots);
    }

    /**
     * @param array<string,array{storage:int,fileid:int,path:string,like:string}> $roots
     */
    private function addRoot(array &$roots, ?int $storage, ?int $fileid, ?string $internalPath): void {
        if ($storage === null || $storage <= 0 || $fileid === null || $fileid <= 0 || $internalPath === null) {
            return;
        }
        $path = rtrim($internalPath, '/');
        $roots[$storage . ':' . $fileid] = [
            'storage' => $storage,
            'fileid' => $fileid,
            'path' => $path,
            'like' => $this->likePrefix($path),
        ];
    }

    /**
     * Timeline paths as configured for the user, or the instance default when
     * the user has none. '_empty_' is Memories' "not chosen yet" sentinel and
     * resolves to no path at all.
     *
     * @return string[]
     */
    private function configuredTimelinePaths(string $user): array {
        $raw = trim($this->config->getUserValue($user, self::MEMORIES_APP, 'timelinePath', ''));
        if ($raw === '') {
            $raw = trim($this->config->getSystemValueString('memories.timeline.default_path', ''));
        }
        if ($raw === '') {
            return [];
        }
        $paths = [];
        foreach (explode(';', $raw) as $part) {
            $part = trim($part);
            if ($part === '' || $part === '_empty_') {
                continue;
            }
            $paths[] = $part;
        }
        return $paths;
    }

    /**
     * Subtrees under $root that Memories hides: folders holding a .nomedia /
     * .nomemories marker, and dot-folders (which covers Memories' .archive).
     *
     * @param array{storage:int,fileid:int,path:string,like:string} $root
     * @return array<int,array{storage:int,fileid:int,like:string}>
     */
    private function excludedSubtrees(array $root): array {
        $markers = implode(',', array_fill(0, count(self::MARKER_FILES), '?'));
        $sql = "
            SELECT f.fileid, f.parent, f.name, f.path, mt.mimetype
            FROM *PREFIX*filecache f
            JOIN *PREFIX*mimetypes mt ON mt.id = f.mimetype
            WHERE f.storage = ?
              AND (f.path = ? OR f.path LIKE ?)
              AND (f.name IN ({$markers}) OR (mt.mimetype = ? AND f.name LIKE ?))
        ";
        $params = array_merge(
            [$root['storage'], $root['path'], $root['like']],
            self::MARKER_FILES,
            [self::DIRECTORY_MIME, '.%'],
        );

        try {
            $result = $this->db->prepare($sql)->execute($params);
            $rows = $result ? $result->fetchAll() : [];
        } catch (\Throwable $e) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $isDirectory = (string)($row['mimetype'] ?? '') === self::DIRECTORY_MIME;
            $name = (string)($row['name'] ?? '');
            $path = rtrim((string)($row['path'] ?? ''), '/');

            if (in_array($name, self::MARKER_FILES, true)) {
                // The marker excludes the folder holding it, not the marker file.
                $parent = (int)($row['parent'] ?? 0);
                $parentPath = $isDirectory ? $path : self::parentPath($path);
                if ($parent > 0 && $parentPath !== null) {
                    $out[] = ['storage' => $root['storage'], 'fileid' => $parent, 'like' => $this->likePrefix($parentPath)];
                }
                continue;
            }

            // A dot-folder that *is* the timeline root was chosen deliberately.
            if ($isDirectory && $path !== '' && $path !== $root['path']) {
                $out[] = ['storage' => $root['storage'], 'fileid' => (int)$row['fileid'], 'like' => $this->likePrefix($path)];
            }
        }
        return $out;
    }

    /** LIKE pattern matching everything below $path, with wildcards in $path escaped. */
    private function likePrefix(string $path): string {
        return $this->db->escapeLikeParameter($path) . '/%';
    }

    /** @return string|null null when $path has no parent directory component */
    private static function parentPath(string $path): ?string {
        $pos = strrpos($path, '/');
        return $pos === false ? null : substr($path, 0, $pos);
    }

    /**
     * @param array<int,array{storage:int,fileid:int,like:string}> $allowed
     * @param array<int,array{storage:int,fileid:int,like:string}> $excluded
     * @return array{sql:string,params:array<int,mixed>}|null
     */
    public static function buildPredicate(array $allowed, array $excluded, string $alias = 'f'): ?array {
        if (!$allowed) {
            return null;
        }
        $params = [];
        $sql = ' AND (' . self::subtreeOr($allowed, $alias, $params) . ')';
        if ($excluded) {
            $sql .= ' AND NOT (' . self::subtreeOr($excluded, $alias, $params) . ')';
        }
        return ['sql' => $sql, 'params' => $params];
    }

    /**
     * @param array<int,array{storage:int,fileid:int,like:string}> $excluded
     * @return array{sql:string,params:array<int,mixed>}|null
     */
    public static function buildExclusionPredicate(array $excluded, string $alias = 'f'): ?array {
        if (!$excluded) {
            return null;
        }
        $params = [];
        $sql = ' AND NOT (' . self::subtreeOr($excluded, $alias, $params) . ')';
        return ['sql' => $sql, 'params' => $params];
    }

    /**
     * @param array<int,array{storage:int,fileid:int,like:string}> $subtrees
     * @param array<int,mixed> $params
     */
    private static function subtreeOr(array $subtrees, string $alias, array &$params): string {
        $terms = [];
        foreach ($subtrees as $subtree) {
            $terms[] = "({$alias}.storage = ? AND ({$alias}.fileid = ? OR {$alias}.path LIKE ?))";
            $params[] = $subtree['storage'];
            $params[] = $subtree['fileid'];
            $params[] = $subtree['like'];
        }
        return implode(' OR ', $terms);
    }
}
