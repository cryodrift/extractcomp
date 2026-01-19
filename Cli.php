<?php

//declare(strict_types=1);

namespace cryodrift\extractcomp;

use cryodrift\fw\Config;
use cryodrift\fw\Context;
use cryodrift\fw\Core;
use cryodrift\fw\interface\Handler;
use cryodrift\fw\Main;
use cryodrift\fw\trait\CliHandler;
use cryodrift\fw\trait\CliDefaults;
use cryodrift\fw\trait\CmdRunner;
use cryodrift\fw\cli\Colors;

class Cli implements Handler
{
    use CliHandler;
    use CliDefaults;
    use CmdRunner;

    public function __construct(private readonly array $project_include, private readonly string $gitfilter_src, private readonly array $clidefaults = [])
    {
    }

    public function handle(Context $ctx): Context
    {
        // Merge defaults (CLI args take precedence)
        $this->defaultsCli($ctx, $this->clidefaults);
        return $this->handleCli($ctx);
    }

    /**
     * Extract components from the monorepo (source/<module>) into standalone git repos preserving history.
     * Scaffolding-style CLI entry. Git commands are implemented in dedicated helper methods below.
     *
     * @cli extract components
     * @cli params: -source="" (path to monorepo)
     * @cli params: -dest="" (destination base directory for new repos)
     * @cli params: [-branch=""] (preferred source branch)
     * @cli params: [-vendor=""] (composer vendor)
     * @cli params: [-modules=""] (space or comma separated list of component names to include)
     * @cli params: [-name=""] (override component name; affects composer name and destination dir)
     * @cli params: [-gitmeta=""] (multiple) pass-through tokens forwarded to git-filter.cmd to rewrite commit metadata across all history (wrapper interprets them). Also used to configure authorship for commits we create (e.g., "user.name John Doe").
     * @cli params: [-gitrewritefile=""] (multiple) path(s) to rewrite mapping files for git filter-repo --replace-text; each file contains lines like:
     * @cli params:    literal:old_text==>new_text or regex:/pattern/flags==>replacement
     * @cli params:    (Deprecated) -gitsearch and -gitreplace can still be used together; we will generate a temp rewrite file pairing them as literal:search==>replace
     * @cli params: [-write] (does change the repo)
     */
    protected function extract(
      string $source,
      string $dest,
      string $branch = '',
      string $vendor = '',
      string $name = '',
      string $modules = '',
      array $gitmeta = [],
      array $gitrewritefile = [],
      array $gitsearch = [], // deprecated
      array $gitreplace = [], // deprecated
      bool $write = false
    ): string {
        // basic validation
        if (!$source || !is_dir($source)) {
            return 'Source path not found: ' . $source;
        }
        if (!$dest) {
            return 'Destination directory required';
        }

        // $source is the root directory containing the modules (can be deeper than the git root)
        $sourcePackagesRoot = rtrim($source, "\\/");
        if (!is_dir($sourcePackagesRoot)) {
            return 'Modules root not found: ' . $sourcePackagesRoot;
        }

        Core::echo(Colors::get('[info]', Colors::FG_light_blue) . ' Packages root:', Colors::get($sourcePackagesRoot, Colors::FG_light_blue));

        // Detect the actual Git repository root for all git commands
        $sourceGitRoot = $this->findGitRoot($sourcePackagesRoot);
        if ($sourceGitRoot === '') {
            return 'Could not locate git repository root starting from: ' . $sourcePackagesRoot;
        }
        Core::echo(Colors::get('[info]', Colors::FG_light_blue) . ' Git root:', Colors::get($sourceGitRoot, Colors::FG_light_blue));

        // Compute the relative base from git root to the modules root (e.g., "src")
        $sourceRelModulesBase = $this->relPath($sourceGitRoot, $sourcePackagesRoot); // may be '' if modules are at repo root
        Core::echo(Colors::get('[info]', Colors::FG_light_blue) . ' Relative modules base:', Colors::get($sourceRelModulesBase !== '' ? $sourceRelModulesBase : '(repo root)', Colors::FG_light_blue));

        // Load versions registry to persist last known versions across exports
        $versionsRegistry = $this->loadVersionsRegistry();

        $all = array_values(array_filter(scandir($sourcePackagesRoot), function ($n) use ($sourcePackagesRoot) {
            return $n !== '.' && $n !== '..' && is_dir($sourcePackagesRoot . DIRECTORY_SEPARATOR . $n);
        }));
        Core::echo(Colors::get('[info]', Colors::FG_light_blue) . ' Found modules:', Colors::get(implode(', ', $all), Colors::FG_light_blue));

        $sel = $this->filterModules($all, $modules);
        Core::echo(Colors::get('[info]', Colors::FG_light_blue) . ' Selected modules:', Colors::get(implode(', ', $sel), Colors::FG_light_blue));
        if (empty($sel)) {
            return 'No packages to process';
        }
        $selCount = count($sel);
        if ($name !== '' && $selCount > 1) {
            Core::echo(Colors::get('[warn]', Colors::FG_yellow) . ' -name provided but multiple modules selected; ignoring override for per-module names.');
        }

        // determine effective source branch (run at git root)
        $branch = $this->resolveSourceBranch($sourceGitRoot, $branch);
        if ($branch === '') {
            return 'Could not determine a usable source branch';
        }
        Core::echo(Colors::get('[info]', Colors::FG_light_blue) . ' Effective source branch:', Colors::get($branch, Colors::FG_light_blue));

        $makeTemp = function (string $suffix) {
            $base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
            return $base . DIRECTORY_SEPARATOR . 'extractcomp_' . Core::getUid(8) . '_' . $suffix;
        };

        // parse gitmeta entries as arbitrary git config key/value pairs (e.g., "user.name John Doe")
        $metaConfigs = $this->buildMetaConfigs($gitmeta);

        foreach ($sel as $pkg) {
            Core::echo(Colors::get('[extract]', Colors::FG_light_cyan) . ' package:', Colors::get($pkg, Colors::FG_light_cyan));

            // Ensure destination destParentDir directory exists (only in write mode)
            $destParentDir = rtrim($dest, "\\/");
            if ($write) {
                try {
                    Core::dirCreate($destParentDir, false);
                } catch (\Throwable $e) {
                    Core::echo(Colors::get('[error]', Colors::FG_light_red) . ' cannot create dir', Colors::get($destParentDir . ' (' . $e->getMessage() . ')', Colors::FG_light_red));
                    continue;
                }
            } else {
                Core::echo(Colors::get('[dry]', Colors::FG_light_gray) . ' skip creating dest dir (will use temp workdir) -> ' . $destParentDir);
            }

            // Module path relative to git root (for filtering)
            $sourceModuleRel = $sourceRelModulesBase !== '' ? ($sourceRelModulesBase . DIRECTORY_SEPARATOR . $pkg) : $pkg;
            $sourceModuleRelGit = $this->toGitPath($sourceModuleRel);
            Core::echo(Colors::get('[info]', Colors::FG_light_blue) . ' Module rel path:', Colors::get($sourceModuleRelGit, Colors::FG_light_blue));

            $compName = ($name !== '' && $selCount === 1) ? $name : $pkg;
            $destComponentDir = $this->join($dest, $compName);
            $extractBranch = 'extract-' . str_replace(['/', '\\', ' '], '-', $pkg);
            $packageName = $vendor . '/' . $compName;

            $destComponentGitDir = $this->join($destComponentDir, '.git');
            $componentExists = is_dir($destComponentDir) && is_dir($destComponentGitDir);

            // Fast-path: if destination repo exists and source module hasn't changed since last run, skip heavy work
            if ($componentExists) {
                $sourceLatestTs = $this->getLatestCommitTimeForPath($sourceGitRoot, $branch, $sourceModuleRelGit);
                $destLatestTs = $this->getLatestCommitTime($destComponentDir);
                if ($sourceLatestTs > 0 && $destLatestTs > 0 && $destLatestTs >= $sourceLatestTs) {
                    Core::echo(Colors::get('[skip]', Colors::FG_light_green) . ' No new commits for module since last extraction; skipping heavy operations.');
                    // Still ensure LICENSE and composer license are correct, and update composer version if necessary
                    $versionToWrite = $this->determineVersionForPackageExtract($destComponentDir, '0.1.0', $packageName, false);
                    $destComposer = $this->readComposer($destComponentDir);
                    $prevVer = isset($destComposer['version']) ? (string)$destComposer['version'] : '';
                    $needVersionUpdate = ($versionToWrite !== '' && $versionToWrite !== $prevVer);
                    if ($needVersionUpdate) {
                        $destComposer['version'] = $versionToWrite;
                        $this->writeComposer($destComponentDir, $destComposer, $write);
                    }
                    // track last known version in registry
                    $versionsRegistry[$packageName] = $versionToWrite;
                    // Ensure LICENSE only if missing
                    $this->copyLicenseIfMissing($destComponentDir, $write);
                    if ($needVersionUpdate) {
                        $this->gitCommitAll($destComponentDir, 'Set version from versions registry', $write);
                    }
                    // Ensure origin remote points to GitHub for main branch even if no new commits
                    $this->gitEnsureOriginRemote($destComponentDir, $packageName, 'main', $write);
                    // Tag current HEAD with the computed version
                    $this->gitTagVersion($destComponentDir, $versionToWrite, $write);
                    continue;
                }
            }

            if (!$componentExists) {
                // Fresh export: always build in a temporary working repository, then copy/clone to destination
                $tempOpsDir = $makeTemp($pkg);
                Core::echo(Colors::get('[info]', Colors::FG_light_blue) . ' using temp workdir: ' . $tempOpsDir);

                // 1) Clone source repo into temp
                $this->gitCloneBranch($sourceGitRoot, '', $tempOpsDir, true);

                // 2) Checkout branch
                $this->runCmd(['git', 'checkout', $branch], $tempOpsDir, true);

                // 3) Create extract branch
                $this->runCmd(['git', 'checkout', '-B', $extractBranch], $tempOpsDir, true);

                // 4) Filter to module path using git filter-repo directly
                $filterCmd = $this->buildFilterRepoCommand($sourceModuleRelGit, $gitrewritefile, $gitsearch, $gitreplace, $gitmeta, $tempOpsDir);
                $this->runCmd($filterCmd, $tempOpsDir, true);

                // 5) Ensure composer.json version and LICENSE in temp, then commit
                $packageName = $vendor . '/' . $compName;
                $sourceModuleDir = $this->join($sourcePackagesRoot, $pkg);

                // After filtering, module files are at repo root. Prepare composer from source metadata
                $json = $this->readComposer($sourceModuleDir);
                $json['version'] = $this->determineVersionForPackageExtract($tempOpsDir, '0.1.0', $packageName, true);
                $this->writeComposer($tempOpsDir, $json, true);
                // track last known version in registry
                $versionsRegistry[$packageName] = $json['version'];

                // Ensure LICENSE exists in export
                $this->copyLicenseIfMissing($tempOpsDir, true);
                // Apply metadata for newly created commit(s)
                $this->applyMetaConfigs($tempOpsDir, $metaConfigs, true);
                $this->gitCommitAll($tempOpsDir, 'Prepare package ' . $packageName . ' - ensure composer version and LICENSE', true);
                // Tag the prepared state with the computed version
                $this->gitTagVersion($tempOpsDir, (string)($json['version'] ?? ''), true);

                // 6) Align final repository state in temp: keep only 'main'
                $this->runCmd(['git', 'checkout', '-B', 'main'], $tempOpsDir, true);
                $this->runCmd(['git', 'reset', '--hard', $extractBranch], $tempOpsDir, true);
                $this->gitDeleteOtherBranches($tempOpsDir, 'main', true);

                if ($write) {
                    // 7) Create/refresh destination by cloning temp repo, so no extra commits are created in destination
                    // Remove destination if it exists but without git dir (safety): we'll just clone into a new dir
                    if (!is_dir($destComponentDir)) {
                        Core::dirCreate(dirname($destComponentDir), false);
                    }
                    // If destination folder exists and is non-empty, remove it to avoid clone errors
                    if (is_dir($destComponentDir)) {
                        $this->deleteDirRecursive($destComponentDir);
                    }
                    $this->gitCloneBranch($tempOpsDir, 'main', $destComponentDir, true);
                    Core::echo(Colors::get('[info]', Colors::FG_light_blue) . ' exported to destination via clone: ' . $destComponentDir);
                    // Ensure GitHub origin remote points to cryodrift/{packagename}.git and track main
                    $this->gitEnsureOriginRemote($destComponentDir, $packageName, 'main', true);
                    // Tag destination repo with the version from composer.json
                    $this->gitTagVersion($destComponentDir, (string)($json['version'] ?? ''), true);
                } else {
                    Core::echo(Colors::get('[dry]', Colors::FG_light_gray) . ' dry-run completed in temp workdir: ' . $tempOpsDir);
                }
                // Cleanup temp
                if (is_dir($tempOpsDir)) {
                    $this->deleteDirRecursive($tempOpsDir);
                }
            } else {
                // Update existing destination repo: build export in a fresh temp dir and merge into a working clone
                $tempExportDir = $makeTemp($pkg . '__export');
                $destWorkDir = $write ? $destComponentDir : $makeTemp($pkg . '__dest');

                if (!$write) {
                    Core::echo(Colors::get('[dry]', Colors::FG_light_gray) . ' using temp export dir: ' . $tempExportDir);
                    Core::echo(Colors::get('[dry]', Colors::FG_light_gray) . ' cloning destination repo to temp workdir: ' . $destWorkDir);
                    // Clone existing destination repo into temp workdir
                    $this->gitCloneBranch($destComponentDir, '', $destWorkDir, true);
                }

                // Build/refresh export branch in temporary clone
                $this->gitCloneBranch($sourceGitRoot, '', $tempExportDir, true);
                $this->runCmd(['git', 'checkout', $branch], $tempExportDir, true);
                $this->runCmd(['git', 'checkout', '-B', $extractBranch], $tempExportDir, true);
                $filterCmd = $this->buildFilterRepoCommand($sourceModuleRelGit, $gitrewritefile, $gitsearch, $gitreplace, $gitmeta, $tempExportDir);
                $this->runCmd($filterCmd, $tempExportDir, true);

                // In the destination working repo, add/refresh remote "export" pointing to tempExportDir and fetch
                $remoteName = 'export';
                // Try to remove if exists (ignore errors)
                $this->runCmd(['git', 'remote', 'remove', $remoteName], $destWorkDir, true);
                $this->runCmd(['git', 'remote', 'add', $remoteName, $tempExportDir], $destWorkDir, true);
                $this->gitFetchRemoteSafe($destWorkDir, $remoteName, true);

                // Determine current branch in destination working repo
                [$cHead, $out] = $this->runCmdCapture(['git', 'symbolic-ref', '--quiet', '--short', 'HEAD'], $destWorkDir, true);
                $destBranch = $cHead === 0 ? trim((string)$out) : '';
                if ($destBranch === '') {
                    foreach (["main", "master", "develop", "trunk"] as $cand) {
                        [$rc,] = $this->runCmd(['git', 'show-ref', '--verify', 'refs/heads/' . $cand], $destWorkDir, true);
                        if ($rc === 0) {
                            $destBranch = $cand;
                            break;
                        }
                    }
                    if ($destBranch === '') {
                        $destBranch = 'main';
                    }
                }

                // Ensure we are on destination branch
                $this->runCmd(['git', 'checkout', $destBranch], $destWorkDir, true);

                // Check whether export has new commits vs dest (before version bump in temp)
                [, $counts] = $this->runCmdCapture(['git', 'rev-list', '--left-right', '--count', $destBranch . '...' . $remoteName . '/' . $extractBranch], $destWorkDir, true);
                $leftRight = trim((string)$counts);
                $newOnExport = 0;
                $newOnDest = 0;
                if ($leftRight !== '') {
                    $parts = preg_split('/\s+/', $leftRight);
                    if (count($parts) >= 2) {
                        $newOnDest = (int)$parts[0];
                        $newOnExport = (int)$parts[1];
                    }
                }
                Core::echo(Colors::get('[info]', Colors::FG_light_blue) . ' Commits unique - dest/export: ' . $newOnDest . '/' . $newOnExport, $destWorkDir);

                // Ensure Apache-2.0 licensing and composer version in TEMP export repo, not in destination
                $packageName = $vendor . '/' . $compName;
                $sourceModuleDir = $this->join($sourcePackagesRoot, $pkg);
                $versionToWrite = $this->determineVersionForPackageExtract($tempExportDir, '0.1.0', $packageName, ($newOnExport > 0));

                // Prepare composer from source metadata in TEMP export
                $json = $this->readComposer($sourceModuleDir);
                $prevVerInJson = isset($json['version']) ? (string)$json['version'] : '';
                if ($versionToWrite !== '' && $versionToWrite !== $prevVerInJson) {
                    $json['version'] = $versionToWrite;
                }
                $this->writeComposer($tempExportDir, $json, true);
                $this->copyLicenseIfMissing($tempExportDir, true);
                $this->applyMetaConfigs($tempExportDir, $metaConfigs, true);
                [, $stTemp] = $this->runCmdCapture(['git', 'status', '--porcelain'], $tempExportDir, true);
                if (trim((string)$stTemp) !== '') {
                    $this->gitCommitAll($tempExportDir, 'Update package metadata (composer.json, LICENSE)', true);
                }

                // Update registry with final version
                $versionsRegistry[$packageName] = $versionToWrite;

                // Fetch temp export again to include metadata commit
                $this->gitFetchRemoteSafe($destWorkDir, $remoteName, true);

                // Align final repository state in destination: hard-reset main to export branch and keep only main
                $this->runCmd(['git', 'checkout', '-B', 'main'], $destWorkDir, true);
                $this->runCmd(['git', 'reset', '--hard', $remoteName . '/' . $extractBranch], $destWorkDir, true);
                $this->gitDeleteOtherBranches($destWorkDir, 'main', true);

                // Ensure origin remote for main branch points to GitHub repo
                $this->gitEnsureOriginRemote($destWorkDir, $packageName, 'main', $write);
                // Tag destination repo with the new version
                $this->gitTagVersion($destWorkDir, $versionToWrite, $write);

                if (!$write) {
                    Core::echo(Colors::get('[info]', Colors::FG_light_blue) . ' dry-run completed in temp dirs. dest untouched.');
                }
                // cleanup temps for this module
                if (is_dir($tempExportDir)) {
                    $this->deleteDirRecursive($tempExportDir);
                }
                if (!$write && is_dir($destWorkDir)) {
                    $this->deleteDirRecursive($destWorkDir);
                }
            }
        }

        // Save versions registry so last versions persist even if destination repos are removed
        if ($write) {
            $this->saveVersionsRegistry($versionsRegistry);
        } else {
            Core::echo(Colors::get('[dry]', Colors::FG_light_gray) . ' skip saving versions registry (dry-run)');
        }
        return 'All packages processed';
    }

    /**
     * @cli Remove all branches (including main/master) except the given keep branch in a git repository; keeps only history in the keep branch.
     * @cli params: -keep="" (branch name to keep; all others will be deleted in the repo)
     * @cli params: -repopath="" (path to the repository)
     * @cli params: [-write] (does change the repo)
     */
    protected function rembranches(string $keep, string $path, bool $write = false): string
    {
        $cwd = $this->findGitRoot($path);
        if ($cwd === '') {
            return 'Not a git repository: ' . $path;
        }

        Core::echo(Colors::get('[info]', Colors::FG_light_blue) . ' Repo:', Colors::get($cwd, Colors::FG_light_blue));
        Core::echo(Colors::get('[info]', Colors::FG_light_blue) . ' Keep branch:', Colors::get($keep, Colors::FG_light_blue));
        $this->gitDeleteOtherBranches($cwd, $keep, $write);
        return 'Removed other branches (kept: ' . $keep . ')';
    }


    // --- helpers ---

    private function resolveSourceBranch(string $source, string $preferred): string
    {
        // ensure it's a git repo
        [$c] = $this->runCmd(['git', 'rev-parse', '--is-inside-work-tree'], $source, true);
        if ($c !== 0) {
            return '';
        }
        $effective = '';
        $try = function (string $b) use (&$effective, $source): bool {
            if ($b === '') {
                return false;
            }
            [$c,] = $this->runCmd(['git', 'show-ref', '--verify', 'refs/heads/' . $b], $source, true);
            if ($c === 0) {
                $effective = $b;
                return true;
            }
            [$c2,] = $this->runCmd(['git', 'show-ref', '--verify', 'refs/remotes/origin/' . $b], $source, true);
            if ($c2 === 0) {
                $this->runCmd(['git', 'branch', '--track', $b, 'origin/' . $b], $source, true);
                [$c3,] = $this->runCmd(['git', 'show-ref', '--verify', 'refs/heads/' . $b], $source, true);
                if ($c3 === 0) {
                    $effective = $b;
                    return true;
                }
            }
            return false;
        };
        if (!$try($preferred)) {
            [$cHead, $out] = $this->runCmdCapture(['git', 'symbolic-ref', '--quiet', '--short', 'HEAD'], $source, true);
            if ($cHead === 0) {
                $head = trim((string)$out);
                $try($head);
            }
        }
        if ($effective === '') {
            foreach (["main", "master", "develop", "trunk"] as $cand) {
                if ($try($cand)) {
                    break;
                }
            }
        }
        return $effective;
    }


    private function getLatestCommitTimeForPath(string $repoDir, string $branch, string $path): int
    {
        // Uses git log to find the latest commit timestamp (unix epoch) on given branch affecting specified path
        // Returns 0 when not found or on error
        [$rc, $out] = $this->runCmdCapture(['git', 'log', '-1', '--format=%ct', $branch, '--', $path], $repoDir, true);
        if ($rc !== 0) {
            return 0;
        }
        $ts = (int)trim((string)$out);
        return max($ts, 0);
    }

    private function getLatestCommitMessage(string $repoDir, string $branch, string $path, string $format = '%s%n%n%b'): string
    {
        // Returns latest commit subject affecting path, empty string on error
        [$rc, $out] = $this->runCmdCapture(
          ['git', 'log', '-1', '--format=' . $format, $branch, '--', $path],
          $repoDir,
          true
        );

        if ($rc !== 0) {
            return '';
        }

        return trim((string)$out);
    }

    private function getLatestCommitTime(string $repoDir): int
    {
        [$rc, $out] = $this->runCmdCapture(['git', 'log', '-1', '--format=%ct'], $repoDir, true);
        if ($rc !== 0) {
            return 0;
        }
        $ts = (int)trim((string)$out);
        return max($ts, 0);
    }

    private function join(string $a, string $b): string
    {
        // Join path segments without introducing duplicate or leading separators
        if ($a === '') {
            return ltrim($b, "\\/");
        }
        if ($b === '') {
            return rtrim($a, "\\/");
        }
        $sep = '/';
        return rtrim($a, "\\/") . $sep . ltrim($b, "\\/");
    }

    private function toGitPath(string $p): string
    {
        return str_replace('\\', '/', $p);
    }

    private function findGitRoot(string $startDir): string
    {
        // Try using git to ask for the toplevel (works from any subdir of repo)
        [$code, $out] = $this->runCmdCapture(['git', 'rev-parse', '--show-toplevel'], $startDir, true);
        if ($code === 0) {
            $root = trim((string)$out);
            if ($root !== '' && is_dir($root)) {
                return $root;
            }
        }
        // Fallback: search upwards for .git directory
        $dir = rtrim($startDir, "\\/");
        for ($i = 0; $i < 20 && $dir !== '' && $dir !== DIRECTORY_SEPARATOR; $i++) {
            if (is_dir($dir . DIRECTORY_SEPARATOR . '.git') || is_file($dir . DIRECTORY_SEPARATOR . '.git')) {
                return $dir;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }
        return '';
    }

    private function relPath(string $from, string $to): string
    {
        // Return path of $to relative to $from; '' if identical; if not a subpath, return best-effort relative
        $fromReal = rtrim(realpath($from) ?: $from, "\\/");
        $toReal = rtrim(realpath($to) ?: $to, "\\/");
        // Case-insensitive on Windows
        $fromCmp = stripos(PHP_OS_FAMILY, 'Windows') !== false ? strtolower($fromReal) : $fromReal;
        $toCmp = stripos(PHP_OS_FAMILY, 'Windows') !== false ? strtolower($toReal) : $toReal;
        if ($fromCmp === $toCmp) {
            return '';
        }
        if (strpos($toCmp, $fromCmp . DIRECTORY_SEPARATOR) === 0) {
            return substr($toReal, strlen($fromReal) + 1);
        }
        // Fallback compute relative via segments
        $fromParts = preg_split('~[\\/]~', $fromReal, -1, PREG_SPLIT_NO_EMPTY);
        $toParts = preg_split('~[\\/]~', $toReal, -1, PREG_SPLIT_NO_EMPTY);
        $len = min(count($fromParts), count($toParts));
        $i = 0;
        while ($i < $len && ((stripos(PHP_OS_FAMILY, 'Windows') !== false ? strtolower($fromParts[$i]) : $fromParts[$i]) === (stripos(PHP_OS_FAMILY, 'Windows') !== false ? strtolower($toParts[$i]) : $toParts[$i]))) {
            $i++;
        }
        $up = array_fill(0, count($fromParts) - $i, '..');
        $down = array_slice($toParts, $i);
        $rel = implode(DIRECTORY_SEPARATOR, array_merge($up, $down));
        return $rel;
    }

    private function filterModules(array $all, string $modules): array
    {
        $modules = trim($modules ?? '');
        if ($modules === '') {
            return $all;
        }
        $norm = preg_replace('/\s+/', ' ', str_replace([','], ' ', $modules));
        $wanted = array_filter(array_map('trim', explode(' ', $norm)), fn($v) => $v !== '');
        $set = array_flip($wanted);
        $sel = array_values(array_filter($all, fn($p) => isset($set[$p])));
        $missing = array_values(array_filter($wanted, fn($m) => !in_array($m, $all, true)));
        if (!empty($missing)) {
            Core::echo(Colors::get('[warn]', Colors::FG_yellow) . ' requested modules not found', Colors::get(implode(', ', $missing), Colors::FG_yellow));
        }
        return $sel;
    }

    // --- git error handling helpers ---
    private function isTempRemoteName(string $remote): bool
    {
        $r = trim($remote);
        return $r === 'export' || $r === 'project_export';
    }

    private function classifyGitFetchResult(int $rc, string $output, ?string $remoteOrPath = null): array
    {
        $txt = strtolower((string)$output);
        $benign = false;
        $summary = '';
        if ($rc !== 0) {
            if (str_contains($txt, 'would clobber existing tag')) {
                $benign = true;
                $summary = 'Remote has immutable tags already present locally; skipping tag updates';
            }
            if (str_contains($txt, 'does not appear to be a git repository') || str_contains($txt, 'not a git repository')) {
                if ($remoteOrPath !== null && $this->isTempRemoteName($remoteOrPath)) {
                    $benign = true;
                    $summary = 'Temporary export remote no longer exists; fetch skipped';
                }
            }
            if (!$benign && str_contains($txt, 'could not fetch')) {
                if (str_contains($txt, 'project_export') || str_contains($txt, 'export')) {
                    $benign = true;
                    $summary = 'Temporary export remote unavailable; fetch skipped';
                }
            }
        }
        return [$benign ? false : ($rc !== 0), $summary]; // fatal flag, summary
    }

    private function gitFetchAllSafe(string $repoPath, bool $write, ?string $remoteOrPath = null): void
    {
        [$rc, $out] = $this->runCmdCapture(['git', 'fetch', '--all', '--tags'], $repoPath, $write);
        [$fatal, $summary] = $this->classifyGitFetchResult($rc, (string)$out, $remoteOrPath);
        if ($fatal) {
            // Re-run in verbose mode to show full error via standard runner
            $this->runCmd(['git', 'fetch', '--all', '--tags'], $repoPath, $write);
            return;
        }
        if ($rc !== 0) {
            // Reduce noise: print one concise info line
            Core::echo(Colors::get('[info]', Colors::FG_light_blue) . ' git fetch note:', Colors::get($summary !== '' ? $summary : 'non-fatal fetch issue', Colors::FG_light_blue));
        }
    }

    private function gitFetchRemoteSafe(string $repoPath, string $remote, bool $write): void
    {
        [$rc, $out] = $this->runCmdCapture(['git', 'fetch', $remote], $repoPath, $write);
        [$fatal, $summary] = $this->classifyGitFetchResult($rc, (string)$out, $remote);
        if ($fatal) {
            $this->runCmd(['git', 'fetch', $remote], $repoPath, $write);
            return;
        }
        if ($rc !== 0) {
            Core::echo(Colors::get('[info]', Colors::FG_light_blue) . ' git fetch ' . $remote . ':', Colors::get($summary !== '' ? $summary : 'non-fatal fetch issue', Colors::FG_light_blue));
        }
    }

    private function gitCloneBranch(string $source, string $branch, string $targetPath, bool $write = false): void
    {
        // Clone the entire repo (local path or remote) into targetPath on the specified branch
        // Ensure targetPath does not already contain a .git; if exists, reuse by fetching/checkout
        if (is_dir($targetPath) && is_dir($this->join($targetPath, '.git'))) {
            $this->gitFetchAllSafe($targetPath, $write);
            if ($branch !== '') {
                $this->runCmd(['git', 'checkout', '-B', $branch], $targetPath, $write);
                $this->runCmd(['git', 'pull', '--ff-only'], $targetPath, $write);
            }
            return;
        }
        // Create parent dir then clone
        $parent = dirname(rtrim($targetPath, "\\/"));
        if ($write) {
            Core::dirCreate($parent, false);
        } else {
            Core::echo(Colors::get('[dry]', Colors::FG_light_gray) . ' ensure dir ' . $parent);
        }
        $cmd = ['git', 'clone'];
        if ($branch !== '') {
            $cmd[] = '-b';
            $cmd[] = $branch;
        }
        $cmd[] = $source;
        $cmd[] = $targetPath;
        $this->runCmd($cmd, null, $write);
    }


    private function gitDeleteOtherBranches(string $repoPath, string $keep, bool $write = false): void
    {
        // Switch to keep branch to avoid deleting the current branch
        $this->runCmd(['git', 'checkout', '-B', $keep], $repoPath, $write);
        // Delete all local branches except keep (including main/master if different)
        [, $out] = $this->runCmdCapture(['git', 'branch', '--format=%(refname:short)'], $repoPath, $write);
        $lines = preg_split("/\r?\n/", (string)$out);
        foreach ($lines as $b) {
            $b = trim($b);
            if ($b === '' || $b === $keep) {
                continue;
            }
            Core::echo(Colors::get('[info]', Colors::FG_light_blue) . ' Deleting local branch: ' . $b);
            $this->runCmd(['git', 'branch', '-D', $b], $repoPath, $write);
        }
        // Attempt to remove remote branches too (origin/*) except keep if remote exists
        [$rc, $rem] = $this->runCmdCapture(['git', 'remote'], $repoPath, $write);
        if ($rc === 0) {
            $remotes = preg_split("/\r?\n/", (string)$rem);
            $remotes = array_filter(array_map('trim', $remotes), fn($r) => $r !== '');
            foreach ($remotes as $remote) {
                // Skip temporary local remotes used for export to avoid pushing into checked-out branches
                if ($remote === 'export' || $remote === 'project_export') {
                    Core::echo(Colors::get('[info]', Colors::FG_light_blue) . ' Skipping branch deletions for temporary remote: ' . $remote);
                    continue;
                }
                // get remote branches
                [, $rlist] = $this->runCmdCapture(['git', 'branch', '-r', '--format=%(refname:short)'], $repoPath, $write);
                $rLines = preg_split("/\r?\n/", (string)$rlist);
                foreach ($rLines as $rb) {
                    $rb = trim($rb);
                    if ($rb === '' || str_starts_with($rb, $remote . '/' . $keep)) {
                        continue;
                    }
                    // Only delete branches that belong to this remote
                    if (strpos($rb, $remote . '/') === 0) {
                        $short = substr($rb, strlen($remote) + 1);
                        Core::echo(Colors::get('[info]', Colors::FG_light_blue) . ' Deleting remote branch: ' . $rb);
                        $this->runCmd(['git', 'push', $remote, '--delete', $short], $repoPath, $write);
                    }
                }
                // Also set the HEAD of the remote to keep if supported (optional best-effort)
                $this->runCmd(['git', 'remote', 'set-head', $remote, $keep], $repoPath, $write);
            }
        }
        // Expire reflog entries and aggressively prune objects to fully clean up deleted branches
        $this->runCmd(['git', 'reflog', 'expire', '--expire=now', '--all'], $repoPath, $write);
        $this->runCmd(['git', 'gc', '--prune=now', '--aggressive'], $repoPath, $write);
    }

    private function gitEnsureBranch(string $repoPath, string $branch, bool $write = false): void
    {
        $this->runCmd(['git', 'checkout', '-B', $branch], $repoPath, $write);
    }

    private function gitCommitAll(string $repoPath, string $message, bool $write = false): void
    {
        $this->runCmd(['git', 'add', '-A'], $repoPath, $write);
        $this->runCmd(['git', 'commit', '-m', $message], $repoPath, $write);
    }

    private function hasWorkingTreeChanges(string $repoPath, bool $write = false): bool
    {
        [, $out] = $this->runCmdCapture(['git', 'status', '--porcelain'], $repoPath, $write);
        return trim((string)$out) !== '';
    }

    private function gitEnsureOriginRemote(string $repoPath, string $packageName, string $branch = 'main', bool $write = false): void
    {
        // Build GitHub URL using cryodrift vendor and the package short name (part after vendor/ if provided)
        $pkg = trim($packageName);
        $slashPos = strpos($pkg, '/');
        if ($slashPos !== false) {
            $pkg = substr($pkg, $slashPos + 1);
        }
        if ($pkg === '') {
            return; // nothing to do
        }
        $url = 'https://github.com/cryodrift/' . $pkg . '.git';

        // Check if origin exists
        [, $rem] = $this->runCmdCapture(['git', 'remote'], $repoPath, $write);
        $remotes = preg_split("/\r?\n/", (string)$rem);
        $remotes = array_filter(array_map('trim', $remotes), fn($r) => $r !== '');
        $hasOrigin = in_array('origin', $remotes, true);

        if (!$write) {
            if (!$hasOrigin) {
                Core::echo(Colors::get('[dry]', Colors::FG_light_gray) . ' add remote origin ' . $url, $repoPath);
            } else {
                Core::echo(Colors::get('[dry]', Colors::FG_light_gray) . ' set remote origin url ' . $url, $repoPath);
            }
            // Show intended local tracking configuration and upstream behavior
            Core::echo(Colors::get('[dry]', Colors::FG_light_gray) . ' config: push.autoSetupRemote=true', $repoPath);
            Core::echo(Colors::get('[dry]', Colors::FG_light_gray) . ' config: branch.' . $branch . '.remote=origin', $repoPath);
            Core::echo(Colors::get('[dry]', Colors::FG_light_gray) . ' config: branch.' . $branch . '.merge=refs/heads/' . $branch, $repoPath);
            Core::echo(Colors::get('[dry]', Colors::FG_light_gray) . ' set upstream to origin/' . $branch . ' for ' . $branch . ' (if remote branch exists)', $repoPath);
            return;
        }

        if (!$hasOrigin) {
            $this->runCmd(['git', 'remote', 'add', 'origin', $url], $repoPath, $write);
        } else {
            // Always refresh URL in case it points to a temp/old location
            $this->runCmd(['git', 'remote', 'set-url', 'origin', $url], $repoPath, $write);
        }

        // Make sure we have up-to-date refs
        $this->gitFetchAllSafe($repoPath, $write);

        // Ensure local branch exists
        $this->runCmd(['git', 'checkout', '-B', $branch], $repoPath, $write);

        // Configure push auto-setup and local tracking to avoid upstream errors on first push
        $this->runCmd(['git', 'config', 'push.autoSetupRemote', 'true'], $repoPath, $write);
        // Pre-configure branch tracking locally (works even if the remote branch doesn't exist yet)
        $this->runCmd(['git', 'config', 'branch.' . $branch . '.remote', 'origin'], $repoPath, $write);
        $this->runCmd(['git', 'config', 'branch.' . $branch . '.merge', 'refs/heads/' . $branch], $repoPath, $write);

        // Check if the remote branch exists before setting HEAD/upstream
        [$hasRemoteRef] = $this->runCmd(['git', 'show-ref', '--verify', 'refs/remotes/origin/' . $branch], $repoPath, $write);
        if ($hasRemoteRef === 0) {
            // Set remote HEAD and upstream tracking only when the remote branch exists
            $this->runCmd(['git', 'remote', 'set-head', 'origin', $branch], $repoPath, $write);
            $this->runCmd(['git', 'branch', '--set-upstream-to=origin/' . $branch, $branch], $repoPath, $write);
        } else {
            // Avoid noisy advice message when upstream doesn't exist yet
            $this->runCmd(['git', 'config', 'advice.setUpstreamFailure', 'false'], $repoPath, $write);
        }
    }


    private function readComposer(string $sourcePath): array
    {
        $out = [];
        $composerPath = $this->join($sourcePath, 'composer.json');
        if (is_file($composerPath)) {
            $raw = Core::fileReadOnce($composerPath);
            try {
                $out = Core::jsonRead($raw);
            } catch (\JsonException $ex) {
                Core::echo(Colors::get('[Error]', Colors::FG_red), $ex->getMessage(), $sourcePath);
            }
        }
        return $out;
    }

    private function writeComposer(string $repoPath, array $json, bool $write = false): void
    {
        $composerPath = $this->join($repoPath, 'composer.json');
        $jsonText = Core::jsonWrite($json, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

        if (!$write) {
            Core::echo(Colors::get('[dry]', Colors::FG_light_gray) . ' write composer.json', Colors::get($composerPath, Colors::FG_light_gray));
            // Show a short preview line to aid the user
            Core::echo(Colors::get('[dry]', Colors::FG_light_gray) . ' content preview:', Colors::get($jsonText, Colors::FG_yellow));
            return;
        }

        Core::fileWrite($composerPath, $jsonText, 0, true);
    }

    private function readComposerProjectTemplate(string $templatePath): array
    {
        $out = [];
        if (is_file($templatePath)) {
            try {
                $raw = Core::fileReadOnce($templatePath);
                $out = Core::jsonRead($raw);
            } catch (\JsonException $ex) {
                Core::echo(Colors::get('[Error]', Colors::FG_red), $ex->getMessage(), $templatePath);
            }
        }
        return $out;
    }

    private function ensureDirsWithGitkeep(string $baseDir, array $dirs, bool $write, bool $silent = false): void
    {
        foreach ($dirs as $d) {
            $dirPath = $this->join($baseDir, $d);
            if ($write) {
                Core::dirCreate($dirPath, false);
                Core::fileWrite($this->join($dirPath, '.gitkeep'), '', 0, true);
            } elseif (!$silent) {
                Core::echo(Colors::get('[dry]', Colors::FG_light_gray) . ' ensure dir ' . $dirPath . ' with .gitkeep');
            }
        }
    }

    private function ensureLicenseCached(): string
    {
        $cachePath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'apache-2.0.LICENSE.txt';
        if (!is_file($cachePath)) {
            try {
                $txt = Core::fileReadOnce('https://www.apache.org/licenses/LICENSE-2.0.txt', true, [
                  'http' => [
                    'timeout' => 15,
                    'user_agent' => 'extractcomp/1.0'
                  ]
                ]);
            } catch (\Throwable $e) {
                // Fallback minimal notice if network unavailable
                $txt = "Apache License\nVersion 2.0, January 2004\nhttps://www.apache.org/licenses/\n\n" .
                  "Please obtain the full license text from the URL above.";
            }
            Core::fileWrite($cachePath, $txt, 0, true);
        }
        return $cachePath;
    }

    private function copyLicenseIfMissing(string $repoPath, bool $write = false): void
    {
        $targets = [
          $this->join($repoPath, 'LICENSE'),
          $this->join($repoPath, 'LICENSE.txt'),
          $this->join($repoPath, 'LICENSE.md'),
        ];
        $exists = false;
        foreach ($targets as $t) {
            if (is_file($t)) {
                $exists = true;
                break;
            }
        }
        if ($exists) {
            return;
        }
        $dest = $targets[0];
        if (!$write) {
            Core::echo(Colors::get('[dry]', Colors::FG_light_gray) . ' copy Apache-2.0 LICENSE to', Colors::get($dest, Colors::FG_light_gray));
            return;
        }
        $src = $this->ensureLicenseCached();
        try {
            $data = Core::fileReadOnce($src, true);
            Core::fileWrite($dest, $data, 0, true);
        } catch (\Throwable $e) {
            Core::echo(Colors::get('[warn]', Colors::FG_yellow) . ' could not write LICENSE: ' . $e->getMessage(), $repoPath);
        }
    }

    private function sanitizeMappingText(string $text): string
    {
        // Ensure single-line mapping entries; represent newlines visibly
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = str_replace(["\n"], "\\n", $text);
        return $text;
    }

    private function makeRewriteFileFromPairs(array $search, array $replace): string
    {
        $s = array_values(array_filter(array_map(fn($v) => trim((string)$v), $search), fn($v) => $v !== ''));
        $r = array_values(array_filter(array_map(fn($v) => trim((string)$v), $replace), fn($v) => $v !== ''));
        $count = min(count($s), count($r));
        if ($count === 0) {
            Core::echo(Colors::get('[warn]', Colors::FG_yellow) . ' Deprecated -gitsearch/-gitreplace provided but could not build pairs (need both).');
            return '';
        }
        if (count($s) !== count($r)) {
            Core::echo(Colors::get('[warn]', Colors::FG_yellow) . ' Search/replace count mismatch; using first ' . $count . ' pairs.');
        }
        $tmp = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'extractcomp_rewrite_' . Core::getUid(8) . '.txt';
        $lines = '';
        for ($i = 0; $i < $count; $i++) {
            $old = $this->sanitizeMappingText($s[$i]);
            $new = $this->sanitizeMappingText($r[$i]);
            $lines .= 'literal:' . $old . '==>' . $new . "\n";
        }
        try {
            Core::fileWrite($tmp, $lines, 0, true);
            Core::echo(Colors::get('[info]', Colors::FG_light_blue) . ' Created temporary rewrite file from legacy params', $tmp);
        } catch (\Throwable $e) {
            Core::echo(Colors::get('[error]', Colors::FG_light_red) . ' Failed to write temp rewrite file: ' . $e->getMessage());
            return '';
        }
        return $tmp;
    }

    private function splitPair(string $pair): array
    {
        $pair = trim($pair);
        if ($pair === '') {
            return ['', ''];
        }
        $pos = strrpos($pair, ':');
        if ($pos === false) {
            return [$pair, '*'];
        }
        return [substr($pair, 0, $pos), substr($pair, $pos + 1)];
    }

    private function bumpPatchVersion(string $ver, string $fallback = '0.1.0'): string
    {
        $v = trim((string)$ver);
        if ($v === '') {
            return $fallback;
        }
        if (!preg_match('/^(\d+)\.(\d+)\.(\d+)/', $v, $m)) {
            return $fallback;
        }
        $maj = (int)$m[1];
        $min = (int)$m[2];
        $pat = (int)$m[3] + 1;
        return $maj . '.' . $min . '.' . $pat;
    }


    private function cleanupTempDirs(array $paths): void
    {
        foreach ($paths as $path) {
            if (!is_string($path) || $path === '') {
                continue;
            }
            // Only remove directories inside the system temp directory for safety
            if (!$this->isUnderSysTemp($path)) {
                Core::echo(Colors::get('[warn]', Colors::FG_yellow) . ' skip cleanup (not in system temp): ' . $path);
                continue;
            }
            if (is_dir($path)) {
                $this->deleteDirRecursive($path);
                Core::echo(Colors::get('[cleanup]', Colors::FG_light_gray) . ' removed temp dir: ' . $path);
            } elseif (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function isUnderSysTemp(string $path): bool
    {
        $p = realpath($path);
        $t = realpath(sys_get_temp_dir());
        if ($p === false || $t === false) {
            return false;
        }
        // normalize separators and compare prefix
        $p = rtrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $p), DIRECTORY_SEPARATOR);
        $t = rtrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $t), DIRECTORY_SEPARATOR);
        return $p === $t || str_starts_with($p . DIRECTORY_SEPARATOR, $t . DIRECTORY_SEPARATOR);
    }

    private function deleteDirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS);
        $ri = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($ri as $file) {
            /** @var \SplFileInfo $file */
            $path = $file->getPathname();
            if ($file->isDir() && !$file->isLink()) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function buildFilterRepoCommand(string $moduleRelGit, array $gitrewritefile, array $gitsearch, array $gitreplace, array $gitmetaArgs = [], ?string $workDirForDocker = null): array
    {
        // Build git filter-repo argument list; later decide whether to run via docker or external wrapper
        $modulePath = rtrim($moduleRelGit, '/') . '/';
        // Use --path to limit history to the module, and --path-rename to move its contents to repo root
        $args = ['--path', $modulePath, '--path-rename', $modulePath . ':', '--force'];
        $rewriteFiles = [];
        if (!empty($gitrewritefile)) {
            foreach ($gitrewritefile as $rf) {
                $rf = trim((string)$rf);
                if ($rf !== '') {
                    $rewriteFiles[] = $rf;
                }
            }
        } else {
            // Backward-compat support: convert deprecated -gitsearch/-gitreplace pairs
            if (!empty($gitsearch) || !empty($gitreplace)) {
                $tmpFile = $this->makeRewriteFileFromPairs($gitsearch, $gitreplace);
                if ($tmpFile !== '') {
                    $rewriteFiles[] = $tmpFile;
                }
            }
        }
        foreach ($rewriteFiles as $rf) {
            $args[] = '--replace-text';
            $args[] = $rf;
        }
        // Derive desired name/email from metaconfig tokens to build filter-repo callbacks if needed.
        $desiredName = '';
        $desiredEmail = '';
        $hasNameCb = false;
        $hasEmailCb = false;
        // First pass: inspect flags for explicit callbacks and collect desired values from non-flag tokens
        foreach ($gitmetaArgs as $gm0) {
            $t = trim((string)$gm0);
            if ($t === '') {
                continue;
            }
            if (preg_match('/^\s*--name-callback(\s|$)/', $t)) {
                $hasNameCb = true;
            }
            if (preg_match('/^\s*--email-callback(\s|$)/', $t)) {
                $hasEmailCb = true;
            }
            // capture mappings like "authorName X", "authorEmail Y", "user.name X", "user.email Y"
            if (!preg_match('/^\s*-{1,2}/', $t)) {
                $sp = strpos($t, ' ');
                if ($sp !== false) {
                    $key = trim(substr($t, 0, $sp));
                    $val = trim(substr($t, $sp + 1));
                    if ($val !== '') {
                        switch ($key) {
                            case 'authorName':
                            case 'committerName':
                            case 'user.name':
                                $desiredName = $val;
                                break;
                            case 'authorEmail':
                            case 'committerEmail':
                            case 'user.email':
                                $desiredEmail = $val;
                                break;
                        }
                    }
                }
            }
        }
        // If explicit callbacks are not provided but desired values are present, add callbacks.
        if (!$hasNameCb && $desiredName !== '') {
            $args[] = '--name-callback';
            // Use a single-quoted Python bytes literal with 'return ' to satisfy filter-repo; rely on quoting to keep it shell-safe
            $args[] = "return b'" . str_replace("'", "\\'", $desiredName) . "'";
        }
        if (!$hasEmailCb && $desiredEmail !== '') {
            $args[] = '--email-callback';
            $args[] = "return b'" . str_replace("'", "\\'", $desiredEmail) . "'";
        }
        // Pass through only valid filter-repo style flags (starting with '-') to git-filter.cmd.
        // Tokens like 'authorName John' are treated as local git config via buildMetaConfigs/applyMetaConfigs and are NOT forwarded here.
        foreach ($gitmetaArgs as $gm) {
            $gm = trim((string)$gm);
            if ($gm === '') {
                continue;
            }
            // Only forward if it looks like a flag (e.g., --mailmap path, --replace-message file, etc.)
            if (!preg_match('/^\s*-{1,2}[^\s]/', $gm)) {
                // skip non-flag tokens to avoid "git-filter-repo: error: unrecognized arguments"
                continue;
            }
            // split by whitespace to preserve multiple arguments (quotes should be preserved by caller)
            $parts = preg_split('/\s+/', $gm);
            if (is_array($parts)) {
                foreach ($parts as $p) {
                    if ($p !== '') {
                        $args[] = $p;
                    }
                }
            }
        }
        // Always run git filter-repo via Docker (gitfilter is not optional)
        $srcDir = $this->gitfilter_src;
        $srcDir = rtrim($srcDir, "\\/");
        if ($srcDir === '' || !is_dir($srcDir)) {
            throw new \RuntimeException(Core::toLog('EXTRACTCOMP_GITFILTER_SRC not set or not a directory;', $srcDir));
        }
        $work = $workDirForDocker !== null ? rtrim($workDirForDocker, "\\/") : getcwd();
        $cmd = [
          'docker',
          'run',
          '--rm',
          '-v',
          $work . ':/app',
          '-v',
          $srcDir . ':/src',
          '-w',
          '/app',
          'python:latest',
          'python3',
          '/src/git-filter-repo',
        ];
        $cmd = array_merge($cmd, $args);
        return $cmd;
    }

    private function gitTagVersion(string $repoPath, string $version, bool $write = false): void
    {
        $tag = trim((string)$version);
        if ($tag === '') {
            return;
        }
        if (!$write) {
            Core::echo(Colors::get('[dry]', Colors::FG_light_gray) . ' tag HEAD with', Colors::get($tag, Colors::FG_light_gray));
            return;
        }
        // Create or update lightweight tag pointing to current HEAD
        $this->runCmd(['git', 'tag', '-f', $tag], $repoPath, $write);
    }

    private function applyMetaConfigs(string $repoDir, array $metaConfigs, bool $write = false): void
    {
        if (empty($metaConfigs)) {
            return;
        }
        foreach ($metaConfigs as $mk => $mv) {
            $this->runCmd(['git', 'config', $mk, $mv], $repoDir, $write);
        }
    }

    private function buildMetaConfigs(array $gitmeta): array
    {
        // parse gitmeta entries as arbitrary git config key/value pairs (e.g., "user.name John Doe")
        // Also accept aliases: authorName/authorEmail/committerName/committerEmail -> user.name/user.email
        $metaConfigs = [];
        foreach ($gitmeta as $gm) {
            $gm = trim((string)$gm);
            if ($gm === '') {
                continue;
            }
            $spacePos = strpos($gm, ' ');
            if ($spacePos === false) {
                continue;
            }
            $key = trim(substr($gm, 0, $spacePos));
            $val = trim(substr($gm, $spacePos + 1));
            if ($key === '' || $val === '') {
                continue;
            }
            // Map known aliases
            $aliasMap = [
              'authorName' => 'user.name',
              'authorEmail' => 'user.email',
              'committerName' => 'user.name',
              'committerEmail' => 'user.email',
            ];
            if (isset($aliasMap[$key])) {
                $key = $aliasMap[$key];
            }
            $metaConfigs[$key] = $val;
        }
        return $metaConfigs;
    }


    private function getPackageShortName(string $package): string
    {
        $pkg = trim((string)$package);
        // Extract part after vendor/ if provided (vendor/name -> name)
        $slashPos = strpos($pkg, '/');
        if ($slashPos !== false) {
            $pkg = substr($pkg, $slashPos + 1);
        }
        $pkg = trim($pkg);
        return $pkg !== '' ? $pkg : 'projecttpl';
    }

    /**
     * @cli Create a skeleton project repository by copying a curated set of files from an existing repo.
     */
    protected function project(
      string $dest,
      string $branch,
      array $gitmeta = [],
      string $includesfile = '',
      string $composerfile = '',
      string $rootdir = '',
      string $prjdir = '',
      bool $push = false,
      bool $write = false
    ): string {
        $dest = $this->fixPath($dest);
        $rootdir = $rootdir ?: Main::$rootdir;
        $rootdir = $this->fixPath($rootdir);
        $prjdir = trim($this->fixPath($prjdir), '/');

        if ($composerfile === '') {
            $composerfile = $this->join(__DIR__, 'composerproject.json');
        }

        $composerfile = $this->fixPath($composerfile);

        // Read composer.json from template
        $composer = $this->readComposerProjectTemplate($composerfile);

        $package = $composer['name'];
        // Place repository in a subfolder named after the package short name (vendor/name -> name)
        $pkgShort = $this->getPackageShortName($package);
        $destRepoRoot = $this->join($dest, $pkgShort);
        $destRepoRoot = $this->fixPath($destRepoRoot);

        // Initialize or reuse git repo at destRepoRoot
        $destGitDir = $this->join($destRepoRoot, '.git');
        $repoExists = is_dir($destGitDir);

        // Ensure destination directory exists (only when writing)
        if ($write) {
            try {
                Core::dirCreate($destRepoRoot, false);
            } catch (\Throwable $e) {
                return 'Cannot create destination: ' . $destRepoRoot . ' (' . $e->getMessage() . ')';
            }
        } else {
            Core::echo(Colors::get('[dry]', Colors::FG_light_gray) . ' ensure dir ' . $destRepoRoot);
        }


        // If repository already exists, we will copy files and bump version only if there are changes
        if (!$repoExists) {
            // Initialize new repository and set branch
            $this->runCmd(['git', 'init'], $destRepoRoot, $write);
            $this->runCmd(['git', 'checkout', '-B', $branch], $destRepoRoot, $write);
        } else {
            // Ensure desired branch exists/checked out
            $this->gitEnsureBranch($destRepoRoot, $branch, $write);
            $this->runCmd(['git', 'pull'], $destRepoRoot, $write);
        }

        // Apply git meta (user.name, user.email, etc.)
        $metaConfigs = $this->buildMetaConfigs($gitmeta);
        $this->applyMetaConfigs($destRepoRoot, $metaConfigs, $write);

        if ($includesfile) {
            $includes = Core::fileInclude($includesfile, []);
        } else {
            $includes = $this->project_include;
        }
        // first push to sync
        if ($push) {
            $this->runCmd(['git', 'push'], $destRepoRoot, $write);
        }

        // Copy listed items
        foreach ($this->listProjectItems($destRepoRoot, $includes, $rootdir) as $from => $to) {
            $from = $this->fixPath($from);
            $to = $this->fixPath($to);
            if (!is_dir($from)) {
                $to = strtr($to, '\\', '/');
                if ($prjdir) {
                    $to = str_replace('/' . $prjdir, '', $to);
                }
                if ($write) {
                    $shortfile = trim(str_replace($rootdir, '', $from), '/');
                    if ($composerfile !== $shortfile) {
                        $data = Core::fileReadOnce($from);
                        Core::fileWrite($to, $data, 0, true);
                        Core::echo(Colors::get('[file]', Colors::FG_light_green) . ' copy ' . $from . ' => ' . $to);
                        if (str_contains($from, 'composer.json')) {
                            Core::echo(Colors::get('[composer.json]', Colors::FG_red), $composerfile, $shortfile);
                        }
                    }
                } else {
                    Core::echo(Colors::get('[file]', Colors::FG_light_gray) . ' copy ' . $from . ' => ' . $to);
                }
            }
        }

        // Decide if working tree has changes after copying files
        $hasChanges = $this->hasWorkingTreeChanges($destRepoRoot, $write);

        // Compute version: bump only when files changed or registry has higher version
        $versionToWrite = $this->determineVersionForPackageExtract($destRepoRoot, '0.1.0', $package, $hasChanges);

        $commitmsg = $this->getLatestCommitMessage($rootdir, $branch, $this->join($rootdir, Main::path($prjdir)), '%B');
        // Only write/update composer.json version when other files have changed
        if ($hasChanges) {
            Core::echo(Colors::get('[changed]', Colors::FG_light_red),'to new version', $versionToWrite);
            $composer['version'] = $versionToWrite;
            $this->writeComposer($destRepoRoot, $composer, $write);
            // Commit all changes we made
            $this->gitCommitAll($destRepoRoot, $commitmsg, $write);

            // Ensure the repository has an origin remote configured for the target branch
            $this->gitEnsureOriginRemote($destRepoRoot, $package, $branch, $write);
            // Tag the last commit (or current HEAD) with the computed version
            $this->gitTagVersion($destRepoRoot, $versionToWrite, $write);

            // Load versions registry to persist last known versions across exports
            $versionsRegistry = $this->loadVersionsRegistry();
            $versionsRegistry[$package] = $versionToWrite;
            // Save versions registry so last versions persist even if destination repos are removed
            if ($write) {
                $this->saveVersionsRegistry($versionsRegistry);
            } else {
                Core::echo(Colors::get('[dry]', Colors::FG_light_gray) . ' skip saving versions registry (dry-run)');
            }
        } else {
            Core::echo(Colors::get('[info]', Colors::FG_light_blue) . ' No file changes detected; skipping composer.json write and commit.');
        }
        if ($push) {
            $this->runCmd(['git', 'push'], $destRepoRoot, $write);
            $this->runCmd(['git', 'push', '--tags'], $destRepoRoot, $write);
        }


        return 'composer project at: ' . $destRepoRoot;
    }

    private function fixPath(string $path): string
    {
        $path = strtr($path, '\\', '/');
        $path = rtrim($path, '/');
        return $path;
    }

    /**
     */
    private function listProjectItems(string $destroot, array $includes, string $rootdir = ''): array
    {
        $out = [];
        foreach ($includes as $key => $value) {
            if (is_numeric($key)) {
                $destdir = Main::path($this->join($rootdir, $value));
                if (is_dir($destdir)) {
                    Core::iterate(Core::dirList($destdir), function (\SplFileInfo $f) use ($destroot, &$out) {
                        $out[$f->getPathname()] = $this->join($destroot, $f->getPathname());
                    });
                } else {
                    $out[$destdir] = $this->join($destroot, $value);
                }
            } else {
                $out[Main::path($this->join($rootdir, $key))] = $this->join($destroot, $value);
            }
        }
        return $out;
    }

    private function compareSemver(string $a, string $b): int
    {
        $pa = [];
        $pb = [];
        if (preg_match('/^(\d+)\.(\d+)\.(\d+)/', $a, $pa) !== 1) {
            return $b !== '' ? -1 : 0;
        }
        if (preg_match('/^(\d+)\.(\d+)\.(\d+)/', $b, $pb) !== 1) {
            return 1;
        }
        $ma = (int)$pa[1];
        $mb = (int)$pb[1];
        if ($ma !== $mb) {
            return $ma < $mb ? -1 : 1;
        }
        $na = (int)$pa[2];
        $nb = (int)$pb[2];
        if ($na !== $nb) {
            return $na < $nb ? -1 : 1;
        }
        $pa3 = (int)$pa[3];
        $pb3 = (int)$pb[3];
        if ($pa3 !== $pb3) {
            return $pa3 < $pb3 ? -1 : 1;
        }
        return 0;
    }

    private function determineVersionForPackage(string $repoDir, string $initialVersion, string $packagename): string
    {
        // Read registry entry by package name
        $regVal = '';
        try {
            $reg = $this->loadVersionsRegistry();
            if ($packagename !== '' && isset($reg[$packagename])) {
                $regVal = $reg[$packagename];
            }
        } catch (\Throwable $e) {
            // ignore registry read errors; proceed with composer.json
        }

        // Composer version in destination
        $composer = $this->readComposer($repoDir);
        $compVer = $composer['version'] ?? '';

        // Pick the higher base between registry value and composer.json
        $base = $regVal !== '' ? $regVal : $compVer;
        if ($regVal !== '' && $compVer !== '') {
            if ($this->compareSemver($compVer, $regVal) > 0) {
                $base = $compVer;
            }
        }

        // Always bump patch from the chosen base, falling back to initialVersion when base invalid/empty
        return $this->bumpPatchVersion($base, $initialVersion);
    }

    private function determineVersionForPackageExtract(string $repoDir, string $initialVersion, string $packagename, bool $hasFileChanges): string
    {
        // Registry version for this package
        $regVal = '';
        try {
            $reg = $this->loadVersionsRegistry();
            if ($packagename !== '' && isset($reg[$packagename])) {
                $regVal = $reg[$packagename];
            }
        } catch (\Throwable $e) {
            // ignore registry read errors
        }

        // Composer version currently present in repo (if any)
        $composer = $this->readComposer($repoDir);
        $compVer = $composer['version'] ?? '';

        // Determine which of the two is higher (semver)
        $higher = $regVal !== '' ? $regVal : $compVer;
        if ($regVal !== '' && $compVer !== '') {
            $higher = ($this->compareSemver($compVer, $regVal) >= 0) ? $compVer : $regVal;
        }

        // Decide whether to bump:
        // - bump when there are actual file changes
        // - OR when the registry version is higher than the current composer version
        $registryHigherThanComposer = ($regVal !== '' && $compVer !== '' && $this->compareSemver($regVal, $compVer) > 0);
        if ($hasFileChanges || $registryHigherThanComposer) {
            return $this->bumpPatchVersion($higher, $initialVersion);
        }

        // No bump: keep the higher known version, or fall back to initialVersion
        if ($higher !== '') {
            return $higher;
        }
        return $initialVersion;
    }

    // --- version registry helpers ---
    private function getVersionsRegistryPath(): string
    {
        // Use a single shared registry file at the application root, regardless of modules location
        // Ignoring $modulesRoot on purpose to ensure one global version storage across projects
        return Main::$rootdir . 'extractcomp_versions.json';
    }

    private function loadVersionsRegistry(): array
    {
        $path = $this->getVersionsRegistryPath();
        if (!is_file($path)) {
            return [];
        }
        try {
            $raw = Core::fileReadOnce($path);
            $data = Core::jsonRead($raw);
            if (is_array($data)) {
                // normalize keys to strings
                $out = [];
                foreach ($data as $k => $v) {
                    if (!is_string($k)) {
                        continue;
                    }
                    $out[$k] = is_string($v) ? $v : (string)$v;
                }
                return $out;
            }
        } catch (\Throwable $e) {
            Core::echo(Colors::get('[warn]', Colors::FG_yellow) . ' cannot read versions registry: ' . $e->getMessage());
        }
        return [];
    }

    private function saveVersionsRegistry(array $registry): void
    {
        $path = $this->getVersionsRegistryPath();
        // Best-effort write
        try {
            $json = Core::jsonWrite($registry, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
            Core::fileWrite($path, $json, 0, true);
            Core::echo(Colors::get('[info]', Colors::FG_light_blue) . ' saved versions registry', $path);
        } catch (\Throwable $e) {
            Core::echo(Colors::get('[warn]', Colors::FG_yellow) . ' failed to save versions registry: ' . $e->getMessage(), $path);
        }
    }


}


