async function refreshNotifications(){try{const r=await api('notifications?per_page=8');state.data.notifications=r.items||[];state.data.unreadNotifications=Number(r.unread||0);render()}catch(e){notify(e.message,true)}}
async function beginLeadConversion(id){state.conversionLeadId=Number(id);state.conversionMatches=[];try{state.conversionMatches=await api(`leads/${id}/matches`);render();open('apm-lead-conversion-modal')}catch(e){notify(e.message,true)}}
async function finishLeadConversion(clientId=0){try{await api(`leads/${state.conversionLeadId}/convert`,{method:'POST',body:JSON.stringify({client_id:Number(clientId)||0,reason:'manual'})});close('apm-lead-conversion-modal');state.conversionLeadId=0;state.conversionMatches=[];await reloadData(clientId?'Lead vinculado ao cliente existente.':'Lead convertido em cliente.')}catch(e){notify(e.message,true)}}
async function moveLeadCard(id,status){try{const lead=state.data.leads.find(x=>Number(x.id)===Number(id));if(lead){lead.status=status;lead.updated_at=new Date().toISOString().slice(0,19).replace('T',' ');render()}await api(`leads/${id}/move`,{method:'POST',body:JSON.stringify({status})});notify(`Lead movido para ${leadStatusLabel(status)}.`)}catch(e){notify(e.message,true);state.data=await api('bootstrap');render()}}
function bindLeadDragDrop(){
 let draggingId=0;
 root.querySelectorAll('.apm-lead-card[draggable="true"]').forEach(card=>{
  card.addEventListener('dragstart',e=>{if(e.target.closest('button')&&!e.target.closest('.apm-lead-drag')){e.preventDefault();return}draggingId=Number(card.dataset.leadId);card.classList.add('dragging');try{e.dataTransfer.effectAllowed='move';e.dataTransfer.setData('text/plain',String(draggingId))}catch(_){}});
  card.addEventListener('dragend',()=>{card.classList.remove('dragging');root.querySelectorAll('[data-lead-drop]').forEach(c=>c.classList.remove('drag-over'))});
 });
 root.querySelectorAll('[data-lead-drop]').forEach(col=>{col.addEventListener('dragover',e=>{e.preventDefault();col.classList.add('drag-over')});col.addEventListener('dragleave',e=>{if(!col.contains(e.relatedTarget))col.classList.remove('drag-over')});col.addEventListener('drop',e=>{e.preventDefault();col.classList.remove('drag-over');const id=draggingId||Number(e.dataTransfer?.getData('text/plain'));if(id)moveLeadCard(id,col.dataset.leadDrop)})});
 // Touch/pointer: o botão pontilhado funciona como alça de arraste.
 let touch=null,ghost=null;
 root.querySelectorAll('.apm-lead-drag').forEach(handle=>handle.addEventListener('pointerdown',e=>{if(e.pointerType==='mouse')return;e.preventDefault();const card=handle.closest('.apm-lead-card');touch={id:Number(card.dataset.leadId),pointerId:e.pointerId};handle.setPointerCapture?.(e.pointerId);ghost=card.cloneNode(true);ghost.classList.add('apm-lead-ghost');document.body.appendChild(ghost);positionGhost(e.clientX,e.clientY)}));
 root.querySelectorAll('.apm-lead-drag').forEach(handle=>handle.addEventListener('pointermove',e=>{if(!touch||touch.pointerId!==e.pointerId)return;e.preventDefault();positionGhost(e.clientX,e.clientY);document.querySelectorAll('[data-lead-drop]').forEach(c=>c.classList.remove('drag-over'));const el=document.elementFromPoint(e.clientX,e.clientY);el?.closest?.('[data-lead-drop]')?.classList.add('drag-over')}));
 root.querySelectorAll('.apm-lead-drag').forEach(handle=>handle.addEventListener('pointerup',e=>{if(!touch||touch.pointerId!==e.pointerId)return;const el=document.elementFromPoint(e.clientX,e.clientY),col=el?.closest?.('[data-lead-drop]');document.querySelectorAll('[data-lead-drop]').forEach(c=>c.classList.remove('drag-over'));ghost?.remove();ghost=null;const id=touch.id;touch=null;if(col)moveLeadCard(id,col.dataset.leadDrop)}));
 function positionGhost(x,y){if(ghost){ghost.style.left=(x-130)+'px';ghost.style.top=(y-25)+'px'}}
}

function filterAppointmentProfessionals(){const service=Number(document.getElementById('apm-appointment-service')?.value||0),sel=document.getElementById('apm-appointment-professional');if(!sel)return;[...sel.options].forEach((o,i)=>{if(i===0)return;const services=(o.dataset.services||'').split(',').filter(Boolean).map(Number);o.hidden=!!service&&!services.includes(service)});if(sel.selectedOptions[0]?.hidden)sel.value=''}
let apmAppointmentSlotsSeq=0;
function renderAppointmentSlotsBox(html){const box=document.getElementById('apm-appointment-slots');if(box)box.innerHTML=html}
async function loadAppointmentSlots(){
 const f=document.getElementById('apm-appointment-form');if(!f)return;
 const serviceId=Number(f.elements.service_id?.value||0),professionalId=Number(f.elements.professional_id?.value||0),date=f.elements.date?.value||'',timeInput=f.elements.start_time,appointmentId=Number(f.elements.id?.value||0);
 if(!serviceId||!professionalId||!date){renderAppointmentSlotsBox('<span class="apm-help">Escolha serviço, profissional e data para ver os horários livres.</span>');return}
 const seq=++apmAppointmentSlotsSeq;
 renderAppointmentSlotsBox('<span class="apm-help">Verificando horários livres…</span>');
 try{
  const q=new URLSearchParams({service_id:String(serviceId),professional_id:String(professionalId),date});
  if(appointmentId)q.set('exclude_appointment_id',String(appointmentId));
  const res=await api(`agenda/slots?${q.toString()}`);
  if(seq!==apmAppointmentSlotsSeq)return;
  const slots=res.slots||[];
  const current=timeInput?.value||'';
  const all=current&&!slots.includes(current)?[...slots,current].sort():slots;
  if(!all.length){renderAppointmentSlotsBox('<span class="apm-help">Nenhum horário livre nesse dia para esse profissional/serviço — ajuste a data ou o profissional.</span>');return}
  renderAppointmentSlotsBox(`<label>Horários livres</label><div class="apm-slot-grid">${all.map(t=>`<button type="button" class="apm-slot-btn ${t===current?'active':''}" data-appointment-slot="${esc(t)}">${esc(t)}</button>`).join('')}</div>`);
  document.querySelectorAll('[data-appointment-slot]').forEach(b=>b.addEventListener('click',()=>{if(timeInput)timeInput.value=b.dataset.appointmentSlot;document.querySelectorAll('[data-appointment-slot]').forEach(x=>x.classList.toggle('active',x===b))}));
 }catch(e){if(seq===apmAppointmentSlotsSeq)renderAppointmentSlotsBox(`<span class="apm-help">${esc(e.message||'Não foi possível verificar os horários livres.')}</span>`)}
}
function selectClientInAppointmentForm(id,client){const sel=document.getElementById('apm-appointment-client');if(sel&&client){let opt=[...sel.options].find(o=>Number(o.value)===id);if(!opt){opt=document.createElement('option');opt.value=String(id);opt.textContent=client.name+(client.phone?' · '+apmFormatPhone(client.phone):'');sel.appendChild(opt)}sel.value=String(id);sel.dispatchEvent(new Event('change',{bubbles:true}))}}
function resetQuickClientMatchState(){state.quickClientMatches=null;state.quickClientPendingData=null}
function snapshotAppointmentForm(){const f=document.getElementById('apm-appointment-form');return f?formObject(f):null}
// O app rebuilda o HTML inteiro a cada render() — isso derruba a classe "open" de TODOS os
// modais e zera o formulário de agendamento que estava por trás. Reabre os dois modais e
// devolve os valores já escolhidos (serviço, profissional, data, horário...) para o usuário
// não precisar refazer o agendamento do zero depois de resolver a duplicidade.
function restoreAppointmentModal(snapshot){
 open('apm-appointment-modal');
 const f=document.getElementById('apm-appointment-form');
 if(f&&snapshot){
  fill(f,snapshot);
  filterAppointmentProfessionals();
  if(snapshot.id){const del=f.querySelector('.apm-btn-delete-appointment');if(del){del.style.display='inline-flex';del.dataset.id=snapshot.id}}
  loadAppointmentSlots();
 }
}
async function createQuickClientAndSelect(data){const res=await api('clients',{method:'POST',body:JSON.stringify(data)});const newId=Number(res.id||0);state.data=await api('bootstrap');const client=(state.data.clients||[]).find(c=>Number(c.id)===newId);selectClientInAppointmentForm(newId,client);resetQuickClientMatchState();close('apm-quick-client-modal');notify('Cliente cadastrado e selecionado no agendamento.')}
async function saveQuickClient(e){e.preventDefault();const f=e.currentTarget,btn=f.querySelector('button[type="submit"]'),data=formObject(f);if(btn){btn.disabled=true;btn.textContent='Verificando…'}try{
 const q=new URLSearchParams();if(data.cpf)q.set('cpf',data.cpf);if(data.phone)q.set('phone',data.phone);if(data.email)q.set('email',data.email);
 const matches=q.toString()?await api(`clients/check-matches?${q.toString()}`):[];
 if(matches.length){state.quickClientPendingData=data;state.quickClientMatches=matches;const snap=snapshotAppointmentForm();render();restoreAppointmentModal(snap);open('apm-quick-client-modal');return}
 await createQuickClientAndSelect(data)
}catch(err){notify(err.message,true)}finally{if(btn){btn.disabled=false;btn.textContent='Salvar e selecionar'}}}
async function useQuickClientMatch(id){const c=(state.quickClientMatches||[]).find(x=>Number(x.id)===Number(id));if(!c)return;if(!(state.data.clients||[]).some(x=>Number(x.id)===Number(id))){state.data=await api('bootstrap')}const client=(state.data.clients||[]).find(x=>Number(x.id)===Number(id))||c;selectClientInAppointmentForm(Number(id),client);resetQuickClientMatchState();close('apm-quick-client-modal');notify('Cadastro existente selecionado no agendamento — nenhum cliente novo foi criado.')}
async function createQuickClientAnyway(){const data=state.quickClientPendingData;if(!data)return;state.quickClientMatches=null;try{await createQuickClientAndSelect(data)}catch(err){notify(err.message,true)}}
function backToQuickClientForm(){state.quickClientMatches=null;const snap=snapshotAppointmentForm();render();restoreAppointmentModal(snap);open('apm-quick-client-modal')}
async function saveAppointment(e){e.preventDefault();const f=e.currentTarget,data=formObject(f),id=data.id;if(id&&f.elements.client_id?.disabled)data.client_id=f.elements.client_id.value;delete data.id;const shouldOfferRecord=!!id&&data.status==='completed';try{await api('appointments'+(id?`/${id}`:''),{method:'POST',body:JSON.stringify(data)});close('apm-appointment-modal');notify(id?'Agendamento atualizado.':'Agendamento criado.');await loadAgenda();state.data=await api('bootstrap');if(shouldOfferRecord){const client=(state.data.clients||[]).find(x=>Number(x.id)===Number(data.client_id));const service=(state.data.services||[]).find(x=>Number(x.id)===Number(data.service_id));const professional=(state.data.professionals||[]).find(x=>Number(x.id)===Number(data.professional_id));state.pendingAttendanceRecord={client_id:Number(data.client_id),professional_id:Number(data.professional_id),service_id:Number(data.service_id),date:data.date,start_time:data.start_time,client_name:client?.name||'Cliente',service_name:service?.name||'Atendimento',professional_name:professional?.name||'Profissional'};render();open('apm-attendance-record-prompt')}}catch(err){notify(err.message,true)}}
async function deleteAppointment(e){const id=e.currentTarget.dataset.id;if(!id||!confirm('Excluir definitivamente este agendamento?'))return;try{await api(`appointments/${id}`,{method:'DELETE'});close('apm-appointment-modal');notify('Agendamento excluído.');loadAgenda();state.data=await api('bootstrap')}catch(err){notify(err.message,true)}}
async function saveBlock(e){e.preventDefault();try{await api('blocks',{method:'POST',body:JSON.stringify(formObject(e.currentTarget))});close('apm-block-modal');notify('Horário bloqueado.');loadAgenda()}catch(err){notify(err.message,true)}}
function toggleBlockRangeMode(isRange){
 const f=document.getElementById('apm-block-form');if(!f)return;
 const dateLabel=document.getElementById('apm-block-date-label'),endDateField=document.getElementById('apm-block-end-date-field'),startTimeField=document.getElementById('apm-block-start-time-field'),endTimeField=document.getElementById('apm-block-end-time-field');
 if(!dateLabel||!endDateField||!startTimeField||!endTimeField)return;
 dateLabel.textContent=isRange?'De *':'Data *';
 endDateField.style.display=isRange?'':'none';
 startTimeField.style.display=isRange?'none':'';
 endTimeField.style.display=isRange?'none':'';
 f.elements.end_date.required=isRange;
 f.elements.start_time.required=!isRange;
 f.elements.end_time.required=!isRange;
}

function bindDelete(kind,resource,label){root.querySelectorAll(`[data-delete-${kind}]`).forEach(b=>b.addEventListener('click',async()=>{const id=b.dataset['delete'+kind[0].toUpperCase()+kind.slice(1)];if(!confirm(`Excluir este ${label}?`))return;try{await api(`${resource}/${id}`,{method:'DELETE'});await reloadData(`${label[0].toUpperCase()+label.slice(1)} excluído.`)}catch(e){notify(e.message,true)}}))}
async function saveForm(e,resource,message){e.preventDefault();const form=e.currentTarget,data=formObject(form),id=data.id;delete data.id;try{await api(resource+(id?`/${id}`:''),{method:'POST',body:JSON.stringify(data)});close(form.closest('.apm-modal-backdrop').id);await reloadData(message)}catch(err){notify(err.message,true)}}
async function saveSettings(e){e.preventDefault();const f=e.currentTarget,fd=new FormData(f),kind=f.dataset.settingsKind||'general',data={};if(kind==='hours'){const hours={};['0','1','2','3','4','5','6'].forEach(d=>hours[d]={active:f.elements[`day_${d}_active`].checked?1:0,start:fd.get(`day_${d}_start`),end:fd.get(`day_${d}_end`)});data.opening_hours=hours}else if(kind==='payments'){data.payment_enabled=f.elements.payment_enabled.checked?1:0;data.payment_test_mode=f.elements.payment_test_mode.checked?1:0;data.infinitepay_handle=fd.get('infinitepay_handle')||'';data.infinitepay_api_key=fd.get('infinitepay_api_key')||'';data.payment_hold_minutes=fd.get('payment_hold_minutes');data.payment_gateway=fd.get('payment_gateway')||'infinitepay'}else if(kind==='whatsapp'){data.whatsapp_enabled=f.elements.whatsapp_enabled.checked?1:0;data.zapi_instance_id=fd.get('zapi_instance_id')||'';data.zapi_instance_token=fd.get('zapi_instance_token')||'';data.zapi_client_token=fd.get('zapi_client_token')||'';data.whatsapp_confirmation_enabled=f.elements.whatsapp_confirmation_enabled.checked?1:0;data.whatsapp_reminder_enabled=f.elements.whatsapp_reminder_enabled.checked?1:0;data.whatsapp_reminder_hours_before=fd.get('whatsapp_reminder_hours_before');data.whatsapp_otp_enabled=f.elements.whatsapp_otp_enabled.checked?1:0;data.whatsapp_template_confirmation=fd.get('whatsapp_template_confirmation')||'';data.whatsapp_template_payment_link=fd.get('whatsapp_template_payment_link')||'';data.whatsapp_template_reminder=fd.get('whatsapp_template_reminder')||'';data.whatsapp_template_otp=fd.get('whatsapp_template_otp')||''}else{['spa_name','phone','email','default_deposit','booking_interval','timezone','default_service_id','cancellation_policy_text','meta_pixel_id','gtm_container_id'].forEach(k=>data[k]=fd.get(k));data.anamnesis_auto_send_enabled=f.elements.anamnesis_auto_send_enabled.checked?1:0}try{await api('settings',{method:'POST',body:JSON.stringify(data)});await reloadData(kind==='hours'?'Horários salvos.':kind==='payments'?'Configurações de pagamento salvas.':kind==='whatsapp'?'Configurações de WhatsApp salvas.':'Configurações salvas.')}catch(err){notify(err.message,true)}}
function applyAccessProfile(e){const f=document.getElementById('apm-user-access-form');if(!f)return;applyModulePreset(f,e.target.value);updateUserAccessUI(f)}
function applyCreateProfile(e){const f=document.getElementById('apm-user-create-form');if(!f)return;applyModulePreset(f,e.target.value);updateUserAccessUI(f)}
function applyModulePreset(form,profile){const wanted=accessPresetModules(profile);if(!wanted.length)return;form.querySelectorAll('[name="modules"]').forEach(c=>{if(c.disabled||c.value==='dashboard')c.checked=true;else c.checked=wanted.includes(c.value)})}
function updateUserAccessUI(form){if(!form)return;const counter=form.querySelector('.apm-module-counter');if(counter){const total=[...form.querySelectorAll('[name="modules"]:checked')].map(x=>x.value).filter(v=>v!=='dashboard').length + 1;counter.textContent=`${total} selecionado(s)`}const enabledEl=form.elements.enabled;const redirectEl=form.elements.redirect;if(enabledEl&&redirectEl){if(!enabledEl.checked){redirectEl.checked=false;redirectEl.disabled=true;redirectEl.closest('.apm-option-toggle')?.classList.add('locked')}else{redirectEl.disabled=false;redirectEl.closest('.apm-option-toggle')?.classList.remove('locked')}}}
function bindUserAccessInteractions(){const form=document.getElementById('apm-user-access-form');if(!form)return;form.querySelectorAll('[name="modules"]').forEach(c=>{c.addEventListener('change',()=>updateUserAccessUI(form));const wrap=c.closest('.apm-drawer-module');if(wrap&&!wrap.dataset.bound){wrap.dataset.bound='1';wrap.addEventListener('click',ev=>{if(c.disabled||ev.target.tagName==='INPUT')return;ev.preventDefault();c.checked=!c.checked;c.dispatchEvent(new Event('change',{bubbles:true}))})}});if(form.elements.enabled)form.elements.enabled.addEventListener('change',()=>updateUserAccessUI(form));updateUserAccessUI(form)}
function bindUserCreateInteractions(){const form=document.getElementById('apm-user-create-form');if(!form)return;form.querySelectorAll('[name="modules"]').forEach(c=>{c.addEventListener('change',()=>updateUserAccessUI(form));const wrap=c.closest('.apm-drawer-module');if(wrap&&!wrap.dataset.bound){wrap.dataset.bound='1';wrap.addEventListener('click',ev=>{if(c.disabled||ev.target.tagName==='INPUT')return;ev.preventDefault();c.checked=!c.checked;c.dispatchEvent(new Event('change',{bubbles:true}))})}});if(form.elements.enabled)form.elements.enabled.addEventListener('change',()=>updateUserAccessUI(form));updateUserAccessUI(form)}
async function saveUserAccess(e){e.preventDefault();const f=e.currentTarget,id=f.dataset.userId,mods=[...f.querySelectorAll('[name="modules"]:checked')].map(x=>x.value);if(!mods.includes('dashboard'))mods.unshift('dashboard');const data={enabled:f.elements.enabled?f.elements.enabled.checked?1:0:1,redirect:f.elements.redirect&&f.elements.redirect.checked?1:0,modules:mods};try{await api(`users/${id}/access`,{method:'POST',body:JSON.stringify(data)});state.data=await api('bootstrap');state.editingUserId=0;render();notify('Acesso do usuário atualizado.')}catch(err){notify(err.message,true)}}
async function saveProfessionalAccess(e){e.preventDefault();const f=e.currentTarget,id=f.dataset.professionalId,email=f.elements.email.value;try{const res=await api(`professionals/${id}/create-access`,{method:'POST',body:JSON.stringify({email})});close('apm-professional-access-modal');state.data.users=res.users;state.data.professionals=res.professionals;const pro=(state.data.professionals||[]).find(p=>Number(p.id)===Number(id));state.professionalAccessCreated={id:res.user.id,login:res.user.login,password:res.user.password,name:pro?.name||''};render();open('apm-professional-access-created-modal')}catch(err){notify(err.message,true)}}
async function saveNewUser(e){e.preventDefault();const f=e.currentTarget,mods=[...f.querySelectorAll('[name="modules"]:checked')].map(x=>x.value);if(!mods.includes('dashboard'))mods.unshift('dashboard');const data={name:f.elements.name.value,email:f.elements.email.value,login:f.elements.login.value,password:f.elements.password.value,role:f.elements.role.value,enabled:f.elements.enabled.checked?1:0,redirect:f.elements.redirect.checked?1:0,modules:mods};try{const res=await api('users',{method:'POST',body:JSON.stringify(data)});close('apm-user-create-modal');state.data=await api('bootstrap');render();notify(`Usuário criado com sucesso. Login: ${res.user.login}`)}catch(err){notify(err.message,true)}}
function formatDate(s){if(!s)return'';const [y,m,d]=s.split('-');return `${d}/${m}/${y}`}

async function saveServiceWithTechnicalSheet(e){e.preventDefault();const form=e.currentTarget,data=formObject(form),id=data.id;delete data.id;data.technical_sheet=collectTechnicalSheet();try{await api('services'+(id?`/${id}`:''),{method:'POST',body:JSON.stringify(data)});close('apm-service-modal');await reloadData('Serviço e ficha técnica salvos.')}catch(err){notify(err.message,true)}}
async function saveInventoryItem(e){e.preventDefault();const form=e.currentTarget,data=formObject(form),id=data.id;delete data.id;if(id)delete data.current_quantity;try{await api('inventory'+(id?`/${id}`:''),{method:'POST',body:JSON.stringify(data)});close('apm-inventory-item-modal');notify('Item de estoque salvo.');await loadInventory()}catch(err){notify(err.message,true)}}
async function saveInventoryMovement(e){e.preventDefault();const form=e.currentTarget,data=formObject(form),id=Number(data.item_id||0);delete data.item_id;try{await api(`inventory/${id}/movement`,{method:'POST',body:JSON.stringify(data)});close('apm-inventory-movement-modal');notify('Movimentação registrada.');await loadInventory()}catch(err){notify(err.message,true)}}


function resetCategoryForm(){
 const f=document.getElementById('apm-category-form');if(!f)return;f.reset();if(f.elements.id)f.elements.id.value='';if(f.elements.active)f.elements.active.checked=true;const submit=f.querySelector('[data-category-submit]');if(submit)submit.textContent='Adicionar categoria';const cancel=f.querySelector('[data-category-cancel-edit]');if(cancel)cancel.style.display='none';
}
function updateServiceDepositPreview(){
 const type=document.getElementById('apm-service-deposit-type'),val=document.getElementById('apm-service-deposit-value'),price=document.getElementById('apm-service-price'),label=document.getElementById('apm-service-deposit-label'),preview=document.getElementById('apm-service-deposit-preview');
 if(!type||!val||!price||!label||!preview)return;
 const isPercentage=type.value!=='fixed',p=Number(price.value)||0,v=Number(val.value)||0;
 label.textContent=isPercentage?'Valor do sinal (%)':'Valor do sinal (R$)';
 val.step=isPercentage?'0.01':'0.01';val.max=isPercentage?'100':'';
 if(isPercentage){const amount=p*(v/100);preview.textContent=p?`= ${brl(amount)} sobre o valor do procedimento`:'Informe o valor do procedimento para ver o sinal calculado.'}
 else{preview.textContent=`O cliente paga ${brl(v)} de sinal, não importa o valor do procedimento.`}
}
function updateServiceComparePreview(){
 const price=document.getElementById('apm-service-price'),compare=document.getElementById('apm-service-compare-price'),preview=document.getElementById('apm-service-compare-preview');
 if(!price||!compare||!preview)return;
 const p=Number(price.value)||0,c=Number(compare.value)||0;
 if(!compare.value.trim()){preview.textContent='Se preenchido e maior que o Valor, aparece riscado ao lado do preço no agendamento online, destacando o desconto.';return}
 if(c<=p){preview.textContent='Precisa ser maior que o Valor para aparecer riscado — do contrário, nenhum desconto é mostrado.';return}
 const off=c-p,pct=Math.round((off/c)*100);
 preview.textContent=`Desconto de ${pct}% (economia de ${brl(off)}) aparecerá riscado ao lado de ${brl(p)}.`;
}
function editCategory(id){
 const x=(state.data?.categories||[]).find(c=>Number(c.id)===Number(id));const f=document.getElementById('apm-category-form');if(!x||!f)return;f.elements.id.value=x.id;f.elements.name.value=x.name||'';if(f.elements.active)f.elements.active.checked=!!Number(x.active);const submit=f.querySelector('[data-category-submit]');if(submit)submit.textContent='Salvar alterações';const cancel=f.querySelector('[data-category-cancel-edit]');if(cancel)cancel.style.display='';f.elements.name.focus();
}
