<?php
if (!defined('ABSPATH')) exit;

class APM_Admin {
    public static function init(){
        add_action('admin_menu',array(__CLASS__,'menu'));
        add_action('admin_enqueue_scripts',array(__CLASS__,'assets'));
        add_filter('admin_body_class',array(__CLASS__,'body_class'));
        add_filter('login_redirect',array(__CLASS__,'login_redirect'),20,3);
    }
    public static function menu(){
        if(!current_user_can('manage_options')&&!APM_Repository::user_can_access_system()) return;
        add_menu_page('Assis Prime','Assis Prime','apm_access','assis-prime',array(__CLASS__,'render'),'dashicons-heart',3);
    }
    public static function render(){
        if(!APM_Repository::user_can_access_system()&&!current_user_can('manage_options')) wp_die('Você não tem permissão para acessar este sistema.');
        echo '<div id="apm-app" class="apm-root"><div class="apm-loading"><span></span><strong>Carregando Assis Prime…</strong></div></div><div id="apm-toast" class="apm-toast" role="status" aria-live="polite"></div>';
    }
    public static function assets($hook){
        if($hook!=='toplevel_page_assis-prime') return;
        wp_enqueue_style('apm-admin',APM_URL.'assets/css/admin.css',array(),APM_VERSION);
        wp_enqueue_script('apm-admin',APM_URL.'assets/js/admin.js',array(),APM_VERSION,true);
        wp_localize_script('apm-admin','APM',array(
            'rest'=>esc_url_raw(rest_url('assis-prime/v1/')),
            'nonce'=>wp_create_nonce('wp_rest'),
            'logo'=>APM_URL.'assets/images/logo.png',
            'siteUrl'=>home_url('/'),
            'bookingPreview'=>APM_Router::booking_url(),
            'currentPage'=>'dashboard',
            'routes'=>APM_Router::route_map(),
            'baseUrl'=>APM_Router::page_url('dashboard'),
            'version'=>APM_VERSION,
        ));
    }

    public static function login_redirect($redirect_to,$requested_redirect_to,$user){
        if (is_wp_error($user) || !($user instanceof WP_User)) return $redirect_to;
        $access=APM_Repository::get_user_access($user->ID);
        if(!empty($access['redirect']) && (!empty($access['enabled']) || !empty($access['is_admin']))) return APM_Router::page_url('dashboard');
        return $redirect_to;
    }

    public static function body_class($classes){
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only URL param; only adds a CSS class, no state change.
        if(isset($_GET['page'])&&sanitize_text_field(wp_unslash($_GET['page']))==='assis-prime') $classes.=' apm-fullscreen-admin';
        return $classes;
    }
}
