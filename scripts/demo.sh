#!/bin/sh
set -e
. ./.env

cat <<TEXT

  PERSONALISED E-LEARNING - DEMO SCRIPT
  =====================================

  Site: ${MOODLE_WWWROOT}

  Every account below uses the same password:  ${DEMO_PASSWORD}

    admin             site administrator (created by the installer)
    demo.admin        Lydia Muthoni   - site administrator
    demo.teacher      Miriam Wafula   - teacher on all ten courses

    student.visual    Amara Otieno    - pre-set style: visual
    student.aural     Brian Kamau     - pre-set style: auditory
    student.rw        Chloe Wanjiru   - pre-set style: read/write
    student.kines     David Mwangi    - pre-set style: kinesthetic
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

  School of Computing and Informatics
    Programming Fundamentals
      CS 101  Introduction to Programming in C   (6 activities)
      CS 102  Python Programming                 (3)
    Core Computer Science
      CS 201  Data Structures                    (4)
      CS 202  Algorithms and Complexity          (4)
      CS 203  Object-Oriented Programming        (4)
    Data and Web Systems
      CS 204  Databases and SQL                  (4)
      CS 303  Web Development                    (4)
    Systems and Networks
      CS 301  Operating Systems                  (3)
      CS 302  Computer Networks                  (3)
    Software Engineering
      CS 304  Software Engineering Practice      (3)

  RUN THE DEMO
  ------------
   1. Log in as student.blank and click anything - a course, the dashboard.
      -> you land on the VARK questionnaire, and cannot leave it.

   2. Answer the 16 questions.
      -> the page names your learning style, with a Continue button back to
         wherever you were trying to go.

   3. Open CS 101 and the activity "Arrays".
      -> a notification appears with a resource matched to that style.

   4. Log out. Log back in as student.rw.

   5. Open the SAME "Arrays" activity.
      -> a DIFFERENT link appears, an article instead of a video.

  That contrast in steps 3 and 5 is the point of the project. Any of the 38
  activities shows it; Arrays is just the one with the most material behind it.

   6. If asked whether it only knows C: open "Joins" in CS 204, or "Sorting
      Algorithms" in CS 202, as two different students.

  Run it again:   make rehearse
  Nothing shows:  make purge

TEXT
