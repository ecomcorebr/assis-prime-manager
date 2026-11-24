<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables; WP object cache does not apply.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from self::table() are internal constants, not user input; PHPCS cannot statically verify data flow.
if (!defined('ABSPATH')) exit;

class APM_Installer {
    const DB_VERSION = '0.9.71';

    public static function activate() {
        self::create_tables();
        self::create_roles();
        self::set_defaults();
        update_option('apm_db_version', self::DB_VERSION);
        self::ensure_whatsapp_cron();
        flush_rewrite_rules();
    }

    public static function deactivate() {
        wp_clear_scheduled_hook('apm_whatsapp_dispatch');
        flush_rewrite_rules();
    }

    public static function maybe_upgrade() {
        if (get_option('apm_db_version') !== self::DB_VERSION) {
            self::create_tables();
            self::create_roles();
            self::set_defaults();
            update_option('apm_db_version', self::DB_VERSION);
        }
        self::ensure_whatsapp_cron();
    }

    // Garante que o disparo horário de lembrete/confirmação por WhatsApp está agendado,
    // mesmo que o evento tenha sido perdido (ex.: reativação do plugin, migração de host).
    public static function ensure_whatsapp_cron() {
        if (!wp_next_scheduled('apm_whatsapp_dispatch')) {
            wp_schedule_event(time() + 300, 'hourly', 'apm_whatsapp_dispatch');
        }
    }

    private static function create_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $p = $wpdb->prefix . 'apm_';

        $sql = array();
        $sql[] = "CREATE TABLE {$p}clients (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(190) NOT NULL,
            cpf VARCHAR(20) DEFAULT '',
            birth_date DATE NULL,
            gender VARCHAR(20) DEFAULT '',
            phone VARCHAR(40) DEFAULT '',
            email VARCHAR(190) DEFAULT '',
            address VARCHAR(255) DEFAULT '',
            city VARCHAR(120) DEFAULT '',
            state VARCHAR(10) DEFAULT '',
            zip_code VARCHAR(20) DEFAULT '',
            source VARCHAR(120) DEFAULT '',
            notes LONGTEXT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY name (name),
            KEY phone (phone),
            KEY email (email),
            KEY cpf (cpf)
        ) $charset;";

        $sql[] = "CREATE TABLE {$p}professionals (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            wp_user_id BIGINT UNSIGNED NULL,
            name VARCHAR(190) NOT NULL,
            email VARCHAR(190) DEFAULT '',
            phone VARCHAR(40) DEFAULT '',
            specialty VARCHAR(190) DEFAULT '',
            color VARCHAR(20) DEFAULT '#8f001f',
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY active (active),
            KEY wp_user_id (wp_user_id)
        ) $charset;";

        $sql[] = "CREATE TABLE {$p}service_categories (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(190) NOT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY name (name)
        ) $charset;";

        $sql[] = "CREATE TABLE {$p}services (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            category_id BIGINT UNSIGNED NULL,
            name VARCHAR(190) NOT NULL,
            badge_text VARCHAR(40) DEFAULT '',
            badge_color VARCHAR(10) DEFAULT '#8f001f',
            description TEXT NULL,
            duration_minutes INT UNSIGNED NOT NULL DEFAULT 60,
            buffer_minutes INT UNSIGNED NOT NULL DEFAULT 0,
            price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            compare_at_price DECIMAL(12,2) NULL DEFAULT NULL,
            anamnesis_template_id BIGINT UNSIGNED NULL DEFAULT NULL,
            deposit_type VARCHAR(20) NOT NULL DEFAULT 'percentage',
            deposit_value DECIMAL(12,2) NOT NULL DEFAULT 30.00,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY category_id (category_id),
            KEY active (active),
            KEY name (name)
        ) $charset;";

        $sql[] = "CREATE TABLE {$p}professional_services (
            professional_id BIGINT UNSIGNED NOT NULL,
            service_id BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (professional_id, service_id),
            KEY service_id (service_id)
        ) $charset;";

        $sql[] = "CREATE TABLE {$p}availability (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            professional_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            weekday TINYINT UNSIGNED NOT NULL,
            start_time TIME NOT NULL,
            end_time TIME NOT NULL,
            interval_minutes INT UNSIGNED NOT NULL DEFAULT 30,
            active TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            KEY professional_weekday (professional_id, weekday)
        ) $charset;";

        $sql[] = "CREATE TABLE {$p}appointments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id BIGINT UNSIGNED NOT NULL,
            professional_id BIGINT UNSIGNED NOT NULL,
            service_id BIGINT UNSIGNED NOT NULL,
            start_at DATETIME NOT NULL,
            end_at DATETIME NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'scheduled',
            source VARCHAR(30) NOT NULL DEFAULT 'internal',
            notes TEXT NULL,
            total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            deposit_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            payment_status VARCHAR(30) NOT NULL DEFAULT 'pending',
            whatsapp_reminder_sent_at DATETIME NULL,
            whatsapp_confirmation_sent_at DATETIME NULL,
            email_confirmation_sent_at DATETIME NULL,
            policy_accepted_at DATETIME NULL,
            policy_text_snapshot LONGTEXT NULL,
            policy_ip_address VARCHAR(80) DEFAULT '',
            policy_user_agent TEXT NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY client_id (client_id),
            KEY professional_id (professional_id),
            KEY service_id (service_id),
            KEY start_at (start_at),
            KEY status (status)
        ) $charset;";


        $sql[] = "CREATE TABLE {$p}payments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            appointment_id BIGINT UNSIGNED NOT NULL,
            provider VARCHAR(30) NOT NULL DEFAULT 'mercadopago',
            external_reference VARCHAR(190) DEFAULT '',
            provider_payment_id VARCHAR(190) DEFAULT '',
            provider_preference_id VARCHAR(190) DEFAULT '',
            amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            payment_method VARCHAR(80) DEFAULT '',
            payment_type VARCHAR(80) DEFAULT '',
            status_detail VARCHAR(120) DEFAULT '',
            fee_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            net_received_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            approved_at DATETIME NULL,
            checkout_url TEXT NULL,
            raw_data LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY appointment_id (appointment_id),
            KEY external_reference (external_reference),
            KEY provider_payment_id (provider_payment_id),
            KEY status (status)
        ) $charset;";

        $sql[] = "CREATE TABLE {$p}crm_leads (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id BIGINT UNSIGNED NULL,
            name VARCHAR(190) NOT NULL,
            phone VARCHAR(40) DEFAULT '',
            email VARCHAR(190) DEFAULT '',
            cpf VARCHAR(20) DEFAULT '',
            source VARCHAR(120) DEFAULT '',
            status VARCHAR(30) NOT NULL DEFAULT 'new',
            interest VARCHAR(190) DEFAULT '',
            assigned_to BIGINT UNSIGNED NULL,
            next_action_at DATETIME NULL,
            notes LONGTEXT NULL,
            converted_at DATETIME NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY status (status),
            KEY client_id (client_id),
            KEY next_action_at (next_action_at)
        ) $charset;";

        $sql[] = "CREATE TABLE {$p}crm_interactions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            lead_id BIGINT UNSIGNED NULL,
            client_id BIGINT UNSIGNED NULL,
            type VARCHAR(30) NOT NULL DEFAULT 'note',
            content LONGTEXT NOT NULL,
            next_action_at DATETIME NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY lead_id (lead_id),
            KEY client_id (client_id)
        ) $charset;";

        $sql[] = "CREATE TABLE {$p}notifications (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            type VARCHAR(60) NOT NULL DEFAULT 'info',
            title VARCHAR(190) NOT NULL,
            message TEXT NULL,
            entity_type VARCHAR(60) DEFAULT '',
            entity_id BIGINT UNSIGNED NULL,
            action_url TEXT NULL,
            metadata LONGTEXT NULL,
            read_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY read_at (read_at),
            KEY created_at (created_at),
            KEY type (type),
            KEY user_read_created (user_id, read_at, created_at),
            KEY user_type_created (user_id, type, created_at)
        ) $charset;";

        $sql[] = "CREATE TABLE {$p}records (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id BIGINT UNSIGNED NOT NULL,
            appointment_id BIGINT UNSIGNED NULL,
            professional_id BIGINT UNSIGNED NULL,
            type VARCHAR(30) NOT NULL DEFAULT 'evolution',
            title VARCHAR(190) NOT NULL,
            content LONGTEXT NULL,
            structured_data LONGTEXT NULL,
            template_key VARCHAR(80) DEFAULT '',
            version_no SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            parent_record_id BIGINT UNSIGNED NULL,
            attachments LONGTEXT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'draft',
            document_hash VARCHAR(64) DEFAULT '',
            fill_token_hash VARCHAR(64) DEFAULT '',
            fill_expires_at DATETIME NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY client_id (client_id),
            KEY appointment_id (appointment_id),
            KEY professional_id (professional_id),
            KEY status (status),
            KEY template_key (template_key),
            KEY parent_record_id (parent_record_id),
            KEY fill_token_hash (fill_token_hash)
        ) $charset;";

        // Modelos de anamnese cadastráveis: cada serviço pode ser vinculado a um modelo
        // (ou a nenhum). custom_steps guarda só as etapas específicas do procedimento — a
        // etapa de Identificação (sempre a 1ª) e a etapa final com a declaração/controle de
        // sessões são sempre injetadas automaticamente pelo sistema ao redor dessas etapas,
        // então o modelo cadastrado aqui nunca precisa recriar esse "ao redor".
        $sql[] = "CREATE TABLE {$p}anamnesis_templates (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            template_key VARCHAR(80) NOT NULL,
            title VARCHAR(190) NOT NULL,
            custom_steps LONGTEXT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY template_key (template_key)
        ) $charset;";

        $sql[] = "CREATE TABLE {$p}signature_requests (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            record_id BIGINT UNSIGNED NOT NULL,
            client_id BIGINT UNSIGNED NOT NULL,
            token_hash VARCHAR(64) NOT NULL,
            token_last4 VARCHAR(4) DEFAULT '',
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            expires_at DATETIME NOT NULL,
            signer_name VARCHAR(190) DEFAULT '',
            signer_cpf VARCHAR(20) DEFAULT '',
            signer_phone VARCHAR(40) DEFAULT '',
            otp_code_hash VARCHAR(64) DEFAULT '',
            otp_expires_at DATETIME NULL,
            otp_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            otp_sent_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
            otp_verified_at DATETIME NULL,
            signed_at DATETIME NULL,
            ip_address VARCHAR(80) DEFAULT '',
            user_agent TEXT NULL,
            document_hash VARCHAR(64) DEFAULT '',
            document_snapshot LONGTEXT NULL,
            audit_data LONGTEXT NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY token_hash (token_hash),
            KEY record_id (record_id),
            KEY client_id (client_id),
            KEY status (status)
        ) $charset;";

        $sql[] = "CREATE TABLE {$p}schedule_blocks (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            professional_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            start_at DATETIME NOT NULL,
            end_at DATETIME NOT NULL,
            reason VARCHAR(255) DEFAULT '',
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY professional_id (professional_id),
            KEY start_at (start_at)
        ) $charset;";


        $sql[] = "CREATE TABLE {$p}inventory_items (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(190) NOT NULL,
            unit VARCHAR(30) NOT NULL DEFAULT 'un',
            current_quantity DECIMAL(14,3) NOT NULL DEFAULT 0.000,
            minimum_quantity DECIMAL(14,3) NOT NULL DEFAULT 0.000,
            supplier VARCHAR(190) DEFAULT '',
            lot VARCHAR(100) DEFAULT '',
            expires_on DATE NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY name (name),
            KEY active (active),
            KEY expires_on (expires_on)
        ) $charset;";

        $sql[] = "CREATE TABLE {$p}service_inventory (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            service_id BIGINT UNSIGNED NOT NULL,
            inventory_item_id BIGINT UNSIGNED NOT NULL,
            quantity DECIMAL(14,3) NOT NULL DEFAULT 0.000,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY service_item (service_id, inventory_item_id),
            KEY inventory_item_id (inventory_item_id)
        ) $charset;";

        $sql[] = "CREATE TABLE {$p}inventory_movements (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            inventory_item_id BIGINT UNSIGNED NOT NULL,
            appointment_id BIGINT UNSIGNED NULL,
            service_id BIGINT UNSIGNED NULL,
            professional_id BIGINT UNSIGNED NULL,
            client_id BIGINT UNSIGNED NULL,
            movement_type VARCHAR(40) NOT NULL,
            quantity DECIMAL(14,3) NOT NULL DEFAULT 0.000,
            balance_after DECIMAL(14,3) NOT NULL DEFAULT 0.000,
            note VARCHAR(255) DEFAULT '',
            event_key VARCHAR(190) DEFAULT NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY event_key (event_key),
            KEY inventory_item_id (inventory_item_id),
            KEY appointment_id (appointment_id),
            KEY created_at (created_at),
            KEY movement_type (movement_type)
        ) $charset;";


        $sql[] = "CREATE TABLE {$p}finance_categories (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(190) NOT NULL,
            type VARCHAR(20) NOT NULL DEFAULT 'income',
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY type (type),
            KEY active (active),
            KEY name (name)
        ) $charset;";

        $sql[] = "CREATE TABLE {$p}finance_payment_methods (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(120) NOT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY active (active),
            KEY name (name)
        ) $charset;";

        $sql[] = "CREATE TABLE {$p}finance_entries (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            type VARCHAR(20) NOT NULL DEFAULT 'receivable',
            description VARCHAR(255) NOT NULL,
            category_id BIGINT UNSIGNED NULL,
            payment_method_id BIGINT UNSIGNED NULL,
            client_id BIGINT UNSIGNED NULL,
            service_id BIGINT UNSIGNED NULL,
            appointment_id BIGINT UNSIGNED NULL,
            base_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            surcharge_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            due_date DATE NOT NULL,
            paid_at DATE NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            installment_group VARCHAR(64) NULL,
            installment_number SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            installment_count SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            recurrence_group VARCHAR(64) NULL,
            recurrence_frequency VARCHAR(20) NULL,
            recurrence_number SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            recurrence_count SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            notes TEXT NULL,
            source VARCHAR(40) DEFAULT 'manual',
            source_key VARCHAR(190) NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY source_key (source_key),
            KEY source (source),
            KEY type (type),
            KEY category_id (category_id),
            KEY payment_method_id (payment_method_id),
            KEY client_id (client_id),
            KEY service_id (service_id),
            KEY appointment_id (appointment_id),
            KEY installment_group (installment_group),
            KEY recurrence_group (recurrence_group),
            KEY recurrence_frequency (recurrence_frequency),
            KEY due_date (due_date),
            KEY status (status),
            KEY type_status_due (type, status, due_date)
        ) $charset;";

        $sql[] = "CREATE TABLE {$p}finance_transactions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            entry_id BIGINT UNSIGNED NOT NULL,
            kind VARCHAR(20) NOT NULL DEFAULT 'receipt',
            amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            payment_method_id BIGINT UNSIGNED NULL,
            transaction_date DATE NOT NULL,
            notes TEXT NULL,
            source VARCHAR(40) DEFAULT 'manual',
            source_key VARCHAR(190) NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY source_key (source_key),
            KEY source (source),
            KEY entry_id (entry_id),
            KEY kind (kind),
            KEY payment_method_id (payment_method_id),
            KEY transaction_date (transaction_date)
        ) $charset;";

        foreach ($sql as $query) {
            dbDelta($query);
        }
        self::seed_anamnesis_templates();
    }

    // Antes desta versão, o único modelo de anamnese ("Limpeza de Pele") era um array fixo
    // no código, usado por TODO agendamento sem distinção de serviço. Agora os modelos ficam
    // no banco e cada serviço escolhe qual (ou nenhum) usar — então o modelo antigo precisa
    // continuar existindo como uma linha de verdade (mesma template_key 'facial_cleaning',
    // para que anamneses já preenchidas antes continuem abrindo normalmente), mas SEM vínculo
    // automático com nenhum serviço: a equipe decide, em cada serviço, se quer usar esse
    // modelo (ou outro) a partir de agora.
    private static function seed_anamnesis_templates() {
        global $wpdb;
        $table = $wpdb->prefix . 'apm_anamnesis_templates';
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE template_key=%s", 'facial_cleaning'));
        if ($exists) return;
        $custom_steps = array(
            array(
                'label' => 'Saúde e rotina',
                'intro' => 'Responda com atenção — essas informações ajudam a equipe a cuidar de você com segurança.',
                'audience' => 'both',
                'fields' => array_map(function ($f) {
                    return array('key' => $f[0], 'type' => 'yesno', 'label' => $f[1], 'detailLabel' => $f[2] ?? '');
                }, array(
                    array('sun_exposure', 'Se expõe ao sol com frequência?'),
                    array('high_glycemic_food', 'Consome alimentos de alto índice glicêmico?'),
                    array('regular_bowel', 'Funcionamento intestinal regular?'),
                    array('previous_facial_treatment', 'Tratamento facial / estético anterior?', 'Qual?'),
                    array('water_intake', 'Ingere água com frequência?'),
                    array('alcohol', 'Ingere bebida alcoólica?'),
                    array('sunscreen', 'Utiliza filtro solar?'),
                    array('menstrual_period', 'Está no período menstrual?'),
                    array('sleep_quality', 'Boa qualidade do sono?'),
                    array('roaccutane', 'Já utilizou Roacutan?', 'Especifique'),
                    array('prosthesis', 'Possui prótese corporal/facial?', 'Especifique'),
                    array('smoking', 'Tabagismo?'),
                    array('cardiac_changes', 'Alterações cardíacas?', 'Especifique'),
                    array('pacemaker', 'Portador de marcapasso?'),
                    array('pregnant_breastfeeding', 'Gestante ou amamentando?', 'Semanas'),
                    array('acids_peels', 'Ácido, peeling químico, cremes ou loções faciais?', 'Quais?'),
                    array('physical_activity', 'Pratica atividade física?', 'Especifique'),
                    array('medication', 'Utiliza algum medicamento?', 'Qual?'),
                    array('allergy', 'Possui algum tipo de alergia?', 'Especifique'),
                    array('ocular_disorder', 'Distúrbio ocular?', 'Especifique'),
                    array('skin_problems', 'Problemas de pele?', 'Especifique'),
                    array('skin_cancer_family', 'Casos de câncer de pele na família?'),
                )),
            ),
            array(
                'label' => 'Avaliação profissional',
                'intro' => 'Campo preenchido pela equipe durante o atendimento.',
                'audience' => 'professional',
                'fields' => array_merge(
                    array_map(function ($f) {
                        return array('key' => $f[0], 'type' => 'yesno', 'label' => $f[1]);
                    }, array(
                        array('comedones', 'Comedões / cravos'), array('pustules', 'Pústulas / espinhas'), array('nodules', 'Nódulos / espinha interna'), array('millium', 'Millium'),
                        array('open_ostia', 'Óstios abertos'), array('hypertrophic_scar', 'Cicatriz hipertrófica'), array('keloid_scar', 'Cicatriz queloideana'),
                        array('melasma', 'Melasma'), array('cloasma', 'Cloasma'), array('hpi', 'H.P.I.'), array('ephelides', 'Efélides'), array('solar_melanosis', 'Melanose solar / mancha de sol'),
                        array('hypochromia_lesion', 'Hipocromia advinda de lesão'), array('radiation_exposure', 'Hipocromia por exposição à radiação'), array('hypochromia_other', 'Outras hipocromias'),
                        array('wrinkles_superficial', 'Rugas superficiais'), array('wrinkles_medium', 'Rugas médias'), array('wrinkles_deep', 'Rugas profundas'),
                    )),
                    array(
                        array('key' => 'skin_biotype', 'type' => 'select', 'label' => 'Biotipo de pele', 'options' => array(array('', 'Selecione'), array('dry', 'Alípica / seca'), array('oily', 'Lipídica / oleosa'), array('normal', 'Normal'), array('mixed', 'Pele mista'))),
                        array('key' => 'skin_thickness', 'type' => 'select', 'label' => 'Espessura', 'options' => array(array('', 'Selecione'), array('thick', 'Espessa'), array('thin', 'Fina'), array('very_thin', 'Muito fina'), array('coarse', 'Grossa'))),
                        array('key' => 'phototype', 'type' => 'select', 'label' => 'Fototipo', 'options' => array(array('', 'Selecione'), array('1', '1'), array('2', '2'), array('3', '3'), array('4', '4'), array('5', '5'), array('6', '6'))),
                        array('key' => 'aging_degree', 'type' => 'select', 'label' => 'Grau de envelhecimento', 'options' => array(array('', 'Selecione'), array('mild', 'Leve'), array('moderate', 'Moderado'), array('advanced', 'Avançado'), array('severe', 'Severo'))),
                        array('key' => 'acne_degree', 'type' => 'select', 'label' => 'Acne — grau', 'options' => array(array('', 'Sem classificação'), array('1', '1'), array('2', '2'), array('3', '3'), array('4', '4'))),
                    )
                ),
            ),
            array(
                'label' => 'Avaliação específica',
                'intro' => 'Conclusão e plano de cuidados.',
                'audience' => 'both',
                'fields' => array(
                    array('key' => 'main_complaint', 'type' => 'textarea', 'label' => 'Queixa principal'),
                    array('key' => 'daily_care', 'type' => 'textarea', 'label' => 'Cuidados diários do cliente'),
                    array('key' => 'suggested_treatment', 'type' => 'textarea', 'label' => 'Tratamento sugerido', 'audience' => 'professional'),
                ),
            ),
        );
        $now = current_time('mysql');
        $wpdb->insert($table, array(
            'template_key' => 'facial_cleaning',
            'title' => 'Anamnese — Limpeza de Pele',
            'custom_steps' => wp_json_encode($custom_steps),
            'active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ));
    }

    private static function create_roles() {
        // Prontuário é dado de saúde sensível (LGPD, art. 5º, II): a capacidade de
        // gerenciar prontuários não faz parte do conjunto padrão de papéis operacionais
        // (recepção). Só Gestor/Administrador têm essa capacidade por padrão; o
        // profissional a recebe à parte, abaixo, pois é o dono natural do prontuário.
        $caps = array(
            'read' => true,
            'apm_access' => true,
            'apm_manage_clients' => true,
            'apm_manage_services' => true,
            'apm_manage_professionals' => true,
            'apm_manage_agenda' => true,
            'apm_manage_crm' => true,
            'apm_manage_inventory' => true,
        );

        add_role('apm_manager', 'Assis Prime - Gestor', array_merge($caps, array('apm_manage_settings' => true, 'apm_manage_users' => true, 'apm_manage_records' => true)));
        add_role('apm_reception', 'Assis Prime - Recepção', $caps);
        add_role('apm_professional', 'Assis Prime - Profissional', array('read' => true, 'apm_access' => true, 'apm_manage_clients' => true, 'apm_manage_records' => true));
        add_role('apm_commercial', 'Assis Prime - Comercial', array('read' => true, 'apm_access' => true, 'apm_manage_clients' => true, 'apm_manage_crm' => true));

        // Atualiza capacidades também para papéis já existentes em instalações anteriores.
        $manager = get_role('apm_manager');
        if ($manager) {
            foreach (array('read','apm_access','apm_manage_clients','apm_manage_services','apm_manage_professionals','apm_manage_agenda','apm_manage_crm','apm_manage_inventory','apm_manage_records','apm_manage_settings','apm_manage_users','apm_manage_finance') as $cap) $manager->add_cap($cap);
        }
        $reception = get_role('apm_reception');
        if ($reception) {
            foreach (array('read','apm_access','apm_manage_clients','apm_manage_services','apm_manage_professionals','apm_manage_agenda','apm_manage_crm','apm_manage_inventory') as $cap) $reception->add_cap($cap);
            // Corrige instalações anteriores (0.9.14 e antes), que concediam esta
            // capacidade à recepção por padrão.
            $reception->remove_cap('apm_manage_records');
        }
        $professional = get_role('apm_professional'); if ($professional) $professional->add_cap('apm_manage_records');
        $commercial = get_role('apm_commercial'); if ($commercial) $commercial->add_cap('apm_manage_crm');

        $admin = get_role('administrator');
        if ($admin) {
            foreach (array('apm_access','apm_manage_clients','apm_manage_services','apm_manage_professionals','apm_manage_agenda','apm_manage_crm','apm_manage_records','apm_manage_inventory','apm_manage_finance','apm_manage_settings','apm_manage_users') as $cap) {
                $admin->add_cap($cap);
            }
        }
    }

    private static function set_defaults() {
        $defaults = array(
            'spa_name' => 'Assis Prime',
            'phone' => '',
            'email' => get_option('admin_email'),
            'currency' => 'BRL',
            'default_deposit' => 30,
            'booking_interval' => 30,
            'timezone' => wp_timezone_string(),
            'payment_gateway' => 'infinitepay',
            'payment_enabled' => 0,
            'payment_test_mode' => 1,
            'infinitepay_handle' => '',
            'infinitepay_api_key' => '',
            'payment_hold_minutes' => 20,
            'cancellation_policy_text' => "Na {spa}, seguimos uma política de reserva que garante mais segurança e organização para nossos clientes e para nossa equipe. 💞\n\nPara confirmar seu horário, solicitamos o pagamento antecipado de {valor_sinal}.\n\nEm caso de cancelamento ou remarcação com, no mínimo, 72 horas de antecedência, considerando o horário comercial, o valor poderá ser devolvido integralmente ou utilizado como crédito para um novo agendamento, conforme sua preferência.\n\nPara cancelamentos ou remarcações com menos de 72 horas de antecedência, o valor da reserva não será devolvido, pois o horário é reservado exclusivamente para você e envolve organização da agenda, planejamento do atendimento e custos operacionais.\n\nEssa política nos permite oferecer um atendimento de qualidade, respeitando o tempo de nossos clientes e profissionais.\n\nAgradecemos pela compreensão e pela confiança em nosso trabalho.",
            'whatsapp_enabled' => 0,
            'zapi_instance_id' => '',
            'zapi_instance_token' => '',
            'zapi_client_token' => '',
            'zapi_webhook_token' => wp_generate_password(32, false, false),
            'whatsapp_otp_enabled' => 0,
            'whatsapp_reminder_enabled' => 1,
            'whatsapp_reminder_hours_before' => 24,
            'whatsapp_confirmation_enabled' => 1,
            'whatsapp_template_confirmation' => class_exists('APM_Repository') ? APM_Repository::default_whatsapp_confirmation_template() : '',
            'whatsapp_template_payment_link' => "Olá, {nome}! Para confirmar seu horário na {spa}, finalize o pagamento do sinal de {valor}:\n\n{link}\n\nAté breve! 💕",
            'whatsapp_template_reminder' => "Olá, {nome}! Passando para lembrar do seu horário na {spa}:\n\n• Procedimento: {procedimento}\n• Profissional: {profissional}\n• Data: {data} às {horario}\n\nCaso precise remarcar, entre em contato com a gente. Até breve!",
            'whatsapp_template_otp' => "Seu código de confirmação para assinar o documento na {spa} é: {codigo}\n\nVálido por 10 minutos. Não compartilhe este código.",
            'opening_hours' => array(
                '1' => array('active'=>1,'start'=>'09:00','end'=>'19:00'),
                '2' => array('active'=>1,'start'=>'09:00','end'=>'19:00'),
                '3' => array('active'=>1,'start'=>'09:00','end'=>'19:00'),
                '4' => array('active'=>1,'start'=>'09:00','end'=>'19:00'),
                '5' => array('active'=>1,'start'=>'09:00','end'=>'19:00'),
                '6' => array('active'=>1,'start'=>'09:00','end'=>'12:00'),
                '0' => array('active'=>0,'start'=>'09:00','end'=>'12:00'),
            ),
        );
        $existing = get_option('apm_settings');
        if (!is_array($existing) || !$existing) {
            add_option('apm_settings', $defaults, '', false);
        } else {
            $merged = array_replace_recursive($defaults, $existing);
            // O Mercado Pago foi substituído pelo InfinitePay: instalações antigas que ainda
            // apontavam para o gateway descontinuado são migradas para o novo, evitando que
            // o pagamento online fique "configurado" para um gateway que o código não suporta mais.
            if (($merged['payment_gateway'] ?? '') === 'mercadopago') {
                $merged['payment_gateway'] = 'infinitepay';
            }
            unset($merged['mercadopago_access_token'], $merged['mercadopago_webhook_secret']);
            update_option('apm_settings', $merged, false);
        }
    }
}
