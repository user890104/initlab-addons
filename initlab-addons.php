<?php
/**
 * Plugin Name: init Lab Addons
 * Plugin URI:  https://github.com/initlab/initlab-addons
 * Description: Widgets and other features for init Lab's website
 * Version:     2.7.0
 * Author:      Vencislav Atanasov
 * Author URI:  https://gitlab.com/user890104
 * Text Domain: initlab-addons
 * Domain Path: /languages
 */

require __DIR__ . '/text-domain.php';
require __DIR__ . '/widgets.php';
require __DIR__ . '/shortcode-mailman-token.php';
require __DIR__ . '/settings.php';
require __DIR__ . '/vcs-checkout.php';
require __DIR__ . '/events-manager.php';
require __DIR__ . '/discord-sync.php';
