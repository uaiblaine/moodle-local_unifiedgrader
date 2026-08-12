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
    # A grade is saved first on purpose. Without one this scenario proves almost
    # nothing: assign_grades starts at -1, so "the grade ends up cleared" holds
    # whether or not the reset does anything, and the on-screen check only reads
    # back the value the panel blanked synchronously. That is exactly how the
    # sibling "-" path stayed broken while looking covered.
    When I am on the Unified Grader for activity "Essay 1"
    And the marking panel has loaded
    And I enter "15" as the overall grade
    Then the saved grade for "student1" on "Essay 1" is "15"
    When I enter "x" as the overall grade
    Then the overall grade shows ""
    And I should not see "Exception"
    And the saved grade for "student1" on "Essay 1" is ""

  # There is deliberately no "--" scenario here. The deliberate reset differs
  # from "-" only in what it does to the submission row, and none of that is
  # observable through the browser: resolve_status() already reports a row with
  # status "new" as "nosubmission", so the page reads the same before and after,
  # and remove_submission() never deletes the row anyway. What it really does -
  # drop the submission plugin data, and only when the grader holds
  # mod/assign:editothersubmission - is asserted directly against the database
  # in assign_adapter_test::test_full_reset_strips_the_orphan_only_with_the_capability.
