@local @local_unifiedgrader @local_unifiedgrader_critical @javascript
Feature: Manual grade override locks against subsequent rubric edits
  As a teacher
  I want to override a rubric-computed grade
  And have my override survive any further rubric tweaks I make to that student
  So that I don't silently lose my override when fixing a typo in a rubric remark

  Background:
    Given the following "courses" exist:
      | fullname    | shortname | category |
      | Test Course | TC101     | 0        |
    And the following "users" exist:
      | username   | firstname | lastname | email                |
      | teacher1   | Teach     | One      | teacher1@example.com |
      | student1   | Stu       | Dent     | student1@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | TC101  | editingteacher |
      | student1 | TC101  | student        |
    And the following "activities" exist:
      | activity | name    | course | idnumber | grade | assignfeedback_comments_enabled |
      | assign   | Essay 1 | TC101  | a1       | 20    | 1                               |
    And I log in as "teacher1"

  Scenario: A grade typed with no grading form survives a page reload
    When I am on the Unified Grader for activity "Essay 1"
    And the marking panel has loaded
    And I enter "18" as the overall grade
    Then the overall grade shows "18"
    # The grade step blurs the input, which fires the focus-out autosave, so
    # the reload reads the grade back from the server. The override badge is
    # only shown beside a grading form; the next scenario asserts it.
    When I reload the page
    And the marking panel has loaded
    Then the overall grade shows "18"

  Scenario: Override survives a rubric score edit
    Given a marking guide is attached to "Essay 1" with criteria:
      | shortname     | maxscore |
      | Argumentation | 10       |
      | Style         | 10       |
    When I am on the Unified Grader for activity "Essay 1"
    And the marking panel has loaded
    And I set the rubric score for "Argumentation" to "8"
    And I set the rubric score for "Style" to "7"
    # Rubric total = 15. Teacher overrides to 18.
    And I enter "18" as the overall grade
    And I set the rubric score for "Argumentation" to "9"
    # Rubric total would now be 16, but the override locked, so:
    Then the overall grade shows "18"
    And I should see "Overridden"

  Scenario: Overriding a rubric grade and resetting it keeps the rubric total
    Given a rubric is attached to "Essay 1" with criteria:
      | criterion | levels                         |
      | Argument  | Weak:0, Sound:5, Compelling:10 |
    When I am on the Unified Grader for activity "Essay 1"
    And the marking panel has loaded
    And I click on "Compelling" "button"
    # A full rubric (10 of 10) is the whole activity grade, 20.
    Then the rubric total shows "10 pts"
    And the overall grade shows "20"
    # Typing in the grade box must recompute the rubric total, not the
    # marking-guide one, which is always 0 on a rubric page and reads "0 / 0".
    When I enter "15" as the overall grade
    Then the rubric total shows "10 pts"
    # And "Reset to rubric total" must put the rubric grade back, not that 0.
    When I click on "Reset to rubric total" "button"
    Then the overall grade shows "20"

  Scenario: Opening the grader on a student whose grade overrides the marking-guide total shows it, locked
    Given a marking guide is attached to "Essay 1" with criteria:
      | shortname     | maxscore |
      | Argumentation | 20       |
    And "student1" has been graded with feedback "Seeded feedback" on "Essay 1"
    # Opened directly, so the first load brings the student's grade and the guide.
    When I am on the Unified Grader for activity "Essay 1"
    And the marking panel has loaded
    # Nothing is scored, so the stored 15 is an override of the guide's 0. Fails if
    # the first load is not treated as a fresh render: the box keeps the guide
    # total, 0, with no override badge and no lock.
    Then the overall grade shows "15"
    And I should see "Overridden"
    When I set the rubric score for "Argumentation" to "8"
    Then the rubric total shows "8 / 20"
    And the overall grade shows "15"

  Scenario: Opening the grader on a student whose grade overrides the rubric total shows it, locked
    Given a rubric is attached to "Essay 1" with criteria:
      | criterion | levels                         |
      | Argument  | Weak:0, Sound:5, Compelling:10 |
    And "student1" has been graded with feedback "Seeded feedback" on "Essay 1"
    When I am on the Unified Grader for activity "Essay 1"
    And the marking panel has loaded
    # No level is picked, so the stored 15 is an override of the rubric's 0. Fails
    # if the first load is not treated as a fresh render: the box keeps the rubric
    # total, 0, with no override badge and no lock.
    Then the overall grade shows "15"
    And I should see "Overridden"
    When I click on "Sound" "button"
    Then the rubric total shows "5 pts"
    And the overall grade shows "15"
