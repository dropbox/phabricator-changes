<?php

class TestKeywordHandler extends ChangesKeywordHandler {
    public $ran = false;
    public $keyword = '/!magic/';
    public function getKeyword() {
        return $this->keyword;
    }

    public function runOnObject($feedWorker, $storyObject, $viewer, $publisher) {
        $this->ran = true;
    }
}

final class DoorkeeperChangesFeedWorkerTestCase extends PhabricatorTestCase {
    public function testSimpleMatched() {
        $keywordHandler = new TestKeywordHandler();
        $worker = new DoorkeeperChangesTestFeedWorker(array());
        $worker->_comment = "This is the !magic comment";
        $worker->_diffKeywordHandlers = [$keywordHandler];
        $worker->publish();
        $this->assertEqual(true, $keywordHandler->ran);
    }
    public function testSimpleUnmatched() {
        $keywordHandler = new TestKeywordHandler();
        $worker = new DoorkeeperChangesTestFeedWorker(array());
        $worker->_comment = "This is the comment";
        $worker->_diffKeywordHandlers = [$keywordHandler];
        $worker->publish();
        $this->assertEqual(false, $keywordHandler->ran);
    }
    public function testRegexSpace() {
        $keywordHandler = new TestKeywordHandler();
        $keywordHandler->keyword = '/\s+/';
        $worker = new DoorkeeperChangesTestFeedWorker(array());
        $worker->_comment = "This is a comment";
        $worker->_diffKeywordHandlers = [$keywordHandler];
        $worker->publish();
        $this->assertEqual(true, $keywordHandler->ran);
    }
    public function testRegexNoSpace() {
        $keywordHandler = new TestKeywordHandler();
        $keywordHandler->keyword = '/\s+/';
        $worker = new DoorkeeperChangesTestFeedWorker(array());
        $worker->_comment = "Thisisacomment";
        $worker->_diffKeywordHandlers = [$keywordHandler];
        $worker->publish();
        $this->assertEqual(false, $keywordHandler->ran);
    }
}
