<?php
/**
 * Custom option and settings:
 *  - callback functions
 */

/**
 * initlab_field_text_template callback function.
 *
 * WordPress has magic interaction with the following keys: label_for, class.
 * - the "label_for" key value is used for the "for" attribute of the <label>.
 * - the "class" key value is used for the "class" attribute of the <tr> containing the field.
 * Note: you can add custom key value pairs to be used inside your callbacks.
 *
 * @param array $args
 * @param string $size
 */
function initlab_field_text_template($args, $size = 24) {
    // Get the value of the setting we've registered with register_setting()
    $options = get_option('initlab_options', []);
    ?>
    <input type="text" size="<?php echo strval($size); ?>" id="<?php echo esc_attr($args['label_for']); ?>" name="initlab_options[<?php echo esc_attr($args['label_for']); ?>]" value="<?php echo esc_attr(array_key_exists($args['label_for'], $options) ? $options[$args['label_for']] : '')?>">
    <?php
}

/**
 * Mailman token section callback function.
 *
 * @param array $args  The settings array, defining title, id, callback.
 */
function initlab_section_mailman_token_callback($args) {
    ?>
    <p><?php _e('Please input the correct settings so the [initlab_mailman_token] shortcode can operate properly.', 'initlab-addons'); ?></p>
    <?php
}

/**
 * initlab_field_mailman_secret callback function.
 *
 * WordPress has magic interaction with the following keys: label_for, class.
 * - the "label_for" key value is used for the "for" attribute of the <label>.
 * - the "class" key value is used for the "class" attribute of the <tr> containing the field.
 * Note: you can add custom key value pairs to be used inside your callbacks.
 *
 * @param array $args
 */
function initlab_field_mailman_version_cb($args) {
    // Get the value of the setting we've registered with register_setting()
    $options = get_option('initlab_options', []);
    ?>
    <select id="<?php echo esc_attr($args['label_for']); ?>" name="initlab_options[<?php echo esc_attr($args['label_for']); ?>]">
        <?php
        foreach ($args['options'] as $value => $label) {
            ?>
        <option value="<?php echo esc_attr($value)?>" <?php echo array_key_exists($args['label_for'], $options) ? selected($options[$args['label_for']], $value, false) : ''; ?>>
            <?php esc_html_e($label, 'initlab-addons'); ?>
        </option>
            <?php
        }
        ?>
    </select>
    <?php
}

function initlab_field_mailman_secret_cb($args) {
    initlab_field_text_template($args, 64);
}

/**
 * Discord section callback function.
 *
 * @param array $args  The settings array, defining title, id, callback.
 */
function initlab_section_discord_callback($args) {
    ?>
    <p><?php _e('In order to integrate with Discord, you need a bot that\'s already added to your server with the <strong>MANAGE_EVENTS</strong> permission.', 'initlab-addons'); ?></p>
    <?php
}

function initlab_field_discord_guild_id_cb($args) {
    initlab_field_text_template($args, 24);
}

function initlab_field_discord_bot_token_cb($args) {
    initlab_field_text_template($args, 96);
}

function initlab_field_discord_stage_channel_id_cb($args) {
    initlab_field_text_template($args, 24);
}

function initlab_field_discord_invite_url_cb($args) {
    initlab_field_text_template($args, 32);
}

function initlab_field_discord_sync_enabled_cb($args) {
    $options = get_option('initlab_options', []);
    $last_sync = get_option('initlab_discord_last_sync', []);
    ?>
    <label>
        <input type="checkbox" id="<?php echo esc_attr($args['label_for']); ?>" name="initlab_options[<?php echo esc_attr($args['label_for']); ?>]" value="1" <?php checked(!empty($options[$args['label_for']])); ?>>
        <?php esc_html_e('Keep the Discord events in sync with the published upcoming events', 'initlab-addons'); ?>
    </label>
    <?php
    if (!empty($last_sync['time'])) {
        ?>
    <p class="description">
        <?php
        echo esc_html(sprintf(
            /* translators: %s: date and time */
            __('Last full sync: %s.', 'initlab-addons'),
            wp_date(get_option('date_format') . ' ' . get_option('time_format'), $last_sync['time'])
        ));
        if (!empty($last_sync['error'])) {
            echo ' ' . esc_html($last_sync['error']);
        } elseif (!empty($last_sync['failed'])) {
            echo ' ' . esc_html(sprintf(
                /* translators: %d: number of events */
                __('Events that could not be synced: %d (see the Discord box on each event).', 'initlab-addons'),
                $last_sync['failed']
            ));
        }
        ?>
    </p>
        <?php
    }
}

/**
 * custom option and settings
 */
function initlab_settings_init() {
    // Register a new setting for "initlab" page.
    register_setting('initlab', 'initlab_options');

    // Register a new section in the "initlab" page.
    add_settings_section(
        'initlab_section_mailman_token',
        __('Mailman token shortcode settings', 'initlab-addons'),
        'initlab_section_mailman_token_callback',
        'initlab'
    );

    // Register a new field in the "initlab_section_mailman_token" section, inside the "initlab" page.
    add_settings_field(
        'initlab_field_mailman_version', // As of WP 4.6 this value is used only internally.
                                           // Use $args' label_for to populate the id inside the callback.
        __('Mailman version', 'initlab-addons'),
        'initlab_field_mailman_version_cb',
        'initlab',
        'initlab_section_mailman_token',
        [
            'label_for' => 'mailman_version',
            'options' => [
                '2.1.16' => '2.1.16 - 2.1.20',
                '2.1.21' => '2.1.21 - 2.1.26',
                '2.1.27' => '2.1.27 - 2.1.29',
                '2.1.30' => '2.1.30+',
            ],
        ]
    );

    // Register a new field in the "initlab_section_mailman_token" section, inside the "initlab" page.
    add_settings_field(
        'initlab_field_mailman_secret', // As of WP 4.6 this value is used only internally.
                                           // Use $args' label_for to populate the id inside the callback.
        __('SUBSCRIBE_FORM_SECRET', 'initlab-addons'),
        'initlab_field_mailman_secret_cb',
        'initlab',
        'initlab_section_mailman_token',
        [
            'label_for' => 'mailman_secret',
        ]
    );

    // Register a new section in the "initlab" page.
    add_settings_section(
        'initlab_section_discord',
        __('Discord settings', 'initlab-addons'),
        'initlab_section_discord_callback',
        'initlab'
    );

    // Register a new field in the "initlab_section_discord" section, inside the "initlab" page.
    add_settings_field(
        'initlab_field_discord_guild_id', // As of WP 4.6 this value is used only internally.
                                           // Use $args' label_for to populate the id inside the callback.
        __('Guild ID (Server ID)', 'initlab-addons'),
        'initlab_field_discord_guild_id_cb',
        'initlab',
        'initlab_section_discord',
        [
            'label_for' => 'discord_guild_id',
        ]
    );

    // Register a new field in the "initlab_section_discord" section, inside the "initlab" page.
    add_settings_field(
        'initlab_field_discord_bot_token', // As of WP 4.6 this value is used only internally.
                                           // Use $args' label_for to populate the id inside the callback.
        __('Bot token', 'initlab-addons'),
        'initlab_field_discord_bot_token_cb',
        'initlab',
        'initlab_section_discord',
        [
            'label_for' => 'discord_bot_token',
        ]
    );

    // Register a new field in the "initlab_section_discord" section, inside the "initlab" page.
    add_settings_field(
        'initlab_field_discord_stage_channel_id', // As of WP 4.6 this value is used only internally.
                                           // Use $args' label_for to populate the id inside the callback.
        __('Stage channel ID', 'initlab-addons'),
        'initlab_field_discord_stage_channel_id_cb',
        'initlab',
        'initlab_section_discord',
        [
            'label_for' => 'discord_stage_channel_id',
        ]
    );

    // Register a new field in the "initlab_section_discord" section, inside the "initlab" page.
    add_settings_field(
        'initlab_field_discord_invite_url', // As of WP 4.6 this value is used only internally.
                                           // Use $args' label_for to populate the id inside the callback.
        __('Invite URL', 'initlab-addons'),
        'initlab_field_discord_invite_url_cb',
        'initlab',
        'initlab_section_discord',
        [
            'label_for' => 'discord_invite_url',
        ]
    );

    // Register a new field in the "initlab_section_discord" section, inside the "initlab" page.
    add_settings_field(
        'initlab_field_discord_sync_enabled', // As of WP 4.6 this value is used only internally.
                                           // Use $args' label_for to populate the id inside the callback.
        __('Event sync', 'initlab-addons'),
        'initlab_field_discord_sync_enabled_cb',
        'initlab',
        'initlab_section_discord',
        [
            'label_for' => 'discord_sync_enabled',
        ]
    );
}

/**
 * Register our initlab_settings_init to the admin_init action hook.
 */
add_action('admin_init', 'initlab_settings_init');

/**
 * Top level menu callback function
 */
function initlab_options_page_html() {
    // check user capabilities
    if (!current_user_can('manage_options')) {
        return;
    }

    // add error/update messages

    // check if the user have submitted the settings
    // WordPress will add the "settings-updated" $_GET parameter to the url
    if (isset($_GET['settings-updated'])) {
        // add settings saved message with the class of "updated"
        add_settings_error('initlab_messages', 'initlab_message', __('Settings saved.', 'initlab-addons'), 'updated');
    }

    // show error/update messages
    settings_errors('initlab_messages');
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        <form action="options.php" method="post">
            <?php
            // output security fields for the registered setting "initlab"
            settings_fields('initlab');
            // output setting sections and their fields
            // (sections are registered for "initlab", each field is registered to a specific section)
            do_settings_sections('initlab');
            // output save settings button
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

/**
 * Add the top level menu page.
 */
function initlab_options_page() {
    add_menu_page(
        __('init Lab Options', 'initlab-addons'),
    __('init Lab', 'initlab-addons'),
        'manage_options',
        'initlab',
        'initlab_options_page_html'
    );
}

/**
 * Register our initlab_options_page to the admin_menu action hook.
 */
add_action('admin_menu', 'initlab_options_page');
