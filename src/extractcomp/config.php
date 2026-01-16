<?php

/**
 * @env EXTRACTCOMP_SOURCE=""
 * @env EXTRACTCOMP_DEST=""
 * @env EXTRACTCOMP_BRANCH="main"
 * @env EXTRACTCOMP_NEWBRANCH="main"
 * @env EXTRACTCOMP_VENDOR="cryodrift"
 * @env EXTRACTCOMP_MODULES=""
 * @env EXTRACTCOMP_WRITE=false
 * @env EXTRACTCOMP_GITFILTER_SRC=""
 */

use cryodrift\fw\Core;

if (!isset($ctx)) {
    $ctx = Core::newContext(new \cryodrift\fw\Config());
}

$cfg = $ctx->config();

// Provide default CLI parameter values for extractcomp via env variables
$cfg[\cryodrift\extractcomp\Cli::class] = [
  'clidefaults' => [
    'source' => Core::env('EXTRACTCOMP_SOURCE'),
    'dest' => Core::env('EXTRACTCOMP_DEST'),
    'branch' => Core::env('EXTRACTCOMP_BRANCH'),
    'newbranch' => Core::env('EXTRACTCOMP_NEWBRANCH'),
    'vendor' => Core::env('EXTRACTCOMP_VENDOR', 'cryodrift'),
    'modules' => Core::env('EXTRACTCOMP_MODULES', ''),
    'write' => Core::env('EXTRACTCOMP_WRITE', false),
    'gitmeta' => ["authorName cryodrift", "authorEmail cryodrift@phblock.at"],
  ],
  'gitfilter_src' => Core::env('EXTRACTCOMP_GITFILTER_SRC', ''),
  'project_include' => [
    'LICENSE',
    'src/extractcomp/indexcomposer.php' => 'index.php',
    'cfg/Readme.md',
    'cfg/echoconfig.php',
    'php.ini',
    '.gitignore',
    'serv.cmd',
    'pub/index.php',
    'Dockerfile',
    'installdocker.sh',
    'Readme.md',
    'convertarraytojson.php',
    'src/extractcomp/.gitignore_package' => '.gitignore',
    'robots.txt'
  ],

];

\cryodrift\fw\Router::addConfigs($ctx, [
  'extractcomp' => \cryodrift\extractcomp\Cli::class,
], \cryodrift\fw\Router::TYP_CLI);
