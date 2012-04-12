<?php

/*
 * Copyright 2012 Facebook
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

class ArcJIRAConfiguration extends ArcanistConfiguration {
  private $workflow;
  private $repositoryApi;
  private $conduit;
  private $jiraId;
  private $jiraApiUrl;
  private $jiraInfo;
  private $comment;

  private function willRunDiffWorkflow() {
    $this->getJiraId();
    // If JIRA parameter is not set, abort custom code execution.
    if (!$this->jiraId) {
      return;
    }
    $this->getJiraApiUrl();
    $this->getJiraInfo();

    // Magic in the pre hook happens only for Git.
    if (!($this->repositoryApi instanceof ArcanistGitAPI)) {
      return;
    }

    $parser = new ArcanistDiffParser();

    $this->repositoryApi->parseRelativeLocalCommit(
      $this->workflow->getArgument('paths')
    );
    $log = $this->repositoryApi->getGitCommitLog();
    $changes = $parser->parseDiff($log);

    // Number of valid (Differential formatted) commits in given range.
    $valid = 0;
    // True if last commit message is valid (Differential formatted).
    $in_last = false;
    foreach (array_reverse($changes) as $key => $change) {
      $message = ArcanistDifferentialCommitMessage::newFromRawCorpus(
        $change->getMetadata('message')
      );
      if ($message->getRevisionID()) {
        $valid += 1;
        $in_last = true;
      } else {
        $in_last = false;
      }
    }

    // Broken history, let `arc diff` complain.
    if ($valid > 1) {
      return;
    }
    // User is stacking commits and `arc diff` was already called before.
    if ($valid == 1 && !$in_last) {
      return;
    }

    // If we got here, there is no valid revision or only last revision
    // is valid.

    $change = reset($changes);
    if ($change->getType() != ArcanistDiffChangeType::TYPE_MESSAGE) {
      throw new Exception('Expected message change.');
    }

    $msg_body = $change->getMetadata('message');
    if (strpos($msg_body, 'Test Plan:') === false) {
       $msg_body .= "\n\nTest Plan: EMPTY\n";
    }

    $message = ArcanistDifferentialCommitMessage::newFromRawCorpus($msg_body);

    $revision_id = $message->getRevisionID();

    $message->pullDataFromConduit($this->conduit);

    $match = null;
    // Get JIRA ID from the commit message if provided.
    preg_match(
      '/[[:alnum:]]+-[[:digit:]]+\s+\[jira\]/',
      $message->getFieldValue('title'),
      $match
    );
    // JIRA ID already in commit message, behave like normal `arc diff`
    if ($match) {
      return;
    }

    // CC and Reviewers are messed up in $message->getFields() - they use
    // PHIDs instead of normal user names.  They have to be converted back.
    if (array_key_exists('ccPHIDs', $message->getFields())) {
      $ccPHIDs = $message->getFieldValue('ccPHIDs');
      $cc = array();
      foreach ($ccPHIDs as $phid) {
        $cc[] = $this->userPHIDToName($phid);
      }
      $message->setFieldValue('ccPHIDs', $cc);
    }
    if (array_key_exists('reviewerPHIDs', $message->getFields())) {
      $reviewerPHIDs = $message->getFieldValue('reviewerPHIDs');
      $reviewer = array();
      foreach ($reviewerPHIDs as $phid) {
        $reviewer[] = $this->userPHIDToName($phid);
      }
      $message->setFieldValue('reviewerPHIDs', $reviewer);
    }

    // Add JIRA user to reviewers.
    if (!array_key_exists('reviewerPHIDs', $message->getFields())) {
      $message->setFieldValue('reviewerPHIDs', array());
    }
    $reviewers = $message->getFieldValue('reviewerPHIDs');
    $reviewers[] = 'JIRA';
    $message->setFieldValue('reviewerPHIDs', $reviewers);

    // Adding a dummy test plan if one is not provided.
    if (!$message->getFieldValue('testPlan')) {
      $message->setFieldValue('testPlan', 'EMPTY');
    }

    $title = $this->jiraInfo['title'];
    $description = $this->jiraInfo['description'];
    $commit_title = $message->getFieldValue('title');
    $commit_summary = $message->getFieldValue('summary');

    // Set new title and summary.
    $message->setFieldValue('title', $title);
    $message->setFieldValue(
      'summary',
      $commit_title . "\n\n" . $commit_summary . "\n\n" . $description
    );

    $fields = $message->getFields();
    $msg = $this->jiraId . ' [jira] ' . $fields['title'];

    if (array_key_exists('summary', $fields)) {
      $msg .= "\n\nSummary:\n" . $fields['summary'];
    }

    if (array_key_exists('testPlan', $fields)) {
      $msg .= "\n\nTest Plan:\n" . $fields['testPlan'];
    }

    if (array_key_exists('ccPHIDs', $fields)) {
      $msg .= "\n\nCC: " . implode(' ', $fields['ccPHIDs']);
    }

    if (array_key_exists('reviewerPHIDs', $fields)) {
      $msg .= "\n\nReviewers: " . implode(' ', $fields['reviewerPHIDs']);
    }

    if (array_key_exists('revisionID', $fields)) {
      $msg .= "\n\nDifferential Revision: " . $fields['revisionID'];
    }

    $this->repositoryApi->amendGitHeadCommit($msg);
  }

  private function didRunDiffWorkflow() {
    // Magic in the post hook happens only for SVN.
    if (!($this->repositoryApi instanceof ArcanistSubversionAPI)) {
      return;
    }

    $diff_id = $this->workflow->getDiffID();

    $commitable_revisions = $this->conduit->callMethodSynchronous(
      'differential.find',
      array(
        'query' => 'commitable',
        'guids' => array($this->workflow->getUserPHID())
      )
    );
    $open_revisions = $this->conduit->callMethodSynchronous(
      'differential.find',
      array(
        'query' => 'open',
        'guids' => array($this->workflow->getUserPHID())
      )
    );
    $revisions_data = array_merge($open_revisions, $commitable_revisions);
    $revisions = array();
    foreach ($revisions_data as $revision) {
      $ref = ArcanistDifferentialRevisionRef::newFromDictionary($revision);
      $revisions[$ref->getId()] = $ref;
    }

    $revision = null;

    if (count($revisions) == 0) {
      // $revision stays null.
      if (!$this->jiraId) {
        // Just create the diff.
        return;
      }
    } else {
      // Choose revision from available ones.
      $choices = array();
      echo "\n\n";
      $ii = 1;
      foreach ($revisions as $ref) {
        $choices[$ii] = $ref;
        echo ' ['.$ii++.'] D'.$ref->getID().' '.$ref->getName()."\n";
      }
      if ($this->jiraId !== null) {
        $choices[$ii] = null;
        echo ' ['.$ii++."] Create a new revision\n";
      }
      echo ' ['.$ii."] Don't attach this diff to any revision\n";

      $valid_choice = false;
      while (!$valid_choice) {
        $id = phutil_console_prompt('Which revision do you want to update?');
        $id = trim(strtoupper($id));
        if (strpos($id, 'D') === 0) {
          $id = trim($id, 'D');
          if (isset($revisions[$id])) {
            $valid_choice = true;
            $revision = $revisions[$id];
          }
        } else {
          $id = intval($id);
          if ($id > 0 && $id < $ii) {
            $valid_choice = true;
            $revision = $choices[$id];
          }
          if ($id == $ii) {
            // Just create the diff.
            return;
          }
        }
      }
    }

    $this->getComment();

    if ($revision === null) {
      // Create a new revision.
      $title = $this->jiraInfo['title'];
      $description = $this->jiraInfo['description'];

      $summary = $this->jiraInfo['link'] . "\n\n"
          . $this->comment . "\n\n" . $description;

      $jira_phid = $this->conduit->callMethodSynchronous(
        'user.find',
        array(
          'aliases' => array('JIRA')
        )
      );
      $jira_phid = $jira_phid['JIRA'];

      $revision = array(
        'diffid' => $diff_id,
        'fields' => array(
          'title' => $this->jiraId . ' [jira] ' . $title,
          'summary' => $summary,
          'reviewerPHIDs' => array($jira_phid),
          'testPlan' => 'EMPTY'
        )
      );

      $result = $this->conduit->callMethodSynchronous(
        'differential.createrevision',
        $revision
      );

      echo "\n\nCreated new revision D".$result['revisionid']."\n";
      echo $result['uri']."\n";
    } else {
      // Update existing revision.
      $result = $this->conduit->callMethodSynchronous(
        'differential.updaterevision',
        array(
          'id' => $revision->getId(),
          'diffid' => $diff_id,
          'fields' => array(),
          'message' => $this->comment
        )
      );

      echo "\n\nUpdated revision D".$result['revisionid']."\n";
      echo $result['uri']."\n";
    }
  }

  private function didRunCommitWorkflow() {
    // Commit is only for SVN => we are using SVN.

    // User didn't actually commit anything, abandon custom workflow.
    if ($this->workflow->getArgument('show')) {
        return;
    }

    $jira_base_url = $this->workflow->getWorkingCopy()
      ->getConfig('jira_base_url');
    if (!$jira_base_url) {
      // Without JIRA URL we can't do anything.
      return;
    }

    $revision_id = $this->workflow->getRevisionID();
    $revision = $this->conduit->callMethodSynchronous(
      'differential.getrevision',
      array(
        'revision_id' => $revision_id,
      )
    );
    $match = null;
    // Get JIRA ID from the title.
    preg_match(
      '/([[:alnum:]]+-[[:digit:]]+)\s+\[jira\]/',
      $revision['title'],
      $match
    );
    // No JIRA ID in the title, abandon custom workflow.
    if (!$match) {
      return;
    }
    $this->jiraId = $match[1];
    try {
      $this->getJiraApiUrl();
      $this->getJiraInfo();

      // 5 means resolved.
      if ($this->jiraInfo['status'] == 5) {
        return; // Issue was already resolved.
      }
      $action = 5;
      // 10002 means patch available.
      if ($this->jiraInfo['status'] == '10002') {
        $action = 741;
      }

      echo "\n\nCongratulations!  ".
        "Now you can go to this URL and resolve the issue:\n";
      echo $jira_base_url.'CommentAssignIssue!default.jspa?action='.
        $action.'&id='.$this->jiraInfo['key']."\n";
    } catch (Exception $ex) {
      echo "\n\nCommit was successful, but unable to access JIRA for ".
        "status update.  Remember to mark the issue resolved after you've ".
        "reviewed it.\n";
    }
  }

  public function willRunWorkflow($command, ArcanistBaseWorkflow $workflow) {
    $this->workflow = $workflow;
    if ($workflow->requiresRepositoryAPI()) {
      $this->repositoryApi = $workflow->getRepositoryAPI();
    }
    if ($workflow->requiresAuthentication() || $workflow->requiresConduit()) {
      $this->conduit = $workflow->getConduit();
    }
    if ($workflow instanceof ArcanistDiffWorkflow) {
      $this->willRunDiffWorkflow();
    }
  }

  public function didRunWorkflow(
      $command,
      ArcanistBaseWorkflow $workflow,
      $err
    ) {
    if ($workflow instanceof ArcanistDiffWorkflow) {
      $this->didRunDiffWorkflow();
    } else if ($workflow instanceof ArcanistCommitWorkflow) {
      $this->didRunCommitWorkflow();
    }
  }

  private function getJiraId() {
      // Get JIRA ID from command line.
      $jiraId = $this->workflow->getArgument('jira');

      // No JIRA ID provided.
      if (!$jiraId) {
        return;
      }

      $match = null;
      preg_match(
        '/^[[:alnum:]]+-[[:digit:]]+$/',
        $jiraId,
        $match
      );

      // User didn't provide full ID with project name and issue number.
      if (!$match) {
        $jira_project = $this->workflow->getWorkingCopy()
          ->getConfig('jira_project');
        if (!$jira_project) {
          throw new ArcanistUsageException(
            'You haven\'t provided full JIRA ID with project name and issue '.
            'number, and `jira_project` is not set in `.arcconfig`.  Use '.
            'full JIRA ID like `--jira HIVE-2486` or set `jira_project` and '.
            'use `--jira 2486`.'
          );
        }

        $match = null;
        preg_match(
          '/^[[:digit:]]+$/',
          $jiraId,
          $match
        );
        if (!$match) {
          throw new ArcanistUsageException(
            'Provided JIRA ID is in wrong format, either specify full ID '.
            'with project name and issue number like `--jira HIVE-2486` or '.
            'provide only the issue number like `--jira 2486`.'
          );
        }

        $jiraId = $jira_project . '-' . $jiraId;
      }

      $this->jiraId = $jiraId;
  }

  private function getJiraApiUrl() {
    $jiraApiUrl = $this->workflow->getWorkingCopy()
      ->getConfig('jira_api_url');

    if (!$jiraApiUrl) {
      throw new ArcanistUsageException(
        'To use `--jira` switch, you have to set `jira_api_url` in your '.
        '`.arcconfig` file'
      );
    }

    $this->jiraApiUrl = $jiraApiUrl;
  }

  private function getJiraInfo() {
    // Pull title and description from JIRA
    $curl = curl_init(
      $this->jiraApiUrl
      . 'jira.issueviews:issue-xml/'
      . $this->jiraId
      . '/'
      . $this->jiraId
      . '.xml?field=title&field=description&field=key&field=status&field=link'
    );
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    $issue = curl_exec($curl);
    try {
      // Will fail both when we fail to connect and if we get non XML
      // response.
      $issue = new SimpleXMLElement($issue, LIBXML_NOERROR);
    } catch (Exception $e) {
      throw new Exception(
        'Failed to get issue information from JIRA.  Check if you can '.
        'connect to used JIRA instance and check if given JIRA ID doesn\'t '.
        'contain errors.'
      );
    }
    $title = (string) $issue->channel->item->title;
    $description = (string) $issue->channel->item->description;
    $key = idx(idx((array) $issue->channel->item->key, '@attributes'), 'id');
    $key = (string) $key;
    $status = idx(
      idx((array) $issue->channel->item->status, '@attributes'),
      'id'
    );
    $status = (string) $status;
    // 1 -> open
    // 4 -> reopened
    // 5 -> resolved
    // 10002 -> patch available
    $link = (string) $issue->channel->item->link;

    if (!$key) {
      throw new Exception('Failed to get issue key from JIRA.');
    }
    if (!$status) {
      throw new Exception('Failed to get issue status from JIRA.');
    }
    if (!$title) {
      throw new Exception('Failed to get issue title from JIRA.');
    }
    $title = preg_replace(
      '/\[[[:alnum:]]+-[[:digit:]]+\](.*)/',
      '$1',
      $title
    );
    $title = trim(html_entity_decode($title, ENT_QUOTES));

    // Different issue types have or don't have descriptions.  Bugs, New
    // Features and Improvements do, Tasks don't, so don't panic if it fails.
    if (!$description) {
      $description = '';
    }
    // Remove HTML markup.
    $description = strip_tags($description);
    $description = trim(html_entity_decode($description, ENT_QUOTES));

    $this->jiraInfo = array(
      'title' => $title,
      'description' => $description,
      'key' => $key,
      'status' => $status,
      'link' => $link,
    );
  }

  private function getComment() {
    $template =
      "\n\n".
      '# Enter a brief description of the changes included in this update.'.
      "\n";

    $comment = id(new PhutilInteractiveEditor($template))
      ->editInteractively();
    $comment = preg_replace('/^\s*#.*$/m', '', $comment);
    $comment = rtrim($comment);

    $this->comment = $comment;
  }

  private function userPHIDToName($phid) {
    $user = $this->conduit->callMethodSynchronous(
      'user.info',
      array(
        'phid' => $phid
      )
    );
    return idx($user, 'userName');
  }

  public function getCustomArgumentsForCommand($command) {
    if ($command != 'diff') return array();
    return array(
      'jira' => array(
        'help' => 'Link this diff to a JIRA issue.',
        'param' => 'issue'
      )
    );
  }
}
