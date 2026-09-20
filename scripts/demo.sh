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
    demo.teacher      Miriam Wafula   - teacher on the course

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

  THE COURSE
  ----------
  One, and every student is enrolled in it. Six activities, which are the six
  topics of C the content library holds - the course is a projection of
  intents.json, so there is nothing to open that the recommender cannot answer.

  School of Computing and Informatics
    Programming Fundamentals
      CS 101  Introduction to Programming in C   (6 activities)
                Introduction to C
                Data Types
                Operators
                Control Structures
                Arrays
                Functions

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

  That contrast in steps 3 and 5 is the point of the project. Any of the six
  activities shows it; Arrays is just the one with the most material behind it.

   6. If asked how the four styles differ, open Arrays as all four students in
      turn. Visual gets a video, auditory a university lecture, read/write an
      article or the language reference, kinesthetic something to run. One
      medium per style is the whole claim, and `make test` enforces it.

   7. If asked what happens outside C: the library covers six topics and
      nothing else. An activity it does not cover gets a 204 and Moodle shows
      no notification - silence rather than a guess.

  Run it again:   make rehearse
  Nothing shows:  make purge

TEXT
