================
 About Arc-JIRA
================

This package provides Arcanist extension that allows integration between
Differential code review tool and JIRA issue tracker.

==============
 Installation
==============

Arcanist configuration
======================

Put `arc_jira_lib` directory somewhere inside your project and update your
`.arcconfig` to look similar to this one::

  {
    "project_id" : "myproject",
    "conduit_uri" : "http://my.phabricator.instance/",
    "phutil_libraries" : {
      "arc_jira_lib" : "somewhere/arc_jira_lib"
    },
    "arcanist_configuration" : "ArcJIRAConfiguration",
    "jira_api_url" : "http://my.jira.instance/"
  }

Apache projects
---------------

For Apache projects `jira_api_url` should be set to
`http://issues.apache.org/jira/si/`.

Phabricator configuration
=========================

Arc-JIRA requires that used Phabricator instance has a user named `JIRA` with
email pointing to email interface of your JIRA instance.  This user will be used
to push comments from Differential to JIRA.

If you want to automatically attach patches to JIRA revisions when someone
updates a diff in Differential, you just have to set
`metamta.differential.attach-patches` option to `true` in Phabricator
configuration file.

Apache projects
---------------

Email interface address of Apache JIRA instance is `jira@apache.org`.

JIRA configuration
==================

JIRA will automatically create a user for your Phabricator instance, first time
it gets email from it.  If you don't have administrator access to used JIRA
instance you won't be able to change name of the Phabricator user.  It is a good
idea to send an email manually (using `sendmail` for example) from Phabricator
email address (set in Phabricator configuration as `metamta.default-address`)
before using Arc-JIRA so you can set the user name.  Example email will look
like this::

  To: jira@my.jira.instance
  From: Phabricator user name <noreply@my.phabricator.instance>
  Subject: [jira] [PROJECT-123] Create new JIRA user.

  Setting Phabricator user name.

where `PROJECT-123` is a name of some JIRA issue.

=======
 Usage
=======

Arc-JIRA workflow differs only minimally from the standard Arcanist workflow.

First time you issue `arc diff` you should add `--jira` switch with JIRA issue
ID, for example `arc diff --jira PROJECT-123`.  That's all, other steps are the
same as in standard Arcanist workflow.
