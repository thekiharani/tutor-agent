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

  RUN THE DEMO
  ------------
   1. Log in as student.blank and click anything - the course, the dashboard.
      -> you land on the VARK questionnaire, and cannot leave it.

   2. Answer the 16 questions.
      -> the page names your learning style, with a Continue button back to
         wherever you were trying to go.

   3. Open the activity "Arrays".
      -> a notification appears with a resource matched to that style.

   4. Log out. Log back in as student.rw.

   5. Open the SAME "Arrays" activity.
      -> a DIFFERENT link appears, an article or PDF instead of a video.

  That contrast in steps 3 and 5 is the point of the project.

  Run it again:   make rehearse
  Nothing shows:  make purge

TEXT
