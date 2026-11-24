<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables; WP object cache does not apply.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from self::table() are internal constants, not user input; PHPCS cannot statically verify data flow.
if (!defined('ABSPATH')) exit;

trait APM_Repository_Inventory_Trait {
    public static function inventory_units() {
        return array(
            'un'=>'Unidade','ml'=>'ml','l'=>'Litro','g'=>'g','kg'=>'kg','par'=>'Par','pct'=>'Pacote','sache'=>'Sachê'
        );
    }

    public static function list_inventory_items($active_only=false) {
        global $wpdb;
        $table=self::table('inventory_items');
        $where=$active_only?' WHERE active=1 ':'';
        return $wpdb->get_results("SELECT * FROM $table $where ORDER BY name ASC",ARRAY_A);
    }

    public static function save_inventory_item($data,$id=0) {
        global $wpdb;
        $table=self::table('inventory_items');
        $name=sanitize_text_field($data['name']??'');
        if(!$name) return new WP_Error('apm_inventory_name','Informe o nome do item.',array('status'=>400));
        $units=self::inventory_units();
        $unit=sanitize_key($data['unit']??'un'); if(!isset($units[$unit])) $unit='un';
        $now=current_time('mysql');
        $minimum=max(0,(float)($data['minimum_quantity']??0));
        $row=array(
            'name'=>$name,
            'unit'=>$unit,
            'minimum_quantity'=>$minimum,
            'supplier'=>sanitize_text_field($data['supplier']??''),
            'lot'=>sanitize_text_field($data['lot']??''),
            'expires_on'=>self::date_or_null($data['expires_on']??''),
            'active'=>empty($data['active'])?0:1,
            'updated_at'=>$now,
        );
        if($id){
            $old=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d",(int)$id),ARRAY_A);
            if(!$old) return new WP_Error('apm_inventory_not_found','Item de estoque não encontrado.',array('status'=>404));
            $wpdb->update($table,$row,array('id'=>(int)$id));
            if((float)$old['current_quantity']<=$minimum && (float)$old['minimum_quantity']<$minimum) self::notify_inventory_low_now((int)$id);
            return (int)$id;
        }
        $initial=max(0,(float)($data['current_quantity']??0));
        $row['current_quantity']=$initial;
        $row['created_at']=$now;
        $wpdb->insert($table,$row);
        $new_id=(int)$wpdb->insert_id;
        if($new_id && $initial>0){
            self::insert_inventory_movement($new_id,'initial',$initial,$initial,'Estoque inicial',0,0,0,0,'initial:'.$new_id);
        }
        if($new_id && (($initial>0 && $initial<=$minimum)||($initial<=0 && $minimum>0))) self::notify_inventory_low_now($new_id);
        return $new_id;
    }

    public static function delete_inventory_item($id) {
        global $wpdb; $id=(int)$id;
        $used=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".self::table('service_inventory')." WHERE inventory_item_id=%d",$id));
        if($used) return new WP_Error('apm_inventory_used','Este item está vinculado à ficha técnica de um ou mais serviços. Desative-o em vez de excluir.',array('status'=>409));
        return $wpdb->delete(self::table('inventory_items'),array('id'=>$id),array('%d'));
    }

    public static function inventory_movement($item_id,$data) {
        global $wpdb; $item_id=(int)$item_id;
        $table=self::table('inventory_items');
        $item=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d",$item_id),ARRAY_A);
        if(!$item) return new WP_Error('apm_inventory_not_found','Item de estoque não encontrado.',array('status'=>404));
        $type=sanitize_key($data['movement_type']??'entry');
        if(!in_array($type,array('entry','loss','adjustment_add','adjustment_remove','return'),true)) $type='entry';
        $qty=max(0,(float)($data['quantity']??0));
        if($qty<=0) return new WP_Error('apm_inventory_qty','Informe uma quantidade maior que zero.',array('status'=>400));
        $delta=in_array($type,array('loss','adjustment_remove'),true)?-$qty:$qty;
        $old=(float)$item['current_quantity']; $new=$old+$delta;
        if($new<0) return new WP_Error('apm_inventory_negative','A movimentação deixaria o estoque negativo.',array('status'=>409));
        $wpdb->update($table,array('current_quantity'=>$new,'updated_at'=>current_time('mysql')),array('id'=>$item_id));
        self::insert_inventory_movement($item_id,$type,$delta,$new,sanitize_text_field($data['note']??''));
        self::maybe_notify_inventory_level($item_id,$old,$new,(float)$item['minimum_quantity']);
        return array('id'=>$item_id,'current_quantity'=>$new);
    }

    private static function insert_inventory_movement($item_id,$type,$quantity,$balance,$note='',$appointment_id=0,$service_id=0,$professional_id=0,$client_id=0,$event_key=null) {
        global $wpdb;
        $row=array(
            'inventory_item_id'=>(int)$item_id,
            'appointment_id'=>$appointment_id?(int)$appointment_id:null,
            'service_id'=>$service_id?(int)$service_id:null,
            'professional_id'=>$professional_id?(int)$professional_id:null,
            'client_id'=>$client_id?(int)$client_id:null,
            'movement_type'=>sanitize_key($type),
            'quantity'=>(float)$quantity,
            'balance_after'=>(float)$balance,
            'note'=>sanitize_text_field($note),
            'event_key'=>$event_key?sanitize_text_field($event_key):null,
            'created_by'=>get_current_user_id()?:null,
            'created_at'=>current_time('mysql'),
        );
        $wpdb->insert(self::table('inventory_movements'),$row);
        return (int)$wpdb->insert_id;
    }

    public static function list_inventory_movements($limit=100) {
        $page=self::inventory_movements_page(1,$limit); return $page['items'];
    }

    public static function inventory_movements_page($page=1,$per_page=30) {
        global $wpdb; $page=max(1,(int)$page);$per=max(10,min(50,(int)$per_page));$offset=($page-1)*$per;
        $m=self::table('inventory_movements');$i=self::table('inventory_items');$s=self::table('services');$c=self::table('clients');
        $total=(int)$wpdb->get_var("SELECT COUNT(*) FROM $m");
        $items=$wpdb->get_results($wpdb->prepare("SELECT m.*,i.name item_name,i.unit,s.name service_name,c.name client_name FROM $m m LEFT JOIN $i i ON i.id=m.inventory_item_id LEFT JOIN $s s ON s.id=m.service_id LEFT JOIN $c c ON c.id=m.client_id ORDER BY m.id DESC LIMIT %d OFFSET %d",$per,$offset),ARRAY_A);
        return array('items'=>$items,'page'=>$page,'per_page'=>$per,'total'=>$total,'pages'=>max(1,(int)ceil($total/$per)));
    }

    public static function get_service_technical_sheet($service_id) {
        global $wpdb; $si=self::table('service_inventory');$ii=self::table('inventory_items');
        return $wpdb->get_results($wpdb->prepare("SELECT si.id,si.service_id,si.inventory_item_id,si.quantity,ii.name item_name,ii.unit,ii.current_quantity,ii.minimum_quantity,ii.active FROM $si si LEFT JOIN $ii ii ON ii.id=si.inventory_item_id WHERE si.service_id=%d ORDER BY ii.name ASC",(int)$service_id),ARRAY_A);
    }

    public static function save_service_technical_sheet($service_id,$rows) {
        global $wpdb; $service_id=(int)$service_id;
        $table=self::table('service_inventory');
        if(!$service_id) return false;
        $clean=array();
        foreach((array)$rows as $row){
            $item_id=(int)($row['inventory_item_id']??0);$qty=max(0,(float)($row['quantity']??0));
            if(!$item_id||$qty<=0) continue;
            $clean[$item_id]=$qty;
        }
        $wpdb->delete($table,array('service_id'=>$service_id),array('%d'));
        $now=current_time('mysql');
        foreach($clean as $item_id=>$qty){
            $wpdb->insert($table,array('service_id'=>$service_id,'inventory_item_id'=>$item_id,'quantity'=>$qty,'created_at'=>$now,'updated_at'=>$now));
        }
        return true;
    }

    public static function validate_inventory_for_service($service_id) {
        $sheet=self::get_service_technical_sheet($service_id); $missing=array();
        foreach($sheet as $row){
            if(empty($row['active'])) continue;
            if((float)$row['current_quantity']+0.00001 < (float)$row['quantity']){
                $missing[]=array('item'=>$row['item_name'],'required'=>(float)$row['quantity'],'available'=>(float)$row['current_quantity'],'unit'=>$row['unit']);
            }
        }
        if($missing){
            $first=$missing[0];
            return new WP_Error('apm_inventory_insufficient','Estoque insuficiente de '.$first['item'].'. Disponível: '.self::format_inventory_quantity($first['available']).' '.$first['unit'].'; necessário: '.self::format_inventory_quantity($first['required']).' '.$first['unit'].'.',array('status'=>409,'items'=>$missing));
        }
        return true;
    }

    public static function consume_inventory_for_appointment($appointment_id) {
        global $wpdb; $appointment_id=(int)$appointment_id;
        $event_prefix='appointment:'.$appointment_id.':';
        $exists=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".self::table('inventory_movements')." WHERE event_key LIKE %s",$wpdb->esc_like($event_prefix).'%'));
        if($exists) return true;
        $a=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".self::table('appointments')." WHERE id=%d",$appointment_id),ARRAY_A);
        if(!$a) return new WP_Error('apm_appointment_not_found','Atendimento não encontrado.',array('status'=>404));
        $check=self::validate_inventory_for_service((int)$a['service_id']); if(is_wp_error($check)) return $check;
        $sheet=self::get_service_technical_sheet((int)$a['service_id']); if(!$sheet) return true;
        $items=self::table('inventory_items');
        $wpdb->query('START TRANSACTION');
        foreach($sheet as $row){
            if(empty($row['active'])) continue;
            $qty=(float)$row['quantity'];$item_id=(int)$row['inventory_item_id'];
            $old=(float)$wpdb->get_var($wpdb->prepare("SELECT current_quantity FROM $items WHERE id=%d FOR UPDATE",$item_id));
            if($old+0.00001<$qty){$wpdb->query('ROLLBACK');return new WP_Error('apm_inventory_insufficient','O estoque mudou durante a conclusão. Revise o item '.$row['item_name'].'.',array('status'=>409));}
            $new=max(0,$old-$qty);
            $ok=$wpdb->update($items,array('current_quantity'=>$new,'updated_at'=>current_time('mysql')),array('id'=>$item_id));
            if($ok===false){$wpdb->query('ROLLBACK');return new WP_Error('apm_inventory_update','Não foi possível atualizar o estoque.',array('status'=>500));}
            $event=$event_prefix.'sheet:'.(int)$row['id'];
            $insert=self::insert_inventory_movement($item_id,'service_consumption',-$qty,$new,'Consumo automático do atendimento',$appointment_id,(int)$a['service_id'],(int)$a['professional_id'],(int)$a['client_id'],$event);
            if(!$insert){$wpdb->query('ROLLBACK');return new WP_Error('apm_inventory_movement','Não foi possível registrar a movimentação de estoque.',array('status'=>500));}
            self::maybe_notify_inventory_level($item_id,$old,$new,(float)$row['minimum_quantity']);
        }
        $wpdb->query('COMMIT');
        return true;
    }

    private static function notify_inventory_low_now($item_id) {
        global $wpdb;
        $item=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".self::table('inventory_items')." WHERE id=%d",(int)$item_id),ARRAY_A);
        if(!$item) return;
        $q=(float)$item['current_quantity'];$min=(float)$item['minimum_quantity'];$unit=$item['unit'];
        $empty=$q<=0;
        $title=$empty?'Produto esgotado':'Estoque baixo';
        $message=$empty?$item['name'].' chegou a 0 '.$unit.'. É necessário repor o produto.':$item['name'].' está com '.self::format_inventory_quantity($q).' '.$unit.'. Mínimo configurado: '.self::format_inventory_quantity($min).' '.$unit.'.';
        self::create_notification($empty?'inventory_empty':'inventory_low',$title,$message,'inventory_item',(int)$item_id,class_exists('APM_Router')?APM_Router::page_url('inventory'):'',array('item_id'=>(int)$item_id,'quantity'=>$q,'minimum'=>$min,'unit'=>$unit));
    }

    private static function maybe_notify_inventory_level($item_id,$old,$new,$minimum) {
        global $wpdb;
        $item=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".self::table('inventory_items')." WHERE id=%d",(int)$item_id),ARRAY_A);
        if(!$item) return;
        $unit=$item['unit'];
        $crossed_low=($old>$minimum && $new<=$minimum);
        $crossed_zero=($old>0 && $new<=0);
        if(!$crossed_low&&!$crossed_zero) return;
        $title=$crossed_zero?'Produto esgotado':'Estoque baixo';
        $message=$crossed_zero
            ? $item['name'].' chegou a 0 '.$unit.'. É necessário repor o produto.'
            : $item['name'].' está com '.self::format_inventory_quantity($new).' '.$unit.'. Mínimo configurado: '.self::format_inventory_quantity($minimum).' '.$unit.'.';
        self::create_notification($crossed_zero?'inventory_empty':'inventory_low',$title,$message,'inventory_item',(int)$item_id,class_exists('APM_Router')?APM_Router::page_url('inventory'):'',array('item_id'=>(int)$item_id,'quantity'=>$new,'minimum'=>$minimum,'unit'=>$unit));
    }

    public static function format_inventory_quantity($q) {
        $q=(float)$q; return rtrim(rtrim(number_format($q,3,',','.'),'0'),',');
    }

    public static function inventory_summary() {
        global $wpdb; $t=self::table('inventory_items');
        return array(
            'items'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $t WHERE active=1"),
            'low'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $t WHERE active=1 AND current_quantity<=minimum_quantity"),
            'empty'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $t WHERE active=1 AND current_quantity<=0"),
            'expiring'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE active=1 AND expires_on IS NOT NULL AND expires_on BETWEEN %s AND %s",current_time('Y-m-d'),gmdate('Y-m-d',strtotime(current_time('Y-m-d').' +30 days')))),
        );
    }
}
