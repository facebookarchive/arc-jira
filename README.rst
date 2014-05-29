================
 About Arc-JIRA
================

**Note that this project is is deprecated. It is neither supported nor any longer the best way to integrate Phabricator with JIRA. This is an archived project and is no longer supported or updated by Facebook. Please do not file issues or pull-requests against this repo.**

This package provides Arcanist extension that allows integration between
Differential code review tool and JIRA issue tracker.

==============
 Installation
==============

Basic Arcanist configuration
============================

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
`https://issues.apache.org/jira/si/`.

Extended Arcanist configuration
===============================

These configuration options are optional, but will provide greater amount of
integration between Phabricator and JIRA.

JIRA project
------------

With basic configuration you'll have to specify full issue name when calling
`arc diff`, for example `arc diff --jira PROJECT-123`.  If you add this::

  "jira_project" : "PROJECT"

to your configuration file you'll be able to use both `arc diff --jira
PROJECT-123` and `arc diff --jira 123`.

Linting
-------

Arc-JIRA includes a basic linter for Java code that checks line lengths,
trailing spaces, etc.  To use the linter add this to your configuration::

  "lint_engine" : "JavaLintEngine"

and optionally::

  "max_line_length" : "82"

The default line length is `80` characters.  The linter will automatically run
on modified files when you use `arc diff`.

Attribution
-----------

If patch author differs from the committer some Apache projects add `(Author via
Committer)` to the commit message.  To get this behaviour when using `arc
commit`, add this to your configuration file::

  "events.listeners" : ["CommitListener"]

Resolving JIRA issue after committing
-------------------------------------

Unfortunately currently running `arc commit` won't resolve the linked JIRA issue
automatically for you, but it can provide you with a link to resolving the issue
in JIRA web interface.  If you add this::

  "jira_base_url" : "http://my.jira.instance/some/path/"

to your configuration file, Arc-JIRA will provide you with a link to resolving
linked JIRA issue after you run `arc commit`.

Apache projects
~~~~~~~~~~~~~~~

For Apache projects `jira_base_url` should be set to
`https://issues.apache.org/jira/secure/`.

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
  Subject: PROJECT-123 [jira] Create new JIRA user.

  Setting Phabricator user name.

where `PROJECT-123` is a name of some JIRA issue.

=======
 Usage
=======

Arc-JIRA workflow differs only minimally from the standard Arcanist workflow.

First time you issue `arc diff` you should add `--jira` switch with JIRA issue
ID, for example `arc diff --jira PROJECT-123`.  That's all, other steps are the
same as in standard Arcanist workflow.
