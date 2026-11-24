<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables; WP object cache does not apply.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from self::table() are internal constants, not user input; PHPCS cannot statically verify data flow.
if (!defined('ABSPATH')) exit;

trait APM_Repository_CRM_Trait {
    public static function lead_statuses() {
        return array('new','contacted','interested','awaiting_booking','scheduled','no_show','won','lost');
    }

    public static function normalize_lead_status($status) {
        $map=array('proposal'=>'interested');
        $status=sanitize_key($status ?: 'new');
        if(isset($map[$status])) $status=$map[$status];
        return in_array($status,self::lead_statuses(),true)?$status:'new';
    }

    public static function list_leads() {
        global $wpdb;
        $l=self::table('crm_leads');
        // Migração leve dos status antigos, preservando os registros existentes.
        $wpdb->query("UPDATE $l SET status='interested' WHERE status='proposal'");
        return $wpdb->get_results("SELECT * FROM $l ORDER BY CASE status WHEN 'new' THEN 1 WHEN 'contacted' THEN 2 WHEN 'interested' THEN 3 WHEN 'awaiting_booking' THEN 4 WHEN 'scheduled' THEN 5 WHEN 'no_show' THEN 6 WHEN 'won' THEN 7 ELSE 8 END, updated_at DESC, id DESC", ARRAY_A);
    }

    public static function save_lead($data,$id=0) {
        global $wpdb; $now=current_time('mysql');
        $row=array(
            'client_id'=>!empty($data['client_id'])?(int)$data['client_id']:null,
            'name'=>sanitize_text_field($data['name']??''),
            'phone'=>sanitize_text_field($data['phone']??''),
            'email'=>sanitize_email($data['email']??''),
            'cpf'=>sanitize_text_field($data['cpf']??''),
            'source'=>sanitize_text_field($data['source']??''),
            'status'=>self::normalize_lead_status($data['status']??'new'),
            'interest'=>sanitize_text_field($data['interest']??''),
            'assigned_to'=>!empty($data['assigned_to'])?(int)$data['assigned_to']:null,
            'next_action_at'=>self::datetime_or_null($data['next_action_at']??''),
            'notes'=>sanitize_textarea_field($data['notes']??''),
            'updated_at'=>$now,
        );
        if(!$row['name']) return new WP_Error('apm_required','Informe o nome do lead.',array('status'=>400));
        if($id){
            $old=$wpdb->get_row($wpdb->prepare("SELECT status FROM ".self::table('crm_leads')." WHERE id=%d",(int)$id),ARRAY_A);
            $wpdb->update(self::table('crm_leads'),$row,array('id'=>(int)$id));
            if($old && $old['status']!==$row['status']) self::log_lead_move((int)$id,$old['status'],$row['status']);
            return (int)$id;
        }
        $row['created_by']=get_current_user_id();$row['created_at']=$now;$wpdb->insert(self::table('crm_leads'),$row);return (int)$wpdb->insert_id;
    }

    public static function move_lead($id,$status) {
        global $wpdb; $id=(int)$id; $status=self::normalize_lead_status($status);
        $lead=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".self::table('crm_leads')." WHERE id=%d",$id),ARRAY_A);
        if(!$lead) return new WP_Error('apm_not_found','Lead não encontrado.',array('status'=>404));
        if($lead['status']===$status) return array('id'=>$id,'status'=>$status);
        $wpdb->update(self::table('crm_leads'),array('status'=>$status,'updated_at'=>current_time('mysql')),array('id'=>$id));
        self::log_lead_move($id,$lead['status'],$status);
        return array('id'=>$id,'status'=>$status);
    }

    private static function log_lead_move($lead_id,$from,$to) {
        global $wpdb;
        $labels=array('new'=>'Novo lead','contacted'=>'Em contato','proposal'=>'Interessado','interested'=>'Interessado','awaiting_booking'=>'Aguardando agendamento','scheduled'=>'Agendado','no_show'=>'Não compareceu','won'=>'Convertido em cliente','lost'=>'Perdido');
        $wpdb->insert(self::table('crm_interactions'),array(
            'lead_id'=>(int)$lead_id,
            'type'=>'status',
            'content'=>'Lead movido de '.($labels[$from]??$from).' para '.($labels[$to]??$to).'.',
            'created_by'=>get_current_user_id(),
            'created_at'=>current_time('mysql')
        ));
    }

    public static function delete_lead($id){global $wpdb;$wpdb->delete(self::table('crm_interactions'),array('lead_id'=>(int)$id));return $wpdb->delete(self::table('crm_leads'),array('id'=>(int)$id));}

    public static function add_lead_interaction($lead_id,$data){
        global $wpdb;$lead_id=(int)$lead_id;$content=sanitize_textarea_field($data['content']??'');
        if(!$lead_id||!$content) return new WP_Error('apm_required','Escreva a interação antes de salvar.',array('status'=>400));
        $wpdb->insert(self::table('crm_interactions'),array('lead_id'=>$lead_id,'type'=>sanitize_key($data['type']??'note'),'content'=>$content,'next_action_at'=>self::datetime_or_null($data['next_action_at']??''),'created_by'=>get_current_user_id(),'created_at'=>current_time('mysql')));
        if(!empty($data['next_action_at'])) $wpdb->update(self::table('crm_leads'),array('next_action_at'=>self::datetime_or_null($data['next_action_at']),'updated_at'=>current_time('mysql')),array('id'=>$lead_id));
        return (int)$wpdb->insert_id;
    }

    public static function lead_interactions($lead_id){
        global $wpdb;$u=$wpdb->users;
        return $wpdb->get_results($wpdb->prepare("SELECT i.*,u.display_name author_name FROM ".self::table('crm_interactions')." i LEFT JOIN $u u ON u.ID=i.created_by WHERE i.lead_id=%d ORDER BY i.id DESC",(int)$lead_id),ARRAY_A);
    }

    private static function normalize_digits($value){ return preg_replace('/\D+/','',(string)$value); }

    // Pontua clientes existentes contra um CPF/telefone/e-mail soltos (não precisa de um
    // lead já cadastrado) — reaproveitado tanto pela conversão de lead quanto pelo
    // "Cadastro rápido" da Agenda, para não duplicar cliente quando a mesma pessoa já existe.
    public static function client_match_candidates($cpf='', $phone='', $email='') {
        global $wpdb; $clients=self::table('clients'); $found=array();
        $cpf=self::normalize_digits($cpf);
        $phone=self::normalize_digits($phone);
        $email=strtolower(trim((string)$email));
        if(!$cpf && !$phone && !$email) return array();
        $rows=$wpdb->get_results("SELECT * FROM $clients ORDER BY id DESC LIMIT 500",ARRAY_A);
        foreach($rows as $c){
            $score=0;$reasons=array();
            $ccpf=self::normalize_digits($c['cpf']??'');$cphone=self::normalize_digits($c['phone']??'');$cemail=strtolower(trim((string)($c['email']??'')));
            if($cpf && $ccpf && $cpf===$ccpf){$score+=100;$reasons[]='CPF';}
            if($phone && $cphone && substr($phone,-8)===substr($cphone,-8)){$score+=60;$reasons[]='WhatsApp';}
            if($email && $cemail && $email===$cemail){$score+=40;$reasons[]='e-mail';}
            if($score>0){$c['match_score']=$score;$c['match_reason']=implode(' + ',$reasons);$found[]=$c;}
        }
        usort($found,function($a,$b){return (int)$b['match_score']<=>(int)$a['match_score'];});
        return array_slice($found,0,5);
    }

    public static function lead_client_matches($id) {
        global $wpdb; $lead=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".self::table('crm_leads')." WHERE id=%d",(int)$id),ARRAY_A);
        if(!$lead) return new WP_Error('apm_not_found','Lead não encontrado.',array('status'=>404));
        return self::client_match_candidates($lead['cpf']??'', $lead['phone']??'', $lead['email']??'');
    }

    public static function convert_lead($id,$data=array()){
        global $wpdb;$lead=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".self::table('crm_leads')." WHERE id=%d",(int)$id),ARRAY_A);
        if(!$lead)return new WP_Error('apm_not_found','Lead não encontrado.',array('status'=>404));
        $client_id=!empty($data['client_id'])?(int)$data['client_id']:(int)($lead['client_id']??0);
        if(!$client_id){
            $matches=self::lead_client_matches($id); if(is_wp_error($matches)) return $matches;
            if(count($matches)) return new WP_Error('apm_duplicate_client','Já existe um cliente com dados semelhantes. Revise antes de converter.',array('status'=>409,'matches'=>$matches));
            $client_id=self::save_client(array('name'=>$lead['name'],'phone'=>$lead['phone'],'email'=>$lead['email'],'cpf'=>$lead['cpf']??'','source'=>$lead['source']?:'CRM','notes'=>$lead['notes'],'status'=>'active'));if(is_wp_error($client_id))return $client_id;
        }
        $reason=sanitize_key($data['reason']??'manual');
        $reason_labels=array('manual'=>'conversão manual pelo CRM','booking_confirmed'=>'agendamento confirmado','payment_approved'=>'pagamento do sinal aprovado','appointment_completed'=>'primeiro atendimento concluído');
        $wpdb->update(self::table('crm_leads'),array('client_id'=>$client_id,'status'=>'won','converted_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')),array('id'=>(int)$id));
        self::log_lead_move((int)$id,$lead['status'],'won');
        self::create_notification('lead_converted','Lead convertido em cliente',$lead['name'].' foi convertido em cliente após '.($reason_labels[$reason]??'uma ação no CRM').'.','client',$client_id,class_exists('APM_Router')?APM_Router::page_url('clients'):'',array('lead_id'=>(int)$id,'client_id'=>$client_id,'reason'=>$reason,'interest'=>$lead['interest']??''));
        return array('lead_id'=>(int)$id,'client_id'=>$client_id,'reason'=>$reason);
    }

    public static function sync_lead_status_for_client($client_id,$status) {
        global $wpdb; $client=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".self::table('clients')." WHERE id=%d",(int)$client_id),ARRAY_A);
        if(!$client) return false;
        $status=self::normalize_lead_status($status);
        $leads=$wpdb->get_results("SELECT * FROM ".self::table('crm_leads')." WHERE status NOT IN ('won','lost') ORDER BY id DESC",ARRAY_A);
        $cpf=self::normalize_digits($client['cpf']??'');$phone=self::normalize_digits($client['phone']??'');$email=strtolower(trim((string)($client['email']??'')));
        foreach($leads as $lead){
            $match=false;
            if($cpf && self::normalize_digits($lead['cpf']??'')===$cpf)$match=true;
            if(!$match && $phone && self::normalize_digits($lead['phone']??'') && substr(self::normalize_digits($lead['phone']??''),-8)===substr($phone,-8))$match=true;
            if(!$match && $email && strtolower(trim((string)($lead['email']??'')))===$email)$match=true;
            if($match && $lead['status']!==$status) self::move_lead((int)$lead['id'],$status);
        }
        return true;
    }

    public static function maybe_convert_lead_for_client($client_id,$reason='booking_confirmed') {
        global $wpdb; $client=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".self::table('clients')." WHERE id=%d",(int)$client_id),ARRAY_A);
        if(!$client) return false;
        $leads=$wpdb->get_results("SELECT * FROM ".self::table('crm_leads')." WHERE status NOT IN ('won','lost') ORDER BY id DESC",ARRAY_A);
        $cpf=self::normalize_digits($client['cpf']??'');$phone=self::normalize_digits($client['phone']??'');$email=strtolower(trim((string)($client['email']??'')));
        foreach($leads as $lead){
            $match=false;
            if($cpf && self::normalize_digits($lead['cpf']??'')===$cpf)$match=true;
            if(!$match && $phone && substr(self::normalize_digits($lead['phone']??''),-8)===substr($phone,-8))$match=true;
            if(!$match && $email && strtolower(trim((string)($lead['email']??'')))===$email)$match=true;
            if($match) self::convert_lead((int)$lead['id'],array('client_id'=>(int)$client_id,'reason'=>$reason));
        }
        return true;
    }

    public static function create_notification($type,$title,$message='',$entity_type='',$entity_id=0,$action_url='',$metadata=array()) {
        global $wpdb; $table=self::table('notifications'); $now=current_time('mysql');
        $users=get_users(array('fields'=>array('ID')));
        foreach($users as $u){
            $uid=(int)$u->ID;
            if(!user_can($uid,'manage_options')&&!user_can($uid,'apm_access')) continue;
            $wpdb->insert($table,array('user_id'=>$uid,'type'=>sanitize_key($type),'title'=>sanitize_text_field($title),'message'=>sanitize_textarea_field($message),'entity_type'=>sanitize_key($entity_type),'entity_id'=>(int)$entity_id,'action_url'=>esc_url_raw($action_url),'metadata'=>wp_json_encode($metadata),'created_at'=>$now));
        }
        return true;
    }

    public static function list_notifications($limit=8) {
        global $wpdb; $uid=get_current_user_id(); $limit=max(1,min(30,(int)$limit));
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM ".self::table('notifications')." WHERE user_id=%d ORDER BY created_at DESC,id DESC LIMIT %d",$uid,$limit),ARRAY_A);
    }

    public static function notifications_page($args=array()) {
        global $wpdb; $uid=get_current_user_id(); $table=self::table('notifications');
        $page=max(1,(int)($args['page']??1)); $per=max(10,min(50,(int)($args['per_page']??20)));
        $status=sanitize_key($args['status']??'all'); $type=sanitize_key($args['type']??''); $search=trim(sanitize_text_field($args['search']??''));
        $where=array('user_id=%d'); $params=array($uid);
        if($status==='unread') $where[]='read_at IS NULL'; elseif($status==='read') $where[]='read_at IS NOT NULL';
        if($type){$where[]='type=%s';$params[]=$type;}
        if($search){$like='%'.$wpdb->esc_like($search).'%';$where[]='(title LIKE %s OR message LIKE %s)';$params[]=$like;$params[]=$like;}
        $where_sql=implode(' AND ',$where);
        $count_sql="SELECT COUNT(*) FROM $table WHERE $where_sql"; $count_sql=$wpdb->prepare($count_sql,$params); $total=(int)$wpdb->get_var($count_sql);
        $offset=($page-1)*$per; $qparams=$params; $qparams[]=$per; $qparams[]=$offset;
        $sql="SELECT * FROM $table WHERE $where_sql ORDER BY created_at DESC,id DESC LIMIT %d OFFSET %d"; $sql=$wpdb->prepare($sql,$qparams);
        $items=$wpdb->get_results($sql,ARRAY_A);
        $types=$wpdb->get_col($wpdb->prepare("SELECT DISTINCT type FROM $table WHERE user_id=%d ORDER BY type ASC",$uid));
        return array('items'=>$items,'page'=>$page,'per_page'=>$per,'total'=>$total,'pages'=>max(1,(int)ceil($total/$per)),'unread'=>self::unread_notifications_count(),'types'=>$types);
    }

    public static function unread_notifications_count() {
        global $wpdb; return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".self::table('notifications')." WHERE user_id=%d AND read_at IS NULL",get_current_user_id()));
    }

    public static function mark_notification_read($id) {
        global $wpdb; return (bool)$wpdb->update(self::table('notifications'),array('read_at'=>current_time('mysql')),array('id'=>(int)$id,'user_id'=>get_current_user_id()));
    }

    public static function mark_notification_unread($id) {
        global $wpdb; return false!==$wpdb->query($wpdb->prepare("UPDATE ".self::table('notifications')." SET read_at=NULL WHERE id=%d AND user_id=%d",(int)$id,get_current_user_id()));
    }

    public static function mark_all_notifications_read() {
        global $wpdb; return false!==$wpdb->query($wpdb->prepare("UPDATE ".self::table('notifications')." SET read_at=%s WHERE user_id=%d AND read_at IS NULL",current_time('mysql'),get_current_user_id()));
    }
}
