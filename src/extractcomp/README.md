# Extractcomp

Tools to extract components from the monorepo and scaffold standalone packages.

## CLI quick help

- List commands for this module:
  php index.php -echo /extractcomp cli -help

- Show help for a specific command:
  php index.php -echo /extractcomp extract -help
  php index.php -echo /extractcomp composer -help
  php index.php -echo /extractcomp readme -help
  php index.php -echo /extractcomp rembranches -help

Note: omit `-write` to do a dry-run; add `-write=true` to apply changes.

## extract — split components into standalone repos

Extract one or more components from a monorepo preserving history. It clones, filters paths, rewrites metadata if requested, generates composer.json and README for each component, and commits the results.

Dependency note:
- This tool can run git filter-repo either via Docker or via an external helper script:
  - Preferred (no external .cmd needed): Set environment variables and extractcomp will run Docker directly:
    - EXTRACTCOMP_GITFILTER_DOCKER=true
    - EXTRACTCOMP_GITFILTER_SRC={PATH_TO_DIR_CONTAINING_git-filter-repo}
    It will execute roughly:
      docker run --rm -v {WORKDIR}:/app -v {EXTRACTCOMP_GITFILTER_SRC}:/src -w /app python:latest \
        python3 /src/git-filter-repo --path <subdir>/ --force [--replace-text <file> ...] [<extra args from -gitmeta> ...]
  - Legacy: If Docker mode is not enabled, it relies on an external helper script named `git-filter.cmd` to forward arguments to git filter-repo. Ensure `git-filter.cmd` is available in your PATH or working directory. Expected usage:
      git-filter.cmd --path <subdir>/ --force [--replace-text <file> ...] [<extra args from -gitmeta> ...]

Required params:
- -source=""     Path to the modules root inside your monorepo (e.g., C:\\repo\\src)
- -dest=""       Destination base directory where new repos/workdirs will be created

Optional params:
- -branch=""     Preferred source branch to read from (falls back to current/main/master)
- -vendor=""     Composer vendor prefix for generated packages (e.g., "myvendor")
- -deps=""       Space or comma separated composer deps (e.g., "vendor/a:^1 vendor/b:~2")
- -modules=""    Space/comma separated list of modules to include (default: all directories under -source)
- -name=""       Override component name (affects composer name and destination folder) — use when extracting a single module
- -gitmeta=""    Repeatable. Two uses:
  - Flags starting with '-' are passed verbatim to git-filter.cmd (e.g., "--mailmap {PATH}\mailmap.txt").
  - Key/value pairs configure new commits locally (e.g., "user.name John Doe", "user.email john@example.com"). Aliases "authorName"/"authorEmail" are accepted for convenience. Non-flag tokens are NOT forwarded to git-filter-repo.
  - Additionally, when you provide a desired author name/email via "authorName ..." / "authorEmail ..." (or "user.name ..." / "user.email ..."), extractcomp will auto-generate appropriate git filter-repo callbacks:
    --name-callback return b"<Name>" and --email-callback return b"<email>", unless you already provided these flags yourself.
- -gitrewritefile="" Repeatable. Path(s) to rewrite mapping files used during history rewrite (see below)
- -gitsearch / -gitreplace  Deprecated pair. If both provided (repeatable), a temporary rewrite file with literal mappings will be generated
- -write           Apply changes (otherwise dry-run prints intended actions)

### Rewrite file format (for -gitrewritefile)
Each file contains one mapping per line:
- literal:old_text==>new_text
- regex:/pattern/flags==>replacement

Examples:
- literal:Acme\\Old==>Acme\\New
- regex:/OldNamespace(\\\\[A-Za-z\\\\]+)?/==>NewNamespace$1

### Copy-pasteable examples (Windows PowerShell)
Replace placeholders in curly braces with your values.

1) Dry-run to see what will happen for a subset of modules
  php index.php -echo /extractcomp extract -source="{MONO_REPO}\src" -dest="{TARGET_BASE}" -modules="sys,libs" -vendor="{VENDOR}" -deps="php:>=8.2"

2) Real run writing results
  php index.php -echo /extractcomp extract -write=true -source="{MONO_REPO}\src" -dest="{TARGET_BASE}" -modules="{MODULES}" -vendor="{VENDOR}" -deps="{DEPS}"

3) Single module with custom package name and metadata rewrite
  php index.php -echo /extractcomp extract -write=true -source="{MONO_REPO}\src" -dest="{TARGET_BASE}" -modules="{ONE_MODULE}" -name="{PACKAGE_NAME}" -vendor="{VENDOR}" -deps="{DEPS}" -gitmeta="authorName John Doe" -gitmeta="authorEmail john@example.com" -gitrewritefile="{MONO_REPO}\.config\rewrite-map.txt"

Tip: When passing Windows paths, quote arguments. Use double backslashes in mapping files when needed.

## composer — scaffold composer.json in modules
This does not perform git operations; it only writes composer.json inside each selected module directory under -source.

Params:
- -source=""     Modules root directory (e.g., C:\\repo\\src)
- -vendor=""     Composer vendor prefix (optional)
- -deps=""       Space/comma separated composer deps (optional)
- -modules=""    Modules to process (optional; default: all)
- -write          Apply changes; otherwise prints what would be written

Examples:
- Show help:  php index.php -echo /extractcomp composer -help
- Dry run:    php index.php -echo /extractcomp composer -source="{MONO_REPO}\src" -vendor="{VENDOR}" -deps="php:>=8.2"
- Write:      php index.php -echo /extractcomp composer -write=true -source="{MONO_REPO}\src" -modules="sys,libs" -vendor="{VENDOR}" -deps="{DEPS}"

## readme — scaffold README.md in modules
This does not perform git operations; it only writes a simple README.md inside each selected module directory under -source.

Params:
- -source=""     Modules root directory (e.g., C:\\repo\\src)
- -vendor=""     Composer vendor prefix (optional; used to build full package name)
- -deps=""       Space/comma separated composer deps (optional; used for README snippet)
- -modules=""    Modules to process (optional; default: all)
- -write          Apply changes; otherwise prints what would be written

Examples:
- Show help:  php index.php -echo /extractcomp readme -help
- Dry run:    php index.php -echo /extractcomp readme -source="{MONO_REPO}\src" -vendor="{VENDOR}"
- Write:      php index.php -echo /extractcomp readme -write=true -source="{MONO_REPO}\src" -modules="sys,libs" -vendor="{VENDOR}"

## rembranches — keep only one branch in a repo
Deletes all branches except the specified one in the target git repository.

Params:
- -keep=""       Branch name to keep
- -repopath=""   Path to the repository (any path within the repo works)
- -write          Apply changes

Example:
  php index.php -echo /extractcomp rembranches -write=true -keep="main" -repopath="{TARGET_REPO}" 
