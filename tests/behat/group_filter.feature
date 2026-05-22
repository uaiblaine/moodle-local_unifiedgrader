@local @local_unifiedgrader @local_unifiedgrader_critical @javascript
Feature: Group filter defaults to the teacher's group and persists across refreshes
  As a teacher who only sees a subset of groups
  I want the grader to remember my last group selection
  So that I don't have to re-select my group every time I reload the page

  Background:
    Given the following "courses" exist:
      | fullname    | shortname | category | groupmode |
      | Test Course | TC101     | 0        | 1         |
    And the following "groups" exist:
      | name    | course | idnumber |
      | Group A | TC101  | gA       |
      | Group B | TC101  | gB       |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teach     | One      | teacher1@example.com |
      | student1 | Stu       | A1       | student1@example.com |
      | student2 | Stu       | B1       | student2@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | TC101  | editingteacher |
      | student1 | TC101  | student        |
      | student2 | TC101  | student        |
    And the following "group members" exist:
      | user     | group |
      | teacher1 | gA    |
      | student1 | gA    |
      | student2 | gB    |
    And the following "activities" exist:
      | activity | name    | course | idnumber | grade | groupmode |
      | assign   | Essay 1 | TC101  | a1       | 20    | 1         |
    And I log in as "teacher1"

  Scenario: Teacher in one group sees only their group's students by default
    # On initial load, grade.php picks the teacher's own group as the
    # default selection (precedence rule, see preferences_manager).
    When I am on the Unified Grader for activity "Essay 1"
    And the marking panel has loaded
    Then I should see "Stu A1"
    And I should not see "Stu B1"

  @local_unifiedgrader_wip
  Scenario: Changing the group filter persists across page reload
    # The exact DOM selectors for the multi-select group dropdown
    # depend on student_navigator.js's render — needs a custom step
    # like `When I select group "Group B" in the navigator` once the
    # selector is finalised.
    When I am on the Unified Grader for activity "Essay 1"
    And the marking panel has loaded
    And I select group "Group B" in the navigator
    Then I should see "Stu B1"
    And I should not see "Stu A1"
    When I reload the page
    And the marking panel has loaded
    # Persistence: the same group stays selected after refresh.
    Then I should see "Stu B1"
    And I should not see "Stu A1"
