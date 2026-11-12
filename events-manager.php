<?php
function em_ical_cors_headers( ){
    //check if this is a calendar request for all events
    if ( preg_match('/events.ics(\?.+)?$/', $_SERVER['REQUEST_URI']) || $_SERVER['REQUEST_URI'] == '/?ical=1' ) {
        header('Access-Control-Allow-Origin: *');
    }
}

add_action ( 'init', 'em_ical_cors_headers', 5 );

function em_add_future_public_scope($scopes) {
        return array_merge($scopes, [
                'future-public' => 'Future events excluding private',
        ]);
}

add_filter('em_get_scopes', 'em_add_future_public_scope', 5);

function em_add_future_public_to_conditions($args) {
        if ($args['scope'] === 'future-public') {
                $args['scope'] = 'future';
                $args['tag'] = '-private';
        }

        return $args;
}

add_filter('em_object_build_sql_conditions_args', 'em_add_future_public_to_conditions', 5);
