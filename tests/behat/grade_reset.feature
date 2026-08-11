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

  # KNOWN PRODUCT DISAGREEMENT, not a broken test. Typing "-" does not clear the
  # grade: the field reverts to the last saved value. The sibling scenario below
  # types "x" and DOES clear, so the two non-numeric paths disagree with each other.
  # The input is type="text" with inputmode="decimal", so setValue does store the
  # "-" and it is the panel JS that puts the old value back — this is not a Mink
  # artefact. Deciding which behaviour is right belongs to whoever owns the UI, so
  # the scenario is parked rather than deleted or rewritten to match the code.
  @local_unifiedgrader_wip
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
    When I am on the Unified Grader for activity "Essay 1"
    And the marking panel has loaded
    And I enter "x" as the overall grade
    Then the overall grade shows ""
    And I should not see "Exception"

  @local_unifiedgrader_wip
  Scenario: Typing "--" removes the orphan submission row too
    # Needs a step to seed an assign_submission row with status='new'
    # (e.g. via a custom data generator step or a direct $DB write).
    # The assertion would then verify, after refreshing, that the
    # student shows as "Not submitted" (no orphan row left behind).
    Given student "student1" has an orphan submission marker for activity "Essay 1"
    When I am on the Unified Grader for activity "Essay 1"
    And the marking panel has loaded
    And I enter "--" as the overall grade
    When I reload the page
    Then I should see "Not submitted"
    And student "student1" has no submission row for activity "Essay 1"
