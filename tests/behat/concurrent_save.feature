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
    # again while that first save is still running. The correction used to be
    # dropped silently — the panel refused any save while one was in flight —
    # so the student kept the first, wrong mark.
    When I enter "12" as the overall grade and correct it to "17" before the save lands
    Then the saved grade for "student1" on "Essay 1" is "17"
    # And the teacher sees the correction on their next visit, not the typo.
    When I reload the page
    And the marking panel has loaded
    Then the overall grade shows "17"
