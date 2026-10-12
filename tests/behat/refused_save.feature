@local @local_unifiedgrader @local_unifiedgrader_critical @javascript
Feature: A save the server refuses is not shown as saved
  As a teacher whose save was refused
  I want the grader to keep showing what is really stored and what is still unsaved
  So that a refusal never passes for a successful save

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
      | activity | name    | course | idnumber | grade | assignfeedback_comments_enabled |
      | assign   | Essay 1 | TC101  | a1       | 20    | 1                               |
    And I log in as "teacher1"

  Scenario Outline: A refused clear puts the stored grade back in the grade box
    Given "student1" has been graded with feedback "Seeded feedback" on "Essay 1"
    And the gradebook grade for "student1" on "Essay 1" is <block>
    When I am on the Unified Grader for activity "Essay 1"
    And the marking panel has loaded
    Then the overall grade shows "15"
    # "-" blanks the box before the save goes out, so the refusal must put the
    # stored 15 back; a blank box would read as ungraded while 15 stands.
    When I enter "-" as the overall grade and close the refusal
    # The two blocks need different advice: "--" lifts an override, not a lock.
    Then the refusal said "<message>"
    And the overall grade shows "15"
    And the saved grade for "student1" on "Essay 1" is "15"

    Examples:
      | block      | message                     |
      | overridden | overridden in the gradebook |
      | locked     | locked in the gradebook     |

  Scenario: A grade typed while a refused clear is in flight is kept
    Given "student1" has been graded with feedback "Seeded feedback" on "Essay 1"
    And the gradebook grade for "student1" on "Essay 1" is overridden
    When I am on the Unified Grader for activity "Essay 1"
    And the marking panel has loaded
    # The refusal puts the stored grade back only if the box still holds what was
    # sent. A grade typed during the round trip is a new edit and must survive,
    # still unsaved, rather than be overwritten with the stored 15.
    When I enter "-" as the overall grade, type "12" before the refusal lands, and close it
    Then the overall grade shows "12"
    And the Unified Grader has unsaved grade changes

  Scenario: Feedback refused on a page opened before comments were disabled stays unsaved
    When I am on the Unified Grader for activity "Essay 1"
    And the marking panel has loaded
    And feedback comments are disabled on "Essay 1"
    When I type "Feedback with nowhere to go" as the overall feedback
    # Precondition: the edit registered, so the check after the refusal means something.
    Then the Unified Grader has unsaved feedback changes
    # A refusal must not mark the feedback as saved: that would silence the
    # leave-page warning and delete the offline copy.
    When I save the grade and close the refusal
    Then the Unified Grader has unsaved feedback changes

  Scenario: Hiding grades does not mark unsaved feedback as saved
    When I am on the Unified Grader for activity "Essay 1"
    And the marking panel has loaded
    When I type "Feedback not saved yet" as the overall feedback
    Then the Unified Grader has unsaved feedback changes
    # Only the end of a grade save may mark feedback as saved. Hiding grades
    # changes the panel's interface state without saving anything.
    When I hide the grades of "Essay 1"
    Then the Unified Grader has unsaved feedback changes
