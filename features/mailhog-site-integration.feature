Feature: Mailhog and the site lifecycle
  How mailhog interacts with other ee commands: site types without a mailhog service, site create on dev environments, site disable/enable/refresh/restart/delete, and state drift behind ee's back.

  # Keep first: it creates bh-mailhog-4.test, which the later scenarios reuse.
  Scenario: Creating a WordPress site with --env=production does not enable mailhog
    When I run 'bin/ee site create bh-mailhog-4.test --type=wp --env=production'
    Then the command should succeed
    And STDOUT should not contain "Enabling mailhog"
    And the site "bh-mailhog-4.test" should have "mailhog_enabled" set to "0"
    And the "mailhog" service of site "bh-mailhog-4.test" should not be running

  Scenario Outline: Creating a WordPress site with --env=<env> enables mailhog
    When I run 'bin/ee site create <site> --type=wp --env=<env>'
    Then the command should succeed
    And STDOUT should contain "Enabling mailhog as this is a <env> server."
    And STDOUT should contain "Mailhog enabled for <site> site"
    And the site "<site>" should have "mailhog_enabled" set to "1"
    And the "mailhog" service of site "<site>" should be running
    And the file "config/postfix/main.cf" of site "<site>" should match "/^relayhost = mailhog:1025$/m"

    Examples:
      | env     | site              |
      | local   | bh-mailhog-7.test |
      | develop | bh-mailhog-8.test |

  Scenario Outline: <type> sites have no mailhog service, so mailhog <subcommand> fails
    Given the site "<site>" exists with flags "--type=<type>"
    When I run 'bin/ee mailhog <subcommand> <site>'
    Then the exit code should be 1
    And STDERR should contain "<site> site does not have support to enable/disable mailhog."
    And the site "<site>" should have "mailhog_enabled" set to "0"
    And the "mailhog" service of site "<site>" should not exist

    Examples:
      | type | site              | subcommand |
      | html | bh-mailhog-5.test | enable     |
      | html | bh-mailhog-5.test | disable    |
      | html | bh-mailhog-5.test | status     |
      | php  | bh-mailhog-6.test | enable     |
      | php  | bh-mailhog-6.test | disable    |
      | php  | bh-mailhog-6.test | status     |

  Scenario Outline: mailhog <subcommand> refuses a disabled site
    Given the site "bh-mailhog-4.test" exists with flags "--type=wp --env=production"
    And the site "bh-mailhog-4.test" is disabled
    When I run 'bin/ee mailhog <subcommand> bh-mailhog-4.test'
    Then the exit code should be 1
    And STDERR should contain "Site bh-mailhog-4.test does not exist / is not enabled."

    Examples:
      | subcommand |
      | enable     |
      | disable    |
      | status     |

  Scenario: Re-enabling a site brings mailhog back when it was enabled
    Given the site "bh-mailhog-4.test" exists with flags "--type=wp --env=production"
    And mailhog is enabled on site "bh-mailhog-4.test"
    When I run 'bin/ee site disable bh-mailhog-4.test'
    Then the command should succeed
    And the "mailhog" service of site "bh-mailhog-4.test" should not be running
    And the site "bh-mailhog-4.test" should have "mailhog_enabled" set to "1"
    When I run 'bin/ee site enable bh-mailhog-4.test'
    Then the command should succeed
    And STDOUT should contain "Mailhog enabled for bh-mailhog-4.test site"
    And the "mailhog" service of site "bh-mailhog-4.test" should be running
    And the file "config/postfix/main.cf" of site "bh-mailhog-4.test" should match "/^relayhost = mailhog:1025$/m"
    When I run 'bin/ee mailhog status bh-mailhog-4.test'
    Then STDOUT should be "Mailhog is UP for bh-mailhog-4.test site."

  Scenario: Re-enabling a site keeps mailhog down when it was disabled
    Given the site "bh-mailhog-4.test" exists with flags "--type=wp --env=production"
    And mailhog is disabled on site "bh-mailhog-4.test"
    When I run 'bin/ee site disable bh-mailhog-4.test'
    Then the command should succeed
    When I run 'bin/ee site enable bh-mailhog-4.test'
    Then the command should succeed
    And STDOUT should not contain "Mailhog enabled for"
    And the "mailhog" service of site "bh-mailhog-4.test" should not be running
    And the site "bh-mailhog-4.test" should have "mailhog_enabled" set to "0"
    When I run 'bin/ee mailhog status bh-mailhog-4.test'
    Then STDOUT should be "Mailhog is DOWN for bh-mailhog-4.test site."

  Scenario: ee site refresh keeps mailhog down when it was disabled
    Given the site "bh-mailhog-4.test" exists with flags "--type=wp --env=production"
    And mailhog is disabled on site "bh-mailhog-4.test"
    When I run 'bin/ee site refresh bh-mailhog-4.test'
    Then the command should succeed
    And STDOUT should not contain "Mailhog enabled for"
    And the "mailhog" service of site "bh-mailhog-4.test" should not be running
    And the site "bh-mailhog-4.test" should have "mailhog_enabled" set to "0"
    And the file "config/postfix/main.cf" of site "bh-mailhog-4.test" should not match "/^relayhost = mailhog:1025$/m"

  Scenario Outline: ee site restart does not change the mailhog state (<state>)
    Given the site "bh-mailhog-4.test" exists with flags "--type=wp --env=production"
    And mailhog is <state> on site "bh-mailhog-4.test"
    When I run 'bin/ee site restart bh-mailhog-4.test'
    Then the command should succeed
    And the "mailhog" service of site "bh-mailhog-4.test" should <running>
    And the site "bh-mailhog-4.test" should have "mailhog_enabled" set to "<flag>"

    Examples:
      | state    | running        | flag |
      | enabled  | be running     | 1    |
      | disabled | not be running | 0    |

  # MAILHOG-1 (new): status/disable look only at the container. When it is stopped outside ee, `disable` refuses ("already down") and sites.mailhog_enabled stays 1, so the next `ee site enable`/`refresh` silently starts mailhog again.
  @known-bug
  Scenario: Disabling mailhog after its container was stopped outside ee clears the DB flag
    Given the site "bh-mailhog-4.test" exists with flags "--type=wp --env=production"
    And mailhog is enabled on site "bh-mailhog-4.test"
    When I stop the "mailhog" container of site "bh-mailhog-4.test" directly
    And I run 'bin/ee mailhog disable bh-mailhog-4.test'
    Then the command should succeed
    And the site "bh-mailhog-4.test" should have "mailhog_enabled" set to "0"
    And the file "config/postfix/main.cf" of site "bh-mailhog-4.test" should match "/^relayhost =\s*$/m"

  # MAILHOG-3 (new): enable ignores the result of `postconf` in the postfix container. With postfix stopped it prints success and records mailhog_enabled=1, while postfix (started again by the final restart) keeps its old relayhost, so mail is not captured.
  @known-bug
  Scenario: Enabling mailhog while postfix is stopped still configures the relay
    Given the site "bh-mailhog-4.test" exists with flags "--type=wp --env=production"
    And mailhog is disabled on site "bh-mailhog-4.test"
    When I stop the "postfix" container of site "bh-mailhog-4.test" directly
    And I run 'bin/ee mailhog enable bh-mailhog-4.test'
    Then the "postfix" service of site "bh-mailhog-4.test" should be running
    And the file "config/postfix/main.cf" of site "bh-mailhog-4.test" should match "/^relayhost = mailhog:1025$/m"

  Scenario: Deleting a site removes its mailhog container
    Given the site "bh-mailhog-4.test" exists with flags "--type=wp --env=production"
    And mailhog is enabled on site "bh-mailhog-4.test"
    When I run 'bin/ee site delete bh-mailhog-4.test --yes'
    Then the command should succeed
    And the site "bh-mailhog-4.test" should not exist
    And the "mailhog" service of site "bh-mailhog-4.test" should not exist
    And the "postfix" service of site "bh-mailhog-4.test" should not exist
