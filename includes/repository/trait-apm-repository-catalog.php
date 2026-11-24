<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables; WP object cache does not apply.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from self::table() are internal constants, not user input; PHPCS cannot statically verify data flow.
if (!defined('ABSPATH')) exit;

trait APM_Repository_Catalog_Trait {
    public static function dashboard() {
        global $wpdb;
        $clients = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . self::table('clients') . " WHERE status='active'");
        $professionals = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . self::table('professionals') . " WHERE active=1");
        $services = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . self::table('services') . " WHERE active=1");
        $latest = $wpdb->get_results("SELECT id,name,phone,email,source,created_at FROM " . self::table('clients') . " ORDER BY id DESC LIMIT 5", ARRAY_A);
        $today = current_time('Y-m-d');
        $month_start = current_time('Y-m-01');
        $appointments_today = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . self::table('appointments') . " WHERE start_at >= %s AND start_at < %s AND status NOT IN ('cancelled','no_show')", $today.' 00:00:00', gmdate('Y-m-d',strtotime($today.' +1 day')).' 00:00:00'));
        $clients_new_month = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . self::table('clients') . " WHERE created_at >= %s", $month_start.' 00:00:00'));
        $services_new_month = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . self::table('services') . " WHERE created_at >= %s", $month_start.' 00:00:00'));
        $professionals_new_month = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . self::table('professionals') . " WHERE created_at >= %s", $month_start.' 00:00:00'));
        $today_appointments = $wpdb->get_results($wpdb->prepare(
            "SELECT a.id,a.start_at,a.status,c.name client_name,s.name service_name,p.name professional_name
             FROM " . self::table('appointments') . " a
             LEFT JOIN " . self::table('clients') . " c ON c.id=a.client_id
             LEFT JOIN " . self::table('services') . " s ON s.id=a.service_id
             LEFT JOIN " . self::table('professionals') . " p ON p.id=a.professional_id
             WHERE a.start_at >= %s AND a.start_at < %s AND a.status NOT IN ('cancelled','no_show')
             ORDER BY a.start_at ASC LIMIT 8",
            $today.' 00:00:00', gmdate('Y-m-d',strtotime($today.' +1 day')).' 00:00:00'
        ), ARRAY_A);
        return compact('clients','professionals','services','latest','appointments_today','clients_new_month','services_new_month','professionals_new_month','today_appointments');
    }

    public static function list_clients($search='') {
        global $wpdb;
        $table = self::table('clients');
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            return $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE name LIKE %s OR phone LIKE %s OR email LIKE %s OR cpf LIKE %s ORDER BY name ASC LIMIT 250", $like,$like,$like,$like), ARRAY_A);
        }
        return $wpdb->get_results("SELECT * FROM $table ORDER BY name ASC LIMIT 250", ARRAY_A);
    }

    public static function client_summary($client_id) {
        global $wpdb;
        $client_id=(int)$client_id;
        if(!$client_id) return new WP_Error('apm_client','Cliente inválido.',array('status'=>400));
        $client=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".self::table('clients')." WHERE id=%d",$client_id),ARRAY_A);
        if(!$client) return new WP_Error('apm_not_found','Cliente não encontrado.',array('status'=>404));

        $a=self::table('appointments'); $s=self::table('services'); $p=self::table('professionals');
        $appointments=$wpdb->get_results($wpdb->prepare(
            "SELECT a.id,a.start_at,a.end_at,a.status,a.source,a.total_amount,a.deposit_amount,a.payment_status,s.name service_name,p.name professional_name
             FROM $a a LEFT JOIN $s s ON s.id=a.service_id LEFT JOIN $p p ON p.id=a.professional_id
             WHERE a.client_id=%d ORDER BY a.start_at DESC LIMIT 120",$client_id),ARRAY_A);

        $completed=array_values(array_filter($appointments,function($x){return ($x['status']??'')==='completed';}));
        $completed_count=count($completed);
        $total_spent=0.0; foreach($completed as $x)$total_spent+=(float)($x['total_amount']??0);
        $average_ticket=$completed_count?round($total_spent/$completed_count,2):0.0;
        $cancelled_count=count(array_filter($appointments,function($x){return ($x['status']??'')==='cancelled';}));
        $no_show_count=count(array_filter($appointments,function($x){return ($x['status']??'')==='no_show';}));
        $attendance_den=$completed_count+$no_show_count;
        $attendance_rate=$attendance_den?round(($completed_count/$attendance_den)*100,1):null;

        $now=current_time('timestamp'); $last_appointment=null; $next_appointment=null; $next_service='';
        foreach($appointments as $x){$ts=strtotime($x['start_at']);if(($x['status']??'')==='completed' && !$last_appointment)$last_appointment=$x['start_at'];if($ts>=$now&&!in_array(($x['status']??''),array('cancelled','no_show','payment_expired'),true)){if(!$next_appointment||$ts<strtotime($next_appointment)){$next_appointment=$x['start_at'];$next_service=$x['service_name']??'';}}}
        $days_since_last=$last_appointment!==null?max(0,(int)floor(($now-strtotime($last_appointment))/DAY_IN_SECONDS)):null;

        $average_frequency_days=null;
        if($completed_count>=2){$dates=array_map(function($x){return strtotime($x['start_at']);},$completed);sort($dates);$diffs=array();for($i=1;$i<count($dates);$i++){$d=round(($dates[$i]-$dates[$i-1])/DAY_IN_SECONDS);if($d>0)$diffs[]=$d;}if($diffs)$average_frequency_days=(int)round(array_sum($diffs)/count($diffs));}

        $fav=$wpdb->get_row($wpdb->prepare(
            "SELECT s.name,COUNT(*) count FROM $a a LEFT JOIN $s s ON s.id=a.service_id WHERE a.client_id=%d AND a.status='completed' GROUP BY a.service_id,s.name ORDER BY count DESC,s.name ASC LIMIT 1",$client_id),ARRAY_A);

        $relationship='new';
        if($completed_count>=3 && $days_since_last!==null && $days_since_last<=60)$relationship='recurring';
        elseif($days_since_last!==null && $days_since_last<=90)$relationship='active';
        elseif($days_since_last!==null && $days_since_last<=180)$relationship='risk';
        elseif($days_since_last!==null && $days_since_last>180)$relationship='inactive';
        elseif($next_appointment)$relationship='active';

        $lead=$wpdb->get_row($wpdb->prepare(
            "SELECT l.*,u.display_name assigned_name FROM ".self::table('crm_leads')." l LEFT JOIN {$wpdb->users} u ON u.ID=l.assigned_to WHERE l.client_id=%d ORDER BY l.id DESC LIMIT 1",$client_id),ARRAY_A);

        return array(
            'client'=>$client,
            'metrics'=>array(
                'total_spent'=>round($total_spent,2),'average_ticket'=>$average_ticket,'completed_count'=>$completed_count,
                'cancelled_count'=>$cancelled_count,'no_show_count'=>$no_show_count,'attendance_rate'=>$attendance_rate,
                'last_appointment'=>$last_appointment,'next_appointment'=>$next_appointment,'next_service'=>$next_service,
                'days_since_last'=>$days_since_last,'average_frequency_days'=>$average_frequency_days,'relationship_status'=>$relationship,
            ),
            'favorite_service'=>$fav?:null,
            'lead'=>$lead?:null,
            'appointments'=>array_slice($appointments,0,30),
        );
    }

    public static function save_client($data, $id=0) {
        global $wpdb;
        $table = self::table('clients');
        $now = current_time('mysql');
        $row = array(
            'name' => sanitize_text_field($data['name'] ?? ''),
            'cpf' => sanitize_text_field($data['cpf'] ?? ''),
            'birth_date' => self::date_or_null($data['birth_date'] ?? ''),
            'gender' => in_array(($data['gender'] ?? ''), array('female','male','other',''), true) ? ($data['gender'] ?? '') : '',
            'phone' => sanitize_text_field($data['phone'] ?? ''),
            'email' => sanitize_email($data['email'] ?? ''),
            'address' => sanitize_text_field($data['address'] ?? ''),
            'city' => sanitize_text_field($data['city'] ?? ''),
            'state' => strtoupper(substr(sanitize_text_field($data['state'] ?? ''),0,2)),
            'zip_code' => sanitize_text_field($data['zip_code'] ?? ''),
            'source' => sanitize_text_field($data['source'] ?? ''),
            'notes' => sanitize_textarea_field($data['notes'] ?? ''),
            'status' => in_array(($data['status'] ?? 'active'), array('active','inactive'), true) ? $data['status'] : 'active',
            'updated_at' => $now,
        );
        if (!$row['name']) return new WP_Error('apm_required', 'Informe o nome do cliente.', array('status'=>400));
        if ($id) {
            $wpdb->update($table, $row, array('id'=>(int)$id));
            return (int)$id;
        }
        $row['created_at'] = $now;
        $wpdb->insert($table, $row);
        return (int)$wpdb->insert_id;
    }

    public static function delete_client($id) {
        global $wpdb;
        return $wpdb->delete(self::table('clients'), array('id'=>(int)$id), array('%d'));
    }

    public static function list_categories() {
        global $wpdb;
        $c=self::table('service_categories');$s=self::table('services');
        return $wpdb->get_results("SELECT c.*,(SELECT COUNT(*) FROM $s s WHERE s.category_id=c.id) services_count FROM $c c ORDER BY c.name ASC", ARRAY_A);
    }

    public static function save_category($data, $id=0) {
        global $wpdb;
        $name = sanitize_text_field($data['name'] ?? '');
        if (!$name) return new WP_Error('apm_required','Informe o nome da categoria.',array('status'=>400));
        $row = array('name'=>$name,'active'=>empty($data['active'])?0:1,'updated_at'=>current_time('mysql'));
        if ($id) {
            $exists=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".self::table('service_categories')." WHERE name=%s AND id<>%d",$name,(int)$id));
            if($exists) return new WP_Error('apm_category','Já existe uma categoria com este nome.',array('status'=>400));
            $ok=$wpdb->update(self::table('service_categories'),$row,array('id'=>(int)$id));
            if($ok===false) return new WP_Error('apm_category','Não foi possível atualizar a categoria.',array('status'=>400));
            return (int)$id;
        }
        $row['created_at']=current_time('mysql');
        $ok=$wpdb->insert(self::table('service_categories'),$row);
        if (!$ok) return new WP_Error('apm_category','Não foi possível salvar a categoria. Verifique se ela já existe.',array('status'=>400));
        return (int)$wpdb->insert_id;
    }

    public static function delete_category($id) {
        global $wpdb;$id=(int)$id;
        $count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".self::table('services')." WHERE category_id=%d",$id));
        if($count>0) return new WP_Error('apm_category_in_use',sprintf('Esta categoria possui %d serviço(s) vinculado(s). Mova os serviços para outra categoria antes de excluir.',$count),array('status'=>409));
        return $wpdb->delete(self::table('service_categories'), array('id'=>$id));
    }

    public static function list_services() {
        global $wpdb;
        $s=self::table('services'); $c=self::table('service_categories');
        $rows=$wpdb->get_results("SELECT s.*, c.name category_name FROM $s s LEFT JOIN $c c ON c.id=s.category_id ORDER BY s.name ASC",ARRAY_A);
        if(!$rows) return array();
        $sheet=self::table('service_inventory'); $items=self::table('inventory_items');
        $all=$wpdb->get_results("SELECT si.id,si.service_id,si.inventory_item_id,si.quantity,i.name item_name,i.unit,i.current_quantity,i.minimum_quantity,i.active FROM $sheet si LEFT JOIN $items i ON i.id=si.inventory_item_id ORDER BY i.name ASC",ARRAY_A);
        $by=array(); foreach($all as $r){$by[(int)$r['service_id']][]=$r;}
        foreach($rows as &$row){$row['technical_sheet']=$by[(int)$row['id']]??array();}
        return $rows;
    }

    public static function save_service($data,$id=0) {
        global $wpdb;
        $deposit_type=in_array(($data['deposit_type']??'percentage'),array('percentage','fixed'),true)?($data['deposit_type']??'percentage'):'percentage';
        // Campo deixado em branco no formulário chega aqui como string vazia (não como chave
        // ausente), então "?? 30" nunca entrava em ação e o sinal virava 0 (sem sinal) sem
        // avisar ninguém. Em branco agora herda o sinal padrão das configurações gerais
        // (só faz sentido em %, não em valor fixo — aí não há como adivinhar um valor em R$).
        $deposit_value_raw=trim((string)($data['deposit_value']??''));
        if($deposit_value_raw===''){
            $deposit_value=$deposit_type==='percentage'?self::decimal(self::get_settings()['default_deposit']??30):0;
        } else {
            $deposit_value=self::decimal($deposit_value_raw);
        }
        $row=array(
            'category_id'=>!empty($data['category_id'])?(int)$data['category_id']:null,
            'name'=>sanitize_text_field($data['name']??''),
            'badge_text'=>mb_substr(sanitize_text_field($data['badge_text']??''),0,40),
            'badge_color'=>sanitize_hex_color($data['badge_color']??'') ?: '#8f001f',
            'description'=>sanitize_textarea_field($data['description']??''),
            'duration_minutes'=>max(5,(int)($data['duration_minutes']??60)),
            'buffer_minutes'=>max(0,min(240,(int)($data['buffer_minutes']??0))),
            'price'=>self::decimal($data['price']??0),
            // "De" (preço riscado): opcional. Em branco fica sem preço riscado — não dá pra
            // adivinhar um valor "original" quando ninguém informou um.
            'compare_at_price'=>trim((string)($data['compare_at_price']??''))===''?null:self::decimal($data['compare_at_price']),
            'anamnesis_template_id'=>!empty($data['anamnesis_template_id'])?(int)$data['anamnesis_template_id']:null,
            'deposit_type'=>$deposit_type,
            'deposit_value'=>$deposit_value,
            'active'=>empty($data['active'])?0:1,
            'updated_at'=>current_time('mysql'),
        );
        if(!$row['name']) return new WP_Error('apm_required','Informe o nome do serviço.',array('status'=>400));
        if($id){
            $wpdb->update(self::table('services'),$row,array('id'=>(int)$id));
            if(array_key_exists('technical_sheet',$data)) self::save_service_technical_sheet((int)$id,(array)$data['technical_sheet']);
            return (int)$id;
        }
        $row['created_at']=current_time('mysql');$wpdb->insert(self::table('services'),$row);$sid=(int)$wpdb->insert_id;
        if(array_key_exists('technical_sheet',$data)) self::save_service_technical_sheet($sid,(array)$data['technical_sheet']);
        return $sid;
    }

    public static function delete_service($id) {
        global $wpdb;
        $wpdb->delete(self::table('professional_services'),array('service_id'=>(int)$id));
        $wpdb->delete(self::table('service_inventory'),array('service_id'=>(int)$id));
        return $wpdb->delete(self::table('services'),array('id'=>(int)$id));
    }

    public static function duplicate_service($id) {
        global $wpdb;
        $id=(int)$id;
        $src=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".self::table('services')." WHERE id=%d",$id),ARRAY_A);
        if(!$src) return new WP_Error('apm_not_found','Serviço não encontrado.',array('status'=>404));
        $sheet=self::get_service_technical_sheet($id);
        $new_id=self::save_service(array(
            'category_id'=>$src['category_id'],
            'name'=>$src['name'].' (cópia)',
            'badge_text'=>$src['badge_text']??'',
            'badge_color'=>$src['badge_color']??'#8f001f',
            'description'=>$src['description'],
            'duration_minutes'=>$src['duration_minutes'],
            'buffer_minutes'=>$src['buffer_minutes']??0,
            'price'=>$src['price'],
            'compare_at_price'=>$src['compare_at_price']??'',
            'anamnesis_template_id'=>$src['anamnesis_template_id']??null,
            'deposit_type'=>$src['deposit_type'],
            'deposit_value'=>$src['deposit_value'],
            'active'=>$src['active'],
            'technical_sheet'=>array_map(function($row){
                return array('inventory_item_id'=>$row['inventory_item_id'],'quantity'=>$row['quantity']);
            },$sheet),
        ));
        if(is_wp_error($new_id)) return $new_id;
        $pros=$wpdb->get_col($wpdb->prepare("SELECT professional_id FROM ".self::table('professional_services')." WHERE service_id=%d",$id));
        foreach($pros as $pid){
            $wpdb->insert(self::table('professional_services'),array('professional_id'=>(int)$pid,'service_id'=>$new_id));
        }
        return $new_id;
    }

    public static function list_professionals() {
        global $wpdb;
        $p=self::table('professionals');$ps=self::table('professional_services');
        $rows=$wpdb->get_results("SELECT * FROM $p ORDER BY name ASC",ARRAY_A);
        foreach($rows as &$row){
            $row['service_ids']=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT service_id FROM $ps WHERE professional_id=%d",$row['id'])));
        }
        return $rows;
    }

    public static function save_professional($data,$id=0) {
        global $wpdb;
        $row=array(
            'name'=>sanitize_text_field($data['name']??''),
            'email'=>sanitize_email($data['email']??''),
            'phone'=>sanitize_text_field($data['phone']??''),
            'specialty'=>sanitize_text_field($data['specialty']??''),
            'color'=>sanitize_hex_color($data['color']??'#8f001f') ?: '#8f001f',
            'active'=>empty($data['active'])?0:1,
            'updated_at'=>current_time('mysql'),
        );
        if(!$row['name']) return new WP_Error('apm_required','Informe o nome do profissional.',array('status'=>400));
        if($id){$wpdb->update(self::table('professionals'),$row,array('id'=>(int)$id));$pid=(int)$id;}
        else{$row['created_at']=current_time('mysql');$wpdb->insert(self::table('professionals'),$row);$pid=(int)$wpdb->insert_id;}
        $wpdb->delete(self::table('professional_services'),array('professional_id'=>$pid));
        $service_ids=array_map('intval',(array)($data['service_ids']??array()));
        foreach(array_unique(array_filter($service_ids)) as $sid){$wpdb->insert(self::table('professional_services'),array('professional_id'=>$pid,'service_id'=>$sid),array('%d','%d'));}
        return $pid;
    }

    public static function delete_professional($id) {
        global $wpdb;
        $wpdb->delete(self::table('professional_services'),array('professional_id'=>(int)$id));
        $wpdb->delete(self::table('availability'),array('professional_id'=>(int)$id));
        return $wpdb->delete(self::table('professionals'),array('id'=>(int)$id));
    }

    public static function get_settings() {
        return get_option('apm_settings',array());
    }

    public static function save_settings($data) {
        $current=self::get_settings();
        $hours=$current['opening_hours']??array();
        if(isset($data['opening_hours'])&&is_array($data['opening_hours'])){
            $hours=array();
            foreach(range(0,6) as $d){
                $src=$data['opening_hours'][(string)$d]??$data['opening_hours'][$d]??array();
                $hours[(string)$d]=array(
                    'active'=>empty($src['active'])?0:1,
                    'start'=>self::time($src['start']??'09:00'),
                    'end'=>self::time($src['end']??'19:00'),
                );
            }
        }
        $settings=array(
            'spa_name'=>sanitize_text_field($data['spa_name']??($current['spa_name']??'Assis Prime')),
            'phone'=>sanitize_text_field($data['phone']??($current['phone']??'')),
            'email'=>sanitize_email($data['email']??($current['email']??'')),
            'currency'=>'BRL',
            'default_deposit'=>min(100,max(0,(float)($data['default_deposit']??($current['default_deposit']??30)))),
            'booking_interval'=>max(5,(int)($data['booking_interval']??($current['booking_interval']??30))),
            'timezone'=>sanitize_text_field($data['timezone']??($current['timezone']??wp_timezone_string())),
            'default_service_id'=>(int)($data['default_service_id']??($current['default_service_id']??0)),
            'anamnesis_auto_send_enabled'=>isset($data['anamnesis_auto_send_enabled'])?(empty($data['anamnesis_auto_send_enabled'])?0:1):(int)($current['anamnesis_auto_send_enabled']??1),
            // Só dígitos (Meta Pixel ID) / letras, números e hífen (GTM-XXXXXXX) — evita que
            // alguém injete HTML/JS aqui, já que estes valores vão direto para o <head> da
            // página pública de agendamento sem passar por esc_html().
            'meta_pixel_id'=>preg_replace('/[^0-9]/','',(string)($data['meta_pixel_id']??($current['meta_pixel_id']??''))),
            'gtm_container_id'=>preg_replace('/[^A-Za-z0-9\-]/','',(string)($data['gtm_container_id']??($current['gtm_container_id']??''))),
            'payment_gateway'=>sanitize_key($data['payment_gateway']??($current['payment_gateway']??'infinitepay')),
            'payment_enabled'=>isset($data['payment_enabled'])?(empty($data['payment_enabled'])?0:1):(int)($current['payment_enabled']??0),
            'payment_test_mode'=>isset($data['payment_test_mode'])?(empty($data['payment_test_mode'])?0:1):(int)($current['payment_test_mode']??1),
            // A documentação da InfinitePay pede o handle sem o "$" inicial; removemos aqui
            // caso alguém cole a InfiniteTag copiada direto do app (com o símbolo incluso).
            'infinitepay_handle'=>ltrim(sanitize_text_field($data['infinitepay_handle']??($current['infinitepay_handle']??'')),'$@'),
            'infinitepay_api_key'=>sanitize_text_field($data['infinitepay_api_key']??($current['infinitepay_api_key']??'')),
            'payment_hold_minutes'=>max(5,min(60,(int)($data['payment_hold_minutes']??($current['payment_hold_minutes']??20)))),
            'cancellation_policy_text'=>array_key_exists('cancellation_policy_text',$data)?sanitize_textarea_field($data['cancellation_policy_text']):($current['cancellation_policy_text']??''),
            'opening_hours'=>$hours,
            'whatsapp_enabled'=>isset($data['whatsapp_enabled'])?(empty($data['whatsapp_enabled'])?0:1):(int)($current['whatsapp_enabled']??0),
            'zapi_instance_id'=>sanitize_text_field($data['zapi_instance_id']??($current['zapi_instance_id']??'')),
            'zapi_instance_token'=>sanitize_text_field($data['zapi_instance_token']??($current['zapi_instance_token']??'')),
            'zapi_client_token'=>sanitize_text_field($data['zapi_client_token']??($current['zapi_client_token']??'')),
            // Token do nosso próprio webhook (não vem da Z-API): gerado uma vez na instalação,
            // nunca aceito via formulário, para não permitir que alguém o troque por um valor fraco.
            'zapi_webhook_token'=>$current['zapi_webhook_token']??wp_generate_password(32,false,false),
            'whatsapp_otp_enabled'=>isset($data['whatsapp_otp_enabled'])?(empty($data['whatsapp_otp_enabled'])?0:1):(int)($current['whatsapp_otp_enabled']??0),
            'whatsapp_reminder_enabled'=>isset($data['whatsapp_reminder_enabled'])?(empty($data['whatsapp_reminder_enabled'])?0:1):(int)($current['whatsapp_reminder_enabled']??1),
            'whatsapp_reminder_hours_before'=>max(1,min(72,(int)($data['whatsapp_reminder_hours_before']??($current['whatsapp_reminder_hours_before']??24)))),
            'whatsapp_confirmation_enabled'=>isset($data['whatsapp_confirmation_enabled'])?(empty($data['whatsapp_confirmation_enabled'])?0:1):(int)($current['whatsapp_confirmation_enabled']??1),
            'whatsapp_template_confirmation'=>array_key_exists('whatsapp_template_confirmation',$data)?sanitize_textarea_field($data['whatsapp_template_confirmation']):($current['whatsapp_template_confirmation']??''),
            'whatsapp_template_payment_link'=>array_key_exists('whatsapp_template_payment_link',$data)?sanitize_textarea_field($data['whatsapp_template_payment_link']):($current['whatsapp_template_payment_link']??''),
            'whatsapp_template_reminder'=>array_key_exists('whatsapp_template_reminder',$data)?sanitize_textarea_field($data['whatsapp_template_reminder']):($current['whatsapp_template_reminder']??''),
            'whatsapp_template_otp'=>array_key_exists('whatsapp_template_otp',$data)?sanitize_textarea_field($data['whatsapp_template_otp']):($current['whatsapp_template_otp']??''),
        );
        update_option('apm_settings',$settings,false);
        return $settings;
    }
}
