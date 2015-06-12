<?php

class DoorkeeperChangesTestFeedWorker extends DoorkeeperChangesFeedWorker {

  public $_diffKeywordHandlers = null;
  public $_comment = null;

  /**
   * Add more handlers here
   */
  protected function getDiffKeywordHandlers() {
      return $this->_diffKeywordHandlers;
  }

  protected function getComment() {
      return $this->_comment;
  }

  protected function getStoryObject() {
      return new DifferentialRevision();
  }

  public function publish() {
      $this->publishFeedStory();
  }
}
