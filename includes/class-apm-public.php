<?php
if (!defined('ABSPATH')) exit;

class APM_Public {
    public static function init(){
        add_shortcode('assis_prime_booking',array(__CLASS__,'shortcode'));
        add_action('template_redirect',array(__CLASS__,'standalone_preview'));
        add_filter('sgo_css_minify_exclude',array(__CLASS__,'exclude_from_siteground_optimizer'));
        add_filter('sgo_css_combine_exclude',array(__CLASS__,'exclude_from_siteground_optimizer'));
        add_filter('sgo_js_minify_exclude',array(__CLASS__,'exclude_from_siteground_optimizer'));
        add_filter('sgo_js_async_exclude',array(__CLASS__,'exclude_from_siteground_optimizer'));
        add_filter('sgo_javascript_combine_exclude',array(__CLASS__,'exclude_from_siteground_optimizer'));
    }
    public static function exclude_from_siteground_optimizer($handles){
        $handles[] = 'apm-public';
        return $handles;
    }
    public static function standalone_preview(){
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only checked for presence to pick a render path, value is never read/used.
        if(!isset($_GET['assis_prime_booking'])) return;
        self::render_document();
        exit;
    }
    public static function shortcode(){
        self::enqueue();
        return '<div id="apm-public-booking" class="apm-public-root"></div>';
    }
    private static function enqueue(){
        wp_enqueue_style('apm-public',APM_URL.'assets/css/public.css',array(),APM_VERSION);
        wp_enqueue_script('apm-public',APM_URL.'assets/js/public.js',array(),APM_VERSION,true);
        wp_localize_script('apm-public','APMPublic',array('rest'=>esc_url_raw(rest_url('assis-prime/v1/')),'logo'=>APM_URL.'assets/images/logo.png','siteUrl'=>home_url('/')));
    }
    public static function render_document(){
        APM_Router::isolate_from_theme(array('apm-public', 'apm-font-inter'), array('apm-public'));
        APM_Router::enqueue_brand_font();
        self::enqueue();
        ?><!doctype html><html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo('charset'); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Agendamento — Assis Prime</title><?php self::render_tracking_head(); wp_head(); ?></head><body class="apm-booking-standalone"><?php self::render_tracking_body_start(); ?><div id="apm-public-booking" class="apm-public-root"></div><?php wp_footer(); ?></body></html><?php
    }

    // Pixel da Meta e Google Tag Manager, carregados só nesta página pública de agendamento
    // (é para onde o cliente aponta as campanhas de anúncio diretamente). Os valores já saem
    // sanitizados de save_settings() (apenas dígitos/letras/hífen), então é seguro imprimi-los
    // direto no HTML sem escapar — escapar quebraria o próprio código do GTM/Pixel.
    private static function render_tracking_head(){
        $settings=APM_Repository::get_settings();
        $gtm=trim((string)($settings['gtm_container_id']??''));
        $pixel=trim((string)($settings['meta_pixel_id']??''));
        if($gtm){
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal GTM boilerplate JS; $gtm is pre-sanitized to [A-Za-z0-9-] in save_settings(), esc_html() here would corrupt the script.
            echo "<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','{$gtm}');</script>\n";
        }
        if($pixel){
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal Meta Pixel boilerplate JS; $pixel is pre-sanitized to digits only in save_settings(), esc_html() here would corrupt the script.
            echo "<script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init','{$pixel}');fbq('track','PageView');</script>\n";
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $pixel is digits-only (see save_settings()); the fallback <img> tracking pixel URL itself cannot go through esc_html() without breaking the & separators.
            echo "<noscript><img height=\"1\" width=\"1\" style=\"display:none\" src=\"https://www.facebook.com/tr?id={$pixel}&ev=PageView&noscript=1\" alt=\"\"/></noscript>\n";
        }
    }

    private static function render_tracking_body_start(){
        $settings=APM_Repository::get_settings();
        $gtm=trim((string)($settings['gtm_container_id']??''));
        if($gtm){
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $gtm is pre-sanitized to [A-Za-z0-9-] in save_settings().
            echo "<noscript><iframe src=\"https://www.googletagmanager.com/ns.html?id={$gtm}\" height=\"0\" width=\"0\" style=\"display:none;visibility:hidden\"></iframe></noscript>\n";
        }
    }
}
