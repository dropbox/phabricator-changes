<?php

// We don't use this to notify changes about builds...see ChangesBuildHelper
// for an explanation and a different set of CURL code
final class ChangesFuture extends FutureProxy {

  private $future;
  private $apiPath;
  private $params;
  private $method = 'GET';

  public function __construct() {
    parent::__construct(null);
  }

  public function setAPIPath($api_path) {
    $this->apiPath = $api_path;
    return $this;
  }

  public function setParams($params) {
    $this->params = $params;
    return $this;
  }

  protected function getProxiedFuture() {
    $changes_uri = PhabricatorEnv::getEnvConfigIfExists('changes.uri');

    if (!$changes_uri) {
      return array(false, 'Missing changes.uri setting');
    }

    if (!$this->future) {
      $params = $this->params;

      $uri = id(new PhutilURI(rtrim($changes_uri, '/')))
        ->setPath(sprintf('/%s/', trim($this->apiPath, '/')))
        ->setQueryParams($this->params);

      $future = new HTTPSFuture($uri);
      $future->setMethod($this->method);

      $this->future = $future;
    }

    return $this->future;
  }

  protected function didReceiveResult($result) {
    list($status, $body, $headers) = $result;

    if ($status->isError()) {
      throw $status;
    }

    try {
      $data = phutil_json_decode($body);
    } catch (PhutilJSONParserException $ex) {
      throw new PhutilProxyException(
        pht('Expected JSON response from Changes.'),
        $ex);
    }

    if (idx($data, 'error')) {
      $error = $data['error'];
      throw new Exception(
        pht('Received error from Changes: %s', $error));
    }

    return $data;
  }
}
