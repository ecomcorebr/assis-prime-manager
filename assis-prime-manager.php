<?php
/**
 * Plugin Name: Assis Prime Manager
 * Description: Sistema de gestão personalizado da Assis Prime com CRM, agenda, prontuários e gestão operacional.
 * Version: 0.9.80
 * Author: ECOMCORE
 * Text Domain: assis-prime-manager
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
    exit;
}

define('APM_VERSION', '0.9.80');
define('APM_FILE', __FILE__);
define('APM_DIR', plugin_dir_path(__FILE__));
define('APM_URL', plugin_dir_url(__FILE__));

require_once APM_DIR . 'includes/class-apm-installer.php';
require_once APM_DIR . 'includes/class-apm-repository.php';
require_once APM_DIR . 'includes/class-apm-rest.php';
require_once APM_DIR . 'includes/class-apm-admin.php';
require_once APM_DIR . 'includes/class-apm-public.php';
require_once APM_DIR . 'includes/class-apm-router.php';

register_activation_hook(__FILE__, array('APM_Installer', 'activate'));
register_deactivation_hook(__FILE__, array('APM_Installer', 'deactivate'));

function apm_bootstrap() {
    APM_Installer::maybe_upgrade();
    APM_REST::init();
    APM_Admin::init();
    APM_Public::init();
    APM_Router::init();
}
add_action('plugins_loaded', 'apm_bootstrap');
add_action('apm_whatsapp_dispatch', array('APM_Repository', 'run_whatsapp_dispatch'));
