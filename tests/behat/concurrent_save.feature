@local @local_unifiedgrader @local_unifiedgrader_critical @javascript
Feature: A save requested while another one is in flight is not dropped
  As a teacher who kept marking while the panel was still saving
  I want the second save to happen once the first one lands
  So that the mark I typed is not lost behind a save that looked successful

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

  Scenario: A grade corrected during a save round trip still reaches the server
    When I am on the Unified Grader for activity "Essay 1"
    And the marking panel has loaded
    # Type a mark and leave the field (which saves), then correct it and leave
    # again while that first save is still running. Fails if a save requested
    # during another one is dropped: the student would keep the first mark.
    When I enter "12" as the overall grade and correct it to "17" before the save lands
    Then the saved grade for "student1" on "Essay 1" is "17"
    # And the teacher sees the correction on their next visit, not the typo.
    When I reload the page
    And the marking panel has loaded
    Then the overall grade shows "17"

  Scenario: A criterion score changed during a save round trip still reaches the server
    Given a marking guide is attached to "Essay 1" with criteria:
      | shortname     | maxscore |
      | Argumentation | 20       |
    When I am on the Unified Grader for activity "Essay 1"
    And the marking panel has loaded
    # A criterion change saves through the debounced autosave, the path every
    # rubric level and every ranged rubric slider takes. Fails if that path drops
    # a request made while a save is in flight and the finishing save marks the
    # change clean: the student would keep the first score.
    When I score "Argumentation" "8", save, and change it to "15" before the save lands
    Then the saved grade for "student1" on "Essay 1" is "15"
