<?php

abstract class ChangesKeywordHandler {
    /**
     * @return string The keyword (regex) that should trigger
     *                this handler.
     */
    abstract public function getKeyword();

    /**
     * This method is called when the handler's keyword matches a comment.
     *
     * @param feedWorker The feedWorker that triggered the handler
     * @param storyObject
     * @param viewer
     * @param publisher
     */
    abstract public function runOnObject($feedWorker, $storyObject, $viewer, $publisher);
}
