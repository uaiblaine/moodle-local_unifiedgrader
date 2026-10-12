@local @local_unifiedgrader @local_unifiedgrader_critical @javascript
Feature: Grades the grader saves, queues or restores reach the right student intact
  As a teacher marking one student after another
  I want every save to settle only the work it carried, for the student it was for
  So that a slow, failed or recovered save never loses or misfiles a mark

  Background:
    Given the following "courses" exist:
      | fullname    | shortname | category |
      | Test Course | TC101     | 0        |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teach     | One      | teacher1@example.com |
      | student1 | Stu       | Dent     | student1@example.com |
      | student2 | Sam       | Pupil    | student2@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | TC101  | editingteacher |
      | student1 | TC101  | student        |
      | student2 | TC101  | student        |
    And the following "activities" exist:
      | activity | name    | course | idnumber | grade | assignfeedback_comments_enabled |
      | assign   | Essay 1 | TC101  | a1       | 20    | 1                               |
    And I log in as "teacher1"

  Scenario: A save that lands after the switch to the next student leaves that student's work unsaved
    When I am on the Unified Grader for activity "Essay 1" grading "student1"
    And the marking panel has loaded
    And grade saves in the Unified Grader take 3 seconds to come back
    # The navigator's save for student1 is held behind the first one and still in
    # flight when student2 has loaded and gets a grade typed (the step fails if it
    # is not). Fails if that save's end marks the form on screen saved and deletes
    # its offline copy: student2's 9 would be lost without a leave-page warning.
    When I enter "12" as the overall grade, go to the next student, and type "9" there before the save lands
    Then the overall grade shows "9"
    And the Unified Grader has unsaved grade changes
    And the Unified Grader keeps an offline copy of the grade "9" for "student2" on "Essay 1"
    And the saved grade for "student1" on "Essay 1" is "12"
    And the saved grade for "student2" on "Essay 1" is ""

  Scenario: A grade corrected while a save is in flight is saved before the switch to the next student
    When I am on the Unified Grader for activity "Essay 1" grading "student1"
    And the marking panel has loaded
    And I note the current Unified Grader student
    And grade saves in the Unified Grader take 2 seconds to come back
    # The correction is never left, so only the save the navigator asks for before
    # switching carries it, and that request is held behind the save still in
    # flight. Fails if the navigator loads student2 without waiting for the held
    # request: it is then dropped as belonging to a student no longer on screen,
    # and student1 keeps the first mark.
    When I enter "12" as the overall grade, correct it to "17", and go to the next student before the save lands
    Then the Unified Grader student should be changed
    And the saved grade for "student1" on "Essay 1" is "17"

  Scenario: A save that fails on the network stays unsaved until its retry lands
    When I am on the Unified Grader for activity "Essay 1" grading "student1"
    And the marking panel has loaded
    And the network drops grade saves in the Unified Grader
    When I enter "12" as the overall grade
    # Precondition: the save failed as a dropped connection does and was queued.
    Then the Unified Grader holds 1 save waiting for a retry
    # Fails if the end of the failed save is taken for a stored one: the grade
    # would count as saved, its offline copy would be deleted, and leaving the page
    # would drop the queued retry without a warning.
    And the Unified Grader has unsaved grade changes
    And the Unified Grader keeps an offline copy of the grade "12" for "student1" on "Essay 1"
    And the saved grade for "student1" on "Essay 1" is ""
    And leaving the Unified Grader asks for confirmation

  Scenario: Restoring the offline copy brings back the marking-guide scores with the grade
    Given a marking guide is attached to "Essay 1" with criteria:
      | shortname     | maxscore |
      | Argumentation | 20       |
    When I am on the Unified Grader for activity "Essay 1" grading "student1"
    And the marking panel has loaded
    When I score "Argumentation" "8" without leaving the marking guide
    # Precondition: the unsaved score is in the offline copy, with the total it gives.
    Then the Unified Grader keeps an offline copy of the grade "8" for "student1" on "Essay 1"
    When I reload the Unified Grader without saving
    And the marking panel has loaded
    And I restore the unsaved changes the Unified Grader recovered
    # Fails if only the grade box is restored: the guide would show the empty score
    # loaded from the server and the 8 would become a manual override of it.
    Then the marking guide score for "Argumentation" shows "8"
    And the overall grade shows "8"
    And I should not see "Overridden"
    And the Unified Grader has unsaved grade changes

  Scenario: A rubric without the zero-points lock fills in and stores the grade core computes
    Given a rubric is attached to "Essay 1" with criteria:
      | criterion | levels                        |
      | Argument  | Weak:1, Sound:2, Compelling:4 |
    And the rubric on "Essay 1" gives the minimum grade for its lowest levels
    When I am on the Unified Grader for activity "Essay 1" grading "student1"
    And the marking panel has loaded
    And I click on "Sound" "button"
    # Core puts a score of 2 at (2 - 1) / (4 - 1) of the way to 20, which is 6.66667,
    # shown to two decimals. Fails if the panel scales from zero (2 / 4 of 20 = 10).
    Then the overall grade shows "6.67"
    # The untouched total goes out as shown, rounded. Fails if the server stores
    # that 6.67 as a manual override instead of keeping core's 6.66667 (or if the
    # panel sent the 10 above).
    And the saved grade for "student1" on "Essay 1" is "6.66667"

  Scenario: A full reset that has to keep the submission says so
    Given the following "permission overrides" exist:
      | capability                     | permission | role           | contextlevel | reference |
      | mod/assign:editothersubmission | Prevent    | editingteacher | Course       | TC101     |
    And "student1" has an unsubmitted submission on "Essay 1"
    And "student1" has been graded with feedback "Seeded feedback" on "Essay 1"
    When I am on the Unified Grader for activity "Essay 1" grading "student1"
    And the marking panel has loaded
    Then the overall grade shows "15"
    # "--" clears the grade and reverts a submission the student never made, which
    # needs mod/assign:editothersubmission. Fails if the teacher is not told that
    # the submission half was refused: the reset would look complete.
    When I enter "--" as the overall grade
    Then the Unified Grader warned "submission was kept because you do not have permission to remove it"
    And the saved grade for "student1" on "Essay 1" is ""
