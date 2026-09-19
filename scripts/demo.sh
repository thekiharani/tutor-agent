#!/bin/sh
set -e
. ./.env

cat <<TEXT

  PERSONALISED E-LEARNING - DEMO SCRIPT
  =====================================

  Site: ${MOODLE_WWWROOT}

  Every account below uses the same password:  ${DEMO_PASSWORD}

    admin             site administrator (created by the installer)
    demo.admin        site administrator (created by the seed)

    student.visual    pre-set style: visual
    student.aural     pre-set style: auditory
    student.rw        pre-set style: read/write
    student.kines     pre-set style: kinesthetic
    student.blank     no style yet - use this one to demo the questionnaire
    student.blank2    no style yet  )
    student.blank3    no style yet  )  spares: run the questionnaire again,
    student.blank4    no style yet  )  or hand one to someone in the room
    student.blank5    no style yet  )
    student.blank6    no style yet  )

  THE COURSES
  -----------
  Ten, and every student is enrolled in all of them, so you can open anything
  as anyone. 38 activities between them, each one covered by the recommender.

    PROG-C    Introduction to Programming in C   (6 activities)
    CS-DS     Data Structures                    (4)
    CS-ALGO   Algorithms and Complexity          (4)
    CS-OOP    Object-Oriented Programming        (4)
    CS-DB     Databases and SQL                  (4)
    CS-OS     Operating Systems                  (3)
    CS-NET    Computer Networks                  (3)
    CS-WEB    Web Development                    (4)
    CS-SWE    Software Engineering Practice      (3)
    CS-PY     Python Programming                 (3)

  RUN THE DEMO
  ------------
   1. Log in as student.blank and click anything - a course, the dashboard.
      -> you land on the VARK questionnaire, and cannot leave it.

   2. Answer the 16 questions.
      -> the page names your learning style, with a Continue button back to
         wherever you were trying to go.

   3. Open PROG-C and the activity "Arrays".
      -> a notification appears with a resource matched to that style.

   4. Log out. Log back in as student.rw.

   5. Open the SAME "Arrays" activity.
      -> a DIFFERENT link appears, an article instead of a video.

  That contrast in steps 3 and 5 is the point of the project. Any of the 38
  activities shows it; Arrays is just the one with the most material behind it.

   6. If asked whether it only knows C: open "Joins" in CS-DB, or "Sorting
      Algorithms" in CS-ALGO, as two different students.

  Run it again:   make rehearse
  Nothing shows:  make purge

TEXT
