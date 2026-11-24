<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables; WP object cache does not apply.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from self::table() are internal constants, not user input; PHPCS cannot statically verify data flow.
if (!defined('ABSPATH')) exit;

class APM_REST {
    public static function init(){ add_action('rest_api_init',array(__CLASS__,'routes')); }
    public static function routes(){
        $ns='assis-prime/v1';
        register_rest_route($ns,'/bootstrap',array('methods'=>'GET','callback'=>array(__CLASS__,'bootstrap'),'permission_callback'=>array(__CLASS__,'can_access')));
        register_rest_route($ns,'/refresh-nonce',array('methods'=>'GET','callback'=>array(__CLASS__,'refresh_nonce'),'permission_callback'=>'is_user_logged_in'));
        foreach(array('clients','services','professionals','categories') as $resource){
            register_rest_route($ns,'/'.$resource,array(
                array('methods'=>'GET','callback'=>array(__CLASS__,'listing'),'permission_callback'=>array(__CLASS__,'can_resource_read'),'args'=>array('resource'=>array('default'=>$resource))),
                array('methods'=>'POST','callback'=>array(__CLASS__,'save'),'permission_callback'=>array(__CLASS__,'can_resource_manage'),'args'=>array('resource'=>array('default'=>$resource))),
            ));
            register_rest_route($ns,'/'.$resource.'/(?P<id>\d+)',array(
                array('methods'=>'POST','callback'=>array(__CLASS__,'save'),'permission_callback'=>array(__CLASS__,'can_resource_manage'),'args'=>array('resource'=>array('default'=>$resource))),
                array('methods'=>'DELETE','callback'=>array(__CLASS__,'delete'),'permission_callback'=>array(__CLASS__,'can_resource_manage'),'args'=>array('resource'=>array('default'=>$resource))),
            ));
        }

        register_rest_route($ns,'/services/(?P<id>\d+)/duplicate',array('methods'=>'POST','callback'=>array(__CLASS__,'duplicate_service'),'permission_callback'=>array(__CLASS__,'can_resource_manage'),'args'=>array('resource'=>array('default'=>'services'))));
        register_rest_route($ns,'/anamnesis-templates',array(
            array('methods'=>'GET','callback'=>array(__CLASS__,'list_anamnesis_templates'),'permission_callback'=>array(__CLASS__,'can_records')),
            array('methods'=>'POST','callback'=>array(__CLASS__,'save_anamnesis_template'),'permission_callback'=>array(__CLASS__,'can_records')),
        ));
        register_rest_route($ns,'/anamnesis-templates/(?P<id>\d+)',array(
            array('methods'=>'POST','callback'=>array(__CLASS__,'save_anamnesis_template'),'permission_callback'=>array(__CLASS__,'can_records')),
            array('methods'=>'DELETE','callback'=>array(__CLASS__,'delete_anamnesis_template'),'permission_callback'=>array(__CLASS__,'can_records')),
        ));
        register_rest_route($ns,'/clients/check-matches',array('methods'=>'GET','callback'=>array(__CLASS__,'check_client_matches'),'permission_callback'=>array(__CLASS__,'can_resource_manage'),'args'=>array('resource'=>array('default'=>'clients'))));
        register_rest_route($ns,'/clients/(?P<id>\d+)/appointments',array('methods'=>'GET','callback'=>array(__CLASS__,'client_appointments'),'permission_callback'=>array(__CLASS__,'can_access')));
        register_rest_route($ns,'/clients/(?P<id>\d+)/summary',array('methods'=>'GET','callback'=>array(__CLASS__,'client_summary'),'permission_callback'=>array(__CLASS__,'can_access')));
        register_rest_route($ns,'/agenda',array('methods'=>'GET','callback'=>array(__CLASS__,'agenda'),'permission_callback'=>array(__CLASS__,'can_access')));
        register_rest_route($ns,'/agenda/search',array('methods'=>'GET','callback'=>array(__CLASS__,'agenda_search'),'permission_callback'=>array(__CLASS__,'can_access')));
        register_rest_route($ns,'/appointments',array(
            array('methods'=>'POST','callback'=>array(__CLASS__,'save_appointment'),'permission_callback'=>array(__CLASS__,'can_manage_agenda')),
        ));
        register_rest_route($ns,'/appointments/(?P<id>\d+)',array(
            array('methods'=>'POST','callback'=>array(__CLASS__,'save_appointment'),'permission_callback'=>array(__CLASS__,'can_manage_agenda')),
            array('methods'=>'DELETE','callback'=>array(__CLASS__,'delete_appointment'),'permission_callback'=>array(__CLASS__,'can_manage_agenda')),
        ));
        register_rest_route($ns,'/appointments/(?P<id>\d+)/send-payment-link',array('methods'=>'POST','callback'=>array(__CLASS__,'send_appointment_payment_link'),'permission_callback'=>array(__CLASS__,'can_manage_agenda')));
        register_rest_route($ns,'/agenda/slots',array('methods'=>'GET','callback'=>array(__CLASS__,'agenda_slots'),'permission_callback'=>array(__CLASS__,'can_manage_agenda')));
        register_rest_route($ns,'/blocks',array(
            array('methods'=>'POST','callback'=>array(__CLASS__,'save_block'),'permission_callback'=>array(__CLASS__,'can_manage_agenda')),
        ));
        register_rest_route($ns,'/blocks/(?P<id>\d+)',array(
            array('methods'=>'DELETE','callback'=>array(__CLASS__,'delete_block'),'permission_callback'=>array(__CLASS__,'can_manage_agenda')),
        ));
        register_rest_route($ns,'/settings',array(
            array('methods'=>'GET','callback'=>array(__CLASS__,'settings'),'permission_callback'=>array(__CLASS__,'can_access')),
            array('methods'=>'POST','callback'=>array(__CLASS__,'settings'),'permission_callback'=>array(__CLASS__,'can_settings')),
        ));
        register_rest_route($ns,'/users',array(
            array('methods'=>'GET','callback'=>array(__CLASS__,'users'),'permission_callback'=>array(__CLASS__,'can_users')),
            array('methods'=>'POST','callback'=>array(__CLASS__,'create_user'),'permission_callback'=>array(__CLASS__,'can_users')),
        ));
        register_rest_route($ns,'/users/(?P<id>\d+)/access',array('methods'=>'POST','callback'=>array(__CLASS__,'save_user_access'),'permission_callback'=>array(__CLASS__,'can_users')));
        register_rest_route($ns,'/professionals/(?P<id>\d+)/create-access',array('methods'=>'POST','callback'=>array(__CLASS__,'create_professional_access'),'permission_callback'=>array(__CLASS__,'can_users')));
        register_rest_route($ns,'/leads',array(
            array('methods'=>'GET','callback'=>array(__CLASS__,'leads'),'permission_callback'=>array(__CLASS__,'can_crm')),
            array('methods'=>'POST','callback'=>array(__CLASS__,'save_lead'),'permission_callback'=>array(__CLASS__,'can_crm')),
        ));
        register_rest_route($ns,'/leads/(?P<id>\d+)',array(
            array('methods'=>'POST','callback'=>array(__CLASS__,'save_lead'),'permission_callback'=>array(__CLASS__,'can_crm')),
            array('methods'=>'DELETE','callback'=>array(__CLASS__,'delete_lead'),'permission_callback'=>array(__CLASS__,'can_crm')),
        ));
        register_rest_route($ns,'/leads/(?P<id>\d+)/interactions',array(
            array('methods'=>'GET','callback'=>array(__CLASS__,'lead_interactions'),'permission_callback'=>array(__CLASS__,'can_crm')),
            array('methods'=>'POST','callback'=>array(__CLASS__,'add_lead_interaction'),'permission_callback'=>array(__CLASS__,'can_crm')),
        ));
        register_rest_route($ns,'/leads/(?P<id>\d+)/convert',array('methods'=>'POST','callback'=>array(__CLASS__,'convert_lead'),'permission_callback'=>array(__CLASS__,'can_crm')));
        register_rest_route($ns,'/leads/(?P<id>\d+)/move',array('methods'=>'POST','callback'=>array(__CLASS__,'move_lead'),'permission_callback'=>array(__CLASS__,'can_crm')));
        register_rest_route($ns,'/leads/(?P<id>\d+)/matches',array('methods'=>'GET','callback'=>array(__CLASS__,'lead_matches'),'permission_callback'=>array(__CLASS__,'can_crm')));
        register_rest_route($ns,'/notifications',array('methods'=>'GET','callback'=>array(__CLASS__,'notifications'),'permission_callback'=>array(__CLASS__,'can_access')));
        register_rest_route($ns,'/notifications/read-all',array('methods'=>'POST','callback'=>array(__CLASS__,'notifications_read_all'),'permission_callback'=>array(__CLASS__,'can_access')));
        register_rest_route($ns,'/notifications/(?P<id>\d+)/read',array('methods'=>'POST','callback'=>array(__CLASS__,'notification_read'),'permission_callback'=>array(__CLASS__,'can_access')));
        register_rest_route($ns,'/notifications/(?P<id>\d+)/unread',array('methods'=>'POST','callback'=>array(__CLASS__,'notification_unread'),'permission_callback'=>array(__CLASS__,'can_access')));
        register_rest_route($ns,'/inventory',array(
            array('methods'=>'GET','callback'=>array(__CLASS__,'inventory'),'permission_callback'=>array(__CLASS__,'can_inventory')),
            array('methods'=>'POST','callback'=>array(__CLASS__,'save_inventory'),'permission_callback'=>array(__CLASS__,'can_inventory')),
        ));
        register_rest_route($ns,'/inventory/(?P<id>\d+)',array(
            array('methods'=>'POST','callback'=>array(__CLASS__,'save_inventory'),'permission_callback'=>array(__CLASS__,'can_inventory')),
            array('methods'=>'DELETE','callback'=>array(__CLASS__,'delete_inventory'),'permission_callback'=>array(__CLASS__,'can_inventory')),
        ));
        register_rest_route($ns,'/inventory/(?P<id>\d+)/movement',array('methods'=>'POST','callback'=>array(__CLASS__,'inventory_movement'),'permission_callback'=>array(__CLASS__,'can_inventory')));
        register_rest_route($ns,'/records',array(
            array('methods'=>'GET','callback'=>array(__CLASS__,'records'),'permission_callback'=>array(__CLASS__,'can_records')),
            array('methods'=>'POST','callback'=>array(__CLASS__,'save_record'),'permission_callback'=>array(__CLASS__,'can_records')),
        ));
        register_rest_route($ns,'/records/(?P<id>\d+)',array(
            array('methods'=>'POST','callback'=>array(__CLASS__,'save_record'),'permission_callback'=>array(__CLASS__,'can_records')),
            array('methods'=>'DELETE','callback'=>array(__CLASS__,'delete_record'),'permission_callback'=>array(__CLASS__,'can_records')),
        ));
        register_rest_route($ns,'/records/(?P<id>\d+)/upload',array('methods'=>'POST','callback'=>array(__CLASS__,'upload_record_file'),'permission_callback'=>array(__CLASS__,'can_records')));
        register_rest_route($ns,'/records/(?P<id>\d+)/signature',array('methods'=>'POST','callback'=>array(__CLASS__,'create_signature'),'permission_callback'=>array(__CLASS__,'can_records')));
        register_rest_route($ns,'/records/(?P<id>\d+)/fill-link',array('methods'=>'POST','callback'=>array(__CLASS__,'create_record_fill_link'),'permission_callback'=>array(__CLASS__,'can_records')));
        register_rest_route($ns,'/signature/(?P<token>[A-Za-z0-9]+)',array('methods'=>'GET','callback'=>array(__CLASS__,'public_signature'),'permission_callback'=>'__return_true'));
        register_rest_route($ns,'/signature/(?P<token>[A-Za-z0-9]+)/sign',array('methods'=>'POST','callback'=>array(__CLASS__,'sign_record'),'permission_callback'=>'__return_true'));
        register_rest_route($ns,'/record-fill/(?P<token>[A-Za-z0-9]+)',array(
            array('methods'=>'GET','callback'=>array(__CLASS__,'public_record_fill'),'permission_callback'=>'__return_true'),
            array('methods'=>'POST','callback'=>array(__CLASS__,'submit_record_fill'),'permission_callback'=>'__return_true'),
        ));


        register_rest_route($ns,'/finance',array(
            array('methods'=>'GET','callback'=>array(__CLASS__,'finance'),'permission_callback'=>array(__CLASS__,'can_finance')),
        ));
        register_rest_route($ns,'/finance/entries',array(
            array('methods'=>'POST','callback'=>array(__CLASS__,'save_finance_entry'),'permission_callback'=>array(__CLASS__,'can_finance')),
        ));
        register_rest_route($ns,'/finance/entries/(?P<id>\d+)',array(
            array('methods'=>'POST','callback'=>array(__CLASS__,'save_finance_entry'),'permission_callback'=>array(__CLASS__,'can_finance')),
            array('methods'=>'DELETE','callback'=>array(__CLASS__,'delete_finance_entry'),'permission_callback'=>array(__CLASS__,'can_finance')),
        ));
        register_rest_route($ns,'/finance/entries/(?P<id>\d+)/transactions',array(
            array('methods'=>'GET','callback'=>array(__CLASS__,'finance_transactions'),'permission_callback'=>array(__CLASS__,'can_finance')),
            array('methods'=>'POST','callback'=>array(__CLASS__,'save_finance_transaction'),'permission_callback'=>array(__CLASS__,'can_finance')),
        ));
        register_rest_route($ns,'/finance/entries/(?P<id>\d+)/transactions/(?P<tx_id>\d+)',array(
            array('methods'=>'DELETE','callback'=>array(__CLASS__,'delete_finance_transaction'),'permission_callback'=>array(__CLASS__,'can_finance')),
        ));
        register_rest_route($ns,'/finance/categories',array(
            array('methods'=>'POST','callback'=>array(__CLASS__,'save_finance_category'),'permission_callback'=>array(__CLASS__,'can_finance')),
        ));
        register_rest_route($ns,'/finance/categories/(?P<id>\d+)',array(
            array('methods'=>'POST','callback'=>array(__CLASS__,'save_finance_category'),'permission_callback'=>array(__CLASS__,'can_finance')),
            array('methods'=>'DELETE','callback'=>array(__CLASS__,'delete_finance_category'),'permission_callback'=>array(__CLASS__,'can_finance')),
        ));
        register_rest_route($ns,'/finance/payment-methods',array(
            array('methods'=>'POST','callback'=>array(__CLASS__,'save_finance_payment_method'),'permission_callback'=>array(__CLASS__,'can_finance')),
        ));
        register_rest_route($ns,'/finance/payment-methods/(?P<id>\d+)',array(
            array('methods'=>'POST','callback'=>array(__CLASS__,'save_finance_payment_method'),'permission_callback'=>array(__CLASS__,'can_finance')),
            array('methods'=>'DELETE','callback'=>array(__CLASS__,'delete_finance_payment_method'),'permission_callback'=>array(__CLASS__,'can_finance')),
        ));

        register_rest_route($ns,'/public-bootstrap',array('methods'=>'GET','callback'=>array(__CLASS__,'public_bootstrap'),'permission_callback'=>'__return_true'));
        register_rest_route($ns,'/public-slots',array('methods'=>'GET','callback'=>array(__CLASS__,'public_slots'),'permission_callback'=>'__return_true'));
        register_rest_route($ns,'/public-booking',array('methods'=>'POST','callback'=>array(__CLASS__,'public_booking'),'permission_callback'=>'__return_true'));
        register_rest_route($ns,'/booking-status/(?P<id>\d+)',array('methods'=>'GET','callback'=>array(__CLASS__,'booking_status'),'permission_callback'=>'__return_true'));
        register_rest_route($ns,'/payment-webhook/infinitepay',array('methods'=>'POST','callback'=>array(__CLASS__,'infinitepay_webhook'),'permission_callback'=>'__return_true'));
        register_rest_route($ns,'/whatsapp-webhook/(?P<token>[A-Za-z0-9]+)',array('methods'=>'POST','callback'=>array(__CLASS__,'whatsapp_webhook'),'permission_callback'=>'__return_true'));
        register_rest_route($ns,'/whatsapp/test-reminder',array('methods'=>'POST','callback'=>array(__CLASS__,'send_test_whatsapp_reminder'),'permission_callback'=>array(__CLASS__,'can_settings')));
        register_rest_route($ns,'/whatsapp/connection-status',array('methods'=>'GET','callback'=>array(__CLASS__,'whatsapp_connection_status'),'permission_callback'=>array(__CLASS__,'can_settings')));
        register_rest_route($ns,'/test-email',array('methods'=>'POST','callback'=>array(__CLASS__,'send_test_email'),'permission_callback'=>array(__CLASS__,'can_settings')));
        register_rest_route($ns,'/system-status',array('methods'=>'GET','callback'=>array(__CLASS__,'system_status'),'permission_callback'=>array(__CLASS__,'can_status')));
        register_rest_route($ns,'/signature/(?P<token>[A-Za-z0-9]+)/send-code',array('methods'=>'POST','callback'=>array(__CLASS__,'send_signature_otp'),'permission_callback'=>'__return_true'));
        register_rest_route($ns,'/signature/(?P<token>[A-Za-z0-9]+)/verify-code',array('methods'=>'POST','callback'=>array(__CLASS__,'verify_signature_otp'),'permission_callback'=>'__return_true'));

    }
    public static function can_access(){return current_user_can('manage_options')||APM_Repository::user_can_access_system();}
    public static function can_manage(){return self::can_access();}
    public static function can_users(){return current_user_can('manage_options')||current_user_can('apm_manage_users');}
    public static function can_settings(){return current_user_can('manage_options')||(current_user_can('apm_manage_settings')&&APM_Repository::user_can_module('settings'));}
    public static function can_status(){return current_user_can('manage_options')||(current_user_can('apm_manage_settings')&&APM_Repository::user_can_module('status'));}
    public static function can_manage_agenda(){return current_user_can('manage_options')||(current_user_can('apm_manage_agenda')&&APM_Repository::user_can_module('agenda'));}
    public static function can_crm(){return current_user_can('manage_options')||(current_user_can('apm_manage_crm')&&APM_Repository::user_can_module('crm'));}
    public static function can_records(){return current_user_can('manage_options')||(current_user_can('apm_manage_records')&&APM_Repository::user_can_module('records'));}
    public static function can_inventory(){return current_user_can('manage_options')||(current_user_can('apm_manage_inventory')&&APM_Repository::user_can_module('inventory'));}
    public static function can_finance(){if(current_user_can('manage_options'))return true;$configured=(bool)get_user_meta(get_current_user_id(),'apm_access_configured',true);return APM_Repository::user_can_module('finance')&&($configured||current_user_can('apm_manage_finance'));}
    public static function can_resource_read(WP_REST_Request $r){
        if(current_user_can('manage_options')) return true;
        $resource=sanitize_key($r->get_param('resource')??'');
        $module=$resource==='categories'?'services':$resource;
        if($module==='clients' || $module==='services' || $module==='professionals'){
            return APM_Repository::user_can_module($module)||APM_Repository::user_can_module('agenda');
        }
        return self::can_access();
    }
    public static function can_resource_manage(WP_REST_Request $r){
        if(current_user_can('manage_options')) return true;
        $resource=sanitize_key($r->get_param('resource')??'');
        $module=$resource==='categories'?'services':$resource;
        $cap=array('clients'=>'apm_manage_clients','services'=>'apm_manage_services','professionals'=>'apm_manage_professionals')[$module]??'';
        return $cap && current_user_can($cap) && APM_Repository::user_can_module($module);
    }

    // Credenciais de gateways de pagamento (e futuras integrações) nunca podem
    // ser devolvidas para quem não tem permissão de gerenciar Configurações.
    private static function redact_settings($settings){
        if(self::can_settings()){
            $settings['whatsapp_webhook_url']=APM_Repository::whatsapp_webhook_url();
            return $settings;
        }
        foreach(array('infinitepay_api_key','zapi_instance_token','zapi_client_token','zapi_webhook_token') as $key){
            if(!empty($settings[$key])) $settings[$key]='';
        }
        return $settings;
    }

    public static function refresh_nonce(){return rest_ensure_response(array('nonce'=>wp_create_nonce('wp_rest')));}
    public static function bootstrap(){
        return rest_ensure_response(array(
            'dashboard'=>APM_Repository::dashboard(),
            'clients'=>APM_Repository::list_clients(),
            'services'=>APM_Repository::list_services(),
            'categories'=>APM_Repository::list_categories(),
            'professionals'=>APM_Repository::list_professionals(),
            'leads'=>self::can_crm()?APM_Repository::list_leads():array(),
            'records'=>self::can_records()?APM_Repository::list_records():array(),
            'anamnesisTemplates'=>self::can_records()?APM_Repository::anamnesis_templates():array(),
            'anamnesisTemplateOptions'=>APM_Repository::list_anamnesis_template_options(),
            'inventoryItems'=>self::can_inventory()?APM_Repository::list_inventory_items():array(),
            'inventorySummary'=>self::can_inventory()?APM_Repository::inventory_summary():array(),
            'settings'=>self::redact_settings(APM_Repository::get_settings()),
            'userAccess'=>APM_Repository::get_user_access(),
            'moduleCatalog'=>APM_Repository::module_catalog(),
            'users'=>self::can_users()?APM_Repository::list_system_users():array(),
            'canManageUsers'=>self::can_users()?1:0,
            'notifications'=>APM_Repository::list_notifications(8),
            'unreadNotifications'=>APM_Repository::unread_notifications_count(),
        ));
    }

    public static function finance(WP_REST_Request $r){
        return rest_ensure_response(APM_Repository::finance_payload(array(
            'type'=>sanitize_key($r->get_param('type')??''),
            'status'=>sanitize_key($r->get_param('status')??''),
            'start'=>sanitize_text_field($r->get_param('start')??''),
            'end'=>sanitize_text_field($r->get_param('end')??''),
            'search'=>sanitize_text_field($r->get_param('search')??''),
        )));
    }
    public static function save_finance_entry(WP_REST_Request $r){$out=APM_Repository::save_finance_entry($r->get_json_params(),(int)$r->get_param('id'));if(is_wp_error($out))return $out;return rest_ensure_response(array('success'=>true,'id'=>$out));}
    public static function delete_finance_entry(WP_REST_Request $r){$out=APM_Repository::delete_finance_entry((int)$r->get_param('id'));if(is_wp_error($out))return $out;return rest_ensure_response(array('success'=>(bool)$out));}
    public static function finance_transactions(WP_REST_Request $r){return rest_ensure_response(array('items'=>APM_Repository::finance_transactions((int)$r->get_param('id'))));}
    public static function save_finance_transaction(WP_REST_Request $r){$out=APM_Repository::save_finance_transaction((int)$r->get_param('id'),$r->get_json_params());if(is_wp_error($out))return $out;return rest_ensure_response(array('success'=>true,'id'=>$out));}
    public static function delete_finance_transaction(WP_REST_Request $r){$out=APM_Repository::delete_finance_transaction((int)$r->get_param('id'),(int)$r->get_param('tx_id'));if(is_wp_error($out))return $out;return rest_ensure_response(array('success'=>(bool)$out));}
    public static function save_finance_category(WP_REST_Request $r){$out=APM_Repository::save_finance_category($r->get_json_params(),(int)$r->get_param('id'));if(is_wp_error($out))return $out;return rest_ensure_response(array('success'=>true,'id'=>$out));}
    public static function delete_finance_category(WP_REST_Request $r){$out=APM_Repository::delete_finance_category((int)$r->get_param('id'));if(is_wp_error($out))return $out;return rest_ensure_response(array('success'=>(bool)$out));}
    public static function save_finance_payment_method(WP_REST_Request $r){$out=APM_Repository::save_finance_payment_method($r->get_json_params(),(int)$r->get_param('id'));if(is_wp_error($out))return $out;return rest_ensure_response(array('success'=>true,'id'=>$out));}
    public static function delete_finance_payment_method(WP_REST_Request $r){$out=APM_Repository::delete_finance_payment_method((int)$r->get_param('id'));if(is_wp_error($out))return $out;return rest_ensure_response(array('success'=>(bool)$out));}

    public static function public_bootstrap(){
        $services=array_values(array_filter(APM_Repository::list_services(),function($x){return !empty($x['active']);}));
        $professionals=array_values(array_filter(APM_Repository::list_professionals(),function($x){return !empty($x['active']);}));
        foreach($professionals as &$p){unset($p['email'],$p['phone'],$p['wp_user_id']);}
        $settings=APM_Repository::get_settings();
        $public_settings=array(
            'spa_name'=>$settings['spa_name']??'Assis Prime',
            'default_deposit'=>(float)($settings['default_deposit']??30),
            'booking_interval'=>(int)($settings['booking_interval']??30),
            'payment_enabled'=>!empty($settings['payment_enabled'])?1:0,
            'payment_test_mode'=>!empty($settings['payment_test_mode'])?1:0,
            'payment_gateway'=>$settings['payment_gateway']??'infinitepay',
            'payment_hold_minutes'=>(int)($settings['payment_hold_minutes']??20),
            'default_service_id'=>(int)($settings['default_service_id']??0),
            'cancellation_policy_text'=>$settings['cancellation_policy_text']??'',
            // Sem isso, a página pública não sabe quais dias da semana estão fechados e
            // deixava toda data selecionável (ou, depois da v0.9.72, nenhuma — como "fechado"
            // virou o padrão de segurança quando a informação não vem, a lista de dias ficava
            // vazia e travava o agendamento inteiro). Não tem nada sensível aqui, só horário.
            'opening_hours'=>$settings['opening_hours']??array(),
        );
        return rest_ensure_response(array('services'=>$services,'professionals'=>$professionals,'settings'=>$public_settings));
    }
    public static function listing(WP_REST_Request $r){
        $res=$r->get_param('resource');
        if($res==='clients') return rest_ensure_response(APM_Repository::list_clients(sanitize_text_field($r->get_param('search')??'')));
        if($res==='services') return rest_ensure_response(APM_Repository::list_services());
        if($res==='professionals') return rest_ensure_response(APM_Repository::list_professionals());
        if($res==='categories') return rest_ensure_response(APM_Repository::list_categories());
        return new WP_Error('apm_resource','Recurso inválido.',array('status'=>404));
    }
    public static function save(WP_REST_Request $r){
        $res=$r->get_param('resource');$id=(int)$r->get_param('id');$data=$r->get_json_params();
        if($res==='clients')$out=APM_Repository::save_client($data,$id);
        elseif($res==='services')$out=APM_Repository::save_service($data,$id);
        elseif($res==='professionals')$out=APM_Repository::save_professional($data,$id);
        elseif($res==='categories')$out=APM_Repository::save_category($data,$id);
        else return new WP_Error('apm_resource','Recurso inválido.',array('status'=>404));
        if(is_wp_error($out)) return $out;
        return rest_ensure_response(array('success'=>true,'id'=>$out));
    }
    public static function delete(WP_REST_Request $r){
        $res=$r->get_param('resource');$id=(int)$r->get_param('id');
        if($res==='clients')$out=APM_Repository::delete_client($id);
        elseif($res==='services')$out=APM_Repository::delete_service($id);
        elseif($res==='professionals')$out=APM_Repository::delete_professional($id);
        elseif($res==='categories')$out=APM_Repository::delete_category($id);
        else return new WP_Error('apm_resource','Recurso inválido.',array('status'=>404));
        if(is_wp_error($out)) return $out;
        return rest_ensure_response(array('success'=>(bool)$out));
    }

    public static function duplicate_service(WP_REST_Request $r){
        $out=APM_Repository::duplicate_service((int)$r->get_param('id'));
        if(is_wp_error($out)) return $out;
        return rest_ensure_response(array('success'=>true,'id'=>$out));
    }

    public static function list_anamnesis_templates(){return rest_ensure_response(APM_Repository::list_anamnesis_templates());}
    public static function save_anamnesis_template(WP_REST_Request $r){
        $out=APM_Repository::save_anamnesis_template($r->get_json_params(),(int)$r->get_param('id'));
        if(is_wp_error($out)) return $out;
        return rest_ensure_response(array('success'=>true,'id'=>$out));
    }
    public static function delete_anamnesis_template(WP_REST_Request $r){
        $out=APM_Repository::delete_anamnesis_template((int)$r->get_param('id'));
        if(is_wp_error($out)) return $out;
        return rest_ensure_response(array('success'=>(bool)$out));
    }

    public static function client_appointments(WP_REST_Request $r){
        if(!current_user_can('manage_options')&&!APM_Repository::user_can_module('clients')) return new WP_Error('rest_forbidden','Sem permissão para acessar o histórico.',array('status'=>403));
        return rest_ensure_response(APM_Repository::list_client_appointments((int)$r->get_param('id')));
    }

    public static function client_summary(WP_REST_Request $r){
        if(!current_user_can('manage_options')&&!APM_Repository::user_can_module('clients')) return new WP_Error('rest_forbidden','Sem permissão para acessar o cliente.',array('status'=>403));
        $out=APM_Repository::client_summary((int)$r->get_param('id'));
        if(is_wp_error($out)) return $out;
        return rest_ensure_response($out);
    }

    public static function agenda(WP_REST_Request $r){
        $start=sanitize_text_field($r->get_param('start')??'');
        $end=sanitize_text_field($r->get_param('end')??'');
        $professional_id=(int)($r->get_param('professional_id')??0);
        return rest_ensure_response(array(
            'appointments'=>APM_Repository::list_appointments($start,$end,$professional_id),
            'blocks'=>APM_Repository::list_blocks($start,$end,$professional_id),
        ));
    }
    public static function agenda_search(WP_REST_Request $r){
        $q=sanitize_text_field($r->get_param('q')??'');
        return rest_ensure_response(array('appointments'=>APM_Repository::search_appointments($q,40)));
    }

    public static function save_appointment(WP_REST_Request $r){
        $id=(int)$r->get_param('id');
        $out=APM_Repository::save_appointment($r->get_json_params(),$id);
        if(is_wp_error($out)) return $out;
        return rest_ensure_response(array('success'=>true,'id'=>$out));
    }
    public static function delete_appointment(WP_REST_Request $r){
        return rest_ensure_response(array('success'=>(bool)APM_Repository::delete_appointment((int)$r->get_param('id'))));
    }
    public static function send_appointment_payment_link(WP_REST_Request $r){
        $out=APM_Repository::send_payment_link_for_appointment((int)$r->get_param('id'));
        if(is_wp_error($out)) return $out;
        return rest_ensure_response($out);
    }
    public static function save_block(WP_REST_Request $r){
        $out=APM_Repository::save_block($r->get_json_params(),0);
        if(is_wp_error($out)) return $out;
        return rest_ensure_response(array('success'=>true,'id'=>$out));
    }
    public static function delete_block(WP_REST_Request $r){
        return rest_ensure_response(array('success'=>(bool)APM_Repository::delete_block((int)$r->get_param('id'))));
    }

    public static function users(WP_REST_Request $r){
        return rest_ensure_response(array(
            'users'=>APM_Repository::list_system_users(),
            'moduleCatalog'=>APM_Repository::module_catalog(),
        ));
    }
    public static function create_user(WP_REST_Request $r){
        $out = APM_Repository::create_system_user($r->get_json_params());
        if (is_wp_error($out)) return $out;
        return rest_ensure_response(array('success'=>true,'user'=>$out));
    }
    public static function save_user_access(WP_REST_Request $r){
        $out=APM_Repository::save_user_access((int)$r->get_param('id'),$r->get_json_params());
        if(is_wp_error($out)) return $out;
        return rest_ensure_response(array('success'=>true,'access'=>$out));
    }
    public static function create_professional_access(WP_REST_Request $r){
        $out=APM_Repository::create_access_for_professional((int)$r->get_param('id'),(array)$r->get_json_params());
        if(is_wp_error($out)) return $out;
        return rest_ensure_response(array('success'=>true,'user'=>$out,'users'=>APM_Repository::list_system_users(),'professionals'=>APM_Repository::list_professionals()));
    }


    public static function public_slots(WP_REST_Request $r){
        $out=APM_Repository::public_slots((int)$r->get_param('service_id'),(int)$r->get_param('professional_id'),sanitize_text_field($r->get_param('date')??''));
        if(is_wp_error($out)) return $out;
        return rest_ensure_response(array('slots'=>$out));
    }
    // Mesma função do agendamento público, mas autenticada e aceitando exclude_appointment_id
    // — usada pelo modal de Agendamento do painel para mostrar os horários livres do
    // profissional/serviço/data escolhidos, em vez de um campo de hora em branco.
    public static function agenda_slots(WP_REST_Request $r){
        $out=APM_Repository::public_slots((int)$r->get_param('service_id'),(int)$r->get_param('professional_id'),sanitize_text_field($r->get_param('date')??''),(int)$r->get_param('exclude_appointment_id'));
        if(is_wp_error($out)) return $out;
        return rest_ensure_response(array('slots'=>$out));
    }
    public static function public_booking(WP_REST_Request $r){
        $out=APM_Repository::create_public_booking($r->get_json_params());
        if(is_wp_error($out)) return $out;
        return rest_ensure_response($out);
    }
    public static function booking_status(WP_REST_Request $r){
        $out=APM_Repository::booking_status((int)$r->get_param('id'));
        if(is_wp_error($out)) return $out;
        return rest_ensure_response($out);
    }
    public static function infinitepay_webhook(WP_REST_Request $r){
        $out=APM_Repository::handle_infinitepay_webhook((array)$r->get_json_params());
        if(is_wp_error($out)) return $out;
        return rest_ensure_response($out);
    }
    public static function whatsapp_webhook(WP_REST_Request $r){
        $out=APM_Repository::handle_whatsapp_webhook((array)$r->get_json_params(),$r->get_param('token'));
        if(is_wp_error($out)) return $out;
        return rest_ensure_response($out);
    }
    public static function system_status(){return rest_ensure_response(APM_Repository::system_status());}
    public static function send_test_whatsapp_reminder(){
        $out=APM_Repository::send_test_whatsapp_reminder();
        if(is_wp_error($out)) return $out;
        return rest_ensure_response(array_merge(array('success'=>true),$out));
    }
    public static function whatsapp_connection_status(){return rest_ensure_response(APM_Repository::zapi_connection_status());}
    public static function send_test_email(){
        $out=APM_Repository::send_test_email();
        if(is_wp_error($out)) return $out;
        // Sempre responde com HTTP 200 (sucesso ou falha dentro do campo "success"): uma falha
        // de SMTP é um resultado esperado desse diagnóstico, não um erro do servidor — e alguns
        // provedores/CDNs interferem ou mascaram o corpo de respostas com status de erro (5xx),
        // o que impedia a mensagem explicando o problema de chegar até a tela.
        return rest_ensure_response(array_merge(array('success'=>true),$out));
    }
    public static function create_record_fill_link(WP_REST_Request $r){$out=APM_Repository::create_record_fill_link((int)$r->get_param('id'),$r->get_json_params());if(is_wp_error($out))return $out;return rest_ensure_response($out);}
    public static function public_record_fill(WP_REST_Request $r){$out=APM_Repository::public_record_fill($r->get_param('token'));if(is_wp_error($out))return $out;return rest_ensure_response($out);}
    public static function submit_record_fill(WP_REST_Request $r){$out=APM_Repository::submit_record_fill($r->get_param('token'),(array)$r->get_json_params());if(is_wp_error($out))return $out;return rest_ensure_response($out);}
    public static function send_signature_otp(WP_REST_Request $r){
        $out=APM_Repository::create_signature_otp($r->get_param('token'));
        if(is_wp_error($out)) return $out;
        return rest_ensure_response($out);
    }
    public static function verify_signature_otp(WP_REST_Request $r){
        $params=$r->get_json_params();
        $out=APM_Repository::verify_signature_otp($r->get_param('token'),$params['code']??'');
        if(is_wp_error($out)) return $out;
        return rest_ensure_response($out);
    }


    public static function leads(){return rest_ensure_response(APM_Repository::list_leads());}
    public static function save_lead(WP_REST_Request $r){$out=APM_Repository::save_lead($r->get_json_params(),(int)$r->get_param('id'));if(is_wp_error($out))return $out;return rest_ensure_response(array('success'=>true,'id'=>$out));}
    public static function delete_lead(WP_REST_Request $r){return rest_ensure_response(array('success'=>(bool)APM_Repository::delete_lead((int)$r->get_param('id'))));}
    public static function lead_interactions(WP_REST_Request $r){return rest_ensure_response(APM_Repository::lead_interactions((int)$r->get_param('id')));}
    public static function add_lead_interaction(WP_REST_Request $r){$out=APM_Repository::add_lead_interaction((int)$r->get_param('id'),$r->get_json_params());if(is_wp_error($out))return $out;return rest_ensure_response(array('success'=>true,'id'=>$out));}
    public static function convert_lead(WP_REST_Request $r){$out=APM_Repository::convert_lead((int)$r->get_param('id'),(array)$r->get_json_params());if(is_wp_error($out))return $out;return rest_ensure_response($out);}
    public static function move_lead(WP_REST_Request $r){$out=APM_Repository::move_lead((int)$r->get_param('id'),$r->get_param('status')?:(($r->get_json_params()['status']??'')));if(is_wp_error($out))return $out;return rest_ensure_response($out);}
    public static function lead_matches(WP_REST_Request $r){$out=APM_Repository::lead_client_matches((int)$r->get_param('id'));if(is_wp_error($out))return $out;return rest_ensure_response($out);}
    public static function check_client_matches(WP_REST_Request $r){
        $cpf=sanitize_text_field($r->get_param('cpf')??'');
        $phone=sanitize_text_field($r->get_param('phone')??'');
        $email=sanitize_email($r->get_param('email')??'');
        return rest_ensure_response(APM_Repository::client_match_candidates($cpf,$phone,$email));
    }
    public static function notifications(WP_REST_Request $r){
        return rest_ensure_response(APM_Repository::notifications_page(array(
            'page'=>(int)($r->get_param('page')??1),
            'per_page'=>(int)($r->get_param('per_page')??20),
            'status'=>sanitize_key($r->get_param('status')??'all'),
            'type'=>sanitize_key($r->get_param('type')??''),
            'search'=>sanitize_text_field($r->get_param('search')??''),
        )));
    }
    public static function notification_read(WP_REST_Request $r){return rest_ensure_response(array('success'=>APM_Repository::mark_notification_read((int)$r->get_param('id'))));}
    public static function notification_unread(WP_REST_Request $r){return rest_ensure_response(array('success'=>APM_Repository::mark_notification_unread((int)$r->get_param('id'))));}
    public static function notifications_read_all(){return rest_ensure_response(array('success'=>APM_Repository::mark_all_notifications_read()));}
    public static function inventory(WP_REST_Request $r){return rest_ensure_response(array('items'=>APM_Repository::list_inventory_items(),'movements'=>APM_Repository::inventory_movements_page((int)($r->get_param('movement_page')??1),(int)($r->get_param('movement_per_page')??30)),'summary'=>APM_Repository::inventory_summary(),'units'=>APM_Repository::inventory_units()));}
    public static function save_inventory(WP_REST_Request $r){$out=APM_Repository::save_inventory_item($r->get_json_params(),(int)$r->get_param('id'));if(is_wp_error($out))return $out;return rest_ensure_response(array('success'=>true,'id'=>$out));}
    public static function delete_inventory(WP_REST_Request $r){$out=APM_Repository::delete_inventory_item((int)$r->get_param('id'));if(is_wp_error($out))return $out;return rest_ensure_response(array('success'=>(bool)$out));}
    public static function inventory_movement(WP_REST_Request $r){$out=APM_Repository::inventory_movement((int)$r->get_param('id'),$r->get_json_params());if(is_wp_error($out))return $out;return rest_ensure_response($out);}
    public static function records(WP_REST_Request $r){return rest_ensure_response(APM_Repository::list_records((int)($r->get_param('client_id')??0)));}
    public static function save_record(WP_REST_Request $r){$out=APM_Repository::save_record($r->get_json_params(),(int)$r->get_param('id'));if(is_wp_error($out))return $out;return rest_ensure_response(array('success'=>true,'id'=>$out));}
    public static function delete_record(WP_REST_Request $r){$out=APM_Repository::delete_record((int)$r->get_param('id'));if(is_wp_error($out))return $out;return rest_ensure_response(array('success'=>(bool)$out));}
    public static function upload_record_file(WP_REST_Request $r){
        $id=(int)$r->get_param('id');
        $access=APM_Repository::ensure_record_access($id); if(is_wp_error($access)) return $access;
        // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- REST endpoint; auth via permission_callback. File validated by wp_check_filetype_and_ext() below.
        if(empty($_FILES['file'])) return new WP_Error('apm_upload','Selecione um arquivo.',array('status'=>400));
        require_once ABSPATH.'wp-admin/includes/file.php';
        require_once ABSPATH.'wp-admin/includes/media.php';
        require_once ABSPATH.'wp-admin/includes/image.php';
        $file=$_FILES['file'];
        // phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if((int)($file['size']??0)>8*1024*1024) return new WP_Error('apm_upload_size','O arquivo deve ter no máximo 8 MB.',array('status'=>400));
        // O Content-Type enviado pelo navegador é fornecido pelo próprio cliente e pode ser
        // falsificado; wp_check_filetype_and_ext() inspeciona a extensão e, quando possível,
        // o conteúdo real do arquivo (fileinfo) antes de aceitá-lo.
        $allowed_mimes=array('jpg|jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','pdf'=>'application/pdf');
        $allowed_types=array('image/jpeg','image/png','image/webp','application/pdf');
        $check=wp_check_filetype_and_ext($file['tmp_name']??'',$file['name']??'',$allowed_mimes);
        if(empty($check['ext'])||empty($check['type'])||!in_array($check['type'],$allowed_types,true)) return new WP_Error('apm_upload_type','Envie JPG, PNG, WEBP ou PDF.',array('status'=>400));
        $attachment_id=media_handle_upload('file',0);
        if(is_wp_error($attachment_id)) return $attachment_id;
        $url=wp_get_attachment_url($attachment_id);
        global $wpdb; $table=$wpdb->prefix.'apm_records';
        $current=$wpdb->get_var($wpdb->prepare("SELECT attachments FROM $table WHERE id=%d",$id));
        if($current===null){wp_delete_attachment($attachment_id,true);return new WP_Error('apm_record_not_found','Prontuário não encontrado.',array('status'=>404));}
        $line=esc_url_raw($url); $updated=trim((string)$current); $updated.=($updated?"\n":'').$line;
        $wpdb->update($table,array('attachments'=>$updated,'updated_at'=>current_time('mysql')),array('id'=>$id));
        return rest_ensure_response(array('success'=>true,'attachment_id'=>(int)$attachment_id,'url'=>$url));
    }

    public static function create_signature(WP_REST_Request $r){$out=APM_Repository::create_signature_request((int)$r->get_param('id'),$r->get_json_params());if(is_wp_error($out))return $out;return rest_ensure_response($out);}
    public static function public_signature(WP_REST_Request $r){$out=APM_Repository::public_signature($r->get_param('token'));if(is_wp_error($out))return $out;return rest_ensure_response($out);}
    public static function sign_record(WP_REST_Request $r){$out=APM_Repository::sign_record($r->get_param('token'),$r->get_json_params(),$_SERVER);if(is_wp_error($out))return $out;return rest_ensure_response($out);}

    public static function settings(WP_REST_Request $r){
        if($r->get_method()==='POST') return rest_ensure_response(APM_Repository::save_settings($r->get_json_params()));
        return rest_ensure_response(self::redact_settings(APM_Repository::get_settings()));
    }
}
