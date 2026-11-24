<?php
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/repository/trait-apm-repository-catalog.php';
require_once __DIR__ . '/repository/trait-apm-repository-access.php';
require_once __DIR__ . '/repository/trait-apm-repository-agenda.php';
require_once __DIR__ . '/repository/trait-apm-repository-crm.php';
require_once __DIR__ . '/repository/trait-apm-repository-records.php';
require_once __DIR__ . '/repository/trait-apm-repository-anamnesis.php';
require_once __DIR__ . '/repository/trait-apm-repository-inventory.php';
require_once __DIR__ . '/repository/trait-apm-repository-finance.php';
require_once __DIR__ . '/repository/trait-apm-repository-whatsapp.php';
require_once __DIR__ . '/repository/trait-apm-repository-status.php';

/**
 * Facade de acesso aos dados do Assis Prime.
 *
 * A API pública desta classe foi mantida por compatibilidade. A implementação
 * está separada por domínio em traits para evitar um repositório monolítico.
 */
class APM_Repository {
    use APM_Repository_Catalog_Trait,
        APM_Repository_Access_Trait,
        APM_Repository_Agenda_Trait,
        APM_Repository_CRM_Trait,
        APM_Repository_Records_Trait,
        APM_Repository_Anamnesis_Trait,
        APM_Repository_Inventory_Trait,
        APM_Repository_Finance_Trait,
        APM_Repository_Whatsapp_Trait,
        APM_Repository_Status_Trait;

    private static function table($name) {
        global $wpdb;
        return $wpdb->prefix . 'apm_' . $name;
    }

    private static function decimal($v){
        if (is_int($v) || is_float($v)) return round((float) $v, 2);

        $v = trim((string) $v);
        if ($v === '') return 0.0;

        // Remove currency symbols, regular/non-breaking spaces and anything
        // unrelated to a numeric monetary value while preserving separators.
        $v = str_replace(array('R$', "\xc2\xa0", ' '), '', $v);
        $v = preg_replace('/[^0-9,.-]/', '', $v);

        $last_comma = strrpos($v, ',');
        $last_dot   = strrpos($v, '.');

        if ($last_comma !== false && $last_dot !== false) {
            // 1.530,00 => 1530.00 | 1,530.00 => 1530.00
            if ($last_comma > $last_dot) {
                $v = str_replace('.', '', $v);
                $v = str_replace(',', '.', $v);
            } else {
                $v = str_replace(',', '', $v);
            }
        } elseif ($last_comma !== false) {
            // Brazilian decimal notation: 530,00 => 530.00
            $v = str_replace('.', '', $v);
            $v = str_replace(',', '.', $v);
        }
        // If there is only a dot, keep it as the decimal separator.
        // This is important because number inputs return values like "530.00".

        return round((float) $v, 2);
    }

    private static function datetime_or_null($v){$v=sanitize_text_field($v);if(!$v)return null;$v=str_replace('T',' ',$v);return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/',$v)?(strlen($v)===16?$v.':00':$v):null;}

    private static function date_or_null($v){$v=sanitize_text_field($v);return preg_match('/^\d{4}-\d{2}-\d{2}$/',$v)?$v:null;}

    private static function time($v){$v=sanitize_text_field($v);return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/',$v)?$v:'09:00';}

    // Cabeçalhos genéricos de proxy (X-Forwarded-For) são enviados pelo próprio
    // visitante e podem ser falsificados quando não existe um proxy confiável na
    // frente do site. Só confiamos no cabeçalho específico do Cloudflare, que só
    // chega até a origem quando o tráfego realmente passou pela rede deles.
    private static function resolve_client_ip($server) {
        $cf = isset($server['HTTP_CF_CONNECTING_IP']) ? trim((string) $server['HTTP_CF_CONNECTING_IP']) : '';
        if ($cf && filter_var($cf, FILTER_VALIDATE_IP)) return $cf;
        return isset($server['REMOTE_ADDR']) ? (string) $server['REMOTE_ADDR'] : '';
    }

    // Limite simples baseado em transient, por IP, para endpoints públicos sem
    // autenticação que gravam dados (evita que um script encha o CRM de registros falsos).
    private static function rate_limit_public_action($action, $limit, $window_seconds) {
        $ip = self::resolve_client_ip($_SERVER ?? array());
        if (!$ip) return true;
        $key = 'apm_rl_' . sanitize_key($action) . '_' . md5($ip);
        $count = (int) get_transient($key);
        if ($count >= $limit) return new WP_Error('apm_rate_limited', 'Muitas tentativas em pouco tempo. Aguarde alguns minutos e tente novamente.', array('status' => 429));
        set_transient($key, $count + 1, $window_seconds);
        return true;
    }
}
