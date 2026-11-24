<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables; WP object cache does not apply.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from self::table() are internal constants, not user input; PHPCS cannot statically verify data flow.
if (!defined('ABSPATH')) exit;

trait APM_Repository_Finance_Trait {
    public static function finance_categories($type='') {
        global $wpdb;
        $t=self::table('finance_categories');
        $sql="SELECT * FROM {$t}";$args=array();
        if(in_array($type,array('income','expense'),true)){$sql.=" WHERE type=%s";$args[]=$type;}
        $sql.=" ORDER BY active DESC, name ASC";
        return $args?$wpdb->get_results($wpdb->prepare($sql,$args),ARRAY_A):$wpdb->get_results($sql,ARRAY_A);
    }

    public static function save_finance_category($data,$id=0){
        global $wpdb;$t=self::table('finance_categories');$id=(int)$id;
        $name=sanitize_text_field($data['name']??'');$type=sanitize_key($data['type']??'income');
        if(!$name)return new WP_Error('apm_fin_cat_name','Informe o nome da categoria.',array('status'=>400));
        if(!in_array($type,array('income','expense'),true))return new WP_Error('apm_fin_cat_type','Tipo de categoria inválido.',array('status'=>400));
        $row=array('name'=>$name,'type'=>$type,'active'=>empty($data['active'])?0:1,'updated_at'=>current_time('mysql'));
        if($id){$wpdb->update($t,$row,array('id'=>$id));return $id;}
        $row['created_at']=current_time('mysql');$wpdb->insert($t,$row);return (int)$wpdb->insert_id;
    }

    public static function delete_finance_category($id){
        global $wpdb;$id=(int)$id;$entries=self::table('finance_entries');
        $used=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$entries} WHERE category_id=%d",$id));
        if($used)return new WP_Error('apm_fin_cat_used','Esta categoria está vinculada a lançamentos financeiros e não pode ser excluída. Você pode desativá-la.',array('status'=>400));
        return (bool)$wpdb->delete(self::table('finance_categories'),array('id'=>$id));
    }

    public static function finance_payment_methods(){
        global $wpdb;$t=self::table('finance_payment_methods');
        return $wpdb->get_results("SELECT * FROM {$t} ORDER BY active DESC, name ASC",ARRAY_A);
    }

    public static function save_finance_payment_method($data,$id=0){
        global $wpdb;$t=self::table('finance_payment_methods');$id=(int)$id;$name=sanitize_text_field($data['name']??'');
        if(!$name)return new WP_Error('apm_fin_method_name','Informe o nome da forma de pagamento.',array('status'=>400));
        $row=array('name'=>$name,'active'=>empty($data['active'])?0:1,'updated_at'=>current_time('mysql'));
        if($id){$wpdb->update($t,$row,array('id'=>$id));return $id;}
        $row['created_at']=current_time('mysql');$wpdb->insert($t,$row);return (int)$wpdb->insert_id;
    }

    public static function delete_finance_payment_method($id){
        global $wpdb;$id=(int)$id;$entries=self::table('finance_entries');$tx=self::table('finance_transactions');
        $used=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$entries} WHERE payment_method_id=%d",$id));
        if(!$used)$used=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tx} WHERE payment_method_id=%d",$id));
        if($used)return new WP_Error('apm_fin_method_used','Esta forma de pagamento está vinculada a lançamentos e não pode ser excluída. Você pode desativá-la.',array('status'=>400));
        return (bool)$wpdb->delete(self::table('finance_payment_methods'),array('id'=>$id));
    }


    // Etapa 4 — Agenda/Atendimento -> Financeiro.
    // Os helpers abaixo mantêm uma única cobrança automática por agendamento e
    // registram os pagamentos aprovados de forma idempotente.
    private static function finance_auto_category_id(){
        global $wpdb;$t=self::table('finance_categories');$name='Atendimentos e serviços';
        $id=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$t} WHERE name=%s AND type='income' ORDER BY id ASC LIMIT 1",$name));
        if($id)return $id;
        $now=current_time('mysql');$wpdb->insert($t,array('name'=>$name,'type'=>'income','active'=>1,'created_at'=>$now,'updated_at'=>$now));return (int)$wpdb->insert_id;
    }

    private static function finance_auto_payment_method_id($provider='',$payment_type='',$payment_method=''){
        global $wpdb;$t=self::table('finance_payment_methods');$provider=sanitize_key($provider);$payment_type=sanitize_key($payment_type);$payment_method=sanitize_key($payment_method);
        if($provider==='infinitepay'){
            if($payment_method==='pix')$name='PIX InfinitePay';
            elseif($payment_method==='credit_card')$name='Cartão de crédito InfinitePay';
            else $name='InfinitePay';
        }else $name=$provider==='test'?'Pagamento de teste':'Pagamento online';
        $id=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$t} WHERE name=%s ORDER BY id ASC LIMIT 1",$name));
        if($id)return $id;
        $now=current_time('mysql');$wpdb->insert($t,array('name'=>$name,'active'=>1,'created_at'=>$now,'updated_at'=>$now));return (int)$wpdb->insert_id;
    }

    public static function finance_entry_for_appointment($appointment_id){
        global $wpdb;$appointment_id=(int)$appointment_id;if(!$appointment_id)return null;$e=self::table('finance_entries');
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$e} WHERE source_key=%s LIMIT 1",'appointment:'.$appointment_id),ARRAY_A);
    }

    public static function sync_appointment_finance($appointment_id){
        global $wpdb;$appointment_id=(int)$appointment_id;if(!$appointment_id)return false;
        $a=self::table('appointments');$s=self::table('services');$e=self::table('finance_entries');
        $appointment=$wpdb->get_row($wpdb->prepare("SELECT a.*,s.name service_name FROM {$a} a LEFT JOIN {$s} s ON s.id=a.service_id WHERE a.id=%d",$appointment_id),ARRAY_A);
        if(!$appointment)return false;
        $source_key='appointment:'.$appointment_id;$entry=self::finance_entry_for_appointment($appointment_id);
        $inactive=in_array($appointment['status'],array('cancelled','no_show','payment_expired'),true);
        $amount=round((float)$appointment['total_amount'],2);
        if($inactive||$amount<=0){
            if($entry){$wpdb->update($e,array('status'=>'cancelled','updated_at'=>current_time('mysql')),array('id'=>(int)$entry['id']));}
            return $entry?(int)$entry['id']:false;
        }
        $due=substr((string)$appointment['start_at'],0,10);if(!$due)$due=current_time('Y-m-d');$now=current_time('mysql');
        $description='Atendimento — '.($appointment['service_name']?:'Serviço');
        $row=array(
            'type'=>'receivable','description'=>$description,'category_id'=>self::finance_auto_category_id(),'client_id'=>(int)$appointment['client_id'],'service_id'=>(int)$appointment['service_id'],'appointment_id'=>$appointment_id,
            'base_amount'=>$amount,'discount_amount'=>0,'surcharge_amount'=>0,'amount'=>$amount,'due_date'=>$due,'installment_group'=>null,'installment_number'=>1,'installment_count'=>1,
            'recurrence_group'=>null,'recurrence_frequency'=>null,'recurrence_number'=>1,'recurrence_count'=>1,'source'=>'appointment','source_key'=>$source_key,
            'notes'=>'Gerado automaticamente a partir da Agenda.','updated_at'=>$now
        );
        if($entry){
            // Se o lançamento havia sido cancelado e o agendamento voltou a ficar ativo, reabre-o.
            $wpdb->update($e,$row,array('id'=>(int)$entry['id']));
            self::recalc_finance_receivable((int)$entry['id']);
            return (int)$entry['id'];
        }
        $row['paid_amount']=0;$row['paid_at']=null;$row['status']='pending';$row['payment_method_id']=null;$row['created_by']=get_current_user_id()?:null;$row['created_at']=$now;
        $ok=$wpdb->insert($e,$row);if(!$ok){$existing=self::finance_entry_for_appointment($appointment_id);return $existing?(int)$existing['id']:false;}
        return (int)$wpdb->insert_id;
    }

    public static function cancel_appointment_finance($appointment_id,$reason=''){
        global $wpdb;$entry=self::finance_entry_for_appointment((int)$appointment_id);if(!$entry)return false;
        $notes=(string)($entry['notes']??'');if($reason){$line='Cobrança automática encerrada: '.sanitize_text_field($reason);if(strpos($notes,$line)===false)$notes=trim($notes."
".$line);}
        return (bool)$wpdb->update(self::table('finance_entries'),array('status'=>'cancelled','notes'=>$notes,'updated_at'=>current_time('mysql')),array('id'=>(int)$entry['id']));
    }

    public static function sync_appointment_payment_to_finance($appointment_id,$payment_record_id=0){
        global $wpdb;$appointment_id=(int)$appointment_id;$payment_record_id=(int)$payment_record_id;if(!$appointment_id)return false;
        $entry_id=self::sync_appointment_finance($appointment_id);if(!$entry_id)return false;
        $p=self::table('payments');$t=self::table('finance_transactions');$e=self::table('finance_entries');
        if($payment_record_id){$payments=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$p} WHERE id=%d AND appointment_id=%d",$payment_record_id,$appointment_id),ARRAY_A);}
        else{$payments=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$p} WHERE appointment_id=%d AND status IN ('approved','refunded','charged_back') ORDER BY id ASC",$appointment_id),ARRAY_A);}
        foreach($payments as $pay){
            $status=sanitize_key($pay['status']??'');$kind=$status==='approved'?'receipt':(in_array($status,array('refunded','charged_back'),true)?'refund':'');if(!$kind)continue;
            $event='appointment_payment:'.(int)$pay['id'].':'.($kind==='receipt'?'approved':'refund');
            if((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE source_key=%s",$event)))continue;
            $entry=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$e} WHERE id=%d",$entry_id),ARRAY_A);if(!$entry)continue;
            $amount=round((float)$pay['amount'],2);if($amount<=0)continue;
            $paid=self::finance_entry_paid_amount($entry);$balance=max(0,round((float)$entry['amount']-$paid,2));
            if($kind==='receipt')$amount=min($amount,$balance);else $amount=min($amount,$paid);
            if($amount<=0.001)continue;
            $method=self::finance_auto_payment_method_id($pay['provider']??'',$pay['payment_type']??'',$pay['payment_method']??'');$date=substr((string)($pay['approved_at']?:($pay['updated_at']?:$pay['created_at'])),0,10)?:current_time('Y-m-d');
            $details=array();if(!empty($pay['provider_payment_id']))$details[]='MP #'.sanitize_text_field($pay['provider_payment_id']);if(!empty($pay['payment_method']))$details[]='meio: '.sanitize_text_field($pay['payment_method']);if(!empty($pay['status_detail']))$details[]='status: '.sanitize_text_field($pay['status_detail']);
            $note=$kind==='receipt'?'Sinal recebido automaticamente pelo agendamento online.':'Estorno do sinal sincronizado automaticamente.';if($details)$note.=' '.implode(' · ',$details);
            $wpdb->insert($t,array('entry_id'=>$entry_id,'kind'=>$kind,'amount'=>$amount,'payment_method_id'=>$method,'transaction_date'=>$date,'notes'=>$note,'source'=>'appointment_payment','source_key'=>$event,'created_by'=>null,'created_at'=>current_time('mysql')));
            self::recalc_finance_receivable($entry_id);
        }
        return $entry_id;
    }

    public static function backfill_appointment_finance(){
        global $wpdb;$a=self::table('appointments');$p=self::table('payments');
        $ids=$wpdb->get_col("SELECT id FROM {$a} WHERE status NOT IN ('cancelled','no_show','payment_expired') ORDER BY id ASC");
        foreach($ids as $id){self::sync_appointment_finance((int)$id);self::sync_appointment_payment_to_finance((int)$id);}
        update_option('apm_finance_agenda_backfill','0.9.12',false);return count($ids);
    }

    private static function finance_entry_paid_amount($row){
        $paid=isset($row['paid_amount'])?(float)$row['paid_amount']:0;
        if($paid<=0 && ($row['status']??'')==='paid')$paid=(float)($row['amount']??0); // compatibilidade com lançamentos da etapa 1
        return max(0,$paid);
    }

    public static function finance_entries($args=array()){
        global $wpdb;
        $e=self::table('finance_entries');$c=self::table('finance_categories');$m=self::table('finance_payment_methods');$cl=self::table('clients');$s=self::table('services');$a=self::table('appointments');
        $where=array('1=1');$params=array();
        $type=sanitize_key($args['type']??'');if(in_array($type,array('receivable','payable'),true)){$where[]='e.type=%s';$params[]=$type;}
        $status=sanitize_key($args['status']??'');
        if(in_array($status,array('pending','partial','paid','cancelled'),true)){$where[]='e.status=%s';$params[]=$status;}
        elseif($status==='overdue'){$where[]="e.status IN ('pending','partial') AND e.due_date < %s";$params[]=current_time('Y-m-d');}
        $start=self::date_or_null($args['start']??'');if($start){$where[]='e.due_date >= %s';$params[]=$start;}
        $end=self::date_or_null($args['end']??'');if($end){$where[]='e.due_date <= %s';$params[]=$end;}
        $search=sanitize_text_field($args['search']??'');if($search!==''){$like='%'.$wpdb->esc_like($search).'%';$where[]='(e.description LIKE %s OR c.name LIKE %s OR cl.name LIKE %s OR s.name LIKE %s)';array_push($params,$like,$like,$like,$like);}
        $sql="SELECT e.*, c.name category_name, c.type category_type, m.name payment_method_name, cl.name client_name, s.name service_name,
            a.start_at appointment_start,
            CASE WHEN e.status IN ('pending','partial') AND e.due_date < CURDATE() THEN 'overdue' ELSE e.status END display_status
            FROM {$e} e
            LEFT JOIN {$c} c ON c.id=e.category_id LEFT JOIN {$m} m ON m.id=e.payment_method_id LEFT JOIN {$cl} cl ON cl.id=e.client_id
            LEFT JOIN {$s} s ON s.id=e.service_id LEFT JOIN {$a} a ON a.id=e.appointment_id
            WHERE ".implode(' AND ',$where)." ORDER BY e.due_date DESC, e.id DESC";
        if($params)$sql=$wpdb->prepare($sql,$params);
        $rows=$wpdb->get_results($sql,ARRAY_A);
        foreach($rows as &$r){
            $paid=self::finance_entry_paid_amount($r);$amount=(float)$r['amount'];
            $r['paid_amount']=$paid;$r['balance_amount']=max(0,round($amount-$paid,2));
        }
        return $rows;
    }

    private static function finance_installment_due($base,$offset){
        $dt=new DateTime($base);$day=(int)$dt->format('d');$dt->modify('first day of +'.(int)$offset.' month');$last=(int)$dt->format('t');$dt->setDate((int)$dt->format('Y'),(int)$dt->format('m'),min($day,$last));return $dt->format('Y-m-d');
    }

    private static function finance_recurrence_due($base,$frequency,$offset){
        $offset=max(0,(int)$offset);
        if($offset===0)return $base;
        if($frequency==='weekly')return gmdate('Y-m-d',strtotime($base.' +'.($offset*7).' days'));
        if($frequency==='biweekly')return gmdate('Y-m-d',strtotime($base.' +'.($offset*14).' days'));
        if($frequency==='yearly'){
            $dt=new DateTime($base);$month=(int)$dt->format('m');$day=(int)$dt->format('d');$year=(int)$dt->format('Y')+$offset;$last=(int)(new DateTime(sprintf('%04d-%02d-01',$year,$month)))->format('t');$dt->setDate($year,$month,min($day,$last));return $dt->format('Y-m-d');
        }
        return self::finance_installment_due($base,$offset);
    }

    public static function save_finance_entry($data,$id=0){
        global $wpdb;$t=self::table('finance_entries');$id=(int)$id;
        $type=sanitize_key($data['type']??'receivable');$description=sanitize_text_field($data['description']??'');$due=self::date_or_null($data['due_date']??'');
        if(!in_array($type,array('receivable','payable'),true))return new WP_Error('apm_fin_type','Tipo de lançamento inválido.',array('status'=>400));
        if(!$description)return new WP_Error('apm_fin_desc','Informe a descrição.',array('status'=>400));
        if(!$due)return new WP_Error('apm_fin_due','Informe a data de vencimento.',array('status'=>400));

        $base=self::decimal($data['base_amount']??($data['amount']??0));$discount=max(0,self::decimal($data['discount_amount']??0));$surcharge=max(0,self::decimal($data['surcharge_amount']??0));
        if($type==='payable'){$discount=0;$surcharge=0;}
        $amount=round($base-$discount+$surcharge,2);
        if($base<=0||$amount<=0)return new WP_Error('apm_fin_amount','Informe um valor maior que zero.',array('status'=>400));
        if($discount>$base+$surcharge)return new WP_Error('apm_fin_discount','O desconto não pode tornar o valor final negativo.',array('status'=>400));

        $status=sanitize_key($data['status']??'pending');if(!in_array($status,array('pending','partial','paid','cancelled'),true))$status='pending';
        $existing=$id?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id=%d",$id),ARRAY_A):null;
        if($existing&&($existing['source']??'manual')==='appointment')return new WP_Error('apm_fin_auto_edit','Este lançamento é atualizado automaticamente pela Agenda. Altere o agendamento para modificar cliente, serviço, valor ou vencimento.',array('status'=>400));
        $paid_amount=$existing?self::finance_entry_paid_amount($existing):0;
        if($type==='receivable'){
            if($amount+0.001<$paid_amount)return new WP_Error('apm_fin_amount_paid','O valor final não pode ser menor que o total já recebido.',array('status'=>400));
            if($status!=='cancelled')$status=$paid_amount<=0?'pending':($paid_amount+0.001>=$amount?'paid':'partial');
        }
        $paid_at=null;
        if($type==='payable'&&$status==='paid'){$paid_at=self::date_or_null($data['paid_at']??'')?:current_time('Y-m-d');$paid_amount=$amount;}
        elseif($type==='payable')$paid_amount=0;
        elseif($status==='paid')$paid_at=$existing['paid_at']??current_time('Y-m-d');

        $row=array(
            'type'=>$type,'description'=>$description,'category_id'=>!empty($data['category_id'])?(int)$data['category_id']:null,'payment_method_id'=>!empty($data['payment_method_id'])?(int)$data['payment_method_id']:null,
            'client_id'=>($type==='receivable'&&!empty($data['client_id']))?(int)$data['client_id']:null,'service_id'=>($type==='receivable'&&!empty($data['service_id']))?(int)$data['service_id']:null,'appointment_id'=>($type==='receivable'&&!empty($data['appointment_id']))?(int)$data['appointment_id']:null,
            'base_amount'=>$base,'discount_amount'=>$discount,'surcharge_amount'=>$surcharge,'amount'=>$amount,'paid_amount'=>$paid_amount,'due_date'=>$due,'paid_at'=>$paid_at,'status'=>$status,
            'recurrence_group'=>$existing['recurrence_group']??null,'recurrence_frequency'=>$existing['recurrence_frequency']??null,'recurrence_number'=>$existing['recurrence_number']??1,'recurrence_count'=>$existing['recurrence_count']??1,
            'notes'=>sanitize_textarea_field($data['notes']??''),'source'=>$existing['source']??'manual','source_key'=>$existing['source_key']??null,'updated_at'=>current_time('mysql')
        );
        if($id){$wpdb->update($t,$row,array('id'=>$id));return $id;}

        $now=current_time('mysql');$ids=array();

        // Etapa 3: contas a pagar podem ser únicas ou recorrentes. As ocorrências são materializadas
        // no banco para manter vencimentos, pagamento e histórico independentes por competência.
        if($type==='payable'){
            $recurrence_mode=sanitize_key($data['recurrence_mode']??'single');
            $frequency='';$count=1;
            if($recurrence_mode==='monthly'){$frequency='monthly';$count=max(2,min(60,(int)($data['recurrence_count']??12)));}
            elseif($recurrence_mode==='recurring'){
                $frequency=sanitize_key($data['recurrence_frequency']??'monthly');
                if(!in_array($frequency,array('weekly','biweekly','monthly','yearly'),true))$frequency='monthly';
                $count=max(2,min(60,(int)($data['recurrence_count']??12)));
            }
            if($count>1){
                $group=wp_generate_uuid4();
                for($i=1;$i<=$count;$i++){
                    $rr=$row;$rr['due_date']=self::finance_recurrence_due($due,$frequency,$i-1);$rr['recurrence_group']=$group;$rr['recurrence_frequency']=$frequency;$rr['recurrence_number']=$i;$rr['recurrence_count']=$count;
                    // Somente a primeira ocorrência pode nascer paga. As futuras permanecem pendentes.
                    if($i>1){$rr['status']='pending';$rr['paid_at']=null;$rr['paid_amount']=0;}
                    $rr['installment_group']=null;$rr['installment_number']=1;$rr['installment_count']=1;$rr['created_by']=get_current_user_id();$rr['created_at']=$now;
                    $wpdb->insert($t,$rr);$ids[]=(int)$wpdb->insert_id;
                }
                return $ids;
            }
            $row['recurrence_group']=null;$row['recurrence_frequency']=null;$row['recurrence_number']=1;$row['recurrence_count']=1;$row['installment_group']=null;$row['installment_number']=1;$row['installment_count']=1;$row['created_by']=get_current_user_id();$row['created_at']=$now;$wpdb->insert($t,$row);return (int)$wpdb->insert_id;
        }

        $installments=max(1,min(24,(int)($data['installment_count']??1)));
        $group=$installments>1?wp_generate_uuid4():null;
        if($installments===1){
            $row['installment_group']=$group;$row['installment_number']=1;$row['installment_count']=1;$row['created_by']=get_current_user_id();$row['created_at']=$now;$wpdb->insert($t,$row);return (int)$wpdb->insert_id;
        }
        $cent_total=(int)round($amount*100);$cent_base=intdiv($cent_total,$installments);$remainder=$cent_total-($cent_base*$installments);
        for($i=1;$i<=$installments;$i++){
            $part_cents=$cent_base+($i<=$remainder?1:0);$part=$part_cents/100;
            $ir=$row;$ir['description']=$description.' ('.$i.'/'.$installments.')';$ir['base_amount']=$part;$ir['discount_amount']=0;$ir['surcharge_amount']=0;$ir['amount']=$part;$ir['paid_amount']=0;$ir['due_date']=self::finance_installment_due($due,$i-1);$ir['installment_group']=$group;$ir['installment_number']=$i;$ir['installment_count']=$installments;$ir['created_by']=get_current_user_id();$ir['created_at']=$now;
            $wpdb->insert($t,$ir);$ids[]=(int)$wpdb->insert_id;
        }
        return $ids;
    }

    public static function delete_finance_entry($id){
        global $wpdb;$id=(int)$id;$tx=self::table('finance_transactions');$e=self::table('finance_entries');
        $source=(string)$wpdb->get_var($wpdb->prepare("SELECT source FROM {$e} WHERE id=%d",$id));
        if($source==='appointment')return new WP_Error('apm_fin_auto_entry','Este lançamento é controlado automaticamente pela Agenda. Cancele ou altere o agendamento para atualizar a cobrança.',array('status'=>400));
        $has=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tx} WHERE entry_id=%d",$id));
        if($has)return new WP_Error('apm_fin_entry_tx','Este lançamento possui recebimentos/estornos registrados. Cancele o lançamento em vez de excluí-lo.',array('status'=>400));
        return (bool)$wpdb->delete(self::table('finance_entries'),array('id'=>$id));
    }

    public static function finance_transactions($entry_id){
        global $wpdb;$t=self::table('finance_transactions');$m=self::table('finance_payment_methods');
        return $wpdb->get_results($wpdb->prepare("SELECT t.*,m.name payment_method_name FROM {$t} t LEFT JOIN {$m} m ON m.id=t.payment_method_id WHERE t.entry_id=%d ORDER BY t.transaction_date DESC,t.id DESC",(int)$entry_id),ARRAY_A);
    }

    private static function recalc_finance_receivable($entry_id){
        global $wpdb;$e=self::table('finance_entries');$t=self::table('finance_transactions');$entry=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$e} WHERE id=%d",(int)$entry_id),ARRAY_A);if(!$entry)return false;
        $received=(float)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(CASE WHEN kind='receipt' THEN amount ELSE -amount END),0) FROM {$t} WHERE entry_id=%d",(int)$entry_id));$received=max(0,round($received,2));$amount=(float)$entry['amount'];
        if($entry['status']==='cancelled')$status='cancelled';else $status=$received<=0?'pending':($received+0.001>=$amount?'paid':'partial');
        $paid_at=$status==='paid'?(string)$wpdb->get_var($wpdb->prepare("SELECT MAX(transaction_date) FROM {$t} WHERE entry_id=%d AND kind='receipt'",(int)$entry_id)):null;
        $wpdb->update($e,array('paid_amount'=>$received,'status'=>$status,'paid_at'=>$paid_at,'updated_at'=>current_time('mysql')),array('id'=>(int)$entry_id));return true;
    }

    public static function save_finance_transaction($entry_id,$data){
        global $wpdb;$e=self::table('finance_entries');$t=self::table('finance_transactions');$entry_id=(int)$entry_id;
        $entry=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$e} WHERE id=%d",$entry_id),ARRAY_A);
        if(!$entry||$entry['type']!=='receivable')return new WP_Error('apm_fin_tx_entry','Conta a receber não encontrada.',array('status'=>404));
        if($entry['status']==='cancelled')return new WP_Error('apm_fin_tx_cancelled','Não é possível movimentar uma conta cancelada.',array('status'=>400));
        $kind=sanitize_key($data['kind']??'receipt');if(!in_array($kind,array('receipt','refund'),true))$kind='receipt';$amount=self::decimal($data['amount']??0);$date=self::date_or_null($data['transaction_date']??'')?:current_time('Y-m-d');
        if($amount<=0)return new WP_Error('apm_fin_tx_amount','Informe um valor maior que zero.',array('status'=>400));
        // Converte silenciosamente um lançamento pago da etapa 1 em histórico da etapa 2 antes da primeira movimentação.
        $tx_count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE entry_id=%d",$entry_id));
        if(!$tx_count && $entry['status']==='paid' && (float)$entry['paid_amount']<=0){
            $wpdb->insert($t,array('entry_id'=>$entry_id,'kind'=>'receipt','amount'=>(float)$entry['amount'],'payment_method_id'=>!empty($entry['payment_method_id'])?(int)$entry['payment_method_id']:null,'transaction_date'=>$entry['paid_at']?:current_time('Y-m-d'),'notes'=>'Pagamento migrado da etapa 1 do Financeiro','source'=>'migration','source_key'=>null,'created_by'=>get_current_user_id(),'created_at'=>current_time('mysql')));
            $entry['paid_amount']=$entry['amount'];
        }
        $paid=self::finance_entry_paid_amount($entry);$balance=max(0,(float)$entry['amount']-$paid);
        if($kind==='receipt'&&$amount>$balance+0.001)return new WP_Error('apm_fin_tx_balance','O recebimento não pode ser maior que o saldo pendente.',array('status'=>400));
        if($kind==='refund'&&$amount>$paid+0.001)return new WP_Error('apm_fin_tx_refund','O estorno não pode ser maior que o valor já recebido.',array('status'=>400));
        $wpdb->insert($t,array('entry_id'=>$entry_id,'kind'=>$kind,'amount'=>$amount,'payment_method_id'=>!empty($data['payment_method_id'])?(int)$data['payment_method_id']:null,'transaction_date'=>$date,'notes'=>sanitize_textarea_field($data['notes']??''),'source'=>'manual','source_key'=>null,'created_by'=>get_current_user_id(),'created_at'=>current_time('mysql')));
        self::recalc_finance_receivable($entry_id);return (int)$wpdb->insert_id;
    }

    public static function delete_finance_transaction($entry_id,$tx_id){
        global $wpdb;$t=self::table('finance_transactions');$source=(string)$wpdb->get_var($wpdb->prepare("SELECT source FROM {$t} WHERE id=%d AND entry_id=%d",(int)$tx_id,(int)$entry_id));
        if($source==='appointment_payment')return new WP_Error('apm_fin_auto_tx','Este recebimento foi sincronizado automaticamente com o pagamento do agendamento e não pode ser excluído manualmente.',array('status'=>400));
        $ok=$wpdb->delete($t,array('id'=>(int)$tx_id,'entry_id'=>(int)$entry_id));if($ok)self::recalc_finance_receivable((int)$entry_id);return (bool)$ok;
    }

    public static function finance_summary(){
        global $wpdb;$e=self::table('finance_entries');$tx=self::table('finance_transactions');$today=current_time('Y-m-d');$month_start=current_time('Y-m-01');$month_end=gmdate('Y-m-t',strtotime($today));
        $receivable=(float)$wpdb->get_var("SELECT COALESCE(SUM(GREATEST(amount-paid_amount,0)),0) FROM {$e} WHERE type='receivable' AND status IN ('pending','partial')");
        $payable=(float)$wpdb->get_var("SELECT COALESCE(SUM(amount),0) FROM {$e} WHERE type='payable' AND status='pending'");
        $received=(float)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(CASE WHEN t.kind='receipt' THEN t.amount ELSE -t.amount END),0) FROM {$tx} t WHERE t.transaction_date BETWEEN %s AND %s",$month_start,$month_end));
        $legacy=(float)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(e.amount),0) FROM {$e} e WHERE e.type='receivable' AND e.status='paid' AND e.paid_at BETWEEN %s AND %s AND NOT EXISTS(SELECT 1 FROM {$tx} t WHERE t.entry_id=e.id)",$month_start,$month_end));$received+=$legacy;
        $paid=(float)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(amount),0) FROM {$e} WHERE type='payable' AND status='paid' AND paid_at BETWEEN %s AND %s",$month_start,$month_end));
        $overdue=(float)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(CASE WHEN type='receivable' THEN GREATEST(amount-paid_amount,0) ELSE amount END),0) FROM {$e} WHERE status IN ('pending','partial') AND due_date < %s",$today));
        return array('balance'=>$received-$paid,'receivable'=>$receivable,'payable'=>$payable,'received_month'=>$received,'paid_month'=>$paid,'overdue'=>$overdue);
    }

    public static function finance_reference_data(){
        global $wpdb;$s=self::table('services');$a=self::table('appointments');$c=self::table('clients');$p=self::table('professionals');
        $services=$wpdb->get_results("SELECT id,name,price,active FROM {$s} ORDER BY active DESC,name ASC",ARRAY_A);
        $appointments=$wpdb->get_results("SELECT a.id,a.client_id,a.service_id,a.total_amount,a.deposit_amount,a.payment_status,a.start_at,a.status,c.name client_name,s.name service_name,p.name professional_name FROM {$a} a LEFT JOIN {$c} c ON c.id=a.client_id LEFT JOIN {$s} s ON s.id=a.service_id LEFT JOIN {$p} p ON p.id=a.professional_id WHERE a.start_at >= DATE_SUB(NOW(),INTERVAL 365 DAY) ORDER BY a.start_at DESC LIMIT 500",ARRAY_A);
        return array('services'=>$services,'appointments'=>$appointments);
    }

    public static function finance_payload($args=array()){
        if(get_option('apm_finance_agenda_backfill')!=='0.9.12')self::backfill_appointment_finance();
        $refs=self::finance_reference_data();
        return array('summary'=>self::finance_summary(),'entries'=>self::finance_entries($args),'categories'=>self::finance_categories(),'paymentMethods'=>self::finance_payment_methods(),'services'=>$refs['services'],'appointments'=>$refs['appointments']);
    }
}
