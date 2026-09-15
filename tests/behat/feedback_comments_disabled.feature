@local @local_unifiedgrader @local_unifiedgrader_critical @javascript
Feature: Overall feedback is replaced by a notice when feedback comments are disabled
  As a teacher grading an assignment that does not use feedback comments
  I want the grader to tell me so instead of offering an editor
  So that I never write feedback that the assignment silently throws away

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
      | activity | name        | course | idnumber | grade | assignfeedback_comments_enabled |
      | assign   | No comments | TC101  | a1       | 20    | 0                               |
      | assign   | Comments on | TC101  | a2       | 20    | 1                               |
    And I log in as "teacher1"

  Scenario: Feedback comments disabled shows the notice and no editor
    When I am on the Unified Grader for activity "No comments"
    And the marking panel has loaded
    Then I should see "Feedback comments are not enabled for this assessment"
    And "[data-action=feedback-input]" "css_element" should not exist
    And "[data-action=save-grade]" "css_element" should exist

  Scenario: Feedback comments enabled keeps the editor
    When I am on the Unified Grader for activity "Comments on"
    And the marking panel has loaded
    Then I should not see "Feedback comments are not enabled for this assessment"
    And "[data-action=feedback-input]" "css_element" should exist
