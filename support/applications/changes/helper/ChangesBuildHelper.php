<?php

final class ChangesBuildHelper {
  public function executeBuild($object, $build_target=null) {
    $changes_uri = PhabricatorEnv::getEnvConfigIfExists('changes.uri');

    if (!$changes_uri) {
      return array(false, 'Missing changes.uri setting');
    }

    $uri = sprintf('%s/api/0/builds/', rtrim($changes_uri, '/'));

    if ($object instanceof DifferentialDiff) {
      list($success, $data) = $this->getParamsForDiff($object, $build_target);
    // } else if ($object instanceof PhabricatorRepositoryCommit) {
    //   list($success, $data) = $this->getParamsForCommit($object);
    } else {
      $success = false;
    }

    if (!$success) {
      return array(false, $data);
    }

    try {
      if ($data['patch']) {
        $patch_file = tempnam('', 'changes-patch');

        $fp = fopen($patch_file, 'wb');
        fwrite($fp, $data['patch']);
        fclose($fp);

        $data['patch'] = '@'.$patch_file;
      }

      list($success, $result) = $this->sendBuildToChanges($uri, $data);

      if ($success != 200) {
        return array(false, $result);
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

    return array(true, $result);
  }

  private function sendBuildToChanges($uri, $data) {
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
    $success = $status === 200;

    return array($success, $result);
  }

  private function formatAuthor($author) {
    $email = $author->loadPrimaryEmailAddress();

    return sprintf('%s <%s>', $author->getRealName(), $email);
  }

  private function getParamsForCommit($commit) {
    // TODO: we need label/etc yet
    $repo = $commit->getRepository();

    if (!$repo) {
      return array(false, 'Missing repository for commit');
    }

    $data = array();

    $data['sha'] = $commit->getCommitIdentifier();
    $data['target'] = $data['sha'];
    $data['repository[phabricator.callsign'] = $repo->getCallsign();
    $data['message'] = $commit->getSummary();
    $data['patch'] = null;

    $author = id(new PhabricatorPeopleQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withPHIDs(array($commit->getAuthorPHID()))
      ->executeOne();

    // commits *may* be missing an authorPHID if they're committing as an invalid
    // (or new) author
    if ($author) {
      $data['author'] = $this->formatAuthor($author);
    }

    return array(true, $data);
  }

  private function getParamsForDiff($diff, $build_target=null) {
    $data = array();

    $revision = $diff->getRevision();
    // TODO(dcramer): we'd like to support revision-less diffs
    if (!$revision) {
      return array(false, 'Not attached to a revision');
    }

    $repo = $revision->getRepository();
    if (!$repo) {
      return array(false, 'Missing repository for diff');
    }

    $buildTargetPHID = ($build_target ? $build_target->getPHID() : null);

    $data['target'] = sprintf('D%s', $revision->getID());
    $data['label'] = $revision->getTitle();
    $data['message'] = $revision->getSummary();
    $data['repository[phabricator.callsign'] = $repo->getCallsign();
    $data['patch[data]'] = json_encode(array(
      'diffID' => $diff->getID(),
      'revisionID' => $revision->getID(),
      'buildTargetPHID' => $buildTargetPHID,
      'url' => PhabricatorEnv::getProductionURI('/'.$revision->getMonogram()),
    ));

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
