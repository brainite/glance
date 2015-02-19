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
  protected $client;

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
    $confs = Yaml::parse($conf_path);
    $output->writeln("Conf: $conf_path");

    // Extract the defaults.
    if (isset($confs['defaults'])) {
      $defaults = $confs['defaults'];
      unset($confs['defaults']);
    }
    $defaults = array_merge(array(
      'filter' => 'is:open',
    ), (array) $defaults);

    // Get the token for output.
    $token_override = $input->getOption('token');
    if (empty($token_override) && isset($defaults['token'])) {
      $token_override = $defaults['token'];
    }
    if (empty($token_override)) {
      if (is_file(dirname(dirname(__DIR__)) . '/github_token.txt')) {
        $token_override = trim(file_get_contents(dirname(dirname(__DIR__))
          . '/github_token.txt'));
      }
    }

    // Build the replacements.
    $tr = array();
    $month = (int) strftime('%m');
    for ($i = 0; $i <= 11; ++$i) {
      if ($i) {
        if ($month + $i > 12) {
          $month -= 12;
        }
        $tr["{{month_$i}}"] = sprintf('%02d', $month + $i);
      }
      else {
        $tr["{{month}}"] = sprintf('%02d', $month);
      }
    }

    try {
      // Initialize the client.
      //       $client = new \Github\Client(new \Github\HttpClient\CachedHttpClient(array(
      //         'cache_dir' => '/tmp/github-api-cache-' . get_current_user(),
      //       )));
      $this->client = new \Github\Client();
      $client = &$this->client;
      if (!empty($token_override)) {
        $output->writeln("Authenticating via token");
        $client->authenticate($token_override, \Github\Client::AUTH_HTTP_TOKEN);
      }

      // Iterate through the configurations.
      foreach ($confs as $conf_id => $conf) {

        // Track the issues as:
        // $issues[$issue['id']] = $issue;
        // $weights[$issue['id']] = 1;
        $issues = array();
        $weights = array();

        $conf = array_merge($defaults, (array) $conf);
        foreach ($conf['repos'] as $repo) {
          list($search_user, $search_repo) = explode('/', $repo, 2);

          // Get a list of all issues.
          $items = $this->getItems($conf['filter'], $repo);
          foreach ($items as $item) {
            $id = $item['html_url'];
            $issues[$id] = $item;
            $weights[$id] = 1;
          }

          // Process the weights.
          foreach ($conf['weights'] as $weight) {
            $filter = trim(strtr($weight['filter'], $tr));

            // Process the filters
            if (preg_match('@label:("[^"]+"|[^ ]+)$@s', $filter, $arr)) {
              // Handle a basic label filter
              $items = array();
              foreach ($issues as $issue) {
                foreach ($issue['labels'] as $label) {
                  if (strcasecmp($arr[1], $label['name']) === 0) {
                    $items[] = $issue;
                    break;
                  }
                }
              }
              $output->writeln(sizeof($items) . " results for basic label '$filter'");
            }
            elseif (preg_match('@milestone:("[^"]+"|[^ ]+)$@s', $filter, $arr)) {
              // Handle a basic milestone filter
              $items = array();
              foreach ($issues as $issue) {
                if (isset($issue['milestone']) && strcasecmp($arr[1], $issue['milestone']['title']) === 0) {
                  $items[] = $issue;
                }
              }
              $output->writeln(sizeof($items) . " results for basic milestone '$filter'");
            }
            else {
              // If this is not handled internally, use the search.
              $filter = "$conf[filter] $filter";
              $items = $this->getItems($filter, $repo);
              $output->writeln(sizeof($items) . " results for '$filter'");
            }

            // Apply the weighting.
            foreach ($items as $item) {
              $id = $item['html_url'];
              $weights[$id] *= $weight['weight'];
            }
          }
        }
      }

      $contents = '';
      arsort($weights);
      $i = 0;
      foreach ($weights as $id => $weight) {
        if ($weight <= 1) {
          continue;
        }
        $link = (++$i) . ". [" . $issues[$id]['title'] . "]("
          . $issues[$id]['html_url'] . ")";
        $contents .= $link . "\n";
      }

      // If the output is a repo, then
      if (isset($conf['output']['repo'])) {
        $commitMessage = "Updated by Glance";
        $committer = NULL;
        list($output_user, $output_repo) = explode('/', $conf['output']['repo'], 2);
        if ($client->api('repo')->contents()->show($output_user, $output_repo, $conf['output']['path'])) {
          $oldFile = $client->api('repo')->contents()->show($output_user, $output_repo, $conf['output']['path'], $conf['output']['branch']);
          $checkOld = preg_replace("@\s+@s", '', $oldFile['content']);
          $checkNew = base64_encode($contents);
          if ($checkOld === $checkNew) {
            $output->writeln("No change to file content");
          }
          else {
            $fileInfo = $client->api('repo')->contents()->update($output_user, $output_repo, $conf['output']['path'], $contents, $commitMessage, $oldFile['sha'], $conf['output']['branch'], $committer);
          }
        }
        else {
          $fileInfo = $client->api('repo')->contents()->create($output_user, $output_repo, $conf['output']['path'], $contents, $commitMessage, $conf['output']['branch'], $committer);
        }
      }
    } catch (\Exception $e) {
      $output->writeln("GitHub exception: " . $e->getMessage());
      return;
    }
  }

  private function getItems($filter, $repo) {
    static $api = NULL;
    if (!isset($api)) {
      $api = $this->client->api('search');
      $api->setPerPage(1000);
    }

    $results = $api->issues("repo:$repo $filter");
    return (array) $results['items'];
  }

}