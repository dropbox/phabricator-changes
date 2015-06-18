<?php

class ChangesDiffRetryKeywordHandler extends ChangesKeywordHandler {

    public function getKeyword() {
        return "/!retry/";
    }

    public function runOnObject($feedWorker, $storyObject, $viewer, $publisher) {
        assert(is_a($storyObject, 'DifferentialRevision'));
        $changesBotUsername = PhabricatorEnv::getEnvConfigIfExists('changes.bot.username');
        if (!$changesBotUsername) {
            return;
        }
        $user = id(new PhabricatorUser())->loadOneWhere("username = %s", $changesBotUsername);
        if (!$user) {
            return;
        }
        $revision = id(new DifferentialRevisionQuery())
            ->setViewer($user)
            ->withIDs(array($storyObject->getID()))
            ->needReviewerStatus(true)
            ->executeOne();
        $diff = $revision->loadActiveDiff();
        if (!$diff) {
            $this->postComment('This revision has no active diffs.', $revision, $user);
            return;
        }
        $comment = $this->sendChangesRetry($diff->getID());
        if (!$comment) {
            return;
        }
        $this->postComment($comment, $revision, $user);
    }

    /**
     * Try to post a message to Changes. Returns a status message that should
     * be posted to the user.
     */
    private function sendChangesRetry($diffId) {
        $changes_uri = PhabricatorEnv::getEnvConfigIfExists('changes.uri');
        if (!$changes_uri) {
            return 'changes.uri not set';
        }
        $uri = sprintf('%s/api/0/phabricator_diffs/%s/retry/', rtrim($changes_uri, '/'), $diffId);
        $future = id(new HTTPSFuture($uri))
            ->setMethod('POST');
        list($status, $body, $headers) = $future->resolve();
        $statusCode = $status->getStatusCode();
        switch($statusCode) {
            case 200:
                $builds = json_decode($body, true);
                if ($builds === null) {
                    return "Error while retrying builds. Received an invalid response from Changes.";
                } else {
                    $count = count($builds);
                    if ($count == 0) {
                        return 'No builds restarted.';
                    } else {
                        $names = array();
                        foreach ($builds as $build) {
                            $names[] = $build['project']['name'];
                        }
                        $namesText = implode(', ', $names);
                        return "Builds restarted: $namesText";
                    }
                }
            case 400:
                $dict = json_decode($body, true);
                if (!$dict || !array_key_exists('error', $dict)) {
                    return "Error while retrying builds. Received in invalid error message from Changes.";
                } else {
                    return $dict['error'];
                }
            case 500:
                return "Error on Changes while retrying builds: Internal Server Error";
            case 7: // CURLE_COULDNT_CONNECT
            case 52: // CURLE_GOT_NOTHING
                return 'Unable to connect to Changes to restart the builds.';
            default:
                return "An unknown error occurred while retrying builds. Error code: $statusCode.";
        }
        assert(false);
    }

    private function postComment($comment, $revision, $user) {
        $xactions = array(
            id(new DifferentialTransaction())
            ->setTransactionType(PhabricatorTransactions::TYPE_COMMENT)
            ->attachComment(
                id(new DifferentialTransactionComment())
                    ->setAuthorPHID($user->getPHID())
                    ->setRevisionPHID($revision->getID())
                    ->setContent($comment))
            );

        $editor = id(new DifferentialTransactionEditor())
            ->setActor($user)
            ->setContentSource(PhabricatorContentSource::newForSource(PhabricatorContentSource::SOURCE_DAEMON, array()))
            ->setContinueOnMissingFields(true)
            ->setContinueOnNoEffect(true);
        $editor->applyTransactions($revision, $xactions);
    }
}
