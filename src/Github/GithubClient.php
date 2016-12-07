<?php
/*
 * This file is part of the Glance package.
 *
 * (c) CPNP
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Glance\Github;

class GithubClient {
  protected $client;
  protected $output;

  static public function factoryToken(&$output = NULL, $token = NULL) {
    $client = new GithubClient($output);
    $this->client = new \Github\Client();
    if (!empty($token)) {
      $this->authenticateToken($token);
    }
    return $client;
  }

  public function __construct(&$output = NULL) {
    $this->output =& $output;
  }

  public function &authenticateToken($token) {
    $this->writeln("Authenticating via token");
    $this->client->authenticate($token, \Github\Client::AUTH_HTTP_TOKEN);
    return $this;
  }

  public function &writeln($msg) {
    if (is_object($this->output)) {
      $this->output->writeln($msg);
    }
    return $this;
  }

}