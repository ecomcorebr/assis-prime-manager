<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables; WP object cache does not apply.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names from self::table() are internal constants, not user input; PHPCS cannot statically verify data flow.
if (!defined('ABSPATH')) exit;

trait APM_Repository_Agenda_Trait {
    public static function list_appointments($start='', $end='', $professional_id=0) {
        global $wpdb;
        $a=self::table('appointments'); $c=self::table('clients'); $p=self::table('professionals'); $s=self::table('services');
        $where=array('1=1'); $args=array();
        if($start){ $where[]='a.start_at >= %s'; $args[]=sanitize_text_field($start).' 00:00:00'; }
        if($end){ $where[]='a.start_at < %s'; $args[]=sanitize_text_field($end).' 23:59:59'; }
        if($professional_id){ $where[]='a.professional_id = %d'; $args[]=(int)$professional_id; }
        $sql="SELECT a.*, c.name client_name, c.phone client_phone, p.name professional_name, p.color professional_color, s.name service_name, s.duration_minutes FROM $a a LEFT JOIN $c c ON c.id=a.client_id LEFT JOIN $p p ON p.id=a.professional_id LEFT JOIN $s s ON s.id=a.service_id WHERE ".implode(' AND ',$where)." ORDER BY a.start_at ASC";
        if($args) $sql=$wpdb->prepare($sql,$args);
        return $wpdb->get_results($sql,ARRAY_A);
    }

    public static function search_appointments($query, $limit=40) {
        global $wpdb;
        $query = trim(sanitize_text_field($query));
        if (strlen($query) < 2) return array();
        $limit = max(1, min(60, (int)$limit));
        $a=self::table('appointments'); $c=self::table('clients'); $p=self::table('professionals'); $s=self::table('services');
        $like='%'.$wpdb->esc_like($query).'%';
        $sql=$wpdb->prepare(
            "SELECT a.*, c.name client_name, c.phone client_phone, p.name professional_name, p.color professional_color, s.name service_name, s.duration_minutes
             FROM $a a
             LEFT JOIN $c c ON c.id=a.client_id
             LEFT JOIN $p p ON p.id=a.professional_id
             LEFT JOIN $s s ON s.id=a.service_id
             WHERE (c.name LIKE %s OR c.phone LIKE %s OR c.email LIKE %s OR s.name LIKE %s OR p.name LIKE %s)
             ORDER BY CASE WHEN a.start_at >= %s THEN 0 ELSE 1 END ASC,
                      CASE WHEN a.start_at >= %s THEN a.start_at END ASC,
                      CASE WHEN a.start_at < %s THEN a.start_at END DESC
             LIMIT %d",
            $like,$like,$like,$like,$like,current_time('mysql'),current_time('mysql'),current_time('mysql'),$limit
        );
        return $wpdb->get_results($sql, ARRAY_A);
    }

    public static function list_client_appointments($client_id) {
        global $wpdb;
        $client_id=(int)$client_id;
        if(!$client_id) return array();
        $a=self::table('appointments'); $p=self::table('professionals'); $s=self::table('services');
        return $wpdb->get_results($wpdb->prepare("SELECT a.id,a.start_at,a.end_at,a.status,a.source,a.total_amount,a.deposit_amount,a.payment_status,s.name service_name,p.name professional_name FROM $a a LEFT JOIN $s s ON s.id=a.service_id LEFT JOIN $p p ON p.id=a.professional_id WHERE a.client_id=%d ORDER BY a.start_at DESC LIMIT 100",$client_id),ARRAY_A);
    }

    public static function save_appointment($data,$id=0) {
        global $wpdb;
        $client_id=(int)($data['client_id']??0); $professional_id=(int)($data['professional_id']??0); $service_id=(int)($data['service_id']??0);
        $previous=$id?$wpdb->get_row($wpdb->prepare("SELECT * FROM ".self::table('appointments')." WHERE id=%d",(int)$id),ARRAY_A):null;
        if(!$client_id||!$professional_id||!$service_id) return new WP_Error('apm_required','Selecione cliente, profissional e serviço.',array('status'=>400));
        $date=self::date_or_null($data['date']??''); $time=self::time($data['start_time']??'');
        if(!$date) return new WP_Error('apm_date','Informe uma data válida.',array('status'=>400));
        $service=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".self::table('services')." WHERE id=%d",$service_id),ARRAY_A);
        if(!$service) return new WP_Error('apm_service','Serviço não encontrado.',array('status'=>404));
        $professional=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".self::table('professionals')." WHERE id=%d AND active=1",$professional_id),ARRAY_A);
        if(!$professional) return new WP_Error('apm_professional','Profissional não encontrado ou inativo.',array('status'=>404));
        // Vínculo obrigatório: um profissional só atende o serviço se estiver marcado em
        // "Serviços realizados" no cadastro dele. Antes, um profissional sem nenhum serviço
        // marcado era tratado como "atende tudo", o que fazia qualquer profissional aparecer
        // disponível para qualquer procedimento até alguém configurar algo nele.
        if(!(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".self::table('professional_services')." WHERE professional_id=%d AND service_id=%d",$professional_id,$service_id))) return new WP_Error('apm_service_professional','Este profissional não está vinculado ao serviço selecionado.',array('status'=>400));
        $start_at=$date.' '.$time.':00';
        $duration=max(5,(int)$service['duration_minutes']);
        $end_at=gmdate('Y-m-d H:i:s',strtotime($start_at.' +'.$duration.' minutes'));
        $status=in_array(($data['status']??'scheduled'),array('pending_payment','payment_expired','scheduled','confirmed','completed','cancelled','no_show'),true)?$data['status']:'scheduled';
        $needs_inventory=($status==='completed' && (!$previous || ($previous['status']??'')!=='completed'));
        if($needs_inventory){$inventory_check=self::validate_inventory_for_service($service_id);if(is_wp_error($inventory_check))return $inventory_check;}
        if(!in_array($status,array('cancelled','no_show'),true)){
            // O intervalo de buffer (tempo de preparo após o atendimento, configurado por serviço)
            // soma-se ao horário final dos agendamentos já existentes antes de checar sobreposição,
            // para que o próximo horário só fique livre depois desse intervalo.
            $conflict=$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".self::table('appointments')." a LEFT JOIN ".self::table('services')." s ON s.id=a.service_id WHERE a.professional_id=%d AND a.id<>%d AND a.status NOT IN ('cancelled','no_show','payment_expired') AND a.start_at < %s AND DATE_ADD(a.end_at, INTERVAL COALESCE(s.buffer_minutes,0) MINUTE) > %s",$professional_id,(int)$id,$end_at,$start_at));
            if($conflict) return new WP_Error('apm_conflict','Já existe um atendimento para este profissional neste horário.',array('status'=>409));
            $block=$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".self::table('schedule_blocks')." WHERE (professional_id=0 OR professional_id=%d) AND start_at < %s AND end_at > %s",$professional_id,$end_at,$start_at));
            if($block) return new WP_Error('apm_blocked','O horário selecionado está bloqueado.',array('status'=>409));
        }
        $price=(float)$service['price']; $deposit=(float)$service['deposit_value'];
        $deposit_amount=$service['deposit_type']==='fixed'?$deposit:round($price*($deposit/100),2);
        $now=current_time('mysql');
        $row=array('client_id'=>$client_id,'professional_id'=>$professional_id,'service_id'=>$service_id,'start_at'=>$start_at,'end_at'=>$end_at,'status'=>$status,'source'=>'internal','notes'=>sanitize_textarea_field($data['notes']??''),'total_amount'=>$price,'deposit_amount'=>$deposit_amount,'updated_at'=>$now);
        if($id){
            $wpdb->update(self::table('appointments'),$row,array('id'=>(int)$id));
            if($needs_inventory){
                $consume=self::consume_inventory_for_appointment((int)$id);
                if(is_wp_error($consume)){
                    if($previous) $wpdb->update(self::table('appointments'),array('status'=>$previous['status'],'updated_at'=>current_time('mysql')),array('id'=>(int)$id));
                    return $consume;
                }
            }
            self::sync_appointment_finance((int)$id);
            if($status==='scheduled'||$status==='pending_payment') self::sync_lead_status_for_client($client_id,'scheduled'); elseif($status==='no_show') self::sync_lead_status_for_client($client_id,'no_show'); elseif(in_array($status,array('confirmed','completed'),true)) self::maybe_convert_lead_for_client($client_id,$status==='completed'?'appointment_completed':'booking_confirmed');
            // Agendamentos criados/editados direto no painel (fora do fluxo de agendamento
            // online) nunca avisavam a cliente de nada. Ambos os envios já são idempotentes
            // (cada um só dispara uma vez por agendamento, controlado por *_sent_at), então é
            // seguro chamar sempre que o status ficar "agendado"/"confirmado" — MAS só quando o
            // sinal não está mais pendente: o texto da confirmação diz "sinal pago", então
            // mandá-lo com o sinal ainda em aberto mente para a cliente e confunde (ela recebe
            // "tudo certo, sinal pago" e, logo em seguida, o link pedindo para pagar o sinal).
            $owes_deposit=$deposit_amount>0&&($previous['payment_status']??'pending')!=='paid';
            if(in_array($status,array('scheduled','confirmed'),true)&&!$owes_deposit){self::safe_send_booking_confirmation((int)$id);self::safe_send_booking_confirmation_email((int)$id);self::safe_create_anamnesis_for_appointment((int)$id);}
            return (int)$id;
        }
        $row['payment_status']='pending'; $row['created_by']=get_current_user_id(); $row['created_at']=$now;
        $wpdb->insert(self::table('appointments'),$row); $new_id=(int)$wpdb->insert_id;
        if($needs_inventory){$consume=self::consume_inventory_for_appointment($new_id);if(is_wp_error($consume)){$wpdb->delete(self::table('appointments'),array('id'=>$new_id));return $consume;}}
        self::sync_appointment_finance($new_id);
        if($status==='scheduled'||$status==='pending_payment') self::sync_lead_status_for_client($client_id,'scheduled'); elseif($status==='no_show') self::sync_lead_status_for_client($client_id,'no_show'); elseif(in_array($status,array('confirmed','completed'),true)) self::maybe_convert_lead_for_client($client_id,$status==='completed'?'appointment_completed':'booking_confirmed');
        // Um agendamento recém-criado sempre nasce com payment_status "pending" (ver acima) —
        // então, se tem sinal a cobrar, ele sempre está em aberto neste momento. A equipe usa o
        // botão "Enviar link de pagamento" para avisar a cliente nesse caso; a mensagem de
        // "sinal pago" só faz sentido quando não há sinal a cobrar.
        if(in_array($status,array('scheduled','confirmed'),true)&&$deposit_amount<=0){self::safe_send_booking_confirmation($new_id);self::safe_send_booking_confirmation_email($new_id);self::safe_create_anamnesis_for_appointment($new_id);}
        return $new_id;
    }

    public static function delete_appointment($id) {
        global $wpdb;$id=(int)$id;
        self::cancel_appointment_finance($id,'agendamento excluído');
        return $wpdb->delete(self::table('appointments'),array('id'=>$id),array('%d'));
    }

    public static function list_blocks($start='', $end='', $professional_id=0) {
        global $wpdb;
        $b=self::table('schedule_blocks'); $p=self::table('professionals');
        $where=array('1=1'); $args=array();
        if($start){ $where[]='b.start_at >= %s'; $args[]=sanitize_text_field($start).' 00:00:00'; }
        if($end){ $where[]='b.start_at < %s'; $args[]=sanitize_text_field($end).' 23:59:59'; }
        if($professional_id){ $where[]='(b.professional_id=0 OR b.professional_id=%d)'; $args[]=(int)$professional_id; }
        $sql="SELECT b.*, p.name professional_name FROM $b b LEFT JOIN $p p ON p.id=b.professional_id WHERE ".implode(' AND ',$where)." ORDER BY b.start_at ASC";
        if($args) $sql=$wpdb->prepare($sql,$args);
        return $wpdb->get_results($sql,ARRAY_A);
    }

    public static function save_block($data,$id=0) {
        global $wpdb;
        $professional_id=(int)($data['professional_id']??0); $date=self::date_or_null($data['date']??'');
        if(!$date) return new WP_Error('apm_date','Informe uma data válida.',array('status'=>400));
        // Bloqueio de período (ex.: férias): quando "end_date" vem preenchido, ignora os campos
        // de horário e bloqueia o(s) dia(s) inteiro(s), de 00:00 do primeiro dia até 23:59 do
        // último — útil para quando o profissional (ou o SPA inteiro) fica fora por vários dias.
        $end_date=self::date_or_null($data['end_date']??'');
        if($end_date && strtotime($end_date)>=strtotime($date)){
            $start_at=$date.' 00:00:00'; $end_at=$end_date.' 23:59:59';
        } else {
            $start=self::time($data['start_time']??'09:00'); $end=self::time($data['end_time']??'10:00');
            $start_at=$date.' '.$start.':00'; $end_at=$date.' '.$end.':00';
            if(strtotime($end_at)<=strtotime($start_at)) return new WP_Error('apm_time','O horário final deve ser posterior ao inicial.',array('status'=>400));
        }
        $now=current_time('mysql');
        $row=array('professional_id'=>$professional_id,'start_at'=>$start_at,'end_at'=>$end_at,'reason'=>sanitize_text_field($data['reason']??''),'updated_at'=>$now);
        if($id){$wpdb->update(self::table('schedule_blocks'),$row,array('id'=>(int)$id));return (int)$id;}
        $row['created_by']=get_current_user_id();$row['created_at']=$now;$wpdb->insert(self::table('schedule_blocks'),$row);return (int)$wpdb->insert_id;
    }

    public static function delete_block($id) {
        global $wpdb;
        return $wpdb->delete(self::table('schedule_blocks'),array('id'=>(int)$id),array('%d'));
    }

    // $exclude_appointment_id: usado pelo painel ao EDITAR um agendamento já existente — sem
    // isso, o próprio horário atual do agendamento apareceria como "ocupado" (afinal, é ele
    // mesmo quem está ocupando aquele horário no banco), impedindo a operadora de reconfirmar
    // o mesmo horário ao editar. No agendamento público não há agendamento a excluir, então
    // esse parâmetro fica 0 e o comportamento continua idêntico ao de antes.
    public static function public_slots($service_id, $professional_id, $date, $exclude_appointment_id = 0) {
        global $wpdb;
        self::expire_pending_bookings();
        $service_id=(int)$service_id; $professional_id=(int)$professional_id; $date=self::date_or_null($date); $exclude_appointment_id=(int)$exclude_appointment_id;
        if(!$service_id||!$professional_id||!$date) return new WP_Error('apm_slots_data','Selecione serviço, profissional e uma data válida.',array('status'=>400));
        $service=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".self::table('services')." WHERE id=%d AND active=1",$service_id),ARRAY_A);
        $professional=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".self::table('professionals')." WHERE id=%d AND active=1",$professional_id),ARRAY_A);
        if(!$service||!$professional) return new WP_Error('apm_slots_not_found','Serviço ou profissional indisponível.',array('status'=>404));
        if(!(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".self::table('professional_services')." WHERE professional_id=%d AND service_id=%d",$professional_id,$service_id))) return new WP_Error('apm_service_professional','Este profissional não realiza o serviço selecionado.',array('status'=>400));

        $settings=self::get_settings();
        $tz=wp_timezone();
        $day=new DateTimeImmutable($date.' 12:00:00',$tz);
        $weekday=(string)$day->format('w');
        $hours=$settings['opening_hours'][$weekday]??array('active'=>0,'start'=>'09:00','end'=>'19:00');
        if(empty($hours['active'])) return array();
        $duration=max(5,(int)$service['duration_minutes']);
        $open=new DateTimeImmutable($date.' '.self::time($hours['start']??'09:00').':00',$tz);
        $close=new DateTimeImmutable($date.' '.self::time($hours['end']??'19:00').':00',$tz);
        // Ao editar (exclude_appointment_id presente), a equipe pode legitimamente precisar ver/
        // reconfirmar um horário que já passou (ex.: lançar um atendimento feito mais cedo no
        // mesmo dia) — então o piso de "só a partir de daqui a 30 min" só vale para quem está
        // criando um agendamento novo, igual já era antes desta mudança.
        $min_start=$exclude_appointment_id?new DateTimeImmutable('1970-01-01',$tz):current_datetime()->modify('+30 minutes');
        $day_start=$date.' 00:00:00'; $day_end=$date.' 23:59:59';

        // Em vez de testar um horário a cada poucos minutos (o que oferecia opções como
        // 11:05 ou 11:10 logo depois de um atendimento terminar às 11:00 — minutos que
        // nenhum outro procedimento conseguiria aproveitar), a sugestão agora é construída
        // a partir dos intervalos realmente ocupados do profissional no dia: calcula as
        // janelas livres entre eles e, dentro de cada janela, propõe horários espaçados
        // pela duração do próprio procedimento escolhido — mantendo a agenda compacta.
        $busy=array();
        $apps=$exclude_appointment_id
            ? $wpdb->get_results($wpdb->prepare(
                "SELECT a.start_at,a.end_at,COALESCE(s.buffer_minutes,0) buffer_minutes
                 FROM ".self::table('appointments')." a LEFT JOIN ".self::table('services')." s ON s.id=a.service_id
                 WHERE a.professional_id=%d AND a.id<>%d AND a.status NOT IN ('cancelled','no_show','payment_expired') AND a.start_at < %s AND a.end_at > %s",
                $professional_id,$exclude_appointment_id,$day_end,$day_start
            ),ARRAY_A)
            : $wpdb->get_results($wpdb->prepare(
                "SELECT a.start_at,a.end_at,COALESCE(s.buffer_minutes,0) buffer_minutes
                 FROM ".self::table('appointments')." a LEFT JOIN ".self::table('services')." s ON s.id=a.service_id
                 WHERE a.professional_id=%d AND a.status NOT IN ('cancelled','no_show','payment_expired') AND a.start_at < %s AND a.end_at > %s",
                $professional_id,$day_end,$day_start
            ),ARRAY_A);
        foreach($apps as $a){
            $busy[]=array(
                new DateTimeImmutable($a['start_at'],$tz),
                (new DateTimeImmutable($a['end_at'],$tz))->modify('+'.(int)$a['buffer_minutes'].' minutes'),
            );
        }
        $blocks=$wpdb->get_results($wpdb->prepare(
            "SELECT start_at,end_at FROM ".self::table('schedule_blocks')." WHERE (professional_id=0 OR professional_id=%d) AND start_at < %s AND end_at > %s",
            $professional_id,$day_end,$day_start
        ),ARRAY_A);
        foreach($blocks as $b){
            $busy[]=array(new DateTimeImmutable($b['start_at'],$tz),new DateTimeImmutable($b['end_at'],$tz));
        }
        usort($busy,function($a,$b){return $a[0]<=>$b[0];});
        $merged=array();
        foreach($busy as $b){
            if($merged && $b[0]<=$merged[count($merged)-1][1]){
                if($b[1]>$merged[count($merged)-1][1]) $merged[count($merged)-1][1]=$b[1];
            } else {
                $merged[]=$b;
            }
        }
        $gaps=array(); $cursor=$open;
        foreach($merged as $b){
            if($b[0]>$cursor) $gaps[]=array($cursor,min($b[0],$close));
            if($b[1]>$cursor) $cursor=$b[1];
            if($cursor>=$close) break;
        }
        if($cursor<$close) $gaps[]=array($cursor,$close);

        $slots=array();
        foreach($gaps as $gap){
            for($t=$gap[0];$t->modify('+'.$duration.' minutes')<=$gap[1];$t=$t->modify('+'.$duration.' minutes')){
                if($t<=$min_start) continue;
                $slots[]=$t->format('H:i');
            }
        }
        return $slots;
    }

    // Mesmo algoritmo (dígitos verificadores, módulo 11) usado na validação em tempo real do
    // formulário público — refeito aqui porque a validação do navegador pode ser contornada.
    private static function is_valid_cpf($cpf) {
        $d=preg_replace('/\D/','',(string)$cpf);
        if(strlen($d)!==11||preg_match('/^(\d)\1{10}$/',$d)) return false;
        for($t=9;$t<11;$t++){
            $sum=0;
            for($i=0;$i<$t;$i++) $sum+=(int)$d[$i]*(($t+1)-$i);
            $rev=($sum*10)%11; if($rev>=10) $rev=0;
            if($rev!==(int)$d[$t]) return false;
        }
        return true;
    }

    // Prova de que a cliente viu e aceitou a política de cancelamento antes de confirmar —
    // reforçada aqui no backend porque a validação do checkbox no navegador pode ser
    // contornada. Se não houver política cadastrada, não há o que aceitar: segue sem exigir
    // nada e devolve array vazio (nada a gravar). Devolve WP_Error quando falta a aceitação
    // exigida, ou um array pronto para mesclar na linha do agendamento quando está tudo certo.
    private static function build_policy_acceptance_proof($data,$settings,$server) {
        $policy_text=trim((string)($settings['cancellation_policy_text']??''));
        if($policy_text && empty($data['policy_accepted'])) return new WP_Error('apm_booking_policy','É preciso confirmar que leu a política de reserva e cancelamento.',array('status'=>400));
        if(empty($data['policy_accepted'])) return array();
        return array(
            'policy_accepted_at'=>current_time('mysql'),
            'policy_text_snapshot'=>sanitize_textarea_field($data['policy_text_snapshot']??$policy_text),
            'policy_ip_address'=>self::resolve_client_ip($server),
            'policy_user_agent'=>sanitize_text_field(substr((string)($server['HTTP_USER_AGENT']??''),0,500)),
        );
    }

    public static function create_public_booking($data) {
        global $wpdb;
        $limited=self::rate_limit_public_action('public_booking',8,600);
        if(is_wp_error($limited)) return $limited;
        self::expire_pending_bookings();
        $service_id=(int)($data['service_id']??0); $professional_id=(int)($data['professional_id']??0);
        $date=self::date_or_null($data['date']??''); $start_time=self::time($data['start_time']??'');
        $name=sanitize_text_field($data['name']??''); $phone=sanitize_text_field($data['phone']??''); $email=sanitize_email($data['email']??''); $cpf=sanitize_text_field($data['cpf']??'');
        if(!$service_id||!$professional_id||!$date||!$name||!$phone||!$email||!$cpf) return new WP_Error('apm_booking_required','Preencha os dados obrigatórios para continuar.',array('status'=>400));
        if(!is_email($email)) return new WP_Error('apm_booking_email','Informe um e-mail válido.',array('status'=>400));
        if(!self::is_valid_cpf($cpf)) return new WP_Error('apm_booking_cpf','Informe um CPF válido.',array('status'=>400));
        $slots=self::public_slots($service_id,$professional_id,$date); if(is_wp_error($slots)) return $slots;
        if(!in_array($start_time,$slots,true)) return new WP_Error('apm_booking_slot','Este horário acabou de ficar indisponível. Escolha outro horário.',array('status'=>409));
        $service=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".self::table('services')." WHERE id=%d AND active=1",$service_id),ARRAY_A);
        if(!$service) return new WP_Error('apm_booking_service','Serviço indisponível.',array('status'=>404));

        $settings=self::get_settings(); $test_mode=!empty($settings['payment_test_mode']);
        $policy_proof=self::build_policy_acceptance_proof($data,$settings,$_SERVER??array());
        if(is_wp_error($policy_proof)) return $policy_proof;

        $client_id=self::find_or_create_public_client(array('name'=>$name,'phone'=>$phone,'email'=>$email,'cpf'=>$cpf));
        if(is_wp_error($client_id)) return $client_id;
        $start_at=$date.' '.$start_time.':00'; $duration=max(5,(int)$service['duration_minutes']);
        $end_at=gmdate('Y-m-d H:i:s',strtotime($start_at.' +'.$duration.' minutes'));
        $price=(float)$service['price']; $deposit_value=(float)$service['deposit_value'];
        $deposit_amount=$service['deposit_type']==='fixed'?$deposit_value:round($price*($deposit_value/100),2);
        $needs_payment=$deposit_amount>0;
        $status=$needs_payment?'pending_payment':'confirmed'; $payment_status=$needs_payment?'pending':'paid'; $now=current_time('mysql');
        $row=array('client_id'=>$client_id,'professional_id'=>$professional_id,'service_id'=>$service_id,'start_at'=>$start_at,'end_at'=>$end_at,'status'=>$status,'source'=>'public','notes'=>sanitize_textarea_field($data['notes']??''),'total_amount'=>$price,'deposit_amount'=>$deposit_amount,'payment_status'=>$payment_status,'created_by'=>null,'created_at'=>$now,'updated_at'=>$now);
        if($policy_proof) $row=array_merge($row,$policy_proof);
        if(!$wpdb->insert(self::table('appointments'),$row)) return new WP_Error('apm_booking_save','Não foi possível criar a reserva.',array('status'=>500));
        $appointment_id=(int)$wpdb->insert_id;
        self::sync_appointment_finance($appointment_id);

        if(!$needs_payment){
            self::safe_send_booking_confirmation($appointment_id);self::safe_send_booking_confirmation_email($appointment_id);
            $anamnesis=self::safe_create_anamnesis_for_appointment($appointment_id);
            return array('appointment_id'=>$appointment_id,'status'=>'confirmed','payment_status'=>'paid','checkout_url'=>'','test_mode'=>0,'fill_url'=>$anamnesis['fill_url']??'');
        }
        if($test_mode){
            $wpdb->update(self::table('appointments'),array('status'=>'confirmed','payment_status'=>'paid','updated_at'=>current_time('mysql')),array('id'=>$appointment_id));
            $payment_id=self::insert_payment($appointment_id,'test','TEST-'.$appointment_id,$deposit_amount,'approved','','');
            self::sync_appointment_finance($appointment_id);self::sync_appointment_payment_to_finance($appointment_id,$payment_id);
            self::maybe_convert_lead_for_client($client_id,'payment_approved');
            self::safe_send_booking_confirmation($appointment_id);self::safe_send_booking_confirmation_email($appointment_id);
            $anamnesis=self::safe_create_anamnesis_for_appointment($appointment_id);
            return array('appointment_id'=>$appointment_id,'status'=>'confirmed','payment_status'=>'paid','checkout_url'=>'','test_mode'=>1,'fill_url'=>$anamnesis['fill_url']??'');
        }
        if(empty($settings['payment_enabled'])||($settings['payment_gateway']??'')!=='infinitepay'||empty($settings['infinitepay_handle'])){
            $wpdb->update(self::table('appointments'),array('status'=>'payment_expired','payment_status'=>'unavailable','updated_at'=>current_time('mysql')),array('id'=>$appointment_id));
            self::cancel_appointment_finance($appointment_id,'pagamento online indisponível');
            return new WP_Error('apm_gateway_unavailable','O pagamento online ainda não está disponível. Entre em contato com o SPA.',array('status'=>503));
        }
        $payment=self::create_infinitepay_checkout_link($appointment_id,$service,$name,$email,$phone,$deposit_amount);
        if(is_wp_error($payment)){
            $wpdb->update(self::table('appointments'),array('status'=>'payment_expired','payment_status'=>'error','updated_at'=>current_time('mysql')),array('id'=>$appointment_id));
            self::cancel_appointment_finance($appointment_id,'erro ao iniciar pagamento');
            return $payment;
        }
        return array('appointment_id'=>$appointment_id,'status'=>'pending_payment','payment_status'=>'pending','checkout_url'=>$payment['checkout_url'],'test_mode'=>0,'expires_minutes'=>(int)($settings['payment_hold_minutes']??20));
    }

    private static function find_or_create_public_client($data) {
        global $wpdb; $table=self::table('clients'); $email=sanitize_email($data['email']??''); $phone=sanitize_text_field($data['phone']??''); $cpf=sanitize_text_field($data['cpf']??'');
        $id=0;
        // CPF primeiro: é o identificador mais confiável (não muda, ao contrário de telefone/e-mail)
        // e evita duplicar cliente quando a pessoa reserva de novo com um e-mail ou WhatsApp diferente.
        if($cpf){$cpf_digits=preg_replace('/\D+/','',$cpf);if($cpf_digits){$rows=$wpdb->get_results("SELECT id,cpf FROM $table WHERE cpf IS NOT NULL AND cpf<>'' ORDER BY id DESC LIMIT 2000",ARRAY_A);foreach($rows as $r){if(preg_replace('/\D+/','',$r['cpf'])===$cpf_digits){$id=(int)$r['id'];break;}}}}
        if(!$id && $email) $id=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE email=%s ORDER BY id DESC LIMIT 1",$email));
        if(!$id && $phone){$digits=preg_replace('/\D+/','',$phone);if($digits){$like='%'.$wpdb->esc_like(substr($digits,-8)).'%';$rows=$wpdb->get_results($wpdb->prepare("SELECT id,phone FROM $table WHERE phone LIKE %s ORDER BY id DESC LIMIT 20",$like),ARRAY_A);foreach($rows as $r){if(substr(preg_replace('/\D+/','',$r['phone']),-8)===substr($digits,-8)){$id=(int)$r['id'];break;}}}}
        if($id){
            // Nunca sobrescreve dados de contato já preenchidos de um cliente existente a
            // partir de um formulário público sem autenticação: só completa o que estiver
            // em branco, evitando que alguém sequestre o cadastro de outra pessoa.
            $existing=$wpdb->get_row($wpdb->prepare("SELECT name,phone,email,cpf FROM $table WHERE id=%d",$id),ARRAY_A);
            $update=array('updated_at'=>current_time('mysql'));
            if(empty($existing['name']) && !empty($data['name'])) $update['name']=sanitize_text_field($data['name']);
            if(empty($existing['phone']) && $phone) $update['phone']=$phone;
            if(empty($existing['email']) && $email) $update['email']=$email;
            if(empty($existing['cpf']) && !empty($data['cpf'])) $update['cpf']=sanitize_text_field($data['cpf']);
            if(count($update)>1) $wpdb->update($table,$update,array('id'=>$id));
            return $id;
        }
        return self::save_client(array('name'=>$data['name'],'phone'=>$phone,'email'=>$email,'cpf'=>$data['cpf']??'','source'=>'Agendamento online','status'=>'active'));
    }

    private static function infinitepay_headers($settings) {
        $headers=array('Content-Type'=>'application/json');
        $api_key=trim((string)($settings['infinitepay_api_key']??''));
        if($api_key) $headers['Authorization']='Bearer '.$api_key;
        return $headers;
    }

    private static function create_infinitepay_checkout_link($appointment_id,$service,$name,$email,$phone,$amount) {
        $settings=self::get_settings(); $handle=trim((string)($settings['infinitepay_handle']??''));
        if(!$handle) return new WP_Error('apm_ip_handle','InfiniteTag (handle) da InfinitePay não configurada.',array('status'=>503));
        $external='APM-'.$appointment_id.'-'.wp_generate_password(8,false,false);
        $booking_url=class_exists('APM_Router')?APM_Router::booking_url():home_url('/agendamento-online/');
        $digits=preg_replace('/\D+/','',(string)$phone);
        $customer=array('name'=>substr((string)$name,0,100));
        if($email) $customer['email']=$email;
        if($digits) $customer['phone_number']='+'.(strlen($digits)<=11?'55'.$digits:$digits);
        $body=array(
            'handle'=>$handle,
            'order_nsu'=>$external,
            'redirect_url'=>add_query_arg(array('apm_booking'=>$appointment_id),$booking_url),
            'webhook_url'=>rest_url('assis-prime/v1/payment-webhook/infinitepay'),
            'items'=>array(array('quantity'=>1,'price'=>(int)round((float)$amount*100),'description'=>'Sinal - '.substr((string)$service['name'],0,90))),
            'customer'=>$customer,
        );
        $response=wp_remote_post('https://api.checkout.infinitepay.io/links',array('timeout'=>20,'headers'=>self::infinitepay_headers($settings),'body'=>wp_json_encode($body)));
        if(is_wp_error($response)) return new WP_Error('apm_ip_connection','Não foi possível conectar à InfinitePay.',array('status'=>502));
        $code=wp_remote_retrieve_response_code($response); $json=json_decode(wp_remote_retrieve_body($response),true);
        $checkout_url=$json['url']??($json['payment_url']??'');
        if($code<200||$code>=300||!$checkout_url) return new WP_Error('apm_ip_link','Não foi possível iniciar o pagamento na InfinitePay.',array('status'=>502));
        self::insert_payment($appointment_id,'infinitepay',$external,$amount,'pending','',$checkout_url);
        return array('checkout_url'=>$checkout_url,'external_reference'=>$external);
    }

    private static function insert_payment($appointment_id,$provider,$external,$amount,$status,$preference_id='',$checkout_url='') {
        global $wpdb; $now=current_time('mysql');
        $wpdb->insert(self::table('payments'),array('appointment_id'=>(int)$appointment_id,'provider'=>sanitize_key($provider),'external_reference'=>sanitize_text_field($external),'provider_preference_id'=>sanitize_text_field($preference_id),'amount'=>(float)$amount,'status'=>sanitize_key($status),'checkout_url'=>esc_url_raw($checkout_url),'created_at'=>$now,'updated_at'=>$now));
        return (int)$wpdb->insert_id;
    }

    // Gera um link de pagamento InfinitePay para um agendamento criado manualmente no painel
    // (fora do fluxo de agendamento online) e envia por WhatsApp e/ou e-mail, já que esse
    // agendamento não passou pelo checkout automático. Usado quando a equipe marca um
    // horário direto na Agenda e precisa cobrar o sinal da cliente remotamente.
    public static function send_payment_link_for_appointment($appointment_id) {
        global $wpdb;
        $appointment_id=(int)$appointment_id;
        $a=$wpdb->get_row($wpdb->prepare(
            "SELECT a.*, c.name client_name, c.phone client_phone, c.email client_email, s.name service_name
             FROM ".self::table('appointments')." a
             LEFT JOIN ".self::table('clients')." c ON c.id=a.client_id
             LEFT JOIN ".self::table('services')." s ON s.id=a.service_id
             WHERE a.id=%d",$appointment_id
        ),ARRAY_A);
        if(!$a) return new WP_Error('apm_not_found','Agendamento não encontrado.',array('status'=>404));
        if(in_array($a['status'],array('cancelled','no_show'),true)) return new WP_Error('apm_invalid_status','Não é possível gerar link de pagamento para um agendamento cancelado.',array('status'=>400));
        if($a['payment_status']==='paid') return new WP_Error('apm_already_paid','O sinal deste agendamento já foi pago.',array('status'=>400));
        $deposit_amount=(float)$a['deposit_amount'];
        if($deposit_amount<=0) return new WP_Error('apm_no_deposit','Este agendamento não tem sinal a cobrar.',array('status'=>400));
        $settings=self::get_settings();
        if(empty($settings['payment_enabled'])||($settings['payment_gateway']??'')!=='infinitepay'||empty($settings['infinitepay_handle'])) return new WP_Error('apm_gateway_unavailable','O pagamento online ainda não está configurado em Configurações > Pagamentos.',array('status'=>503));
        if(empty($a['client_phone']) && empty($a['client_email'])) return new WP_Error('apm_no_contact','O cliente não tem telefone nem e-mail cadastrado para receber o link.',array('status'=>400));
        $payment=self::create_infinitepay_checkout_link($appointment_id,array('name'=>$a['service_name']??'Atendimento'),$a['client_name'],$a['client_email'],$a['client_phone'],$deposit_amount);
        if(is_wp_error($payment)) return $payment;
        $wpdb->update(self::table('appointments'),array('status'=>'pending_payment','payment_status'=>'pending','updated_at'=>current_time('mysql')),array('id'=>$appointment_id));
        self::sync_appointment_finance($appointment_id);
        $spa_name=$settings['spa_name']??'Assis Prime';
        // O link já foi gerado e salvo no agendamento nesse ponto — isso é o que importa de
        // verdade. Se o envio por WhatsApp ou e-mail falhar (ou travar com um erro inesperado),
        // a resposta ainda precisa voltar com o link certinho para a equipe copiar e mandar
        // manualmente, em vez de derrubar a requisição inteira.
        $sent_whatsapp=false;$whatsapp_error='';
        try {
            if(!empty($a['client_phone'])){
                $message=self::render_whatsapp_template(
                    $settings['whatsapp_template_payment_link']??'',
                    "Olá, {nome}! Para confirmar seu horário na {spa}, finalize o pagamento do sinal de {valor}:\n\n{link}\n\nAté breve! 💕",
                    array('nome'=>$a['client_name'],'spa'=>$spa_name,'procedimento'=>$a['service_name']??'','valor'=>'R$ '.number_format($deposit_amount,2,',','.'),'link'=>$payment['checkout_url'])
                );
                $wa_result=self::send_whatsapp_message($a['client_phone'],$message);
                if(is_wp_error($wa_result)){$whatsapp_error=$wa_result->get_error_message();} else {$sent_whatsapp=true;}
            }
        } catch (\Throwable $e) {
            $whatsapp_error=$e->getMessage();
            error_log('[Assis Prime] Falha ao enviar link de pagamento por WhatsApp do agendamento #'.$appointment_id.': '.$e->getMessage()); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional exception logging.
        }
        $sent_email=false;$email_error='';
        try {
            if(!empty($a['client_email'])){
                // Mesmo motivo do teste de e-mail em Configurações > Geral: um plugin de SMTP
                // (WP Mail SMTP etc.) que recusa o envio avisa o WordPress pela action
                // "wp_mail_failed" com o motivo real — sem capturar esse hook aqui, o e-mail do
                // link de pagamento falhava em silêncio e o único aviso era um texto genérico
                // que não ajudava a equipe a saber o que estava errado no servidor.
                $mail_error=null;
                $capture_mail_error=function($wp_error) use (&$mail_error){$mail_error=$wp_error;};
                add_action('wp_mail_failed',$capture_mail_error);
                ob_start();
                $mail_sent=self::send_payment_link_email($a['client_email'],$a['client_name'],$payment['checkout_url'],$a['service_name']??'',$deposit_amount,$spa_name);
                remove_action('wp_mail_failed',$capture_mail_error);
                $stray=trim(ob_get_clean());
                if($mail_sent){$sent_email=true;} else {
                    $reason=$mail_error instanceof WP_Error?$mail_error->get_error_message():'';
                    $email_error=$reason?$reason:($stray?wp_strip_all_tags($stray):'O WordPress recusou o envio (wp_mail retornou falso). Verifique a configuração de SMTP do servidor.');
                }
            }
        } catch (\Throwable $e) {
            if(ob_get_level()>0) ob_end_clean();
            $email_error=$e->getMessage();
            error_log('[Assis Prime] Falha ao enviar link de pagamento por e-mail do agendamento #'.$appointment_id.': '.$e->getMessage()); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional exception logging.
        }
        return array('checkout_url'=>$payment['checkout_url'],'sent_whatsapp'=>$sent_whatsapp,'sent_email'=>$sent_email,'whatsapp_error'=>$whatsapp_error,'email_error'=>$email_error,'client_name'=>$a['client_name']);
    }

    private static function send_payment_link_email($to_email,$to_name,$checkout_url,$service_name,$amount,$spa_name) {
        $first_name=trim((string)strtok((string)$to_name,' ')) ?: 'tudo bem';
        $subject=sprintf('%s: finalize o pagamento do seu horário',$spa_name);
        $valor='R$ '.number_format((float)$amount,2,',','.');
        $intro=$service_name
            ? "Para confirmar seu horário de <strong style=\"color:#2b2026;\">".esc_html($service_name)."</strong> na <strong style=\"color:#2b2026;\">".esc_html($spa_name)."</strong>, falta finalizar o pagamento do sinal de <strong style=\"color:#960018;\">{$valor}</strong>."
            : "Para confirmar seu horário na <strong style=\"color:#2b2026;\">".esc_html($spa_name)."</strong>, falta finalizar o pagamento do sinal de <strong style=\"color:#960018;\">{$valor}</strong>.";
        $body_html='<p style="margin:0 0 6px;color:#960018;font-size:12px;font-weight:600;letter-spacing:.6px;text-transform:uppercase;">Falta pouco</p>'
            .'<h2 style="margin:0 0 16px;color:#2b2026;font-size:22px;font-weight:600;">Olá, '.esc_html($first_name).'!</h2>'
            .'<p style="margin:0 0 24px;color:#6b5a60;font-size:15px;line-height:1.6;">'.$intro.'</p>'
            .'<p style="margin:0;color:#6b5a60;font-size:14px;">Pague com Pix ou cartão clicando no botão abaixo.</p>';
        $settings=self::get_settings();
        $html=self::email_html_template($settings,'Finalize o pagamento do sinal: '.$valor,$subject,$body_html,$checkout_url,'Pagar sinal agora');
        $headers=array('Content-Type: text/html; charset=UTF-8');
        return wp_mail($to_email,$subject,$html,$headers);
    }

    // wp_mail() só confirma que o WordPress aceitou enfileirar o e-mail localmente — não que
    // ele chegou de verdade na caixa de entrada. Sem um relay SMTP configurado (ex.: via
    // Cloudflare Email Routing, SendGrid, etc.) com SPF/DKIM corretos para o domínio, boa parte
    // dos provedores de e-mail simplesmente descarta ou joga no spam o que sai pela função
    // mail() nativa do PHP. Este teste manda um e-mail real para o próprio e-mail do
    // administrador logado, para confirmar rapidamente se o envio está, ao menos, saindo do
    // servidor — a entrega final ainda depende da configuração de SMTP/DNS do domínio.
    public static function send_test_email() {
        $user = wp_get_current_user();
        $to = $user ? $user->user_email : '';
        if (!$to) return new WP_Error('apm_no_admin_email', 'Não foi possível identificar o e-mail do usuário logado.', array('status' => 400));
        $settings = self::get_settings();
        $spa_name = $settings['spa_name'] ?? 'Assis Prime';
        $subject = sprintf('%s: teste de envio de e-mail', $spa_name);
        $body_html = '<p style="margin:0 0 6px;color:#960018;font-size:12px;font-weight:600;letter-spacing:.6px;text-transform:uppercase;">Diagnóstico</p>'
            . '<h2 style="margin:0 0 16px;color:#2b2026;font-size:22px;font-weight:600;">Tudo certo por aqui!</h2>'
            . '<p style="margin:0;color:#6b5a60;font-size:15px;line-height:1.6;">Este é um e-mail de teste do Assis Prime Manager. Se você recebeu esta mensagem (mesmo que na caixa de spam), o envio de e-mails do site está funcionando.</p>';
        $html = self::email_html_template($settings, 'Teste de envio de e-mail', $subject, $body_html);
        // Quando um plugin de SMTP (WP Mail SMTP, etc.) recusa o envio, ele não lança exceção
        // nem imprime nada na tela — ele dispara a action "wp_mail_failed" com um WP_Error
        // contendo o motivo real (autenticação, remetente não verificado, recusa do servidor
        // de destino etc.) e só então wp_mail() devolve false. Sem capturar esse hook, a única
        // coisa que sabíamos era "retornou falso", sem nenhuma pista do motivo — por isso o
        // teste deste plugin parecia "dar erro" sem explicar nada, mesmo com o teste do
        // próprio WP Mail SMTP funcionando (ele mostra esse mesmo motivo na tela dele).
        $mail_error = null;
        $capture_mail_error = function ($wp_error) use (&$mail_error) { $mail_error = $wp_error; };
        add_action('wp_mail_failed', $capture_mail_error);
        // Alguns plugins de SMTP mal configurados lançam uma exceção de verdade dentro de
        // wp_mail() (em vez de só devolver falso) — sem captura aqui, isso derruba a
        // requisição inteira com "erro crítico no site" em vez de dizer qual é o problema.
        // Também capturamos qualquer saída (warning/notice do PHP) que o plugin de SMTP
        // imprima na tela: sem isso, esse texto solto se mistura à resposta JSON e quebra
        // o parse no navegador, fazendo a mensagem de erro real nunca chegar até a tela.
        ob_start();
        try {
            $sent = wp_mail($to, $subject, $html, array('Content-Type: text/html; charset=UTF-8'));
        } catch (\Throwable $e) {
            remove_action('wp_mail_failed', $capture_mail_error);
            $stray = trim(ob_get_clean());
            $reason = $mail_error instanceof WP_Error ? ' Motivo informado pelo SMTP: ' . $mail_error->get_error_message() . '.' : '';
            $detail = $stray ? ' Detalhe técnico: ' . wp_strip_all_tags($stray) : '';
            return array('success' => false, 'message' => 'O envio de e-mail travou com um erro: ' . $e->getMessage() . '.' . $reason . $detail . ' Verifique o plugin/configuração de SMTP do servidor.');
        }
        remove_action('wp_mail_failed', $capture_mail_error);
        $stray = trim(ob_get_clean());
        if (!$sent) {
            $reason = $mail_error instanceof WP_Error ? ' Motivo informado pelo SMTP: ' . $mail_error->get_error_message() . '.' : '';
            $detail = $stray ? ' Detalhe técnico: ' . wp_strip_all_tags($stray) : '';
            return array('success' => false, 'message' => 'O WordPress recusou o envio (wp_mail retornou falso).' . $reason . $detail . ' Verifique a configuração de SMTP do servidor.');
        }
        return array('success' => true, 'to' => $to);
    }

    public static function booking_status($appointment_id) {
        global $wpdb; self::expire_pending_bookings();
        $a=$wpdb->get_row($wpdb->prepare("SELECT a.id,a.status,a.payment_status,a.start_at,a.total_amount,a.deposit_amount,a.service_id,s.name service_name,p.name professional_name FROM ".self::table('appointments')." a LEFT JOIN ".self::table('services')." s ON s.id=a.service_id LEFT JOIN ".self::table('professionals')." p ON p.id=a.professional_id WHERE a.id=%d",(int)$appointment_id),ARRAY_A);
        if(!$a) return new WP_Error('apm_booking_not_found','Reserva não encontrada.',array('status'=>404));
        if($a['status']==='confirmed' && $a['payment_status']==='paid'){
            $anamnesis=self::safe_create_anamnesis_for_appointment((int)$a['id']);
            if($anamnesis) $a['fill_url']=$anamnesis['fill_url'];
        }
        return $a;
    }

    public static function handle_infinitepay_webhook($payload) {
        global $wpdb; $settings=self::get_settings(); $handle=trim((string)($settings['infinitepay_handle']??''));
        if(!$handle) return array('received'=>true,'configured'=>false);
        $external=sanitize_text_field($payload['order_nsu']??'');
        if(!$external) return new WP_Error('apm_ip_webhook','Pedido não identificado.',array('status'=>400));
        $record=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".self::table('payments')." WHERE external_reference=%s ORDER BY id DESC LIMIT 1",$external),ARRAY_A);
        if(!$record) return array('received'=>true,'ignored'=>true);
        $transaction_nsu=sanitize_text_field($payload['transaction_nsu']??'');
        $slug=sanitize_text_field($payload['invoice_slug']??($payload['slug']??''));
        if(!$transaction_nsu||!$slug) return new WP_Error('apm_ip_webhook_data','Dados da transação incompletos.',array('status'=>400));
        // O webhook da InfinitePay não vem assinado: nunca aprovamos com base só no que ele
        // diz. Reconsultamos a API oficial (payment_check) com os identificadores recebidos
        // antes de confirmar qualquer coisa — mesmo princípio já aplicado ao Mercado Pago.
        $check_body=array('handle'=>$handle,'order_nsu'=>$external,'transaction_nsu'=>$transaction_nsu,'slug'=>$slug);
        $response=wp_remote_post('https://api.checkout.infinitepay.io/payment_check',array('timeout'=>20,'headers'=>self::infinitepay_headers($settings),'body'=>wp_json_encode($check_body)));
        if(is_wp_error($response)||wp_remote_retrieve_response_code($response)!==200) return new WP_Error('apm_ip_webhook_verify','Não foi possível confirmar o pagamento na InfinitePay.',array('status'=>502));
        $check=json_decode(wp_remote_retrieve_body($response),true);
        if(empty($check['success'])) return new WP_Error('apm_ip_webhook_verify','Não foi possível confirmar o pagamento na InfinitePay.',array('status'=>502));
        $amount_cents=(int)($check['amount']??0); $paid_amount_cents=(int)($check['paid_amount']??0);
        $expected_cents=(int)round((float)$record['amount']*100);
        if(abs($amount_cents-$expected_cents)>2) return new WP_Error('apm_ip_amount','Valor do pagamento não confere.',array('status'=>400));
        $mapped=(!empty($check['paid'])&&$paid_amount_cents>=$amount_cents)?'approved':'pending';
        $wpdb->update(self::table('payments'),array(
            'provider_payment_id'=>$transaction_nsu,'provider_preference_id'=>$slug,'status'=>$mapped,
            'payment_method'=>sanitize_text_field($check['capture_method']??($payload['capture_method']??'')),
            'net_received_amount'=>$mapped==='approved'?round($paid_amount_cents/100,2):0,
            'raw_data'=>wp_json_encode($check),'updated_at'=>current_time('mysql'),
        ),array('id'=>(int)$record['id']));
        if($mapped==='approved'){
            $wpdb->update(self::table('appointments'),array('status'=>'confirmed','payment_status'=>'paid','updated_at'=>current_time('mysql')),array('id'=>(int)$record['appointment_id']));
            self::sync_appointment_finance((int)$record['appointment_id']);self::sync_appointment_payment_to_finance((int)$record['appointment_id'],(int)$record['id']);
            $client_id=(int)$wpdb->get_var($wpdb->prepare("SELECT client_id FROM ".self::table('appointments')." WHERE id=%d",(int)$record['appointment_id'])); if($client_id) self::maybe_convert_lead_for_client($client_id,'payment_approved');
            self::safe_send_booking_confirmation((int)$record['appointment_id']);self::safe_send_booking_confirmation_email((int)$record['appointment_id']);
            // Este webhook confirma o pagamento tanto de agendamentos feitos pela cliente
            // (público) quanto de agendamentos criados pela equipe pelo painel e pagos via
            // "Enviar link de pagamento" — mas só o fluxo público disparava a ficha de
            // anamnese automática depois do pagamento. Chamando aqui também, a ficha passa a
            // ser enviada do mesmo jeito nos dois casos. Idempotente: se o serviço não tiver
            // ficha vinculada, ou a ficha já existir, a chamada simplesmente não faz nada.
            self::safe_create_anamnesis_for_appointment((int)$record['appointment_id']);
        }
        return array('received'=>true,'status'=>$mapped);
    }

    public static function expire_pending_bookings() {
        global $wpdb; $settings=self::get_settings(); $minutes=max(5,(int)($settings['payment_hold_minutes']??20));
        $cutoff=gmdate('Y-m-d H:i:s',current_time('timestamp')-($minutes*60));$table=self::table('appointments');
        $ids=$wpdb->get_col($wpdb->prepare("SELECT id FROM {$table} WHERE status='pending_payment' AND payment_status='pending' AND created_at < %s",$cutoff));
        $wpdb->query($wpdb->prepare("UPDATE {$table} SET status='payment_expired', payment_status='expired', updated_at=%s WHERE status='pending_payment' AND payment_status='pending' AND created_at < %s",current_time('mysql'),$cutoff));
        foreach($ids as $id)self::cancel_appointment_finance((int)$id,'reserva expirada sem pagamento');
    }
}
