<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables; WP object cache does not apply.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from self::table() are internal constants, not user input; PHPCS cannot statically verify data flow.
if (!defined('ABSPATH')) exit;

/**
 * Modelos de anamnese e o fluxo de preenchimento pelo próprio cliente.
 *
 * Cada modelo cadastrável (tabela apm_anamnesis_templates) guarda só as etapas
 * específicas daquele procedimento ("custom_steps"). Duas etapas são sempre
 * injetadas automaticamente ao redor delas, iguais para qualquer modelo:
 *   - Etapa 1 "Identificação": dados de cadastro do cliente (CPF, nascimento, sexo, telefone).
 *   - Etapa final: controle de sessões + declaração de veracidade, exigida antes da assinatura.
 * Isso mantém o cadastro de um modelo novo simples (só as perguntas de saúde que mudam
 * de procedimento para procedimento) sem abrir mão de nenhum dos dois blocos fixos.
 *
 * O array final (anamnesis_templates()) é consumido tanto pelo assistente dentro do
 * painel (onde o profissional vê todas as etapas) quanto pela página pública de
 * preenchimento (onde só aparecem os campos cuja audiência é 'client' ou 'both').
 */
trait APM_Repository_Anamnesis_Trait {

    private static function anamnesis_identification_step() {
        return array(
            'label' => 'Identificação',
            'intro' => 'Confirme seus dados de cadastro.',
            'audience' => 'both',
            'fields' => array(
                array('key' => 'client_cpf', 'type' => 'text', 'label' => 'CPF', 'audience' => 'both'),
                array('key' => 'client_birth_date', 'type' => 'date', 'label' => 'Nascimento', 'audience' => 'both'),
                array('key' => 'client_gender', 'type' => 'select', 'label' => 'Sexo', 'audience' => 'both', 'options' => array(
                    array('', 'Não informado'), array('female', 'Feminino'), array('male', 'Masculino'), array('other', 'Outro'),
                )),
                array('key' => 'client_phone', 'type' => 'text', 'label' => 'Telefone', 'audience' => 'both'),
            ),
        );
    }

    // Monta o array de execução (com os ids numéricos 1..N e as etapas fixas ao redor) a
    // partir das etapas cadastradas de um modelo. É só aqui que os ids de etapa existem —
    // no banco só fica o título e as etapas do meio.
    private static function anamnesis_assemble_template($title, $custom_steps) {
        $steps = array();
        $steps[] = array_merge(array('id' => 1), self::anamnesis_identification_step());
        $custom_steps = is_array($custom_steps) ? array_values($custom_steps) : array();
        $next_id = 2;
        foreach ($custom_steps as $step) {
            $step = is_array($step) ? $step : array();
            $audience = in_array(($step['audience'] ?? 'both'), array('both', 'professional'), true) ? $step['audience'] : 'both';
            $fields = array();
            foreach ((array) ($step['fields'] ?? array()) as $f) {
                if (empty($f['key']) || empty($f['type'])) continue;
                $fields[] = $f;
            }
            if (!$fields) continue;
            $steps[] = array(
                'id' => $next_id++,
                'label' => (string) ($step['label'] ?? 'Etapa'),
                'intro' => (string) ($step['intro'] ?? ''),
                'audience' => $audience,
                'fields' => $fields,
            );
        }
        // Garante que sempre exista uma etapa final (mesmo que o modelo não tenha nenhuma
        // etapa própria) — é nela que a declaração de veracidade e o controle de sessões
        // aparecem, requisito para liberar a assinatura.
        if (count($steps) < 2) {
            $steps[] = array('id' => $next_id++, 'label' => 'Conclusão', 'intro' => 'Revise e confirme as informações.', 'audience' => 'both', 'fields' => array());
        }
        return array('title' => (string) $title, 'steps' => $steps);
    }

    public static function anamnesis_templates() {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT * FROM " . self::table('anamnesis_templates') . " WHERE active=1 ORDER BY id ASC", ARRAY_A);
        $out = array();
        foreach ((array) $rows as $row) {
            $steps = json_decode($row['custom_steps'] ?? '', true);
            $out[$row['template_key']] = self::anamnesis_assemble_template($row['title'], is_array($steps) ? $steps : array());
        }
        return $out;
    }

    // Lista leve (id + título), usada para montar o seletor "Ficha de anamnese" na tela de
    // Serviços — disponível para quem gerencia Serviços mesmo sem acesso a Prontuários, já
    // que não expõe nenhuma pergunta nem dado de saúde, só o nome dos modelos existentes.
    public static function list_anamnesis_template_options() {
        global $wpdb;
        return $wpdb->get_results("SELECT id,title FROM " . self::table('anamnesis_templates') . " WHERE active=1 ORDER BY title ASC", ARRAY_A);
    }

    // Lista "crua" para a tela de administração dos modelos (título + etapas do meio tal
    // qual cadastradas, sem a montagem das etapas fixas — é isso que o formulário de edição
    // precisa para reabrir exatamente o que foi salvo).
    public static function list_anamnesis_templates() {
        global $wpdb;
        $templates_table = self::table('anamnesis_templates');
        $services_table = self::table('services');
        $rows = $wpdb->get_results(
            "SELECT t.*, (SELECT COUNT(*) FROM $services_table s WHERE s.anamnesis_template_id = t.id) services_count
             FROM $templates_table t ORDER BY t.title ASC", ARRAY_A
        );
        foreach ($rows as &$row) {
            $steps = json_decode($row['custom_steps'] ?? '', true);
            $row['custom_steps'] = is_array($steps) ? $steps : array();
            $row['services_count'] = (int) $row['services_count'];
        }
        return $rows;
    }

    private static function anamnesis_sanitize_field($f) {
        $f = is_array($f) ? $f : array();
        $type = in_array(($f['type'] ?? ''), array('text', 'textarea', 'date', 'yesno', 'select'), true) ? $f['type'] : 'text';
        $label = sanitize_text_field($f['label'] ?? '');
        if ($label === '') return null;
        $key = sanitize_key($f['key'] ?? '') ?: str_replace('-', '_', sanitize_title($label));
        $out = array('key' => $key, 'type' => $type, 'label' => $label);
        if ($type === 'yesno' && !empty($f['detailLabel'])) $out['detailLabel'] = sanitize_text_field($f['detailLabel']);
        if ($type === 'select') {
            $options = array();
            foreach ((array) ($f['options'] ?? array()) as $opt) {
                $value = sanitize_text_field(is_array($opt) ? ($opt[0] ?? '') : '');
                $label_opt = sanitize_text_field(is_array($opt) ? ($opt[1] ?? '') : '');
                if ($label_opt === '') continue;
                $options[] = array($value, $label_opt);
            }
            if (!$options) return null;
            $out['options'] = $options;
        }
        return $out;
    }

    private static function anamnesis_sanitize_steps($steps) {
        $out = array();
        foreach ((array) $steps as $step) {
            $step = is_array($step) ? $step : array();
            $label = sanitize_text_field($step['label'] ?? '');
            if ($label === '') continue;
            $fields = array();
            $seen_keys = array();
            foreach ((array) ($step['fields'] ?? array()) as $f) {
                $field = self::anamnesis_sanitize_field($f);
                if (!$field) continue;
                $k = $field['key']; $n = 2;
                while (in_array($k, $seen_keys, true)) { $k = $field['key'] . '_' . $n; $n++; }
                $field['key'] = $k; $seen_keys[] = $k;
                $fields[] = $field;
            }
            if (!$fields) continue;
            $out[] = array(
                'label' => $label,
                'intro' => sanitize_text_field($step['intro'] ?? ''),
                'audience' => in_array(($step['audience'] ?? 'both'), array('both', 'professional'), true) ? $step['audience'] ?? 'both' : 'both',
                'fields' => $fields,
            );
        }
        return $out;
    }

    public static function save_anamnesis_template($data, $id = 0) {
        global $wpdb;
        $title = sanitize_text_field($data['title'] ?? '');
        if (!$title) return new WP_Error('apm_required', 'Informe o nome do modelo de anamnese.', array('status' => 400));
        $custom_steps = self::anamnesis_sanitize_steps($data['custom_steps'] ?? array());
        if (!$custom_steps) return new WP_Error('apm_anamnesis_no_steps', 'Adicione ao menos uma etapa com uma pergunta para salvar o modelo.', array('status' => 400));
        $table = self::table('anamnesis_templates');
        $now = current_time('mysql');
        if ($id) {
            $wpdb->update($table, array(
                'title' => $title,
                'custom_steps' => wp_json_encode($custom_steps),
                'updated_at' => $now,
            ), array('id' => (int) $id));
            return (int) $id;
        }
        $key = sanitize_title($title) ?: 'modelo';
        $base_key = $key; $n = 2;
        while ($wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE template_key=%s", $key))) { $key = $base_key . '-' . $n; $n++; }
        $wpdb->insert($table, array(
            'template_key' => $key,
            'title' => $title,
            'custom_steps' => wp_json_encode($custom_steps),
            'active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ));
        return (int) $wpdb->insert_id;
    }

    public static function delete_anamnesis_template($id) {
        global $wpdb;
        $id = (int) $id;
        $services_table = self::table('services');
        $in_use = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $services_table WHERE anamnesis_template_id=%d", $id));
        if ($in_use) return new WP_Error('apm_anamnesis_in_use', 'Este modelo está em uso em ' . $in_use . ' serviço(s). Troque ou remova o modelo desses serviços antes de excluir.', array('status' => 409));
        return $wpdb->delete(self::table('anamnesis_templates'), array('id' => $id), array('%d'));
    }

    public static function anamnesis_template($key) {
        $all = self::anamnesis_templates();
        return $all[$key] ?? null;
    }

    private static function anamnesis_option_label($options, $value) {
        foreach ((array) $options as $opt) {
            if ((string) $opt[0] === (string) $value) return $opt[1];
        }
        return $value !== '' && $value !== null ? $value : 'Não informado';
    }

    private static function anamnesis_yesno_label($value) {
        return array('yes' => 'Sim', 'no' => 'Não')[$value] ?? 'Não informado';
    }

    // Filtra os campos de um template para a audiência pedida. 'professional' enxerga tudo;
    // 'client' só enxerga campos marcados como 'both' ou 'client' (nunca os de avaliação
    // clínica, que só fazem sentido vindos da equipe). Um campo sem audiência própria herda
    // a da etapa onde está — assim o cadastro de um modelo novo só precisa marcar a audiência
    // uma vez por etapa, não repetir em cada pergunta.
    public static function anamnesis_template_for_audience($key, $audience = 'professional') {
        $tpl = self::anamnesis_template($key);
        if (!$tpl) return null;
        if ($audience === 'professional') return $tpl;
        $steps = array();
        foreach ($tpl['steps'] as $step) {
            $fields = array_values(array_filter($step['fields'], function ($f) use ($step) {
                return in_array($f['audience'] ?? $step['audience'] ?? 'both', array('both', 'client'), true);
            }));
            if ($fields) {
                $step['fields'] = $fields;
                $steps[] = $step;
            }
        }
        $tpl['steps'] = $steps;
        return $tpl;
    }

    private static function anamnesis_client_keys($key) {
        $tpl = self::anamnesis_template_for_audience($key, 'client');
        if (!$tpl) return array();
        $keys = array();
        foreach ($tpl['steps'] as $step) foreach ($step['fields'] as $f) $keys[] = $f['key'];
        return $keys;
    }

    // Monta o HTML do documento (o mesmo que aparece no prontuário e na tela de assinatura)
    // a partir do schema do template — usado tanto quando o profissional salva pelo painel
    // quanto quando o próprio cliente envia as respostas pelo link público.
    public static function build_anamnesis_content($template_key, $data, $client = array()) {
        $tpl = self::anamnesis_template($template_key);
        if (!$tpl) return '';
        $data = is_array($data) ? $data : array();
        $client_name = is_array($client) ? ($client['name'] ?? '') : '';
        $html = '<div class="apm-signed-document"><h3>' . esc_html($tpl['title']) . '</h3>';
        $html .= '<h4>Identificação</h4><div class="apm-document-grid">';
        $html .= '<div><strong>Nome</strong><span>' . esc_html($client_name) . '</span></div>';
        $html .= '<div><strong>CPF</strong><span>' . esc_html($data['client_cpf'] ?? 'Não informado') . '</span></div>';
        $html .= '<div><strong>Nascimento</strong><span>' . esc_html(!empty($data['client_birth_date']) ? self::anamnesis_format_date($data['client_birth_date']) : 'Não informado') . '</span></div>';
        $html .= '<div><strong>Sexo</strong><span>' . esc_html(self::anamnesis_option_label(array(array('female', 'Feminino'), array('male', 'Masculino'), array('other', 'Outro')), $data['client_gender'] ?? '')) . '</span></div>';
        $html .= '<div><strong>Telefone</strong><span>' . esc_html($data['client_phone'] ?? 'Não informado') . '</span></div>';
        $html .= '</div>';
        foreach ($tpl['steps'] as $step) {
            if ($step['id'] === 1) continue;
            $rows = '';
            foreach ($step['fields'] as $f) {
                $v = $data[$f['key']] ?? '';
                if ($v === '' || $v === null) continue;
                if ($f['type'] === 'yesno') {
                    $det = $data[$f['key'] . '_detail'] ?? '';
                    $rows .= '<div><strong>' . esc_html($f['label']) . '</strong><span>' . esc_html(self::anamnesis_yesno_label($v)) . ($det ? ' · ' . esc_html($det) : '') . '</span></div>';
                } elseif ($f['type'] === 'select') {
                    $rows .= '<div><strong>' . esc_html($f['label']) . '</strong><span>' . esc_html(self::anamnesis_option_label($f['options'] ?? array(), $v)) . '</span></div>';
                } elseif ($f['type'] === 'date') {
                    $rows .= '<div><strong>' . esc_html($f['label']) . '</strong><span>' . esc_html(self::anamnesis_format_date($v)) . '</span></div>';
                } elseif ($f['type'] === 'text') {
                    $rows .= '<div><strong>' . esc_html($f['label']) . '</strong><span>' . esc_html($v) . '</span></div>';
                }
            }
            if ($rows) $html .= '<h4>' . esc_html($step['label']) . '</h4><div class="apm-document-grid">' . $rows . '</div>';
            foreach ($step['fields'] as $f) {
                if ($f['type'] !== 'textarea') continue;
                $v = trim((string) ($data[$f['key']] ?? ''));
                if ($v === '') continue;
                $html .= '<p><strong>' . esc_html($f['label']) . ':</strong><br>' . nl2br(esc_html($v)) . '</p>';
            }
        }
        $html .= '<div class="apm-document-declaration"><strong>Declaração</strong><p>Declaro que as informações acima são verdadeiras, não cabendo ao profissional responsabilidade por informações omitidas nesta avaliação.</p><span>Confirmação registrada: ' . (!empty($data['declaration_confirmed']) ? 'Sim' : 'Não') . '</span></div>';
        $html .= '</div>';
        return $html;
    }

    private static function anamnesis_format_date($v) {
        $v = (string) $v;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) return $v;
        $parts = explode('-', substr($v, 0, 10));
        return $parts[2] . '/' . $parts[1] . '/' . $parts[0];
    }

    // Gera o link que o profissional envia (manualmente) para o cliente preencher a
    // anamnese sozinho. Exige que o registro ainda esteja em rascunho: depois de assinado
    // (ou aguardando assinatura) o documento está travado e não deve mais ser editado.
    public static function create_record_fill_link($record_id, $data = array()) {
        global $wpdb;
        $record_id = (int) $record_id;
        $record = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table('records') . " WHERE id=%d", $record_id), ARRAY_A);
        if (!$record) return new WP_Error('apm_not_found', 'Prontuário não encontrado.', array('status' => 404));
        $access = self::ensure_record_access($record_id); if (is_wp_error($access)) return $access;
        if ($record['type'] !== 'anamnesis') return new WP_Error('apm_record_not_anamnesis', 'Só é possível gerar link de preenchimento para anamneses.', array('status' => 400));
        if (!self::anamnesis_template($record['template_key'])) return new WP_Error('apm_record_unknown_template', 'Modelo de anamnese não reconhecido.', array('status' => 400));
        if (in_array($record['status'], array('signed', 'awaiting_signature'), true)) return new WP_Error('apm_record_locked', 'Este documento já está travado e não pode mais ser preenchido.', array('status' => 409));

        $token = wp_generate_password(40, false, false);
        $hash = hash('sha256', $token);
        $hours = max(1, min(720, (int) ($data['expires_hours'] ?? (24 * 7))));
        $expires = gmdate('Y-m-d H:i:s', current_time('timestamp') + $hours * 3600);
        $wpdb->update(self::table('records'), array('fill_token_hash' => $hash, 'fill_expires_at' => $expires, 'updated_at' => current_time('mysql')), array('id' => $record_id));
        $url = class_exists('APM_Router') ? APM_Router::record_fill_url($token) : add_query_arg('token', $token, home_url('/preencher-anamnese/'));
        return array('url' => $url, 'expires_at' => $expires);
    }

    // Disparado automaticamente assim que um agendamento online é confirmado (sinal pago ou
    // sem sinal) — ver send_booking_confirmation() e booking_status(). Cria a anamnese em
    // rascunho já vinculada ao agendamento, gera o link de preenchimento e manda por e-mail
    // (se o cliente informou e-mail) — mas só quando o SERVIÇO agendado está vinculado a um
    // modelo de anamnese. Sem vínculo, a cliente recebe só a confirmação normal, sem ficha
    // nenhuma (é o que a equipe decide, serviço a serviço, em Serviços > Ficha de anamnese).
    // Idempotente: se já existe uma anamnese para este agendamento (segunda chamada, ex.
    // durante o polling de status do pagamento), não cria outra nem reenvia o e-mail — só a
    // primeira chamada recebe fill_url de volta.
    // Nunca deve derrubar a confirmação do agendamento nem o polling de status do pagamento
    // por causa de um problema nesta funcionalidade adicional — ela é "best effort": se falhar,
    // o agendamento continua confirmado normalmente, só sem o redirecionamento automático.
    public static function safe_create_anamnesis_for_appointment($appointment_id) {
        try {
            return self::create_anamnesis_for_appointment($appointment_id);
        } catch (\Throwable $e) {
            error_log('[Assis Prime] Falha ao criar anamnese automática para o agendamento #' . (int) $appointment_id . ': ' . $e->getMessage()); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional exception logging.
            return null;
        }
    }

    public static function create_anamnesis_for_appointment($appointment_id) {
        global $wpdb;
        $settings = self::get_settings();
        // Padrão ligado: só fica desativado se alguém explicitamente desmarcar a opção nas
        // configurações (ausência da chave nas configurações != desativado).
        $auto_enabled = !isset($settings['anamnesis_auto_send_enabled']) || !empty($settings['anamnesis_auto_send_enabled']);
        if (!$auto_enabled) return null;
        $appointment_id = (int) $appointment_id;
        if (!$appointment_id) return null;
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM " . self::table('records') . " WHERE appointment_id=%d AND type='anamnesis' LIMIT 1", $appointment_id));
        if ($existing) return null;

        $a = $wpdb->get_row($wpdb->prepare(
            "SELECT a.professional_id, c.id client_id, c.name client_name, c.email client_email, s.name service_name, s.anamnesis_template_id
             FROM " . self::table('appointments') . " a
             LEFT JOIN " . self::table('clients') . " c ON c.id = a.client_id
             LEFT JOIN " . self::table('services') . " s ON s.id = a.service_id
             WHERE a.id=%d", $appointment_id
        ), ARRAY_A);
        if (!$a || empty($a['client_id'])) return null;
        if (empty($a['anamnesis_template_id'])) return null;

        $template_row = $wpdb->get_row($wpdb->prepare("SELECT template_key, title FROM " . self::table('anamnesis_templates') . " WHERE id=%d AND active=1", (int) $a['anamnesis_template_id']), ARRAY_A);
        if (!$template_row) return null;
        $template_key = $template_row['template_key'];

        $record_id = self::save_record(array(
            'client_id' => (int) $a['client_id'],
            'appointment_id' => $appointment_id,
            'professional_id' => $a['professional_id'] ? (int) $a['professional_id'] : null,
            'type' => 'anamnesis',
            'title' => $template_row['title'],
            'content' => '',
            'structured_data' => array(),
            'template_key' => $template_key,
            'status' => 'draft',
        ));
        if (is_wp_error($record_id) || !$record_id) return null;

        $token = wp_generate_password(40, false, false);
        $hash = hash('sha256', $token);
        $expires = gmdate('Y-m-d H:i:s', current_time('timestamp') + 7 * 24 * 3600);
        $wpdb->update(self::table('records'), array('fill_token_hash' => $hash, 'fill_expires_at' => $expires, 'updated_at' => current_time('mysql')), array('id' => (int) $record_id));
        $fill_url = class_exists('APM_Router') ? APM_Router::record_fill_url($token) : home_url('/preencher-anamnese/' . $token . '/');

        if (!empty($a['client_email'])) {
            self::send_anamnesis_fill_email($a['client_email'], $a['client_name'] ?: '', $fill_url, $a['service_name'] ?: '');
        }

        return array('record_id' => (int) $record_id, 'fill_url' => $fill_url);
    }

    private static function send_anamnesis_fill_email($to_email, $to_name, $fill_url, $service_name) {
        $settings = self::get_settings();
        $spa_name = $settings['spa_name'] ?? 'Assis Prime';
        $first_name = trim((string) strtok((string) $to_name, ' ')) ?: 'tudo bem';
        $subject = sprintf('%s: falta só preencher sua ficha para confirmar', $spa_name);
        $intro = $service_name
            ? "Para deixar tudo pronto para o seu atendimento de <strong style=\"color:#2b2026;\">" . esc_html($service_name) . "</strong> na <strong style=\"color:#2b2026;\">" . esc_html($spa_name) . "</strong>, falta um passo rápido: preencher sua ficha de avaliação."
            : "Para deixar tudo pronto para o seu atendimento na <strong style=\"color:#2b2026;\">" . esc_html($spa_name) . "</strong>, falta um passo rápido: preencher sua ficha de avaliação.";
        $body_html = '<p style="margin:0 0 6px;color:#960018;font-size:12px;font-weight:600;letter-spacing:.6px;text-transform:uppercase;">Falta um passo</p>'
            . '<h2 style="margin:0 0 16px;color:#2b2026;font-size:22px;font-weight:600;">Olá, ' . esc_html($first_name) . '!</h2>'
            . '<p style="margin:0 0 20px;color:#6b5a60;font-size:15px;line-height:1.6;">' . $intro . '</p>'
            . '<p style="margin:0;color:#6b5a60;font-size:14px;">Leva poucos minutos. Depois de preencher, você vai confirmar e assinar com um código enviado por WhatsApp.</p>';
        $html = self::email_html_template($settings, 'Falta preencher sua ficha de avaliação', $subject, $body_html, $fill_url, 'Preencher ficha agora');
        $headers = array('Content-Type: text/html; charset=UTF-8');
        wp_mail($to_email, $subject, $html, $headers);
    }

    public static function public_record_fill($token) {
        global $wpdb;
        $limited = self::rate_limit_public_action('record_fill_view', 30, 600);
        if (is_wp_error($limited)) return $limited;
        $hash = hash('sha256', sanitize_text_field($token));
        $r = self::table('records'); $c = self::table('clients'); $a = self::table('appointments');
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT rec.*, cl.name client_name, ap.start_at appointment_start, sv.name appointment_service
             FROM $r rec LEFT JOIN $c cl ON cl.id=rec.client_id
             LEFT JOIN $a ap ON ap.id=rec.appointment_id
             LEFT JOIN " . self::table('services') . " sv ON sv.id=ap.service_id
             WHERE rec.fill_token_hash=%s LIMIT 1", $hash
        ), ARRAY_A);
        if (!$row) return new WP_Error('apm_fill_invalid', 'Link de preenchimento inválido.', array('status' => 404));
        if (strtotime($row['fill_expires_at']) < current_time('timestamp')) return new WP_Error('apm_fill_expired', 'Este link expirou. Peça um novo à equipe.', array('status' => 410));
        if (in_array($row['status'], array('signed', 'awaiting_signature'), true)) return new WP_Error('apm_fill_done', 'Este formulário já foi enviado.', array('status' => 409));
        $tpl = self::anamnesis_template_for_audience($row['template_key'], 'client');
        if (!$tpl) return new WP_Error('apm_fill_template', 'Modelo de anamnese não reconhecido.', array('status' => 400));
        $data = !empty($row['structured_data']) ? json_decode($row['structured_data'], true) : array();
        if (!is_array($data)) $data = array();
        $client_keys = self::anamnesis_client_keys($row['template_key']);
        $client_data = array();
        foreach ($client_keys as $k) { if (array_key_exists($k, $data)) $client_data[$k] = $data[$k]; if (array_key_exists($k . '_detail', $data)) $client_data[$k . '_detail'] = $data[$k . '_detail']; }
        return array(
            'title' => $tpl['title'],
            'client_name' => $row['client_name'],
            'appointment' => $row['appointment_start'] ? array('start_at' => $row['appointment_start'], 'service_name' => $row['appointment_service']) : null,
            'template' => $tpl,
            'data' => $client_data,
        );
    }

    public static function submit_record_fill($token, $payload) {
        global $wpdb;
        $limited = self::rate_limit_public_action('record_fill_submit', 10, 600);
        if (is_wp_error($limited)) return $limited;
        $hash = hash('sha256', sanitize_text_field($token));
        $record = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table('records') . " WHERE fill_token_hash=%s LIMIT 1", $hash), ARRAY_A);
        if (!$record) return new WP_Error('apm_fill_invalid', 'Link de preenchimento inválido.', array('status' => 404));
        if (strtotime($record['fill_expires_at']) < current_time('timestamp')) return new WP_Error('apm_fill_expired', 'Este link expirou. Peça um novo à equipe.', array('status' => 410));
        if (in_array($record['status'], array('signed', 'awaiting_signature'), true)) return new WP_Error('apm_fill_done', 'Este formulário já foi enviado.', array('status' => 409));

        $client_keys = self::anamnesis_client_keys($record['template_key']);
        if (empty($payload['declaration_confirmed'])) return new WP_Error('apm_fill_declaration', 'Confirme a declaração antes de enviar.', array('status' => 400));

        $existing = !empty($record['structured_data']) ? json_decode($record['structured_data'], true) : array();
        if (!is_array($existing)) $existing = array();
        $incoming = is_array($payload) ? $payload : array();
        foreach ($client_keys as $k) {
            if (array_key_exists($k, $incoming)) $existing[$k] = self::sanitize_record_structured_value($incoming[$k]);
            $dk = $k . '_detail';
            if (array_key_exists($dk, $incoming)) $existing[$dk] = self::sanitize_record_structured_value($incoming[$dk]);
        }
        $existing['declaration_confirmed'] = 1;

        $client = $wpdb->get_row($wpdb->prepare("SELECT name FROM " . self::table('clients') . " WHERE id=%d", (int) $record['client_id']), ARRAY_A);
        $content = self::build_anamnesis_content($record['template_key'], $existing, $client ?: array());

        $save = self::save_record(array(
            'client_id' => $record['client_id'],
            'appointment_id' => $record['appointment_id'],
            'professional_id' => $record['professional_id'],
            'type' => 'anamnesis',
            'title' => $record['title'],
            'content' => $content,
            'structured_data' => $existing,
            'template_key' => $record['template_key'],
            'status' => 'draft',
        ), (int) $record['id']);
        if (is_wp_error($save)) return $save;

        $wpdb->update(self::table('records'), array('fill_token_hash' => '', 'fill_expires_at' => null), array('id' => (int) $record['id']));

        $signature = self::create_signature_request((int) $record['id']);
        if (is_wp_error($signature)) return $signature;
        return array('signature_url' => $signature['url']);
    }
}
