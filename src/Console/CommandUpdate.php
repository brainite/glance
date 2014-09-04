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
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Yaml\Yaml;

class CommandUpdate extends \Symfony\Component\Console\Command\Command {
  protected function configure() {
    $this->setName('glance:update');
    $this->setDescription('Update the reports based on the target configuration');
    $this->setDefinition(array(
      new InputOption('conf', NULL, InputOption::VALUE_REQUIRED, 'Specify a glance configuration file', './glance.yml'),
      new InputOption('token', NULL, InputOption::VALUE_OPTIONAL, 'Provide a token for accessing github', NULL),
    ));
  }

  protected function execute(InputInterface $input, OutputInterface $output) {
    $output->getFormatter()->setStyle('h1', new OutputFormatterStyle('black', 'yellow', array(
      'bold',
    )));

    // Locate the configuration.
    $conf_path = $input->getOption('conf');
    $conf = Yaml::parse($conf_path);
    $output->writeln("Conf: $conf_path");
    var_export($conf);
  }

}