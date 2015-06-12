<?php

class ChangesDiffRetryKeywordHandler extends ChangesKeywordHandler {

    const GENERIC_ERROR_MESSAGE = 'An unknown error occurred while retrying the builds.';

    public function getKeyword() {
        return "/!retry/";
    }

    public function runOnObject($feedWorker, $storyObject, $viewer, $publisher) {
        assert(is_a($storyObject, 'DifferentialRevision'));
        $changes_uri = PhabricatorEnv::getEnvConfigIfExists('changes.uri');
        if (!$changes_uri) {
            return;
        }
        $uri = sprintf('%s/api/0/phabricator_diffs/%s/retry/', rtrim($changes_uri, '/'), $storyObject->getID());
        $future = id(new HTTPSFuture($uri))
            ->setMethod('POST');
        list($status, $body, $headers) = $future->resolve();
        $comment = null;
        switch($status->getStatusCode()) {
            case 200:
                $builds = json_decode($body, true);
                if ($builds === null) {
                    $comment = ChangesDiffRetryKeywordHandler::GENERIC_ERROR_MESSAGE;
                } else {
                    $count = count($builds);
                    if ($count == 0) {
                        $comment = 'No builds restarted.';
                    } else {
                        $names = [];
                        foreach ($builds as $build) {
                            $names[] = $build['project']['name'];
                        }
                        $namesText = implode(', ', $names);
                        $comment = "Builds restarted: $namesText";
                    }
                }
                break;
            case 400:
                $dict = json_decode($body, true);
                if (!$dict || !array_key_exists('error', $dict)) {
                    $comment = ChangesDiffRetryKeywordHandler::GENERIC_ERROR_MESSAGE;
                } else {
                    $comment = $dict['error'];
                }
                break;
            case 52: // this is a curl error message for when there is no reply
                $comment = 'Unable to connect to Changes to restart the builds.';
                break;
            default:
                $comment = ChangesDiffRetryKeywordHandler::GENERIC_ERROR_MESSAGE;
                break;
        }
        if (!$comment) {
            return;
        }
        $changesBotUsername = PhabricatorEnv::getEnvConfigIfExists('changes.bot.username');
        $user = id(new PhabricatorUser())->loadOneWhere("username = %s", 'changesbot');
        if (!$user) {
            return;
        }
        $xactions = [
            id(new DifferentialTransaction())
            ->setTransactionType(PhabricatorTransactions::TYPE_COMMENT)
            ->attachComment(
                id(new DifferentialTransactionComment())
                    ->setAuthorPHID($user->getPHID())
                    ->setRevisionPHID($storyObject->getPHID())
                    ->setContent($comment))
            ];
        $revision = id(new DifferentialRevisionQuery())
            ->setViewer($user)
            ->withIDs(array($storyObject->getID()))
            ->needReviewerStatus(true)
            ->executeOne();
        $editor = id(new DifferentialTransactionEditor())
            ->setActor($user)
            ->setContentSource(PhabricatorContentSource::newForSource(PhabricatorContentSource::SOURCE_DAEMON, array()))
            ->setContinueOnMissingFields(true)
            ->setContinueOnNoEffect(true);
        $editor->applyTransactions($revision, $xactions);
    }
}
