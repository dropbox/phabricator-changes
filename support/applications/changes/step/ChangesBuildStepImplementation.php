<?php

final class ChangesBuildStepImplementation
  extends BuildStepImplementation {

  public function getName() {
    return pht('Create build in Changes');
  }

  public function getGenericDescription() {
    return pht('Create a new build in Changes.');
  }

  public function getDescription() {
    $settings = $this->getSettings();

    return pht('Create a new build in Changes');
  }

  public function execute(
    HarbormasterBuild $build,
    HarbormasterBuildTarget $build_target) {

    $changes_uri = PhabricatorEnv::getEnvConfigIfExists('changes.uri');

    $log_body = $build->createLog($build_target, 'remote', 'http-body');
    $start = $log_body->start();

    if (!$changes_uri) {
      $log_body->append('Missing changes.uri setting');
      $log_body->finalize($start);

      $build->setBuildStatus(HarbormasterBuild::STATUS_FAILED);
      return;
    }

    $log_body->append(sprintf("Changes URI is \"%s\".\n", $changes_uri));

    $uri = sprintf('%s/api/0/builds/', rtrim($changes_uri, '/'));

    $buildable = $build->getBuildable();
    $object = $buildable->getBuildableObject();

    $log_body->append(sprintf("Identifying build parameters for %s (%s).\n",
                      get_class($object), $object->getPHID()));

    if ($object instanceof DifferentialDiff) {
      list($success, $data) = $this->getParamsForDiff($object);
    } else if ($object instanceof PhabricatorRepositoryCommit) {
      list($success, $data) = $this->getParamsForCommit($object);
    } else {
      $success = false;
      $data = sprintf("Unable to create a build for object type (not supported).\n");
    }

    if (!$success) {
      $log_body->append($data);
      $log_body->finalize($start);

      $build->setBuildStatus(HarbormasterBuild::STATUS_FAILED);

      return;
    }


    try {
      if ($data['patch']) {
        $patch_file = tempnam('', 'changes-patch');

        $fp = fopen($patch_file, 'wb');
        fwrite($fp, $data['patch']);
        fclose($fp);

        $data['patch'] = '@'.$patch_file;
      }

      $log_body->append(sprintf("Request params:\n%s\n", var_export($data, true)));

      // The HTTPSFuture implementation in libphutil does not support file uploads, so we
      // attempt to reconstruct the important bits here
      $curl = curl_init();
      $allowed_protocols = CURLPROTO_HTTPS | CURLPROTO_HTTP;
      curl_setopt($curl, CURLOPT_PROTOCOLS, $allowed_protocols);
      curl_setopt($curl, CURLOPT_URL, $uri);
      curl_setopt($curl, CURLOPT_POST, true);
      curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
      curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
      // curl_setopt($curl, CURLOPT_UPLOAD, 1);
      curl_setopt($curl, CURLOPT_TIMEOUT, 60);
      $body = curl_exec($curl);
      $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);

      $result = json_decode($body, true);

      if ($status == 200) {
        $log_body->append(sprintf("Response was:\n%s\n", var_export($result, true)));
      }

      $log_body->finalize($start);

      if ($status != 200) {
        $build->setBuildStatus(HarbormasterBuild::STATUS_FAILED);
      }
    } catch (Exception $ex) {
      if ($data['patch']) {
        unlink($patch_file);
      }
      throw $ex;
    }

    if ($data['patch']) {
      unlink($patch_file);
    }
  }

  public function validateSettings() {
    return true;
  }

  private function formatAuthor($author) {
    $email = $author->loadPrimaryEmailAddress();

    return sprintf('%s <%s>', $author->getRealName(), $email);
  }

  private function getParamsForCommit($commit) {
    // TODO: we need label/etc yet
    $data = array();

    $data['sha'] = $object->getCommitIdentifier();
    $data['target'] = $data['sha'];
    $data['repository'] = (string)$object->getRepository()->getPublicRemoteURI();

    $author = id(new PhabricatorPeopleQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withPHIDs(array($object->getAuthorPHID()))
      ->executeOne();

    // commits *may* be missing an authorPHID if they're committing as an invalid
    // (or new) author
    if ($author) {
      $data['author'] = $this->formatAuthor($author);
    }

    return $data;
  }

  private function getParamsForDiff($diff) {
    $data = array();

    // arc project is required by diff builder
    $arc_project = $diff->loadArcanistProject();
    if (!$arc_project) {
      return array(false, 'Missing arcanist project');
    }
    $diff->attachArcanistProject($arc_project);

    $revision = $diff->getRevision();
    // TODO(dcramer): we'd like to support revision-less diffs
    if (!$revision) {
      return array(false, 'Not attached to a revision');
    }

    $repo = $revision->getRepository();

    $data['patch[label]'] = sprintf('D%s', $revision->getID());
    $data['label'] = $revision->getTitle();
    // TODO(dcramer): we must pass the message
    $data['message'] = '';
    $data['target'] = $data['patch[label]'];
    if (!$repo) {
      return array(false, 'Missing repository for diff');
    }
    $data['repository'] = (string)$repo->getPublicCloneURI();

    $property = id(new DifferentialDiffProperty())->loadOneWhere(
      'diffID = %d AND name = %s',
      $diff->getID(),
      'local:commits');

    if ($property) {
      $local_commits = $property->getData();

      usort($local_commits, array($this, 'compareCommitsByTime'));

      $data['sha'] = $local_commits[0]['parents'][0];
    } else {
      // No sha so we don't know what to build it against
      return array(false, 'Unable to detect parent revision');
    }

    $data['patch'] = $this->buildRawDiff($diff);

    // fetch author from revision as diff may not match what we
    // expect
    $author = id(new PhabricatorPeopleQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withPHIDs(array($revision->getAuthorPHID()))
      ->executeOne();

    $data['author'] = $this->formatAuthor($author);

    return array(true, $data);
  }

  private function compareCommitsByTime($a, $b) {
    if ($a['time'] == $b['time']) {
      return 0;
    }
    return ($a['time'] < $b['time'] ? -1 : 1);
  }

  private function loadFileByPHID($phid) {
    $file = id(new PhabricatorFile())->loadOneWhere(
      'phid = %s',
      $phid);
    if (!$file) {
      return null;
    }
    return $file->loadFileData();
  }

  private function buildRawDiff($diff){
    $user = PhabricatorUser::getOmnipotentUser();

    return id(new ConduitCall(
      'differential.getrawdiff',
      array(
        'diffID' => $diff->getID(),
      )
    ))
    ->setUser($user)
    ->execute();
  }
}
