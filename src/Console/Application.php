<?php
/*
 * This file is part of the Glance package.
 *
 * (c) CPNP
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Glance\Console;

class Application extends \Symfony\Component\Console\Application {
  public function __construct() {
    parent::__construct();

    // Identify all of the available glance console commands.
    $cmds = array(
      new CommandUpdate(),
      new CommandAnalytics(),
    );

    // Add the commands after eliminating the implicit glance namespace.
    foreach ($cmds as &$cmd) {
      $cmd->setName(str_replace('glance:', '', $cmd->getName()));
      $this->add($cmd);
    }
  }
}