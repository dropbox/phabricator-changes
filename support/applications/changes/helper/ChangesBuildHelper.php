<?php

final class ChangesBuildHelper {

  public function stringifyApiResult($success, $data) {
    $revision_id = $data['revision_id'];
    $diff_id = $data['diff_id'];
    $api_result = $data['api_result'];

    $prefix = "D{$revision_id}#{$diff_id}";
    if ($success) {
      $build_names = array();
      foreach ($api_result as $build) {
        $project = idx($build, 'project');
        if ($project) {
          $build_names[] = $project['name'];
        }
      }

      return pht(
        '%s: Started %s build(s): %s.',
        $prefix,
        new PhutilNumber(count($api_result)),
        implode(', ', $build_names));
    } else {
      return pht(
        '%s Failed to start build(s). %s.',
        $prefix,
        print_r($api_result, true));
    }
  }

  public function executeBuild($object, $build_target=null) {
    $changes_uri = PhabricatorEnv::getEnvConfigIfExists('changes.uri');

    if (!$changes_uri) {
      return array(false, 'Missing changes.uri setting');
    }

    $uri = sprintf('%s/api/0/phabricator/notify-diff/', rtrim($changes_uri, '/'));

    if ($object instanceof DifferentialDiff) {
      list($success, $data) = $this->getParamsForDiff($object, $build_target);

      if (!$success) {
        return array(false, $data);
      }
    // } else if ($object instanceof PhabricatorRepositoryCommit) {
    //   list($success, $data) = $this->getParamsForCommit($object);
    } else {
      return array(false, 'Object of type is not buildable');
    }
    try {
      if (!empty($data['patch'])) {
        $patch_file = tempnam('', 'changes-patch');

        $fp = fopen($patch_file, 'wb');
        fwrite($fp, $data['patch']);
        fclose($fp);

        $data['patch'] = '@'.$patch_file;
      }

      list($success, $result) = $this->sendBuildToChanges($uri, $data);
    } catch (Exception $ex) {
      if ($data['patch']) {
        unlink($patch_file);
      }
      throw $ex;
    }

    if ($data['patch']) {
      unlink($patch_file);
    }

    return array($success, $result);
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

    if ($status === 200) {
        return array(true, json_decode($body, true));
    }
    if ($status === 0) {
        $error = curl_error($curl);
        return array(false, "curl error: $error");
    }
    return array(false, "HTTP $status: $body");
  }

  private function formatAuthor($author) {
    $email = $author->loadPrimaryEmailAddress();

    return sprintf('%s <%s>', $author->getRealName(), $email);
  }

  private function getParamsForCommit($commit) {
    return array(false, 'Commits are not currently supported');
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

    $data['label'] = $revision->getTitle();
    $data['message'] = $revision->getSummary();
    $data['tag'] = 'diff';

    $data['phabricator.buildTargetPHID'] = $buildTargetPHID;
    $data['phabricator.callsign'] = $repo->getCallsign();
    $data['phabricator.diffID'] = $diff->getID();
    $data['phabricator.revisionID'] = $revision->getID();
    $data['phabricator.revisionURL'] = PhabricatorEnv::getProductionURI('/'.$revision->getMonogram());

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

    // fetch author from revision as diff may not match what we
    // expect
    $author = id(new PhabricatorPeopleQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withPHIDs(array($revision->getAuthorPHID()))
      ->executeOne();
    $data['author'] = $this->formatAuthor($author);

    // If any field starts with an '@', insert a space at the beginning, to
    // avoid curl interpreting it as a filename to fetch actual data from.
    // This is an atrocious hack, and we should get rid of it once we're on a
    // version of PHP that supports CURLFile.
    foreach ($data as $k => $v) {
      if (strncmp($v, '@', 1) == 0) {
          $data[$k] = ' '.$v;
      }
    }

    $data['patch'] = $this->buildRawDiff($diff);

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
