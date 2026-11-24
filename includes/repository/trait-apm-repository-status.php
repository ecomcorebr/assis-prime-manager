<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables; WP object cache does not apply.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from self::table() are internal constants, not user input; PHPCS cannot statically verify data flow.
if (!defined('ABSPATH')) exit;

// Página "Status do sistema": um raio-x rápido para o gestor confirmar que tudo está
// funcionando (versões, banco de dados, WhatsApp, pagamentos e a tarefa cron que dispara
// os lembretes de agendamento) sem precisar pedir ajuda técnica para checar isso.
trait APM_Repository_Status_Trait {

    public static function system_status() {
        global $wpdb;

        $db_ok = true; $db_error = '';
        $probe = $wpdb->get_var("SELECT 1");
        if ($probe === null && $wpdb->last_error) { $db_ok = false; $db_error = $wpdb->last_error; }
        $table_exists = (bool) $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", self::table('professionals')));

        $settings = self::get_settings();

        $next_scheduled = wp_next_scheduled('apm_whatsapp_dispatch');
        $last_run = get_option('apm_whatsapp_cron_last_run', '');
        $now = current_time('timestamp');
        $last_run_seconds_ago = $last_run ? max(0, $now - strtotime($last_run)) : null;
        // O lembrete roda de hora em hora; se já passou bem mais que isso desde a última
        // execução registrada, é sinal de que o wp-cron real do servidor não está disparando.
        $cron_overdue = $last_run_seconds_ago !== null && $last_run_seconds_ago > 2 * 3600;
        $cron_never_ran = empty($last_run);

        return array(
            'checked_at'   => current_time('mysql'),
            'plugin_version' => APM_VERSION,
            'php_version'  => array('value' => PHP_VERSION, 'ok' => version_compare(PHP_VERSION, '7.4', '>=')),
            'wp_version'   => array('value' => get_bloginfo('version')),
            'database'     => array(
                'ok'               => $db_ok && $table_exists,
                'connection_ok'    => $db_ok,
                'tables_ok'        => $table_exists,
                'error'            => $db_error,
                'installed_version'=> get_option('apm_db_version', ''),
                'required_version' => APM_Installer::DB_VERSION,
                'up_to_date'       => get_option('apm_db_version', '') === APM_Installer::DB_VERSION,
            ),
            'cron' => array(
                'wp_cron_disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
                'next_scheduled_at'=> $next_scheduled ? gmdate('Y-m-d H:i:s', $next_scheduled) : null,
                'last_run_at'      => $last_run ?: null,
                'never_ran'        => $cron_never_ran,
                'overdue'          => $cron_overdue,
            ),
            'whatsapp' => array(
                'enabled'    => (bool) ($settings['whatsapp_enabled'] ?? false),
                'configured' => self::whatsapp_configured($settings),
                'reminder_enabled' => (bool) ($settings['whatsapp_reminder_enabled'] ?? false),
                'confirmation_enabled' => (bool) ($settings['whatsapp_confirmation_enabled'] ?? false),
            ),
            'payments' => array(
                'enabled'    => (bool) ($settings['payment_enabled'] ?? false),
                'test_mode'  => (bool) ($settings['payment_test_mode'] ?? false),
                'gateway'    => $settings['payment_gateway'] ?? 'infinitepay',
                'handle_set' => trim((string) ($settings['infinitepay_handle'] ?? '')) !== '',
            ),
        );
    }
}
