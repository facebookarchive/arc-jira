<?php

/*
 * Copyright 2011 Facebook
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
  public function willRunWorkflow($command, ArcanistBaseWorkflow $workflow) {
    if ($workflow instanceof ArcanistDiffWorkflow) {
      $repository_api = $workflow->getRepositoryAPI();
      // Magic happens only for Git.
      if (!($repository_api instanceof ArcanistGitAPI)) {
        return;
      }

      $conduit = $workflow->getConduit();
      $parser = new ArcanistDiffParser();

      $repository_api->parseRelativeLocalCommit(
        $workflow->getArgument('paths')
      );
      $log = $repository_api->getGitCommitLog();
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

      // No valid revision or only last revision is valid.

      $change = reset($changes);
      if ($change->getType() != ArcanistDiffChangeType::TYPE_MESSAGE) {
        throw new Exception('Expected message change.');
      }
      $message = ArcanistDifferentialCommitMessage::newFromRawCorpus(
        $change->getMetadata('message')
      );

      $revision_id = $message->getRevisionID();

      $message->pullDataFromConduit($conduit);

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

      $jira_id = null;
      // Get JIRA ID from command line.
      if ($workflow->getArgument('jira')) {
        $jira_id = $workflow->getArgument('jira');
      }

      // There is no JIRA ID in either commit message, or command line.
      // Abort execution of custom code.
      if (!$jira_id) {
        return;
      }

      $match = null;
      preg_match(
        '/^[[:alnum:]]+-[[:digit:]]+$/',
        $jira_id,
        $match
      );
      // User didn't provide full ID with project name and issue number.
      if (!$match) {
        $jira_project = $workflow->getWorkingCopy()->getConfig('jira_project');
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
          $jira_id,
          $match
        );
        if (!$match) {
          throw new ArcanistUsageException(
            'Provided JIRA ID is in wrong format, either specify full ID '.
            'with project name and issue number like `--jira HIVE-2486` or '.
            'provide only the issue number like `--jira 2486`.'
          );
        }

        $jira_id = $jira_project . '-' . $jira_id;
      }
      $message->setFieldValue('jira', $jira_id);

      $jira_api_url = $workflow->getWorkingCopy()->getConfig('jira_api_url');

      if (!$jira_api_url) {
        throw new ArcanistUsageException(
          'To use `--jira` switch, you have to set `jira_api_url` in your '.
          '`.arcconfig` file'
        );
      }

      // CC and Reviewers are messed up in $message->getFields() - they use
      // PHIDs instead of normal user names.  They have to be converted back.
      if (array_key_exists('ccPHIDs', $message->getFields())) {
        $ccPHIDs = $message->getFieldValue('ccPHIDs');
        $cc = array();
        foreach ($ccPHIDs as $phid) {
          $cc[] = $this->userPHIDToName($conduit, $phid);
        }
        $message->setFieldValue('ccPHIDs', $cc);
      }
      if (array_key_exists('reviewerPHIDs', $message->getFields())) {
        $reviewerPHIDs = $message->getFieldValue('reviewerPHIDs');
        $reviewer = array();
        foreach ($reviewerPHIDs as $phid) {
          $reviewer[] = $this->userPHIDToName($conduit, $phid);
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

      // Pull title and description from JIRA
      $curl = curl_init(
        $jira_api_url
        . 'jira.issueviews:issue-xml/'
        . $message->getFieldValue('jira')
        . '/'
        . $message->getFieldValue('jira')
        . '.xml?field=title&field=description'
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
          'connect to used JIRA instance and check if given JIRA ID doesn\'t'.
          'contain errors.'
        );
      }
      $title = $issue->channel->item->title;
      $description = $issue->channel->item->description;

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
      $description = str_replace("\n", ' ', $description);
      $description = str_replace('<br/>', "\n", $description);
      $description = str_replace('<p>', "\n\n", $description);
      $description = str_replace('</p>', ' ', $description);
      $description = preg_replace('/<a[^>]*>([^<]*)<\/a>/', '$1', $description);
      $description = preg_replace("/\n */", "\n", $description);
      $description = trim(html_entity_decode($description, ENT_QUOTES));

      $commit_title = $message->getFieldValue('title');
      $commit_summary = $message->getFieldValue('summary');

      // Set new title and summary.
      $message->setFieldValue('title', $title);
      $message->setFieldValue(
        'summary',
        $commit_title . "\n\n" . $commit_summary . "\n\n" . $description
      );

      $fields = $message->getFields();
      $msg = $fields['jira'] . ' [jira] ' . $fields['title'];

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

      $repository_api->amendGitHeadCommit($msg);
    }
  }

  private function userPHIDToName($conduit, $phid) {
    $user = $conduit->callMethodSynchronous(
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
