@local @local_unifiedgrader @local_unifiedgrader_critical @javascript
Feature: The quiz total is a readout, not an entry field
  As a teacher marking a quiz
  I want the overall grade box to show the computed total and refuse typing
  So that I am not invited to enter a mark the quiz cannot store

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
      | activity | name       | course | idnumber | grade |
      | quiz     | Pop quiz   | TC101  | q1       | 100   |
      | assign   | Essay 1    | TC101  | a1       | 20    |
    # Quiz support is opt-in; without it the grader refuses the cmid outright.
    And the "local_unifiedgrader/enable_quiz" admin setting is "1"
    And I log in as "teacher1"

  Scenario: The quiz grade box is read-only and says where its value comes from
    # quiz_grades is derived state — mod_quiz recomputes it from the attempt
    # marks, so a typed total has nowhere to live and was silently discarded.
    When I am on the Unified Grader for activity "Pop quiz"
    And the marking panel has loaded
    Then the overall grade box is read-only
    And I should see "Calculated from the question marks below"

  Scenario: An assignment grade box is still editable
    # The control. Without it the scenario above passes just as well if the box
    # were read-only everywhere, or if the readonly rule fired on every activity.
    When I am on the Unified Grader for activity "Essay 1"
    And the marking panel has loaded
    Then the overall grade box is editable
    And I should not see "Calculated from the question marks below"
