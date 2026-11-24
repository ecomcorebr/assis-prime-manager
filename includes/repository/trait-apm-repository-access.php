<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables; WP object cache does not apply.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from self::table() are internal constants, not user input; PHPCS cannot statically verify data flow.
if (!defined('ABSPATH')) exit;

trait APM_Repository_Access_Trait {
    public static function module_catalog() {
        return array(
            'dashboard'     => 'Visão geral',
            'agenda'        => 'Agenda',
            'crm'           => 'CRM e Leads',
            'clients'       => 'Clientes',
            'records'       => 'Prontuários',
            'inventory'     => 'Estoque',
            'notifications' => 'Notificações',
            'finance'       => 'Financeiro',
            'services'      => 'Serviços',
            'professionals' => 'Profissionais',
            'settings'      => 'Configurações',
            'status'        => 'Status do sistema',
        );
    }

    public static function get_user_access($user_id=0) {
        $user_id = $user_id ? (int) $user_id : get_current_user_id();
        $user = get_userdata($user_id);
        if (!$user) return array('enabled'=>0,'redirect'=>0,'modules'=>array());

        $catalog = self::module_catalog();
        $all_modules = array_keys($catalog);
        $is_admin = user_can($user, 'manage_options');
        $configured = (bool) get_user_meta($user_id, 'apm_access_configured', true);

        if ($is_admin) {
            $modules = get_user_meta($user_id, 'apm_allowed_modules', true);
            if (!$configured || !is_array($modules) || empty($modules)) $modules = $all_modules;
            $modules = array_values(array_intersect($all_modules, array_map('sanitize_key', $modules)));
            foreach(array('inventory','notifications','finance') as $new_module){if(in_array($new_module,$all_modules,true)&&!in_array($new_module,$modules,true))$modules[]=$new_module;}
            if (!in_array('dashboard', $modules, true)) array_unshift($modules, 'dashboard');
            return array(
                'enabled' => 1,
                'redirect' => (int) get_user_meta($user_id, 'apm_redirect_to_panel', true),
                'modules' => $modules,
                'is_admin' => 1,
                'configured' => $configured ? 1 : 0,
            );
        }

        if ($configured) {
            $enabled = (int) get_user_meta($user_id, 'apm_access_enabled', true);
            $modules = get_user_meta($user_id, 'apm_allowed_modules', true);
            if (!is_array($modules)) $modules = array();
            $modules = array_values(array_intersect($all_modules, array_map('sanitize_key', $modules)));
            if($enabled && in_array('notifications',$all_modules,true) && !in_array('notifications',$modules,true)) $modules[]='notifications';
        } else {
            $enabled = user_can($user, 'apm_access') ? 1 : 0;
            $modules = $enabled ? $all_modules : array();
        }

        if ($enabled && !in_array('dashboard', $modules, true)) array_unshift($modules, 'dashboard');

        return array(
            'enabled' => $enabled,
            'redirect' => (int) get_user_meta($user_id, 'apm_redirect_to_panel', true),
            'modules' => $modules,
            'is_admin' => 0,
            'configured' => $configured ? 1 : 0,
        );
    }

    public static function user_can_access_system($user_id=0) {
        $access = self::get_user_access($user_id);
        return !empty($access['enabled']);
    }

    public static function user_can_module($module, $user_id=0) {
        $access = self::get_user_access($user_id);
        return !empty($access['enabled']) && in_array(sanitize_key($module), $access['modules'], true);
    }

    public static function list_system_users() {
        $users = get_users(array('orderby'=>'display_name','order'=>'ASC'));
        $out = array();
        foreach ($users as $user) {
            $access = self::get_user_access($user->ID);
            $role_names = array();
            foreach ((array) $user->roles as $role_key) {
                $role = wp_roles()->roles[$role_key]['name'] ?? $role_key;
                $role_names[] = translate_user_role($role);
            }
            $out[] = array(
                'id' => (int) $user->ID,
                'name' => $user->display_name ?: $user->user_login,
                'login' => $user->user_login,
                'email' => $user->user_email,
                'roles' => $role_names,
                'access' => $access,
            );
        }
        return $out;
    }

    public static function save_user_access($user_id, $data) {
        $user_id = (int) $user_id;
        $user = get_userdata($user_id);
        if (!$user) return new WP_Error('apm_user_not_found','Usuário não encontrado.',array('status'=>404));

        $catalog = self::module_catalog();
        $all_modules = array_keys($catalog);
        $is_admin = user_can($user, 'manage_options');
        $redirect = empty($data['redirect']) ? 0 : 1;

        if ($is_admin) {
            $modules = isset($data['modules']) && is_array($data['modules']) ? array_map('sanitize_key', $data['modules']) : $all_modules;
            $modules = array_values(array_intersect($all_modules, $modules));
            if (!in_array('dashboard',$modules,true)) array_unshift($modules,'dashboard');
            update_user_meta($user_id, 'apm_access_configured', 1);
            update_user_meta($user_id, 'apm_redirect_to_panel', $redirect);
            update_user_meta($user_id, 'apm_allowed_modules', $modules);
            update_user_meta($user_id, 'apm_access_enabled', 1);
            return self::get_user_access($user_id);
        }

        $enabled = empty($data['enabled']) ? 0 : 1;
        $modules = isset($data['modules']) && is_array($data['modules']) ? array_map('sanitize_key', $data['modules']) : array();
        $modules = array_values(array_intersect($all_modules, $modules));
        if ($enabled && !in_array('dashboard',$modules,true)) array_unshift($modules,'dashboard');

        update_user_meta($user_id, 'apm_access_configured', 1);
        update_user_meta($user_id, 'apm_access_enabled', $enabled);
        update_user_meta($user_id, 'apm_redirect_to_panel', $enabled ? $redirect : 0);
        update_user_meta($user_id, 'apm_allowed_modules', $enabled ? $modules : array());

        if ($enabled) $user->add_cap('apm_access');
        else $user->remove_cap('apm_access');

        $module_caps=array(
            'agenda'=>'apm_manage_agenda',
            'clients'=>'apm_manage_clients',
            'services'=>'apm_manage_services',
            'professionals'=>'apm_manage_professionals',
            'settings'=>'apm_manage_settings',
            'crm'=>'apm_manage_crm',
            'records'=>'apm_manage_records',
            'inventory'=>'apm_manage_inventory',
            'finance'=>'apm_manage_finance',
        );
        foreach($module_caps as $module=>$cap){
            if($enabled && in_array($module,$modules,true)) $user->add_cap($cap);
            else $user->remove_cap($cap);
        }

        return self::get_user_access($user_id);
    }

    public static function create_system_user($data) {
        $name = sanitize_text_field($data['name'] ?? '');
        $email = sanitize_email($data['email'] ?? '');
        $login = sanitize_user($data['login'] ?? '', true);
        $password = (string) ($data['password'] ?? '');
        $role = sanitize_key($data['role'] ?? 'apm_reception');

        if (!$name) return new WP_Error('apm_user_name','Informe o nome do usuário.',array('status'=>400));
        if (!$email || !is_email($email)) return new WP_Error('apm_user_email','Informe um e-mail válido.',array('status'=>400));
        if (email_exists($email)) return new WP_Error('apm_user_exists','Já existe um usuário com este e-mail.',array('status'=>400));
        if (!$login) {
            $base = sanitize_user(current(explode('@',$email)), true);
            if (!$base) $base = sanitize_user(remove_accents(strtolower(str_replace(' ','-',$name))), true);
            $login = $base ?: 'usuario';
        }
        $candidate = $login; $i = 1;
        while (username_exists($candidate)) { $candidate = $login . $i; $i++; }
        $login = $candidate;
        if (!$password) $password = wp_generate_password(12, true, true);

        $roles = wp_roles()->roles;
        if (!isset($roles[$role])) $role = 'apm_reception';

        $user_id = wp_insert_user(array(
            'user_login'   => $login,
            'user_pass'    => $password,
            'user_email'   => $email,
            'display_name' => $name,
            'role'         => $role,
        ));
        if (is_wp_error($user_id)) return $user_id;

        $access_data = array(
            'enabled'  => empty($data['enabled']) ? 0 : 1,
            'redirect' => empty($data['redirect']) ? 0 : 1,
            'modules'  => isset($data['modules']) && is_array($data['modules']) ? $data['modules'] : array('dashboard'),
        );
        self::save_user_access((int) $user_id, $access_data);

        return array(
            'id'       => (int) $user_id,
            'login'    => $login,
            'password' => $password,
        );
    }

    public static function create_access_for_professional($professional_id, $data = array()) {
        global $wpdb;
        $professional_id = (int) $professional_id;
        $professional = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table('professionals') . " WHERE id=%d", $professional_id), ARRAY_A);
        if (!$professional) return new WP_Error('apm_professional_not_found', 'Profissional não encontrado.', array('status' => 404));
        if (!empty($professional['wp_user_id'])) return new WP_Error('apm_professional_has_access', 'Este profissional já possui um acesso vinculado.', array('status' => 400));

        $email = sanitize_email($data['email'] ?? $professional['email'] ?? '');
        if (!$email || !is_email($email)) return new WP_Error('apm_user_email', 'Informe um e-mail válido para criar o acesso deste profissional.', array('status' => 400));

        $out = self::create_system_user(array(
            'name'     => $professional['name'],
            'email'    => $email,
            'role'     => 'apm_professional',
            'enabled'  => 1,
            'redirect' => 1,
            'modules'  => array('dashboard', 'agenda', 'clients', 'records', 'notifications'),
        ));
        if (is_wp_error($out)) return $out;

        $wpdb->update(self::table('professionals'), array('wp_user_id' => $out['id'], 'updated_at' => current_time('mysql')), array('id' => $professional_id));

        return $out;
    }
}
