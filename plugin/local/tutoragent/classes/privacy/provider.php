<?php
// Privacy API implementation for local_tutoragent.

namespace local_tutoragent\privacy;

use context;
use context_user;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * The plugin stores one row of personal data per user, so a null_provider is
 * not good enough: without this the plugin shows as non-compliant on the admin
 * privacy page.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'local_tutoragent_vark',
            [
                'userid' => 'privacy:metadata:local_tutoragent_vark:userid',
                'style' => 'privacy:metadata:local_tutoragent_vark:style',
                'scores' => 'privacy:metadata:local_tutoragent_vark:scores',
                'timemodified' => 'privacy:metadata:local_tutoragent_vark:timemodified',
            ],
            'privacy:metadata:local_tutoragent_vark'
        );

        return $collection;
    }

    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $contextlist->add_from_sql(
            "SELECT ctx.id
               FROM {context} ctx
               JOIN {local_tutoragent_vark} v ON v.userid = ctx.instanceid
              WHERE ctx.contextlevel = :contextlevel AND v.userid = :userid",
            ['contextlevel' => CONTEXT_USER, 'userid' => $userid]
        );

        return $contextlist;
    }

    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if (!$context instanceof context_user) {
            return;
        }

        $userlist->add_from_sql(
            'userid',
            "SELECT userid FROM {local_tutoragent_vark} WHERE userid = :userid",
            ['userid' => $context->instanceid]
        );
    }

    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof context_user) {
                continue;
            }

            $record = $DB->get_record('local_tutoragent_vark', ['userid' => $context->instanceid]);
            if (!$record) {
                continue;
            }

            writer::with_context($context)->export_data(
                [get_string('pluginname', 'local_tutoragent')],
                (object) [
                    'style' => $record->style,
                    'scores' => $record->scores,
                    'timemodified' => transform::datetime($record->timemodified),
                ]
            );
        }
    }

    public static function delete_data_for_all_users_in_context(context $context) {
        global $DB;

        if ($context instanceof context_user) {
            $DB->delete_records('local_tutoragent_vark', ['userid' => $context->instanceid]);
        }
    }

    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof context_user) {
                $DB->delete_records('local_tutoragent_vark', ['userid' => $context->instanceid]);
            }
        }
    }

    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;

        $context = $userlist->get_context();
        if (!$context instanceof context_user) {
            return;
        }

        foreach ($userlist->get_userids() as $userid) {
            $DB->delete_records('local_tutoragent_vark', ['userid' => $userid]);
        }
    }
}
