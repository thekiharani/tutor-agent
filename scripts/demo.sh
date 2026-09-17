#!/bin/sh
# Printed by `make demo`. Reads .env so the passwords shown are the real ones.
set -e
. ./.env

cat <<TEXT

  PERSONALISED E-LEARNING - DEMO SCRIPT
  =====================================

  Site:   ${MOODLE_WWWROOT}
  Admin:  ${MOODLE_ADMIN_USER} / ${MOODLE_ADMIN_PASS}

  Students (all share the password ${DEMO_STUDENT_PASS}):

    student.visual    pre-set style: visual
    student.aural     pre-set style: auditory
    student.rw        pre-set style: read/write
    student.kines     pre-set style: kinesthetic
    student.blank     no style yet - use this one to demo the questionnaire

  RUN THE DEMO
  ------------
   1. Log in as student.blank and open the course
      "Introduction to Programming in C".
      -> a notification invites you to take the VARK questionnaire.

   2. Take the questionnaire (16 questions).
      -> the page names your learning style.

   3. Open the activity "Arrays".
      -> a notification appears with a resource matched to that style.

   4. Log out. Log back in as student.rw.

   5. Open the SAME "Arrays" activity.
      -> a DIFFERENT link appears, an article or PDF instead of a video.

  That contrast in steps 3 and 5 is the point of the project.

  If a change does not show up:  make purge

TEXT
