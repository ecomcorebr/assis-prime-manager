<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables; WP object cache does not apply.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from self::table() are internal constants, not user input; PHPCS cannot statically verify data flow.
if (!defined('ABSPATH')) exit;

trait APM_Repository_Records_Trait {
    private static function sanitize_record_structured_value($value){
        if(is_array($value)){
            $out=array();
            foreach($value as $k=>$v){$out[sanitize_key((string)$k)]=self::sanitize_record_structured_value($v);}
            return $out;
        }
        if(is_bool($value)) return $value?1:0;
        if(is_numeric($value) && !is_string($value)) return $value;
        return sanitize_textarea_field((string)$value);
    }

    // Prontuário é dado de saúde sensível: quem não tem alçada de Gestor/Administrador só
    // pode ver ou operar sobre prontuários do(s) profissional(is) ligado(s) ao seu usuário.
    private static function can_view_all_records(){
        return current_user_can('manage_options') || current_user_can('apm_manage_settings');
    }

    private static function current_professional_id(){
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM ".self::table('professionals')." WHERE wp_user_id=%d LIMIT 1", get_current_user_id()));
    }

    public static function ensure_record_access($record_id){
        if(self::can_view_all_records()) return true;
        global $wpdb;
        $professional_id=(int)$wpdb->get_var($wpdb->prepare("SELECT professional_id FROM ".self::table('records')." WHERE id=%d",(int)$record_id));
        if($professional_id && $professional_id===self::current_professional_id()) return true;
        return new WP_Error('apm_record_forbidden','Você não tem acesso a este prontuário.',array('status'=>403));
    }

    public static function list_records($client_id=0){
        global $wpdb;$r=self::table('records');$c=self::table('clients');$p=self::table('professionals');
        $where='1=1';$args=array();if($client_id){$where.=' AND r.client_id=%d';$args[]=(int)$client_id;}
        if(!self::can_view_all_records()){
            $where.=' AND r.professional_id=%d';$args[]=self::current_professional_id();
        }
        $sql="SELECT r.*,c.name client_name,p.name professional_name FROM $r r LEFT JOIN $c c ON c.id=r.client_id LEFT JOIN $p p ON p.id=r.professional_id WHERE $where ORDER BY r.updated_at DESC,r.id DESC";
        if($args)$sql=$wpdb->prepare($sql,$args);
        $rows=$wpdb->get_results($sql,ARRAY_A);
        foreach($rows as &$row){
            $row['signatures']=self::record_signatures((int)$row['id']);
            $row['structured_data']=!empty($row['structured_data'])?json_decode($row['structured_data'],true):array();
            if(!is_array($row['structured_data']))$row['structured_data']=array();
        }
        return $rows;
    }

    public static function save_record($data,$id=0){
        global $wpdb;$now=current_time('mysql');$allowed_types=array('anamnesis','evolution','term','consent','other');$allowed_status=array('draft','final','awaiting_signature','signed');
        $table=self::table('records');

        if($id){
            $current=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d",(int)$id),ARRAY_A);
            if(!$current)return new WP_Error('apm_not_found','Prontuário não encontrado.',array('status'=>404));
            $access=self::ensure_record_access((int)$id); if(is_wp_error($access)) return $access;
            if(($current['status']??'')==='signed')return new WP_Error('apm_record_locked','Este documento já foi assinado e não pode ser alterado. Crie uma nova versão para atualizar as informações.',array('status'=>409));
        }

        $structured=array_key_exists('structured_data',$data) && is_array($data['structured_data'])?self::sanitize_record_structured_value($data['structured_data']):($id&&!empty($current['structured_data'])?(json_decode($current['structured_data'],true)?:array()):array());
        $template_key=array_key_exists('template_key',$data)?sanitize_key($data['template_key']):sanitize_key($current['template_key']??'');
        $parent_record_id=array_key_exists('parent_record_id',$data)?(!empty($data['parent_record_id'])?(int)$data['parent_record_id']:null):(!empty($current['parent_record_id'])?(int)$current['parent_record_id']:null);
        $version_no=array_key_exists('version_no',$data)?max(1,(int)$data['version_no']):max(1,(int)($current['version_no']??1));

        if(!$id && $parent_record_id){
            $parent=$wpdb->get_row($wpdb->prepare("SELECT id,parent_record_id,client_id,type,template_key,version_no FROM $table WHERE id=%d",$parent_record_id),ARRAY_A);
            if(!$parent)return new WP_Error('apm_parent_record','A versão anterior da anamnese não foi encontrada.',array('status'=>404));
            $root=(int)($parent['parent_record_id']?:$parent['id']);
            $max=(int)$wpdb->get_var($wpdb->prepare("SELECT MAX(version_no) FROM $table WHERE id=%d OR parent_record_id=%d",$root,$root));
            $version_no=max(2,$max+1);
            $parent_record_id=$root;
            if(!$template_key)$template_key=sanitize_key($parent['template_key']??'');
        }

        $row=array(
            'client_id'=>(int)($data['client_id']??0),
            'appointment_id'=>!empty($data['appointment_id'])?(int)$data['appointment_id']:null,
            'professional_id'=>!empty($data['professional_id'])?(int)$data['professional_id']:null,
            'type'=>in_array(($data['type']??'evolution'),$allowed_types,true)?$data['type']:'evolution',
            'title'=>sanitize_text_field($data['title']??''),
            'content'=>wp_kses_post($data['content']??''),
            'structured_data'=>wp_json_encode($structured,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'template_key'=>$template_key,
            'version_no'=>$version_no,
            'parent_record_id'=>$parent_record_id,
            'attachments'=>sanitize_textarea_field($data['attachments']??''),
            'status'=>in_array(($data['status']??'draft'),$allowed_status,true)?$data['status']:'draft',
            'updated_at'=>$now
        );
        if(!$row['client_id']||!$row['title'])return new WP_Error('apm_required','Informe o cliente e o título do prontuário.',array('status'=>400));

        if(!empty($data['client_updates']) && is_array($data['client_updates'])){
            $u=array();$cu=$data['client_updates'];
            if(array_key_exists('cpf',$cu))$u['cpf']=sanitize_text_field($cu['cpf']);
            if(array_key_exists('birth_date',$cu))$u['birth_date']=self::date_or_null($cu['birth_date']);
            if(array_key_exists('gender',$cu) && in_array($cu['gender'],array('female','male','other',''),true))$u['gender']=$cu['gender'];
            if(array_key_exists('phone',$cu))$u['phone']=sanitize_text_field($cu['phone']);
            if($u){$u['updated_at']=$now;$wpdb->update(self::table('clients'),$u,array('id'=>$row['client_id']));}
        }

        $row['document_hash']=hash('sha256',wp_json_encode(array(
            'client_id'=>$row['client_id'],'type'=>$row['type'],'title'=>$row['title'],'content'=>$row['content'],
            'structured_data'=>$row['structured_data'],'template_key'=>$row['template_key'],'version_no'=>$row['version_no'],'attachments'=>$row['attachments']
        )));
        if($id){$wpdb->update($table,$row,array('id'=>(int)$id));return (int)$id;}
        $row['created_by']=get_current_user_id();$row['created_at']=$now;$wpdb->insert($table,$row);return (int)$wpdb->insert_id;
    }

    public static function delete_record($id){
        global $wpdb;$id=(int)$id;
        $access=self::ensure_record_access($id); if(is_wp_error($access)) return $access;
        $status=$wpdb->get_var($wpdb->prepare("SELECT status FROM ".self::table('records')." WHERE id=%d",$id));
        if($status==='signed')return new WP_Error('apm_record_locked','Documentos assinados não podem ser excluídos.',array('status'=>409));
        $wpdb->delete(self::table('signature_requests'),array('record_id'=>$id));return $wpdb->delete(self::table('records'),array('id'=>$id));
    }

    public static function record_signatures($record_id){global $wpdb;return $wpdb->get_results($wpdb->prepare("SELECT id,status,token_last4,expires_at,signer_name,signer_cpf,signer_phone,signed_at,ip_address,document_hash,created_at FROM ".self::table('signature_requests')." WHERE record_id=%d ORDER BY id DESC",(int)$record_id),ARRAY_A);}

    public static function create_signature_request($record_id,$data=array()){
        global $wpdb;$record=$wpdb->get_row($wpdb->prepare("SELECT r.*,c.name client_name,c.cpf client_cpf,c.phone client_phone FROM ".self::table('records')." r LEFT JOIN ".self::table('clients')." c ON c.id=r.client_id WHERE r.id=%d",(int)$record_id),ARRAY_A);
        if(!$record)return new WP_Error('apm_not_found','Prontuário não encontrado.',array('status'=>404));
        $access=self::ensure_record_access((int)$record_id); if(is_wp_error($access)) return $access;
        if(($record['status']??'')==='signed')return new WP_Error('apm_signature_signed','Este documento já foi assinado.',array('status'=>409));
        $token=wp_generate_password(40,false,false);$hash=hash('sha256',$token);$hours=max(1,min(168,(int)($data['expires_hours']??48)));$expires=gmdate('Y-m-d H:i:s',current_time('timestamp')+$hours*3600);$now=current_time('mysql');
        $wpdb->insert(self::table('signature_requests'),array('record_id'=>(int)$record_id,'client_id'=>(int)$record['client_id'],'token_hash'=>$hash,'token_last4'=>substr($token,-4),'status'=>'pending','expires_at'=>$expires,'signer_name'=>sanitize_text_field($record['client_name']??''),'signer_cpf'=>sanitize_text_field($record['client_cpf']??''),'signer_phone'=>sanitize_text_field($record['client_phone']??''),'document_hash'=>$record['document_hash'],'document_snapshot'=>wp_json_encode(array('title'=>$record['title'],'content'=>$record['content'],'attachments'=>$record['attachments'],'type'=>$record['type'],'structured_data'=>$record['structured_data'],'template_key'=>$record['template_key'],'version_no'=>$record['version_no'])),'created_by'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now));
        $wpdb->update(self::table('records'),array('status'=>'awaiting_signature','updated_at'=>$now),array('id'=>(int)$record_id));
        $url=class_exists('APM_Router')?APM_Router::signature_url($token):add_query_arg('token',$token,home_url('/assinatura-digital/'));
        return array('id'=>(int)$wpdb->insert_id,'url'=>$url,'expires_at'=>$expires,'token_last4'=>substr($token,-4));
    }

    public static function public_signature($token){
        global $wpdb;$hash=hash('sha256',sanitize_text_field($token));$s=$wpdb->get_row($wpdb->prepare("SELECT sr.*,r.title,r.content,r.attachments,r.type,r.status record_status,c.name client_name,c.cpf client_cpf,c.phone client_phone FROM ".self::table('signature_requests')." sr LEFT JOIN ".self::table('records')." r ON r.id=sr.record_id LEFT JOIN ".self::table('clients')." c ON c.id=sr.client_id WHERE sr.token_hash=%s LIMIT 1",$hash),ARRAY_A);
        if(!$s)return new WP_Error('apm_signature_invalid','Link de assinatura inválido.',array('status'=>404));
        if($s['status']==='pending' && strtotime($s['expires_at'])<current_time('timestamp')){$wpdb->update(self::table('signature_requests'),array('status'=>'expired','updated_at'=>current_time('mysql')),array('id'=>(int)$s['id']));$s['status']='expired';}
        $snapshot=!empty($s['document_snapshot'])?json_decode($s['document_snapshot'],true):array();
        if(is_array($snapshot)&&$snapshot){foreach(array('title','content','attachments','type') as $k){if(array_key_exists($k,$snapshot))$s[$k]=$snapshot[$k];}}
        $s['otp_required']=self::whatsapp_otp_required()?1:0;
        $s['otp_verified']=!empty($s['otp_verified_at'])&&self::signature_otp_satisfied($s)?1:0;
        unset($s['token_hash'],$s['document_snapshot'],$s['audit_data'],$s['user_agent'],$s['otp_code_hash'],$s['otp_expires_at'],$s['otp_attempts'],$s['otp_sent_count'],$s['otp_verified_at']);return $s;
    }

    public static function sign_record($token,$data,$server=array()){
        global $wpdb;$hash=hash('sha256',sanitize_text_field($token));$s=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".self::table('signature_requests')." WHERE token_hash=%s LIMIT 1",$hash),ARRAY_A);
        if(!$s)return new WP_Error('apm_signature_invalid','Link de assinatura inválido.',array('status'=>404));
        if($s['status']==='signed')return new WP_Error('apm_signature_signed','Este documento já foi assinado.',array('status'=>409));
        if($s['status']!=='pending'||strtotime($s['expires_at'])<current_time('timestamp'))return new WP_Error('apm_signature_expired','Este link de assinatura expirou.',array('status'=>410));
        if(!self::signature_otp_satisfied($s))return new WP_Error('apm_wa_otp_required','Confirme o código enviado por WhatsApp antes de assinar.',array('status'=>428));
        $name=sanitize_text_field($data['name']??'');$cpf=sanitize_text_field($data['cpf']??'');$confirm=!empty($data['confirm']);if(!$name||!$confirm)return new WP_Error('apm_signature_required','Confirme seus dados e a concordância com o documento.',array('status'=>400));
        $ip=sanitize_text_field(self::resolve_client_ip($server));$ua=sanitize_text_field($server['HTTP_USER_AGENT']??'');$now=current_time('mysql');$audit=array('signed_at'=>$now,'ip'=>$ip,'user_agent'=>$ua,'record_id'=>(int)$s['record_id'],'document_hash'=>$s['document_hash'],'method'=>'link_token_confirmation');
        $wpdb->update(self::table('signature_requests'),array('status'=>'signed','signer_name'=>$name,'signer_cpf'=>$cpf,'signer_phone'=>sanitize_text_field($data['phone']??$s['signer_phone']),'signed_at'=>$now,'ip_address'=>$ip,'user_agent'=>$ua,'audit_data'=>wp_json_encode($audit),'updated_at'=>$now),array('id'=>(int)$s['id']));
        $wpdb->update(self::table('records'),array('status'=>'signed','updated_at'=>$now),array('id'=>(int)$s['record_id']));
        return array('success'=>true,'signed_at'=>$now,'document_hash'=>$s['document_hash']);
    }
}
