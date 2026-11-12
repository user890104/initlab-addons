<?php
/**
 * Keeps the Discord server's scheduled events in sync with Events Manager.
 *
 * Every trigger (saving an event, the hourly pass, WP-CLI) ends up in
 * initlab_discord_sync_event(), which works out what Discord should show for one
 * event and creates, updates or deletes the Discord event to match.
 */

const INITLAB_DISCORD_META_EVENT_ID = 'discord_event_id';
const INITLAB_DISCORD_META_TEXT_HASH = '_discord_sync_hash';
const INITLAB_DISCORD_META_IMAGE_HASH = '_discord_image_hash';
const INITLAB_DISCORD_META_ERROR = '_discord_sync_error';

const INITLAB_DISCORD_NAME_LIMIT = 100;
const INITLAB_DISCORD_DESCRIPTION_LIMIT = 1000;
const INITLAB_DISCORD_LOCATION_LIMIT = 100;
const INITLAB_DISCORD_IMAGE_SIZE_LIMIT = 8 * 1024 * 1024;
// Discord's guild scheduled event statuses
const INITLAB_DISCORD_STATUS_SCHEDULED = 1;
const INITLAB_DISCORD_STATUS_ACTIVE = 2;

function initlab_discord_config() {
    $options = get_option('initlab_options', []);
    $config = [
        'guild_id' => trim($options['discord_guild_id'] ?? ''),
        'bot_token' => trim($options['discord_bot_token'] ?? ''),
        'invite_url' => trim($options['discord_invite_url'] ?? ''),
    ];
    // "configured" is enough to run a sync by hand, "enabled" also turns on the automatic triggers
    $config['configured'] = $config['guild_id'] !== '' && $config['bot_token'] !== '' && defined('EM_POST_TYPE_EVENT');
    $config['enabled'] = $config['configured'] && !empty($options['discord_sync_enabled']);

    return $config;
}

/**
 * Calls the guild's scheduled events API. Returns the decoded response or a WP_Error.
 */
function initlab_discord_request($method, $path = '', $body = null) {
    $config = initlab_discord_config();
    $base = apply_filters('initlab_discord_api_base', 'https://discord.com/api/v10');
    $args = [
        'method' => $method,
        'timeout' => 30,
        'headers' => [
            'Authorization' => 'Bot ' . $config['bot_token'],
            'User-Agent' => 'DiscordBot (https://github.com/initLab/initlab-addons, 1)',
        ],
    ];
    if ($body !== null) {
        $args['headers']['Content-Type'] = 'application/json';
        $args['body'] = wp_json_encode($body);
    }

    $response = wp_remote_request($base . '/guilds/' . rawurlencode($config['guild_id']) . '/scheduled-events' . $path, $args);
    if (is_wp_error($response)) {
        return $response;
    }

    $status = (int) wp_remote_retrieve_response_code($response);
    $raw = wp_remote_retrieve_body($response);
    $data = json_decode($raw, true);

    // Stay within the rate limit bucket when running in the background
    if (wp_remote_retrieve_header($response, 'x-ratelimit-remaining') === '0' && (wp_doing_cron() || defined('WP_CLI'))) {
        sleep((int) min(10, ceil((float) wp_remote_retrieve_header($response, 'x-ratelimit-reset-after'))));
    }

    if ($status >= 200 && $status < 300) {
        return is_array($data) ? $data : [];
    }

    $detail = is_array($data) ? wp_json_encode($data, JSON_UNESCAPED_UNICODE) : wp_strip_all_tags($raw);
    return new WP_Error(
        $status === 429 ? 'discord_rate_limited' : 'discord_http_error',
        sprintf('Discord: %s -> HTTP %d %s', $method, $status, mb_substr(trim($detail), 0, 300)),
        ['status' => $status, 'retry_after' => is_array($data) ? (float) ($data['retry_after'] ?? 0) : 0]
    );
}

/**
 * Length the way Discord counts it (characters outside the BMP count twice).
 */
function initlab_discord_length($text) {
    return mb_strlen($text) + preg_match_all('/[\x{10000}-\x{10FFFF}]/u', $text);
}

function initlab_discord_truncate($text, $limit) {
    $text = trim($text);
    if (initlab_discord_length($text) <= $limit) {
        return $text;
    }

    $text = mb_substr($text, 0, $limit - 1);
    while (initlab_discord_length($text) > $limit - 1) {
        $text = mb_substr($text, 0, -1);
    }

    return rtrim($text) . '…';
}

function initlab_discord_plain_text($html) {
    $text = html_entity_decode(wp_strip_all_tags(strip_shortcodes($html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = str_replace(["\r\n", "\r", "\xC2\xA0"], ["\n", "\n", ' '], $text);
    $text = preg_replace('/[ \t]+\n/', "\n", $text);

    return trim(preg_replace('/\n{3,}/', "\n\n", $text));
}

/**
 * Start and end of an event as UNIX timestamps, or null if the post is not a usable event.
 */
function initlab_discord_event_times($post_id) {
    if (get_post_type($post_id) !== EM_POST_TYPE_EVENT) {
        return null;
    }

    $EM_Event = em_get_event($post_id, 'post_id');
    if (!$EM_Event || !$EM_Event->event_id || !$EM_Event->event_start_date) {
        return null;
    }

    $start = $EM_Event->start()->getTimestamp();
    $end = $EM_Event->end()->getTimestamp();

    // Discord requires an end time after the start for external events
    return ['start' => $start, 'end' => $end > $start ? $end : $start + HOUR_IN_SECONDS];
}

/**
 * What Discord should show for an event, or null if the event should not be on Discord.
 */
function initlab_discord_payload($post_id, $times) {
    $post = get_post($post_id);
    if ($post->post_status !== 'publish' || has_term('private', EM_TAXONOMY_TAG, $post)) {
        return null;
    }

    $EM_Event = em_get_event($post_id, 'post_id');
    $active_status = $EM_Event->event_active_status;
    if ($active_status !== null && (int) $active_status === 0) {
        // cancelled
        return null;
    }

    $name = initlab_discord_plain_text($post->post_title);
    $link = get_permalink($post);
    $text = initlab_discord_truncate(
        initlab_discord_plain_text($post->post_content),
        INITLAB_DISCORD_DESCRIPTION_LIMIT - initlab_discord_length("\n\n" . $link)
    );
    $location = $EM_Event->has_location() ? initlab_discord_plain_text($EM_Event->get_location()->location_name) : '';

    return [
        'name' => initlab_discord_truncate($name !== '' ? $name : get_bloginfo('name'), INITLAB_DISCORD_NAME_LIMIT),
        'description' => $text !== '' ? $text . "\n\n" . $link : $link,
        'scheduled_start_time' => gmdate('Y-m-d\TH:i:s\Z', $times['start']),
        'scheduled_end_time' => gmdate('Y-m-d\TH:i:s\Z', $times['end']),
        // guild only
        'privacy_level' => 2,
        // external, i.e. not in a voice or stage channel
        'entity_type' => 3,
        'entity_metadata' => [
            'location' => initlab_discord_truncate($location !== '' ? $location : get_bloginfo('name'), INITLAB_DISCORD_LOCATION_LIMIT),
        ],
    ];
}

/**
 * The featured image of an event if Discord can take it, otherwise null.
 */
function initlab_discord_image($post_id) {
    $attachment_id = get_post_thumbnail_id($post_id);
    if (!$attachment_id) {
        return null;
    }

    $file = get_attached_file($attachment_id);
    $mime = get_post_mime_type($attachment_id);
    if (!$file || !is_readable($file) || filesize($file) > INITLAB_DISCORD_IMAGE_SIZE_LIMIT
        || !in_array($mime, ['image/jpeg', 'image/png', 'image/gif'], true)) {
        return null;
    }

    return ['file' => $file, 'mime' => $mime, 'hash' => hash_file('sha256', $file)];
}

function initlab_discord_image_data($image) {
    return 'data:' . $image['mime'] . ';base64,' . base64_encode(file_get_contents($image['file']));
}

function initlab_discord_set_meta($post_id, $key, $value) {
    if ((string) $value === '') {
        delete_post_meta($post_id, $key);
    } else {
        update_post_meta($post_id, $key, $value);
    }
}

function initlab_discord_forget($post_id) {
    delete_post_meta($post_id, INITLAB_DISCORD_META_EVENT_ID);
    delete_post_meta($post_id, INITLAB_DISCORD_META_TEXT_HASH);
    delete_post_meta($post_id, INITLAB_DISCORD_META_IMAGE_HASH);
}

/**
 * True if an older event already uses this Discord event, i.e. ours is a copied ID.
 */
function initlab_discord_id_is_copied($post_id, $discord_id) {
    $owners = get_posts([
        'post_type' => EM_POST_TYPE_EVENT,
        'post_status' => 'any',
        'fields' => 'ids',
        'posts_per_page' => 1,
        'orderby' => 'ID',
        'order' => 'ASC',
        'meta_key' => INITLAB_DISCORD_META_EVENT_ID,
        'meta_value' => $discord_id,
    ]);

    return $owners && (int) $owners[0] < (int) $post_id;
}

/**
 * Makes Discord match one event. Returns what was done (or would be done on a dry run), or a WP_Error.
 *
 * @param int $post_id
 * @param array|null $remote_events Discord's current events keyed by ID, if the caller already listed them
 * @param bool $dry_run
 * @return string|WP_Error
 */
function initlab_discord_reconcile_event($post_id, $remote_events = null, $dry_run = false) {
    $times = initlab_discord_event_times($post_id);
    if (!$times) {
        return 'skip (not an event)';
    }
    if ($times['start'] <= time()) {
        // Discord starts and completes events on its own, a started event is left alone
        return 'skip (already started)';
    }

    $discord_id = (string) get_post_meta($post_id, INITLAB_DISCORD_META_EVENT_ID, true);
    $remote = null;
    if ($discord_id !== '') {
        if (is_array($remote_events)) {
            $remote = $remote_events[$discord_id] ?? null;
        } else {
            $remote = initlab_discord_request('GET', '/' . rawurlencode($discord_id));
            if (is_wp_error($remote)) {
                if (($remote->get_error_data()['status'] ?? 0) !== 404) {
                    return $remote;
                }
                $remote = null;
            }
        }

        if ($remote && (int) $remote['status'] === INITLAB_DISCORD_STATUS_ACTIVE) {
            return 'skip (active on Discord)';
        }

        // Gone or finished on Discord, or an ID copied from another event: start over
        if (!$remote || (int) $remote['status'] !== INITLAB_DISCORD_STATUS_SCHEDULED
            || initlab_discord_id_is_copied($post_id, $discord_id)) {
            if (!$dry_run) {
                initlab_discord_forget($post_id);
            }
            $discord_id = '';
            $remote = null;
        }
    }

    $payload = initlab_discord_payload($post_id, $times);
    if (!$payload) {
        if ($discord_id === '') {
            return 'skip (not for Discord)';
        }
        if (!$dry_run) {
            $response = initlab_discord_request('DELETE', '/' . rawurlencode($discord_id));
            if (is_wp_error($response) && ($response->get_error_data()['status'] ?? 0) !== 404) {
                return $response;
            }
            initlab_discord_forget($post_id);
        }

        return 'delete';
    }

    $text_hash = hash('sha256', wp_json_encode($payload));
    $image = initlab_discord_image($post_id);
    $image_hash = $image ? $image['hash'] : '';

    if ($discord_id === '') {
        if (!$dry_run) {
            $response = initlab_discord_request('POST', '', $image ? $payload + ['image' => initlab_discord_image_data($image)] : $payload);
            if (is_wp_error($response)) {
                return $response;
            }
            if (empty($response['id'])) {
                return new WP_Error('discord_bad_response', 'Discord: the created event has no ID');
            }
            update_post_meta($post_id, INITLAB_DISCORD_META_EVENT_ID, (string) $response['id']);
            update_post_meta($post_id, INITLAB_DISCORD_META_TEXT_HASH, $text_hash);
            initlab_discord_set_meta($post_id, INITLAB_DISCORD_META_IMAGE_HASH, $image_hash);
        }

        return 'create';
    }

    $done = [];
    if (get_post_meta($post_id, INITLAB_DISCORD_META_TEXT_HASH, true) !== $text_hash) {
        if (!$dry_run) {
            $response = initlab_discord_request('PATCH', '/' . rawurlencode($discord_id), $payload);
            if (is_wp_error($response)) {
                return $response;
            }
            update_post_meta($post_id, INITLAB_DISCORD_META_TEXT_HASH, $text_hash);
        }
        $done[] = 'update';
    }

    // If the image disappeared on the Discord side, whatever we uploaded no longer counts
    $uploaded_hash = empty($remote['image']) ? '' : (string) get_post_meta($post_id, INITLAB_DISCORD_META_IMAGE_HASH, true);
    if ($uploaded_hash !== $image_hash) {
        if (!$dry_run) {
            $response = initlab_discord_request('PATCH', '/' . rawurlencode($discord_id), [
                'image' => $image ? initlab_discord_image_data($image) : null,
            ]);
            if (is_wp_error($response)) {
                if ($image) {
                    return $response;
                }
                // Discord may not allow removing a cover image; keep the old one rather than retrying forever
                error_log('init Lab Addons: could not remove the Discord image of event ' . $post_id . ': ' . $response->get_error_message());
            }
        }
        $done[] = $image ? 'upload image' : 'remove image';
    }
    if (!$dry_run) {
        initlab_discord_set_meta($post_id, INITLAB_DISCORD_META_IMAGE_HASH, $image_hash);
    }

    return $done ? implode(', ', $done) : 'unchanged';
}

/**
 * Syncs one event and remembers the outcome, so it can be shown on the event's edit screen.
 *
 * @return string|WP_Error
 */
function initlab_discord_sync_event($post_id, $remote_events = null, $dry_run = false) {
    if (!initlab_discord_config()['configured']) {
        return 'skip (Discord is not configured)';
    }

    $result = initlab_discord_reconcile_event((int) $post_id, $remote_events, $dry_run);
    if (!$dry_run && get_post($post_id)) {
        if (is_wp_error($result)) {
            update_post_meta($post_id, INITLAB_DISCORD_META_ERROR, $result->get_error_message());
            error_log('init Lab Addons: Discord sync of event ' . $post_id . ' failed: ' . $result->get_error_message());
        } else {
            delete_post_meta($post_id, INITLAB_DISCORD_META_ERROR);
        }
    }

    return $result;
}

/**
 * Syncs every upcoming event. Returns the outcome per post ID, or a WP_Error if Discord can't be reached at all.
 *
 * @return array|WP_Error
 */
function initlab_discord_sync_all($dry_run = false) {
    if (!initlab_discord_config()['configured']) {
        return [];
    }

    $listed = initlab_discord_request('GET');
    if (is_wp_error($listed)) {
        if (!$dry_run) {
            update_option('initlab_discord_last_sync', ['time' => time(), 'error' => $listed->get_error_message()], false);
        }
        return $listed;
    }
    $remote_events = array_column($listed, null, 'id');

    $post_ids = get_posts([
        'post_type' => EM_POST_TYPE_EVENT,
        'post_status' => ['publish', 'future', 'pending', 'draft', 'private', 'trash'],
        'fields' => 'ids',
        'posts_per_page' => -1,
        'orderby' => 'ID',
        'order' => 'ASC',
        // The start is stored in the event's own timezone, so allow a day of slack here;
        // the exact check happens per event
        'meta_query' => [[
            'key' => '_event_start_local',
            'value' => gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS),
            'compare' => '>',
            'type' => 'DATETIME',
        ]],
    ]);

    $results = [];
    foreach ($post_ids as $post_id) {
        $results[$post_id] = initlab_discord_sync_event($post_id, $remote_events, $dry_run);
        if (is_wp_error($results[$post_id]) && $results[$post_id]->get_error_code() === 'discord_rate_limited') {
            // Come back for the rest once Discord lets us
            if (!$dry_run) {
                $retry_after = (int) ceil($results[$post_id]->get_error_data()['retry_after'] ?? 0);
                wp_schedule_single_event(time() + max(60, $retry_after), 'initlab_discord_sync_all');
            }
            break;
        }
    }

    if (!$dry_run) {
        update_option('initlab_discord_last_sync', [
            'time' => time(),
            'events' => count($results),
            'failed' => count(array_filter($results, 'is_wp_error')),
        ], false);
    }

    return $results;
}

add_action('initlab_discord_sync_event', 'initlab_discord_sync_event');
add_action('initlab_discord_sync_all', 'initlab_discord_sync_all', 10, 0);

/**
 * Queues a background sync of one event. Runs in WP-Cron, so that Discord can never slow down or break saving.
 * The short delay keeps the job from starting while the save that queued it is still in progress.
 */
function initlab_discord_queue_event($post_id) {
    if (!initlab_discord_config()['enabled'] || get_post_type($post_id) !== EM_POST_TYPE_EVENT
        || get_post_status($post_id) === 'auto-draft') {
        return;
    }

    wp_schedule_single_event(time() + 5, 'initlab_discord_sync_event', [(int) $post_id]);
}

// Covers creating, editing, publishing, unpublishing, trashing and restoring
add_action('save_post', 'initlab_discord_queue_event', 100);

function initlab_discord_queue_on_thumbnail_change($meta_id, $post_id, $meta_key) {
    if ($meta_key === '_thumbnail_id') {
        initlab_discord_queue_event($post_id);
    }
}

add_action('added_post_meta', 'initlab_discord_queue_on_thumbnail_change', 10, 3);
add_action('updated_post_meta', 'initlab_discord_queue_on_thumbnail_change', 10, 3);
add_action('deleted_post_meta', 'initlab_discord_queue_on_thumbnail_change', 10, 3);

/**
 * An event deleted for good takes its post meta with it, so its Discord event is removed by ID.
 */
function initlab_discord_queue_delete($post_id) {
    if (!initlab_discord_config()['enabled'] || get_post_type($post_id) !== EM_POST_TYPE_EVENT) {
        return;
    }

    $discord_id = (string) get_post_meta($post_id, INITLAB_DISCORD_META_EVENT_ID, true);
    $times = initlab_discord_event_times($post_id);
    if ($discord_id === '' || !$times || $times['start'] <= time() || initlab_discord_id_is_copied($post_id, $discord_id)) {
        return;
    }

    wp_schedule_single_event(time(), 'initlab_discord_delete_remote', [$discord_id]);
}

add_action('before_delete_post', 'initlab_discord_queue_delete', 1);

function initlab_discord_delete_remote($discord_id) {
    if (!initlab_discord_config()['configured']) {
        return;
    }

    $response = initlab_discord_request('DELETE', '/' . rawurlencode($discord_id));
    if (is_wp_error($response) && ($response->get_error_data()['status'] ?? 0) !== 404) {
        error_log('init Lab Addons: could not delete Discord event ' . $discord_id . ': ' . $response->get_error_message());
    }
}

add_action('initlab_discord_delete_remote', 'initlab_discord_delete_remote');

/**
 * The hourly pass retries failed events and repairs anything that changed on the Discord side.
 */
function initlab_discord_schedule() {
    $scheduled = wp_next_scheduled('initlab_discord_sync_all');
    $enabled = initlab_discord_config()['enabled'];
    if ($enabled && !$scheduled) {
        wp_schedule_event(time() + MINUTE_IN_SECONDS, 'hourly', 'initlab_discord_sync_all');
    } elseif (!$enabled && $scheduled) {
        wp_clear_scheduled_hook('initlab_discord_sync_all');
    }
}

add_action('init', 'initlab_discord_schedule');

function initlab_discord_deactivate() {
    wp_clear_scheduled_hook('initlab_discord_sync_all');
}

register_deactivation_hook(__DIR__ . '/initlab-addons.php', 'initlab_discord_deactivate');

/**
 * A duplicated event must not inherit the Discord event of its original,
 * otherwise the copy would take over the original's Discord event instead of getting its own.
 */
function initlab_discord_forget_on_duplicate($EM_Event) {
    if ($EM_Event && $EM_Event->post_id) {
        initlab_discord_forget($EM_Event->post_id);
        delete_post_meta($EM_Event->post_id, INITLAB_DISCORD_META_ERROR);
    }

    return $EM_Event;
}

add_filter('em_event_duplicate', 'initlab_discord_forget_on_duplicate');

/**
 * Shows on the event's edit screen whether the event is on Discord.
 */
function initlab_discord_add_meta_box() {
    if (initlab_discord_config()['enabled']) {
        add_meta_box('initlab-discord', 'Discord', 'initlab_discord_meta_box', EM_POST_TYPE_EVENT, 'side', 'low');
    }
}

add_action('add_meta_boxes', 'initlab_discord_add_meta_box');

function initlab_discord_meta_box($post) {
    $config = initlab_discord_config();
    $discord_id = (string) get_post_meta($post->ID, INITLAB_DISCORD_META_EVENT_ID, true);
    $error = (string) get_post_meta($post->ID, INITLAB_DISCORD_META_ERROR, true);

    if ($error !== '') {
        ?>
        <p><strong><?php esc_html_e('The last sync with Discord failed:', 'initlab-addons'); ?></strong><br><?php echo esc_html($error); ?></p>
        <?php
    }

    if ($discord_id !== '') {
        $url = $config['invite_url'] !== ''
            ? add_query_arg('event', $discord_id, $config['invite_url'])
            : 'https://discord.com/events/' . $config['guild_id'] . '/' . $discord_id;
        ?>
        <p><a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener"><?php esc_html_e('View this event on Discord', 'initlab-addons'); ?></a></p>
        <?php
    } else {
        ?>
        <p><?php esc_html_e('Not on Discord. Published upcoming events that are not tagged "private" are added within a few minutes.', 'initlab-addons'); ?></p>
        <?php
    }
}

if (defined('WP_CLI') && WP_CLI) {
    /**
     * Syncs all upcoming events to Discord.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Only show what would be done.
     */
    WP_CLI::add_command('initlab discord-sync', function ($args, $assoc_args) {
        if (!initlab_discord_config()['configured']) {
            WP_CLI::error('Set the Discord guild ID and bot token in the init Lab settings first.');
        }

        $dry_run = !empty($assoc_args['dry-run']);
        $results = initlab_discord_sync_all($dry_run);
        if (is_wp_error($results)) {
            WP_CLI::error($results->get_error_message());
        }

        $failed = 0;
        foreach ($results as $post_id => $result) {
            if (is_wp_error($result)) {
                $failed++;
                $result = 'FAILED: ' . $result->get_error_message();
            }
            WP_CLI::log(sprintf('%d %s: %s', $post_id, get_the_title($post_id), $result));
        }

        $summary = sprintf('%d upcoming events checked%s.', count($results), $dry_run ? ' (dry run)' : '');
        if ($failed) {
            WP_CLI::error($summary . ' ' . $failed . ' failed.');
        }
        WP_CLI::success($summary);
    });
}
