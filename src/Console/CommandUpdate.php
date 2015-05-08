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
  protected $output;

  protected function configure() {
    $this->setName('glance:update');
    $this->setDescription('Update the reports based on the target configuration');
    $this->setDefinition(array(
      new InputOption('conf', NULL, InputOption::VALUE_REQUIRED, 'Specify a glance configuration file', './glance.yml'),
      new InputOption('token', NULL, InputOption::VALUE_OPTIONAL, 'Provide a token for accessing github', NULL),
    ));
  }

  protected function execute(InputInterface $input, OutputInterface $output) {
    $this->output = &$output;
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
      'inherit_from' => NULL,
      'inherit_filter' => NULL,
      'header' => '',
      'footer' => '',
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
      else {
        $output->writeln("<error>Error: You must provide an authentication token.</error>");
        return;
      }

      // Iterate through the configurations.
      $cache = array();
      foreach ($confs as $conf_id => $conf) {
        $conf = array_merge($defaults, (array) $conf);

        // Track the issues as:
        // $issues[$issue['html_url']] = $issue;
        // $weights[$issue['html_url']] = 1;
        if (isset($conf['inherit_from'])) {
          $issues = $cache[$conf['inherit_from']]['issues'];
          $weights = $cache[$conf['inherit_from']]['weights'];
        }
        else {
          $issues = array();
          $weights = array();

          foreach ($conf['repos'] as $repo) {
            list($search_user, $search_repo) = explode('/', $repo, 2);

            // Get a list of all issues.
            $items = $this->getAllItems($conf['filter'], $repo);
            foreach ($items as $item) {
              $id = $item['html_url'];
              $issues[$id] = $item;
              $weights[$id] = 1;
            }

            // Process the weights.
            foreach ($conf['weights'] as $weight) {
              $filter = trim(strtr($weight['filter'], $tr));
              $items = $this->getFilteredItems($conf, $issues, $filter, $repo);

              // Apply the weighting.
              foreach ($items as $item) {
                $id = $item['html_url'];

                // Adjust the weight.
                $weights[$id] *= $weight['weight'];

                // Adjust the assignee when set
                // @todo Support options other than '=owner'
                if (isset($weight['assignee'])) {
                  if ($weight['assignee'] === '=owner') {
                    $issues[$id]['assignee'] = $issues[$id]['user'];
                  }
                }

                // Add suffix to the issue.
                if (isset($weight['suffix'])) {
                  $issues[$id]['title'] .= strtr($weight['suffix'], array(
                    '{{due}}' => isset($issues[$id]['due']) ? $issues[$id]['due'] : '',
                  ));
                }

                // Add the debug output
                if ($output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
                  $output->writeln(sprintf("   %0.1f % 6d %s", round($weights[$id], 1), $item['number'], $issues[$id]['title']));
                }
              }
            }
          }

          // Round the weights before sorting.
          foreach ($weights as &$weight) {
            $weight = round($weight, 1);
          }
          unset($weight);

          // Sort the ids by weight and then by issue title.
          $ids = array_keys($weights);
          uasort($ids, function ($a, $b) use ($weights, $issues) {
            if ($weights[$a] > $weights[$b]) {
              return -1;
            }
            if ($weights[$a] < $weights[$b]) {
              return 1;
            }
            return strcasecmp($issues[$a]['title'], $issues[$b]['title']);
          });

          $cache[$conf_id] = array(
            'issues' => $issues,
            'weights' => $weights,
          );
        }

        // Build the contents.
        $contents = '';
        $i = 0;
        if (isset($conf['inherit_from']) && isset($conf['inherit_filter'])) {
          $items = $this->getFilteredItems($conf, $issues, $conf['inherit_filter'], $repo);
          $visible_issues = array();
          foreach ($items as $item) {
            $id = $item['html_url'];
            $visible_issues[$id] = TRUE;
          }
        }
        else {
          $visible_issues = $issues;
        }
        foreach ($ids as $id) {
          $weight = $weights[$id];
          if ($weight <= 1 || !isset($visible_issues[$id])) {
            continue;
          }
          if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            $output->writeln(sprintf("   %0.1f % 6d %s", round($weight, 1), $issues[$id]['number'], $issues[$id]['title']));
          }
          // Add the bullet.
          $bullet = ++$i;
          $bullet = 1;
          $link = "$bullet. [" . $issues[$id]['title'] . "]("
            . $issues[$id]['html_url'] . ")";
          $contents .= $link . "\n";
          // echo $weight . ' = ' . $link . "\n";
        }

        // Add headers/footers.
        if ($conf['header']) {
          $contents = trim($conf['header']) . "\n\n" . $contents;
        }
        if ($conf['footer']) {
          $contents = trim($contents) . "\n\n" . $conf['footer'];
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
            // Files are created in the catch clause
          }
        }
      }
    } catch (\Github\Exception\RuntimeException $e) {
      if ($e->getCode() == 404) {
        $fileInfo = $client->api('repo')->contents()->create($output_user, $output_repo, $conf['output']['path'], $contents, $commitMessage, $conf['output']['branch'], $committer);
      }
      else {
        $output->writeln("GitHub exception (" . $e->getCode() . "): "
          . $e->getMessage());
      }
    } catch (\Exception $e) {
      $output->writeln("GitHub exception (" . $e->getCode() . "): "
        . $e->getMessage());
      $output->writeln(get_class($e));
      return;
    }
  }

  private function getAllItems($filter = 'is:open', $repo) {
    static $api = NULL;
    if (!isset($api)) {
      $api = $this->client->api('search');
      $api->setPerPage(1000);
    }

    $results = $api->issues("repo:$repo $filter");
    return (array) $results['items'];
  }

  private function getFilteredItems($conf, &$issues, $filter, $repo = NULL) {
    $items = array();
    $filter = trim($filter);
    $msgs = array();

    // Process the filters
    if (preg_match('@^due:("[^"]+"|[^ ]+)$@s', $filter, $arr)) {
      // Custom glance syntax:
      // due:"* .. *"
      $match = $arr[1];
      $from = NULL;
      $until = NULL;
      if (preg_match('@^(.*) \.\. (.*)$@s', trim($match, '" '), $arr)) {
        if (trim($arr[1]) === '*') {
          $from = '0000-00-00';
        }
        else {
          $from = strftime('%Y-%m-%d', strtotime($arr[1]));
        }
        if (trim($arr[2]) === '*') {
          $until = '3000-00-00';
        }
        else {
          $until = strftime('%Y-%m-%d', strtotime($arr[2]));
        }
      }
      $items = array();
      foreach ($issues as &$issue) {
        if (preg_match("@due(?::\s*|\s+)(.*?)(?:\n|$)@is", $issue['body'], $arr)) {
          $tgt = strtotime($arr[1]);
          if ($tgt == 0) {
            continue;
          }
          $tgt = strftime('%Y-%m-%d', $tgt);
          if ($tgt >= $from && $tgt <= $until) {
            $issue['due'] = $tgt;
            $items[] = $issue;
          }
        }
      }
      unset($issue);
      $msgs[] = sizeof($items) . " results for glance due filter '$filter'";
    }
    elseif (preg_match('@^label:("[^"]+"|[^ ]+)$@s', $filter, $arr)) {
      // Handle a basic label filter
      $find = trim($arr[1], '"');
      $items = array();
      foreach ($issues as $issue) {
        foreach ($issue['labels'] as $label) {
          if (strcasecmp($find, $label['name']) === 0) {
            $items[] = $issue;
            break;
          }
        }
      }
      $msgs[] = sizeof($items) . " results for basic label '$filter'";
    }
    elseif (preg_match('@^no:\s*milestone$@s', $filter, $arr)) {
      // Handle a basic milestone filter
      $items = array();
      foreach ($issues as $issue) {
        if (!isset($issue['milestone']) || empty($issue['milestone']['title'])) {
          $items[] = $issue;
        }
      }
      $msgs[] = sizeof($items) . " results for no milestone '$filter'";
    }
    elseif (preg_match('@^milestone:("[^"]+"|[^ ]+)$@s', $filter, $arr)) {
      // Handle a basic milestone filter
      $items = array();
      foreach ($issues as $issue) {
        if (isset($issue['milestone'])
          && strcasecmp($arr[1], $issue['milestone']['title']) === 0) {
          $items[] = $issue;
        }
      }
      $msgs[] = sizeof($items) . " results for basic milestone '$filter'";
    }
    elseif (preg_match('@^no:\s*assignee$@s', $filter, $arr)) {
      // Handle a basic milestone filter
      $items = array();
      foreach ($issues as $issue) {
        if (!isset($issue['assignee']) || empty($issue['assignee']['login'])) {
          $items[] = $issue;
        }
      }
      $msgs[] = sizeof($items) . " results for no assignee '$filter'";
    }
    elseif (preg_match('@^assignee:("[^"]+"|[^ ]+)$@s', $filter, $arr)) {
      // Handle a basic milestone filter
      $items = array();
      foreach ($issues as $issue) {
        if (isset($issue['assignee'])
          && strcasecmp($arr[1], $issue['assignee']['login']) === 0) {
          $items[] = $issue;
        }
      }
      $msgs[] = sizeof($items) . " results for basic assignee '$filter'";
    }
    else {
      // If this is not handled internally, use the search.
      $filter = "$conf[filter] $filter";
      $items = $this->getAllItems($filter, $repo);
      $msgs[] = sizeof($items) . " results for '$filter'";
    }

    if ($this->output->getVerbosity() >= OutputInterface::VERBOSITY_NORMAL) {
      foreach ($msgs as $msg) {
        $this->output->writeln($msg);
      }
    }
    return $items;
  }

}