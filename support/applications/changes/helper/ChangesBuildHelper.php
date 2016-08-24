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

    list($success, $change_info) = $this->buildChangeInformation($diff, $repo);
    if (!$success) {
      // $change_info will be a string with an error message.
      return array(false, $change_info);
    } else {
      list($data['sha'], $data['patch'], $data['phabricator.revisionIDList']) = $change_info;
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

    return array(true, $data);
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

  /**
   * Generates the base commit hash and patch on it which can be used by Changes to reconstruct the diff.
   * If successful, will return an array of (true, (commit_hash, patch)) where the commit_hash is for a commit
   * that is landed into diffusion. If it fails, this will return an array of (false, explanation) where explanation
   * is a string describing why it failed.
   */
  private function buildChangeInformation($diff, $repo) {
    // Find the closest revision that is landed. We traverse up the (possible) set of parent diffs via
    // the following loop:
    // - Find the sha for the "base revision" that Phabricator has identified.
    // - If that sha exists in diffusion (eg is landed), we're good to go, use that one as our base.
    // - If that sha has a differential diff attached to it, add that diff to our list of diffs and
    //   repeat the process with that one.
    // - If there is no diff and landed commit, fail.

    // Some notes on Phabricator's naming schemes and data organization:
    // There is a distinction between a "diff" and a "revision". A "revision" is a logical change that you might link
    // to (eg DXXXX), and a "diff" is a single update posted to the revision. When you look at a revision, there's a
    // "Revision Update History" section that lists all of the diffs and their IDs. Each diff has a series of "local
    // commits" which have hashes for the commit and the tree associated with them, but the actual diff content is just
    // a patch file. The commits aren't actually upload or accessible individually. These hashes (both for commit and
    // for tree) are cached on the revision for quick access. Therefore if we want to find a diff that contains a commit
    // with a given commit hash, we have to first look at all revisions that contain that hash, then look at all of the
    // diffs on those revisions. This is what we end up doing in getDiffIdAndBaseSha.

    // This is the closest sha that is landed in diffusion.
    $sha = null;
    // This is the list of diffs that we end up traversing to get back to a sha that's landed in diffusion. We'll use
    // this list to build up the patch file.
    $diff_ids_traversed = array($diff->getID());
    // This is the sha that is a candidate for use as the $sha variable. We handle the first diff separately because
    // we know for sure which diff we're working with. If we need to go to a parent revision, we'll have to loop through
    // to find the appropriate diff, which is done by the getDiffIdAndBaseSha function.
    $proposed_sha = $this->getParentShaForInitialDiff($diff);

    while (!$sha) {
      // Determine if the proposed sha is landed in diffusion
      if ($this->isShaLanded($repo, $proposed_sha)) {
        // Set the sha and exit the loop
        $sha = $proposed_sha;
      } else {
        // Find a diff where the last local commit is the sha we're looking for.
        $diff_id_and_base_sha = $this->getDiffIdAndBaseSha($repo, $proposed_sha);
        if ($diff_id_and_base_sha == null) {
          return array(false, "Couldn't find an in progress diff with the necessary last commit");
        }

        // Update the sha we're looking for and try again.
        list($new_diff_id, $proposed_sha) = $diff_id_and_base_sha;
        array_push($diff_ids_traversed, $new_diff_id);
      }
    }

    // The diffs were from newest (most recent) to oldest (closest to master). For patching, we want
    // to go the other way.
    $diff_ids_traversed = array_reverse($diff_ids_traversed);
    // We've either encountered an error state by now and returned, or we've found a valid sha hash. We can go
    // ahead and build the patch out of the diffs we traversed.
    $patch = $this->buildRawDiff($diff_ids_traversed);

    // Finally return valid data.
    return array(true, array($sha, $patch, $diff_ids_traversed));
  }

  /**
   * Given a diff, returns the parent sha of the first local commit in the diff.
   */
  private function getParentShaForInitialDiff($diff) {
    return $diff->getSourceControlBaseRevision();
  }

  /**
   * Returns whether a sha exists in diffusion.
   */
  private function isShaLanded($repo, $proposed_sha) {
    return $this->conduit('diffusion.existsquery',
        array(
            'commit' => $proposed_sha,
            'callsign' => $repo->getCallsign(),
        ));
  }

  /**
   * Given a sha, looks for a diff where the most recent local commit is the proposed hash. If one is found, returns
   * an array of (diff_id, base_sha) where diff_id is the id of the found diff and base_sha is the sha hash that the
   * diff is based on (similar to what getParentShaForInitialDiff() returns). If multiple matching diffs are found, an
   * arbitrary one will be chosen. If no such diff is found, this will return null.
   */
  private function getDiffIdAndBaseSha($repo, $proposed_sha) {
    // This function has 3 major steps to it:
    // 1. Find the revisions that contain the given sha
    // 2. Get the local commits for each of the diffs that those revisions contain
    // 3. Look for a set of local commits where the most recent one is the proposed sha

    // Find the revisions that contain the given sha
    if ($repo->getVersionControlSystem() == 'git') {
      $sha_type = 'gtcm';
    } else if ($repo->getVersionControlSystem() == 'hg') {
      $sha_type = 'hgcm';
    } else {
      // Unknown version control type, bail early.
      return null;
    }
    $parent_revisions = $this->conduit('differential.query',
        array(
            'commitHashes' => array(array($sha_type, $proposed_sha)),
        ));
    if (!$parent_revisions) {
      // No revisions contain the sha
      return null;
    }

    // Get the local commits for each of the diffs that those revisions contain
    $get_id = function($rev) { return $rev['id']; };
    $potential_revision_ids = array_map($get_id, $parent_revisions);
    $potential_diffs = $this->conduit('differential.querydiffs',
        array(
            'revisionIDs' => $potential_revision_ids,
        ));
    // Look for a set of local commits where the most recent one is the proposed sha
    foreach ($potential_diffs as $potential_diff) {
      $local_commits_for_diff = $potential_diff['properties']['local:commits'];
      // Local commits are ordered from HEAD to base
      $most_recent_commit = $local_commits_for_diff[0];
      if ($most_recent_commit['commit'] == $proposed_sha) {
        $diff_id = $potential_diff['id'];
        $base_sha = $potential_diff['sourceControlBaseRevision'];
        return array($diff_id, $base_sha);
      }
    }

    // Didn't find any matching set of commits
    return null;
  }

  /**
   * Given an array of diff ids, returns a string that represents the aggregate patch that can be used
   * to apply/recreate all of the diffs. The diffs should be ordered from oldest to newest.
   */
  private function buildRawDiff($diff_ids_traversed) {
    $patch = '';
    foreach ($diff_ids_traversed as $diff_id) {
      // Note: the dot is string concatenation to the end.
      $patch .= $this->conduit(
          'differential.getrawdiff',
          array(
              'diffID' => $diff_id,
          ));
    }
    return $patch;
  }

  /**
   * Helper to access Conduit endpoints.
   */
  private function conduit($method, $args) {
    return id(new ConduitCall($method, $args))
        ->setUser(PhabricatorUser::getOmnipotentUser())
        ->execute();
  }
}
