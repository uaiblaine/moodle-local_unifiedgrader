@local @local_unifiedgrader @local_unifiedgrader_critical @javascript
Feature: Dash escape hatches in the overall grade input
  As a teacher who accidentally interacted with the marking panel
  I want a low-friction way to undo the accidental grade
  So that the student doesn't end up flagged as graded with a zero (or whatever stray value)

  Background:
    Given the following "courses" exist:
      | fullname    | shortname | category |
      | Test Course | TC101     | 0        |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teach     | One      | teacher1@example.com |
      | student1 | Stu       | Dent     | student1@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | TC101  | editingteacher |
      | student1 | TC101  | student        |
    And the following "activities" exist:
      | activity | name    | course | idnumber | grade |
      | assign   | Essay 1 | TC101  | a1       | 20    |
    And I log in as "teacher1"

  Scenario: Typing "-" clears the grade
    When I am on the Unified Grader for activity "Essay 1"
    And the marking panel has loaded
    And I enter "15" as the overall grade
    # Save fires, grade persists.
    When I reload the page
    And the marking panel has loaded
    Then the overall grade shows "15"
    # Now reset.
    When I enter "-" as the overall grade
    Then the overall grade shows ""
    # After refresh, server-side state should reflect "no grade" (-1
    # internally → blank in the display).
    When I reload the page
    And the marking panel has loaded
    Then the overall grade shows ""

  Scenario: Typing a stray non-numeric value also resets without error
    # Regression for the original "lone - throws PARAM_FLOAT exception"
    # bug — any non-numeric input should be normalised to a clean reset.
    #
    # A grade is saved first on purpose. Without one the stored grade already
    # reads as "no grade", so the final check holds whether or not the reset did
    # anything, and the on-screen check only reads back the value the panel
    # blanks before sending.
    When I am on the Unified Grader for activity "Essay 1"
    And the marking panel has loaded
    And I enter "15" as the overall grade
    Then the saved grade for "student1" on "Essay 1" is "15"
    When I enter "x" as the overall grade
    Then the overall grade shows ""
    And I should not see "Exception"
    And the saved grade for "student1" on "Essay 1" is ""

  # No "--" scenario here on purpose: the deliberate reset differs from "-" only
  # in the submission row, which the page cannot show (resolve_status() already
  # reports a row with status "new" as "nosubmission"). It is tested against the
  # database in assign_adapter_test::test_full_reset_strips_the_orphan_only_with_the_capability.
  # The one thing the page shows, the warning when the submission has to be kept,
  # is in js_save_fixes.feature.
