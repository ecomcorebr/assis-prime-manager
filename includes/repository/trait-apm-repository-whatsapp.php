<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables; WP object cache does not apply.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from self::table() are internal constants, not user input; PHPCS cannot statically verify data flow.
if (!defined('ABSPATH')) exit;

trait APM_Repository_Whatsapp_Trait {

    // Template HTML compartilhado pelos e-mails automáticos (confirmação, link de
    // pagamento, teste), seguindo a identidade visual real do site (cor vinho #960018,
    // fundo rosado claro #F5E6E8, logo real do Assis Prime). A logo fica sobre fundo
    // branco (nunca sobre um bloco vinho — o texto da logo já é vinho, ficaria invisível
    // em cima de um fundo da mesma cor). $body_html é o miolo da mensagem (título,
    // parágrafos, tabela de detalhes); $cta_url/$cta_label são opcionais, para um botão
    // de ação centralizado (ex.: "Pagar sinal agora").
    private static function email_html_template($settings, $preheader, $title, $body_html, $cta_url = '', $cta_label = '') {
        $spa_name = esc_html($settings['spa_name'] ?? 'Assis Prime');
        $logo_url = 'https://assisprime.com/wp-content/uploads/2024/08/Logo-Assis-Prime.png';
        $font = "'Roboto',Helvetica,Arial,sans-serif";
        $cta_html = $cta_url ? '<tr><td style="padding:4px 40px 8px;text-align:center;"><a href="' . esc_url($cta_url) . '" style="background:#960018;color:#ffffff;text-decoration:none;font-weight:600;font-size:15px;letter-spacing:.2px;padding:15px 36px;border-radius:999px;display:inline-block;box-shadow:0 6px 16px rgba(150,0,24,.25);">' . esc_html($cta_label) . '</a></td></tr>' : '';
        return '<!doctype html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html($title) . '</title></head>'
            . '<body style="margin:0;padding:0;background:#F5E6E8;font-family:' . $font . ';">'
            . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;">' . esc_html($preheader) . '</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F5E6E8;padding:40px 16px;"><tr><td align="center">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#ffffff;border-radius:20px;overflow:hidden;box-shadow:0 12px 32px rgba(150,0,24,.08);">'
            . '<tr><td style="padding:36px 40px 20px;text-align:center;border-bottom:1px solid #F5E6E8;"><img src="' . esc_url($logo_url) . '" alt="' . $spa_name . '" style="max-height:64px;width:auto;"></td></tr>'
            . '<tr><td style="padding:36px 40px 8px;font-family:' . $font . ';">' . $body_html . '</td></tr>'
            . $cta_html
            . '<tr><td style="padding:12px 40px 0;"><div style="height:1px;background:#F5E6E8;margin:20px 0 0;"></div></td></tr>'
            . '<tr><td style="padding:20px 40px 32px;text-align:center;font-family:' . $font . ';"><p style="margin:0 0 4px;color:#960018;font-size:13px;font-weight:600;letter-spacing:.3px;">' . $spa_name . '</p><p style="margin:0;color:#b9a6ab;font-size:12px;">Esta é uma mensagem automática, não é necessário responder.</p></td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    // Uma linha de "Procedimento: X" dentro da tabela de detalhes de um e-mail.
    private static function email_detail_row($label, $value) {
        if ($value === '' || $value === null) return '';
        return '<tr><td style="padding:10px 0;border-bottom:1px solid #f7eff1;color:#b48a91;font-size:12px;font-weight:600;letter-spacing:.4px;text-transform:uppercase;width:40%;vertical-align:top;">' . esc_html($label) . '</td><td style="padding:10px 0;border-bottom:1px solid #f7eff1;color:#2b2026;font-size:15px;font-weight:500;">' . esc_html($value) . '</td></tr>';
    }

    // ===== Infraestrutura Z-API =====

    private static function zapi_base_url($settings) {
        $instance = trim((string) ($settings['zapi_instance_id'] ?? ''));
        $token = trim((string) ($settings['zapi_instance_token'] ?? ''));
        return "https://api.z-api.io/instances/{$instance}/token/{$token}";
    }

    private static function zapi_headers($settings) {
        $headers = array('Content-Type' => 'application/json');
        $client_token = trim((string) ($settings['zapi_client_token'] ?? ''));
        if ($client_token) $headers['Client-Token'] = $client_token;
        return $headers;
    }

    public static function whatsapp_configured($settings = null) {
        $settings = $settings ?: self::get_settings();
        return !empty($settings['whatsapp_enabled'])
            && trim((string) ($settings['zapi_instance_id'] ?? '')) !== ''
            && trim((string) ($settings['zapi_instance_token'] ?? '')) !== '';
    }

    // URL que deve ser cadastrada no painel da Z-API como webhook "ao receber mensagem".
    // O token no caminho funciona como segredo compartilhado: a Z-API não assina os
    // webhooks que envia, então validamos pela posse do token em vez de confiar cegamente
    // em qualquer POST que chegue nessa rota (mesmo princípio já usado para a InfinitePay).
    public static function whatsapp_webhook_url() {
        $settings = self::get_settings();
        $token = $settings['zapi_webhook_token'] ?? '';
        return rest_url('assis-prime/v1/whatsapp-webhook/' . rawurlencode($token));
    }

    private static function normalize_whatsapp_phone($phone) {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if (!$digits) return '';
        // Números brasileiros informados sem o DDI (10 ou 11 dígitos: DDD + número).
        if (strlen($digits) <= 11) $digits = '55' . $digits;
        return $digits;
    }

    public static function send_whatsapp_message($phone, $message) {
        $settings = self::get_settings();
        if (!self::whatsapp_configured($settings)) return new WP_Error('apm_wa_not_configured', 'A integração com o WhatsApp (Z-API) ainda não está configurada.', array('status' => 503));
        $digits = self::normalize_whatsapp_phone($phone);
        if (!$digits) return new WP_Error('apm_wa_phone', 'Telefone inválido para envio pelo WhatsApp.', array('status' => 400));
        $response = wp_remote_post(self::zapi_base_url($settings) . '/send-text', array(
            'timeout' => 20,
            'headers' => self::zapi_headers($settings),
            'body' => wp_json_encode(array('phone' => $digits, 'message' => (string) $message)),
        ));
        if (is_wp_error($response)) return new WP_Error('apm_wa_connection', 'Não foi possível conectar à Z-API: ' . $response->get_error_message(), array('status' => 502));
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $json = json_decode($body, true);
        if ($code < 200 || $code >= 300) {
            // A Z-API costuma devolver o motivo real no corpo da resposta (ex.: sessão/QR Code
            // desconectado, instância inválida) — sem lê-lo, a mensagem ficava genérica e não
            // dava nenhuma pista do que corrigir (ex.: reconectar o WhatsApp pelo QR Code).
            $reason = is_array($json) ? (string) ($json['message'] ?? $json['error'] ?? '') : '';
            $suffix = $reason ? ': ' . $reason : (trim((string) $body) ? ': ' . wp_strip_all_tags($body) : '');
            return new WP_Error('apm_wa_send', 'Não foi possível enviar a mensagem pelo WhatsApp' . $suffix, array('status' => 502));
        }
        return is_array($json) ? $json : array();
    }

    // ===== Reconhecimento de lead vindo de campanha (Click-to-WhatsApp) =====

    // Localiza, nas formas conhecidas em que a Z-API pode encaminhar os metadados de
    // anúncio (campo "referral" do WhatsApp Cloud API, repassado como está recebido do
    // WhatsApp). O formato exato só pode ser confirmado com um webhook real de produção;
    // por segurança, o código tenta vários caminhos plausíveis e nunca falha caso nenhum
    // deles exista — o lead ainda assim é criado, só sem a atribuição de campanha.
    private static function extract_whatsapp_referral($payload) {
        $candidates = array(
            $payload['referral'] ?? null,
            $payload['message']['referral'] ?? null,
            $payload['text']['referral'] ?? null,
        );
        foreach ($candidates as $c) {
            if (is_array($c) && !empty($c)) return $c;
        }
        return null;
    }

    public static function handle_whatsapp_webhook($payload, $token) {
        $settings = self::get_settings();
        $expected = (string) ($settings['zapi_webhook_token'] ?? '');
        if (!$expected || !hash_equals($expected, (string) $token)) {
            return new WP_Error('apm_wa_webhook_token', 'Token de webhook inválido.', array('status' => 403));
        }
        if (!self::whatsapp_configured($settings)) return array('received' => true, 'configured' => false);

        // Ignora eventos que não são mensagens recebidas de um contato (ex.: confirmação de
        // entrega/leitura, ou mensagens enviadas pelo próprio número do SPA).
        if (!empty($payload['fromMe'])) return array('received' => true, 'ignored' => true);
        $phone = sanitize_text_field((string) ($payload['phone'] ?? ''));
        if (!$phone) return array('received' => true, 'ignored' => true);

        global $wpdb;
        $digits = preg_replace('/\D+/', '', $phone);
        $last8 = substr($digits, -8);

        // Já é cliente? Não cria lead duplicado para quem já está cadastrado.
        $client_id = $last8 ? (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM " . self::table('clients') . " WHERE phone LIKE %s ORDER BY id DESC LIMIT 1",
            '%' . $wpdb->esc_like($last8) . '%'
        )) : 0;
        if ($client_id) return array('received' => true, 'client_id' => $client_id, 'skipped_existing_client' => true);

        // Já existe um lead em aberto com este telefone? Evita recriar a cada nova mensagem
        // da mesma conversa.
        $existing_lead_id = $last8 ? (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM " . self::table('crm_leads') . " WHERE phone LIKE %s AND status NOT IN ('won','lost') ORDER BY id DESC LIMIT 1",
            '%' . $wpdb->esc_like($last8) . '%'
        )) : 0;
        if ($existing_lead_id) return array('received' => true, 'lead_id' => $existing_lead_id, 'duplicate' => true);

        $name = sanitize_text_field((string) ($payload['senderName'] ?? ($payload['chatName'] ?? '')));
        $referral = self::extract_whatsapp_referral($payload);
        $source = 'WhatsApp';
        $interest = '';
        if ($referral) {
            $source = 'Anúncio Meta (clique para WhatsApp)';
            $interest = sanitize_text_field((string) ($referral['headline'] ?? ($referral['body'] ?? '')));
        }

        $lead_id = self::save_lead(array(
            'name' => $name ?: $phone,
            'phone' => $phone,
            'source' => $source,
            'interest' => $interest,
            'status' => 'new',
        ));
        if (is_wp_error($lead_id)) return $lead_id;

        self::create_notification(
            'whatsapp_lead',
            'Novo lead pelo WhatsApp',
            ($name ?: $phone) . ' entrou em contato pelo WhatsApp' . ($interest ? ' (anúncio: "' . $interest . '")' : '') . '.',
            'lead', $lead_id,
            class_exists('APM_Router') ? APM_Router::page_url('crm') : '',
            array('phone' => $phone, 'source' => $source)
        );

        return array('received' => true, 'lead_id' => $lead_id);
    }

    // Preenche um template de mensagem com as variáveis {tag}. Templates são definidos
    // pelo usuário em Configurações › WhatsApp; se estiver vazio (instalação nova ainda
    // não migrada, ou o campo foi apagado), cai no texto padrão para nunca enviar uma
    // mensagem em branco.
    private static function render_whatsapp_template($template, $default, $tags) {
        $template = trim((string) $template) !== '' ? $template : $default;
        $search = array(); $replace = array();
        foreach ($tags as $k => $v) { $search[] = '{' . $k . '}'; $replace[] = (string) $v; }
        return str_replace($search, $replace, $template);
    }

    private static function format_brl($v) { return 'R$ ' . number_format((float) $v, 2, ',', '.'); }

    // Texto padrão da confirmação — também usado em class-apm-installer.php ao criar as
    // configurações iniciais do site. Mantido aqui como única fonte da verdade para não
    // haver dois textos padrão divergentes entre a instalação e o envio de verdade.
    public static function default_whatsapp_confirmation_template() {
        return "*Prontinho!*\n\n*Agendamento Confirmado:*\n📅 Data: {data}\n⏰ Horário: {horario}\n✨ Procedimentos: {procedimento}\n💆🏻 Profissional: {profissional}\n💰 Valor: Sinal pago de {sinal} ficando pendente o valor de {valor} podendo ser pago no PIX ou no Cartão com taxa da máquina\n📍 Local: {spa} (SRTVN 702, Conjunto P, Edifício Brasília Rádio Center, Sala 1127, fica localizado próximo ao Brasilia Shopping)\nLocalização: https://www.google.com/maps/place/SRTVN+702,+Conjunto+P,+Sala+1127,+Bras%C3%ADlia\n\n*Caso precise remarcar, pedimos a gentileza de _avisar_ com até 72 horas de antecedência, dentro do nosso horário comercial.*"; // phpcs:ignore PluginCheck.CodeAnalysis.ShortURL.Found
    }

    // ===== Lembrete e confirmação de agendamento =====

    public static function send_booking_confirmation($appointment_id) {
        $settings = self::get_settings();
        if (empty($settings['whatsapp_confirmation_enabled']) || !self::whatsapp_configured($settings)) return false;
        global $wpdb;
        $a = $wpdb->get_row($wpdb->prepare(
            "SELECT a.*, c.name client_name, c.phone client_phone, s.name service_name, p.name professional_name
             FROM " . self::table('appointments') . " a
             LEFT JOIN " . self::table('clients') . " c ON c.id = a.client_id
             LEFT JOIN " . self::table('services') . " s ON s.id = a.service_id
             LEFT JOIN " . self::table('professionals') . " p ON p.id = a.professional_id
             WHERE a.id = %d", (int) $appointment_id
        ), ARRAY_A);
        if (!$a || empty($a['client_phone']) || !empty($a['whatsapp_confirmation_sent_at'])) return false;
        $date = wp_date('d/m/Y', strtotime($a['start_at']));
        $time = wp_date('H:i', strtotime($a['start_at']));
        $spa_name = $settings['spa_name'] ?? 'Assis Prime';
        $deposit = (float) ($a['deposit_amount'] ?? 0);
        $balance = max(0, (float) ($a['total_amount'] ?? 0) - $deposit);
        $message = self::render_whatsapp_template(
            $settings['whatsapp_template_confirmation'] ?? '',
            self::default_whatsapp_confirmation_template(),
            array(
                'nome' => $a['client_name'], 'spa' => $spa_name, 'procedimento' => $a['service_name'], 'profissional' => $a['professional_name'],
                'data' => $date, 'horario' => $time,
                'sinal' => self::format_brl($deposit), 'valor' => self::format_brl($balance),
            )
        );
        $result = self::send_whatsapp_message($a['client_phone'], $message);
        if (is_wp_error($result)) return false;
        $wpdb->update(self::table('appointments'), array('whatsapp_confirmation_sent_at' => current_time('mysql')), array('id' => (int) $appointment_id));
        return true;
    }

    // E-mail de confirmação de agendamento — existe independente do WhatsApp, porque o
    // WhatsApp depende de uma integração externa (Z-API) configurada; sem essa configuração,
    // a cliente não recebia nenhum aviso. Idempotente como o envio por WhatsApp (controlado
    // por email_confirmation_sent_at), então pode ser chamado toda vez que o agendamento for
    // salvo com status "agendado"/"confirmado" sem risco de enviar duas vezes.
    public static function send_booking_confirmation_email($appointment_id) {
        global $wpdb;
        $appointment_id = (int) $appointment_id;
        $a = $wpdb->get_row($wpdb->prepare(
            "SELECT a.*, c.name client_name, c.email client_email, s.name service_name, p.name professional_name
             FROM " . self::table('appointments') . " a
             LEFT JOIN " . self::table('clients') . " c ON c.id = a.client_id
             LEFT JOIN " . self::table('services') . " s ON s.id = a.service_id
             LEFT JOIN " . self::table('professionals') . " p ON p.id = a.professional_id
             WHERE a.id = %d", $appointment_id
        ), ARRAY_A);
        if (!$a || empty($a['client_email']) || !empty($a['email_confirmation_sent_at'])) return false;
        $settings = self::get_settings();
        $spa_name = $settings['spa_name'] ?? 'Assis Prime';
        $first_name = trim((string) strtok((string) $a['client_name'], ' ')) ?: 'tudo bem';
        $date = wp_date('d/m/Y', strtotime($a['start_at']));
        $time = wp_date('H:i', strtotime($a['start_at']));
        $subject = sprintf('%s: seu agendamento está confirmado', $spa_name);
        $body_html = '<p style="margin:0 0 6px;color:#960018;font-size:12px;font-weight:600;letter-spacing:.6px;text-transform:uppercase;">Agendamento confirmado</p>'
            . '<h2 style="margin:0 0 16px;color:#2b2026;font-size:22px;font-weight:600;">Olá, ' . esc_html($first_name) . '!</h2>'
            . '<p style="margin:0 0 24px;color:#6b5a60;font-size:15px;line-height:1.6;">Seu horário na <strong style="color:#2b2026;">' . esc_html($spa_name) . '</strong> está confirmado. Aqui estão os detalhes:</p>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">'
            . self::email_detail_row('Procedimento', $a['service_name'] ?: '—')
            . self::email_detail_row('Profissional', $a['professional_name'] ?: 'A definir')
            . self::email_detail_row('Data', $date . ' às ' . $time)
            . '</table>'
            . '<p style="margin:28px 0 0;color:#6b5a60;font-size:15px;">Até breve! &#128149;</p>';
        $html = self::email_html_template($settings, 'Seu agendamento está confirmado — ' . $date . ' às ' . $time, $subject, $body_html);
        $headers = array('Content-Type: text/html; charset=UTF-8');
        wp_mail($a['client_email'], $subject, $html, $headers);
        $wpdb->update(self::table('appointments'), array('email_confirmation_sent_at' => current_time('mysql')), array('id' => $appointment_id));
        return true;
    }

    // Confirmação de agendamento (WhatsApp e e-mail) nunca pode derrubar quem chamou: um
    // agendamento feito pelo painel já estava sendo gravado com sucesso no banco, mas um
    // erro fatal de verdade (ex.: um plugin de SMTP mal configurado que lança exceção dentro
    // de wp_mail(), ou a Z-API fora do ar de um jeito inesperado) rodando sem proteção
    // quebrava a resposta inteira da requisição — o WordPress devolvia a tela de "erro
    // crítico no site" em vez da confirmação de que o agendamento foi salvo. Mesmo princípio
    // já aplicado em safe_create_anamnesis_for_appointment().
    public static function safe_send_booking_confirmation($appointment_id) {
        try {
            return self::send_booking_confirmation($appointment_id);
        } catch (\Throwable $e) {
            error_log('[Assis Prime] Falha ao enviar confirmação por WhatsApp do agendamento #' . (int) $appointment_id . ': ' . $e->getMessage()); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional exception logging.
            return false;
        }
    }

    public static function safe_send_booking_confirmation_email($appointment_id) {
        try {
            return self::send_booking_confirmation_email($appointment_id);
        } catch (\Throwable $e) {
            error_log('[Assis Prime] Falha ao enviar confirmação por e-mail do agendamento #' . (int) $appointment_id . ': ' . $e->getMessage()); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional exception logging.
            return false;
        }
    }

    // Executado a cada hora via wp-cron (apm_whatsapp_dispatch). Idempotente: cada
    // agendamento só recebe um lembrete, marcado em whatsapp_reminder_sent_at.
    public static function run_whatsapp_dispatch() {
        update_option('apm_whatsapp_cron_last_run', current_time('mysql'));
        $settings = self::get_settings();
        if (empty($settings['whatsapp_reminder_enabled']) || !self::whatsapp_configured($settings)) return;
        global $wpdb;
        $hours_before = max(1, min(72, (int) ($settings['whatsapp_reminder_hours_before'] ?? 24)));
        $now = current_time('timestamp');
        // Janela de 1h ao redor do alvo (o cron roda de hora em hora): evita perder
        // agendamentos por causa de pequenas variações no horário exato do disparo.
        $window_start = gmdate('Y-m-d H:i:s', $now + ($hours_before * 3600));
        $window_end = gmdate('Y-m-d H:i:s', $now + (($hours_before + 1) * 3600));
        $table = self::table('appointments');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT a.*, c.name client_name, c.phone client_phone, s.name service_name, p.name professional_name
             FROM $table a
             LEFT JOIN " . self::table('clients') . " c ON c.id = a.client_id
             LEFT JOIN " . self::table('services') . " s ON s.id = a.service_id
             LEFT JOIN " . self::table('professionals') . " p ON p.id = a.professional_id
             WHERE a.status IN ('scheduled','confirmed') AND a.start_at >= %s AND a.start_at < %s AND a.whatsapp_reminder_sent_at IS NULL",
            $window_start, $window_end
        ), ARRAY_A);
        foreach ($rows as $a) {
            if (empty($a['client_phone'])) continue;
            $message = self::build_reminder_message($a, $settings);
            $result = self::send_whatsapp_message($a['client_phone'], $message);
            if (!is_wp_error($result)) {
                $wpdb->update($table, array('whatsapp_reminder_sent_at' => current_time('mysql')), array('id' => (int) $a['id']));
            }
        }
    }

    private static function build_reminder_message($a, $settings) {
        $spa_name = $settings['spa_name'] ?? 'Assis Prime';
        $date = wp_date('d/m/Y', strtotime($a['start_at']));
        $time = wp_date('H:i', strtotime($a['start_at']));
        return self::render_whatsapp_template(
            $settings['whatsapp_template_reminder'] ?? '',
            "Olá, {nome}! Passando para lembrar do seu horário na {spa}:\n\n• Procedimento: {procedimento}\n• Profissional: {profissional}\n• Data: {data} às {horario}\n\nCaso precise remarcar, entre em contato com a gente. Até breve!",
            array('nome' => $a['client_name'], 'spa' => $spa_name, 'procedimento' => $a['service_name'], 'profissional' => $a['professional_name'], 'data' => $date, 'horario' => $time)
        );
    }

    // Disparo manual pedido pelo administrador em Configurações > WhatsApp, para testar a
    // integração de verdade: envia o lembrete do próximo agendamento futuro com WhatsApp
    // cadastrado, sem depender da janela horária do cron e sem marcar whatsapp_reminder_sent_at
    // (o envio automático continua ocorrendo normalmente no horário certo).
    // O badge "Conectado" nas Configurações sempre checou só se o ID/token foram preenchidos
    // — nunca se a sessão do WhatsApp (QR Code) realmente está pareada na Z-API agora. Uma
    // sessão pode cair (ex.: celular desconectado da internet, WhatsApp Web expirado) sem que
    // nada no painel avise; o primeiro sinal disso era um cliente reclamando que não recebeu
    // nada. Esta checagem consulta a própria Z-API para confirmar a conexão de verdade.
    public static function zapi_connection_status() {
        $settings = self::get_settings();
        if (!self::whatsapp_configured($settings)) return array('configured' => false, 'connected' => false, 'checked' => false, 'message' => 'Preencha o ID da instância e o token para verificar a conexão.');
        try {
            $response = wp_remote_get(self::zapi_base_url($settings) . '/status', array('timeout' => 15, 'headers' => self::zapi_headers($settings)));
        } catch (\Throwable $e) {
            return array('configured' => true, 'connected' => false, 'checked' => false, 'message' => 'Não foi possível conectar à Z-API agora para verificar o status.');
        }
        if (is_wp_error($response)) return array('configured' => true, 'connected' => false, 'checked' => false, 'message' => 'Não foi possível conectar à Z-API agora para verificar o status.');
        $code = wp_remote_retrieve_response_code($response);
        $raw_body = wp_remote_retrieve_body($response);
        $json = json_decode($raw_body, true);
        if ($code < 200 || $code >= 300 || !is_array($json)) {
            // Resposta fora do formato esperado — não dá pra afirmar que a sessão caiu (isso
            // seria um falso negativo), então o aviso fica neutro e manda confirmar direto no
            // painel da Z-API, em vez de insinuar que o ID/token estão errados (eles já estão
            // cadastrados e o envio de mensagens pode estar funcionando normalmente).
            // Mostra a resposta bruta da Z-API (truncada) na própria mensagem — é o jeito mais
            // rápido de descobrir o nome de campo certo sem precisar acessar o log do servidor.
            $detail = trim((string) $raw_body);
            $detail = $detail !== '' ? ' Resposta recebida (HTTP ' . $code . '): ' . mb_substr(wp_strip_all_tags($detail), 0, 300) : ' (resposta vazia, HTTP ' . $code . ')';
            return array('configured' => true, 'connected' => null, 'checked' => false, 'message' => 'Não foi possível confirmar o status pela Z-API agora (resposta inesperada).' . $detail . ' Isso não significa que o WhatsApp está desconectado — confira diretamente em app.z-api.io se a sessão está pareada, ou use o botão de teste de envio abaixo.');
        }
        // A Z-API já usou nomes diferentes pra esse campo em versões distintas da API —
        // tenta os formatos mais comuns antes de desistir.
        $connected = !empty($json['connected']) || !empty($json['smartphoneConnected']) || !empty($json['session']) || (isset($json['status']) && strtolower((string) $json['status']) === 'connected');
        // Se nenhum dos nomes de campo conhecidos bateu, mostra o JSON bruto (truncado) junto
        // com o aviso de "não conectado" — do contrário um falso negativo (campo com nome novo
        // que a Z-API ainda não documentou) ficaria indistinguível de uma sessão realmente caída.
        $known_fields = array('connected', 'smartphoneConnected', 'session', 'status');
        $field_hit = false;
        foreach ($known_fields as $f) { if (array_key_exists($f, $json)) { $field_hit = true; break; } }
        $debug_suffix = (!$connected && !$field_hit) ? ' Resposta da Z-API, para conferência: ' . mb_substr(wp_strip_all_tags($raw_body), 0, 300) : '';
        return array(
            'configured' => true,
            'connected' => $connected,
            'checked' => true,
            'message' => $connected
                ? 'WhatsApp conectado e pronto para enviar mensagens agora.'
                : 'A instância está configurada, mas o WhatsApp NÃO está conectado agora — normalmente é preciso escanear o QR Code de novo no painel da Z-API (isso acontece quando o celular fica sem internet por muito tempo ou a sessão expira).' . $debug_suffix,
        );
    }

    public static function send_test_whatsapp_reminder() {
        $settings = self::get_settings();
        if (!self::whatsapp_configured($settings)) return new WP_Error('apm_wa_not_configured', 'A integração com o WhatsApp (Z-API) ainda não está configurada.', array('status' => 503));
        global $wpdb;
        $table = self::table('appointments');
        $a = $wpdb->get_row($wpdb->prepare(
            "SELECT a.*, c.name client_name, c.phone client_phone, s.name service_name, p.name professional_name
             FROM $table a
             LEFT JOIN " . self::table('clients') . " c ON c.id = a.client_id
             LEFT JOIN " . self::table('services') . " s ON s.id = a.service_id
             LEFT JOIN " . self::table('professionals') . " p ON p.id = a.professional_id
             WHERE a.status IN ('scheduled','confirmed') AND a.start_at >= %s AND c.phone IS NOT NULL AND c.phone != ''
             ORDER BY a.start_at ASC LIMIT 1",
            current_time('mysql')
        ), ARRAY_A);
        if (!$a) return new WP_Error('apm_wa_no_appointment', 'Não há nenhum agendamento futuro com WhatsApp cadastrado para testar o envio.', array('status' => 404));
        $message = self::build_reminder_message($a, $settings);
        $result = self::send_whatsapp_message($a['client_phone'], $message);
        if (is_wp_error($result)) return $result;
        return array(
            'client_name' => $a['client_name'],
            'start_at' => $a['start_at'],
        );
    }

    // ===== Segundo fator por WhatsApp na assinatura digital =====

    public static function whatsapp_otp_required($settings = null) {
        $settings = $settings ?: self::get_settings();
        return !empty($settings['whatsapp_otp_enabled']) && self::whatsapp_configured($settings);
    }

    public static function create_signature_otp($token) {
        global $wpdb;
        $limited = self::rate_limit_public_action('signature_otp_send', 5, 600);
        if (is_wp_error($limited)) return $limited;
        $hash = hash('sha256', sanitize_text_field($token));
        $s = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table('signature_requests') . " WHERE token_hash=%s LIMIT 1", $hash), ARRAY_A);
        if (!$s) return new WP_Error('apm_signature_invalid', 'Link de assinatura inválido.', array('status' => 404));
        if ($s['status'] === 'signed') return new WP_Error('apm_signature_signed', 'Este documento já foi assinado.', array('status' => 409));
        if ($s['status'] !== 'pending' || strtotime($s['expires_at']) < current_time('timestamp')) return new WP_Error('apm_signature_expired', 'Este link de assinatura expirou.', array('status' => 410));
        if (!self::whatsapp_otp_required()) return new WP_Error('apm_wa_otp_disabled', 'A confirmação por WhatsApp não está habilitada.', array('status' => 400));
        if ((int) $s['otp_sent_count'] >= 5) return new WP_Error('apm_wa_otp_limit', 'Limite de códigos enviados para este documento. Entre em contato com a Assis Prime.', array('status' => 429));
        $phone = $s['signer_phone'] ?: '';
        if (!$phone) return new WP_Error('apm_wa_otp_phone', 'Não há um WhatsApp cadastrado para enviar o código.', array('status' => 400));
        $code = str_pad((string) wp_rand(0, 999999), 6, '0', STR_PAD_LEFT);
        $now = current_time('mysql');
        $expires = gmdate('Y-m-d H:i:s', current_time('timestamp') + 600);
        $settings = self::get_settings();
        $message = self::render_whatsapp_template(
            $settings['whatsapp_template_otp'] ?? '',
            "Seu código de confirmação para assinar o documento na {spa} é: {codigo}\n\nVálido por 10 minutos. Não compartilhe este código.",
            array('codigo' => $code, 'spa' => $settings['spa_name'] ?? 'Assis Prime')
        );
        $result = self::send_whatsapp_message($phone, $message);
        if (is_wp_error($result)) return $result;
        $wpdb->update(self::table('signature_requests'), array(
            'otp_code_hash' => hash('sha256', $code),
            'otp_expires_at' => $expires,
            'otp_attempts' => 0,
            'otp_sent_count' => (int) $s['otp_sent_count'] + 1,
            'otp_verified_at' => null,
            'updated_at' => $now,
        ), array('id' => (int) $s['id']));
        return array('sent' => true, 'phone_last4' => substr(preg_replace('/\D+/', '', $phone), -4), 'expires_at' => $expires);
    }

    public static function verify_signature_otp($token, $code) {
        global $wpdb;
        $limited = self::rate_limit_public_action('signature_otp_verify', 10, 600);
        if (is_wp_error($limited)) return $limited;
        $hash = hash('sha256', sanitize_text_field($token));
        $s = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table('signature_requests') . " WHERE token_hash=%s LIMIT 1", $hash), ARRAY_A);
        if (!$s) return new WP_Error('apm_signature_invalid', 'Link de assinatura inválido.', array('status' => 404));
        if ($s['status'] !== 'pending' || strtotime($s['expires_at']) < current_time('timestamp')) return new WP_Error('apm_signature_expired', 'Este link de assinatura expirou.', array('status' => 410));
        if ((int) $s['otp_attempts'] >= 5) return new WP_Error('apm_wa_otp_locked', 'Muitas tentativas incorretas. Solicite um novo código.', array('status' => 429));
        if (empty($s['otp_code_hash']) || empty($s['otp_expires_at']) || strtotime($s['otp_expires_at']) < current_time('timestamp')) {
            return new WP_Error('apm_wa_otp_expired', 'O código expirou. Solicite um novo.', array('status' => 410));
        }
        $code = preg_replace('/\D+/', '', (string) $code);
        if (!hash_equals($s['otp_code_hash'], hash('sha256', $code))) {
            $wpdb->update(self::table('signature_requests'), array('otp_attempts' => (int) $s['otp_attempts'] + 1), array('id' => (int) $s['id']));
            return new WP_Error('apm_wa_otp_wrong', 'Código incorreto. Confira e tente novamente.', array('status' => 400));
        }
        $wpdb->update(self::table('signature_requests'), array('otp_verified_at' => current_time('mysql'), 'updated_at' => current_time('mysql')), array('id' => (int) $s['id']));
        return array('verified' => true);
    }

    // Usado por sign_record(): o código verificado só vale por uma janela curta, para que
    // confirmar o WhatsApp não autorize, horas depois, uma assinatura feita por outra pessoa
    // com acesso ao mesmo link.
    private static function signature_otp_satisfied($s) {
        if (!self::whatsapp_otp_required()) return true;
        if (empty($s['otp_verified_at'])) return false;
        return (current_time('timestamp') - strtotime($s['otp_verified_at'])) <= 1800;
    }
}
