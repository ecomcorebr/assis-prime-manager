/* v0.9.33 · Anamnese movida para um modelo genérico (perguntas vêm de state.data.anamnesisTemplates,
   a mesma fonte usada pela página pública onde o cliente preenche sozinho), com link de
   preenchimento pelo cliente. */
const APM_ANAMNESIS_TEMPLATE='facial_cleaning';
const apmYesNo=[['','Selecione'],['yes','Sim'],['no','Não']];
function apmAnamnesisTemplateDefs(){return state.data?.anamnesisTemplates||{}}
function apmAnamnesisTemplate(key){return apmAnamnesisTemplateDefs()[key]||null}
function apmAnamnesisTemplateTitle(key){return apmAnamnesisTemplate(key)?.title||'Anamnese'}
function apmSelectOptions(options,value=''){return (options||[]).map(([v,l])=>`<option value="${esc(v)}" ${String(value??'')===String(v)?'selected':''}>${esc(l)}</option>`).join('')}
// O rascunho em memória (state.anamnesisDraft) tem prioridade sobre o que já está salvo no
// servidor: se a tela for redesenhada enquanto o formulário está aberto (ex.: navegação para
// trás no navegador/celular), o modal é reconstruído do zero e precisa repor exatamente o que
// a pessoa já tinha digitado, não voltar em branco nem descartar edições ainda não salvas.
function apmAnamnesisData(){const rec=(state.data?.records||[]).find(r=>Number(r.id)===Number(state.anamnesisEditId||state.anamnesisParentId));const base=(rec?.structured_data&&typeof rec.structured_data==='object')?rec.structured_data:{};return Object.assign({},base,state.anamnesisDraft||{})}
function apmAnamnesisValue(data,key,def=''){return data&&Object.prototype.hasOwnProperty.call(data,key)?data[key]:def}
function apmAnamnesisYesNoField(data,key,label,detailLabel=''){
 const v=apmAnamnesisValue(data,key,'');const detail=apmAnamnesisValue(data,key+'_detail','');
 return `<div class="apm-anamnesis-question"><label>${esc(label)}</label><select class="apm-select" name="${esc(key)}" data-anamnesis-conditional="${esc(key)}">${apmSelectOptions(apmYesNo,v)}</select>${detailLabel?`<div class="apm-anamnesis-detail ${v==='yes'?'':'is-hidden'}" data-anamnesis-detail="${esc(key)}"><label>${esc(detailLabel)}</label><input class="apm-input" name="${esc(key)}_detail" value="${esc(detail)}"></div>`:''}</div>`
}
function apmAnamnesisFieldMarkup(f,data){
 if(f.type==='yesno')return apmAnamnesisYesNoField(data,f.key,f.label,f.detailLabel||'');
 if(f.type==='select')return `<div class="apm-field"><label>${esc(f.label)}</label><select class="apm-select" name="${esc(f.key)}">${apmSelectOptions(f.options,apmAnamnesisValue(data,f.key))}</select></div>`;
 if(f.type==='textarea')return `<div class="apm-field full"><label>${esc(f.label)}</label><textarea class="apm-textarea" name="${esc(f.key)}" rows="4">${esc(apmAnamnesisValue(data,f.key))}</textarea></div>`;
 if(f.type==='date'){const v=apmAnamnesisValue(data,f.key,'');return `<div class="apm-field"><label>${esc(f.label)}</label><input class="apm-input" type="text" inputmode="numeric" placeholder="dd/mm/aaaa" maxlength="10" data-date-mask="${esc(f.key)}" value="${v?esc(formatDate(v)):''}"><input type="hidden" name="${esc(f.key)}" value="${esc(v)}"></div>`}
 return `<div class="apm-field"><label>${esc(f.label)}</label><input class="apm-input" name="${esc(f.key)}" value="${esc(apmAnamnesisValue(data,f.key))}"></div>`;
}
function apmAnamnesisSessionsMarkup(){const rows=(state.recordClientAppointments||[]).filter(a=>a.status==='completed').sort((a,b)=>String(b.start_at).localeCompare(String(a.start_at))).slice(0,20);return rows.length?`<div class="apm-anamnesis-session-table"><div class="head"><span>Data</span><span>Procedimento</span><span>Profissional</span></div>${rows.map(a=>`<div><span>${esc(formatDate(String(a.start_at||'').slice(0,10)))}</span><span>${esc(a.service_name||'Atendimento')}</span><span>${esc(a.professional_name||'—')}</span></div>`).join('')}</div>`:`<div class="apm-info-strip">Ainda não há atendimentos concluídos para alimentar o controle de sessões.</div>`}
function anamnesisTemplatePickerModal(){
 const defs=apmAnamnesisTemplateDefs();const keys=Object.keys(defs);
 return modalWrap('apm-anamnesis-modal','Qual ficha preencher?',`<div class="apm-modal-body"><p class="apm-help">Escolha o modelo de ficha para esta cliente.</p><div class="apm-anamnesis-template-picker">${keys.map(k=>`<button type="button" class="apm-anamnesis-template-option" data-anamnesis-pick-template="${esc(k)}"><strong>${esc(defs[k].title)}</strong><span>${defs[k].steps.length} etapas</span></button>`).join('')}</div></div><div class="apm-modal-footer"><button type="button" class="apm-btn apm-btn-secondary" data-close="apm-anamnesis-modal">Cancelar</button></div>`,false)
}
function anamnesisWizardModal(){
 if(state.anamnesisPickingTemplate)return anamnesisTemplatePickerModal();
 const templateKey=state.anamnesisTemplateKey||APM_ANAMNESIS_TEMPLATE;
 const tpl=apmAnamnesisTemplate(templateKey);
 if(!tpl)return modalWrap('apm-anamnesis-modal','Anamnese','<div class="apm-modal-body"></div>',false);
 const client=(state.data?.clients||[]).find(c=>Number(c.id)===Number(state.recordClientId));
 const rec=(state.data?.records||[]).find(r=>Number(r.id)===Number(state.anamnesisEditId));
 const data=apmAnamnesisData();const pros=(state.data?.professionals||[]).filter(p=>Number(p.active));
 const gender=apmAnamnesisValue(data,'client_gender',client?.gender||'');const cpf=apmAnamnesisValue(data,'client_cpf',client?.cpf||'');const birth=apmAnamnesisValue(data,'client_birth_date',client?.birth_date||'');const phone=apmAnamnesisValue(data,'client_phone',client?.phone||'');
 const profId=rec?.professional_id||apmAnamnesisValue(data,'professional_id','');
 const steps=tpl.steps;
 const stepBody=(step)=>{
  if(step.id===1){
   return `<div class="apm-anamnesis-step-head"><h4>${esc(step.label)}</h4><p>${esc(step.intro||'')}</p></div><div class="apm-form-grid"><div class="apm-field full"><label>Cliente</label><input class="apm-input" value="${esc(client?.name||'Cliente')}" disabled></div>${step.fields.map(f=>apmAnamnesisFieldMarkup(f,data)).join('')}<div class="apm-field full"><label class="apm-toggle"><input type="checkbox" name="update_client" checked> Atualizar também o cadastro do cliente com estes dados</label></div></div>`;
  }
  if(step.id===steps.length){
   return `<div class="apm-anamnesis-step-head"><h4>${esc(step.label)}</h4><p>${esc(step.intro||'')}</p></div><div class="apm-form-grid">${step.fields.map(f=>apmAnamnesisFieldMarkup(f,data)).join('')}</div><div class="apm-anamnesis-session-block"><h5>Controle de sessões / atendimentos</h5><p>Preenchido automaticamente pelos atendimentos concluídos na Agenda.</p>${apmAnamnesisSessionsMarkup()}</div><label class="apm-anamnesis-declaration"><input type="checkbox" name="declaration_confirmed" ${String(apmAnamnesisValue(data,'declaration_confirmed'))==='1'?'checked':''}><span>Declaro que as informações acima são verdadeiras, não cabendo ao profissional responsabilidade por informações omitidas nesta avaliação.</span></label>`;
  }
  if((step.audience||'both')==='professional'){
   const yesno=step.fields.filter(f=>f.type==='yesno'),selects=step.fields.filter(f=>f.type==='select');
   return `<div class="apm-anamnesis-step-head"><h4>${esc(step.label)}</h4><p>${esc(step.intro||'')}</p></div><div class="apm-form-grid"><div class="apm-field full"><label>Profissional responsável</label><select class="apm-select" name="professional_id"><option value="">Selecione</option>${pros.map(p=>`<option value="${p.id}" ${Number(profId)===Number(p.id)?'selected':''}>${esc(p.name)}</option>`).join('')}</select></div></div><div class="apm-anamnesis-prof-grid">${yesno.length?`<div class="apm-anamnesis-group"><h5>Lesões e características</h5>${yesno.map(f=>apmAnamnesisFieldMarkup(f,data)).join('')}</div>`:''}${selects.length?`<div class="apm-anamnesis-group"><h5>Classificação da pele</h5>${selects.map(f=>apmAnamnesisFieldMarkup(f,data)).join('')}</div>`:''}</div>`;
  }
  return `<div class="apm-anamnesis-step-head"><h4>${esc(step.label)}</h4><p>${esc(step.intro||'')}</p></div><div class="apm-anamnesis-question-grid">${step.fields.map(f=>apmAnamnesisFieldMarkup(f,data)).join('')}</div>`;
 };
 return modalWrap('apm-anamnesis-modal',rec?`${esc(tpl.title)} · v${Number(rec.version_no||1)}`:`Nova anamnese — ${esc(tpl.title.replace(/^Anamnese\s*—\s*/,''))}`,`<form id="apm-anamnesis-form" data-record-id="${rec?.id||0}" data-template-key="${esc(templateKey)}"><div class="apm-anamnesis-progress">${steps.map((s,i)=>`<span class="${i===0?'active':''}" data-anamnesis-progress="${i+1}">${i+1} ${esc(s.label)}</span>`).join('')}</div><div class="apm-modal-body apm-anamnesis-modal-body">
 ${steps.map((s,i)=>`<section class="apm-anamnesis-step ${i===0?'active':''}" data-anamnesis-step="${i+1}">${stepBody(s)}</section>`).join('')}
 </div><div class="apm-modal-footer apm-anamnesis-footer"><button type="button" class="apm-btn apm-btn-secondary" data-anamnesis-prev style="display:none">Voltar</button><div class="apm-anamnesis-footer-spacer"></div><button type="button" class="apm-btn apm-btn-secondary" data-close="apm-anamnesis-modal">Cancelar</button><button type="button" class="apm-btn apm-btn-primary" data-anamnesis-next>Continuar</button><button type="button" class="apm-btn apm-btn-secondary" data-anamnesis-save="draft" style="display:none">Salvar rascunho</button><button type="button" class="apm-btn apm-btn-primary" data-anamnesis-save="signature" style="display:none">Salvar e solicitar assinatura</button></div></form>`,false)
}
function apmAnamnesisCollect(form){const fd=new FormData(form),data={};for(const [k,v] of fd.entries()){if(k==='update_client')continue;data[k]=v}data.declaration_confirmed=form.elements.declaration_confirmed?.checked?1:0;data.professional_id=form.elements.professional_id?.value||'';return data}
function apmAnamnesisLabel(key){const map={yes:'Sim',no:'Não',female:'Feminino',male:'Masculino',other:'Outro',dry:'Alípica / seca',oily:'Lipídica / oleosa',normal:'Normal',mixed:'Pele mista',thick:'Espessa',thin:'Fina',very_thin:'Muito fina',coarse:'Grossa',mild:'Leve',moderate:'Moderado',advanced:'Avançado',severe:'Severo',superficial:'Superficiais',medium:'Médias',deep:'Profundas'};return map[key]||key||'Não informado'}
function apmAnamnesisSummaryHtml(data,client,templateKey){
 const tpl=apmAnamnesisTemplate(templateKey||APM_ANAMNESIS_TEMPLATE)||{title:'Anamnese',steps:[]};
 const rows=(fields)=>fields.map(f=>{const v=data[f.key];if(!v)return'';if(f.type==='yesno'){const det=data[f.key+'_detail'];return `<div><strong>${esc(f.label)}</strong><span>${esc(apmAnamnesisLabel(v))}${det?` · ${esc(det)}`:''}</span></div>`}if(f.type==='select'){const opt=(f.options||[]).find(([ov])=>String(ov)===String(v));return `<div><strong>${esc(f.label)}</strong><span>${esc(opt?opt[1]:apmAnamnesisLabel(v))}</span></div>`}return''}).join('');
 const textareas=(fields)=>fields.filter(f=>f.type==='textarea').map(f=>`<p><strong>${esc(f.label)}:</strong><br>${esc(data[f.key]||'Não informado')}</p>`).join('');
 const sectionsHtml=tpl.steps.filter(s=>s.id!==1).map(s=>{const grid=rows(s.fields);return `${grid?`<h4>${esc(s.label)}</h4><div class="apm-document-grid">${grid}</div>`:''}${textareas(s.fields)}`}).join('');
 return `<div class="apm-signed-document"><h3>${esc(tpl.title)}</h3><h4>Identificação</h4><div class="apm-document-grid"><div><strong>Nome</strong><span>${esc(client?.name||'')}</span></div><div><strong>CPF</strong><span>${esc(data.client_cpf||'Não informado')}</span></div><div><strong>Nascimento</strong><span>${data.client_birth_date?esc(formatDate(data.client_birth_date)):'Não informado'}</span></div><div><strong>Sexo</strong><span>${esc(apmAnamnesisLabel(data.client_gender))}</span></div><div><strong>Telefone</strong><span>${esc(data.client_phone||'Não informado')}</span></div></div>${sectionsHtml}<div class="apm-document-declaration"><strong>Declaração</strong><p>Declaro que as informações acima são verdadeiras, não cabendo ao profissional responsabilidade por informações omitidas nesta avaliação.</p><span>Confirmação registrada: ${data.declaration_confirmed?'Sim':'Não'}</span></div></div>`
}
function openAnamnesisWizard(recordId=0,parentId=0){
 const rec=(state.data?.records||[]).find(r=>Number(r.id)===Number(recordId||parentId));
 const keys=Object.keys(apmAnamnesisTemplateDefs());
 if(!rec&&keys.length>1){
  // Mais de um modelo cadastrado e nenhum registro existente ainda para herdar o modelo:
  // pergunta qual ficha usar antes de abrir o assistente, em vez de assumir sempre a mesma.
  state.anamnesisPickingTemplate=true;state.anamnesisTemplateKey=null;
  state.anamnesisEditId=Number(recordId)||0;state.anamnesisParentId=Number(parentId)||0;render();open('apm-anamnesis-modal');return
 }
 if(!rec&&!keys.length){notify('Nenhum modelo de anamnese cadastrado ainda. Cadastre um em Prontuários > Modelos de anamnese.',true);return}
 state.anamnesisPickingTemplate=false;
 state.anamnesisTemplateKey=rec?.template_key||keys[0]||APM_ANAMNESIS_TEMPLATE;
 state.anamnesisEditId=Number(recordId)||0;state.anamnesisParentId=Number(parentId)||0;state.anamnesisStep=1;state.anamnesisDraft={};render();open('apm-anamnesis-modal')
}
function apmAnamnesisShowStep(step){
 const tpl=apmAnamnesisTemplate(state.anamnesisTemplateKey||APM_ANAMNESIS_TEMPLATE);const total=tpl?tpl.steps.length:4;
 state.anamnesisStep=Math.min(total,Math.max(1,Number(step)||1));const modal=document.getElementById('apm-anamnesis-modal');if(!modal)return;modal.querySelectorAll('[data-anamnesis-step]').forEach(x=>x.classList.toggle('active',Number(x.dataset.anamnesisStep)===state.anamnesisStep));modal.querySelectorAll('[data-anamnesis-progress]').forEach(x=>{const n=Number(x.dataset.anamnesisProgress);x.classList.toggle('active',n===state.anamnesisStep);x.classList.toggle('done',n<state.anamnesisStep)});const prev=modal.querySelector('[data-anamnesis-prev]'),next=modal.querySelector('[data-anamnesis-next]'),draft=modal.querySelector('[data-anamnesis-save="draft"]'),sig=modal.querySelector('[data-anamnesis-save="signature"]');if(prev)prev.style.display=state.anamnesisStep>1?'':'none';if(next)next.style.display=state.anamnesisStep<total?'':'none';if(draft)draft.style.display=state.anamnesisStep===total?'':'none';if(sig)sig.style.display=state.anamnesisStep===total?'':'none'}
async function saveAnamnesis(mode='draft'){
 const form=document.getElementById('apm-anamnesis-form');if(!form)return;const client=(state.data?.clients||[]).find(c=>Number(c.id)===Number(state.recordClientId));const data=apmAnamnesisCollect(form);if(mode==='signature'&&!data.declaration_confirmed){notify('Confirme a declaração antes de solicitar a assinatura.',true);return}
 const templateKey=form.dataset.templateKey||APM_ANAMNESIS_TEMPLATE;
 const id=Number(form.dataset.recordId)||0;const payload={client_id:Number(state.recordClientId),professional_id:Number(data.professional_id)||null,type:'anamnesis',title:apmAnamnesisTemplateTitle(templateKey),content:apmAnamnesisSummaryHtml(data,client,templateKey),structured_data:data,template_key:templateKey,status:'draft',parent_record_id:!id?Number(state.anamnesisParentId)||null:null};if(form.elements.update_client?.checked)payload.client_updates={cpf:data.client_cpf,birth_date:data.client_birth_date,gender:data.client_gender,phone:data.client_phone};
 try{const saved=await api('records'+(id?`/${id}`:''),{method:'POST',body:JSON.stringify(payload)});const rid=Number(id||saved.id);close('apm-anamnesis-modal');state.data=await api('bootstrap');state.anamnesisEditId=0;state.anamnesisParentId=0;if(mode==='signature'){state.signatureRecordId=rid;state.signatureResult=await api(`records/${rid}/signature`,{method:'POST',body:JSON.stringify({expires_hours:48})});state.data=await api('bootstrap');render();open('apm-signature-modal');notify('Anamnese salva e link de assinatura gerado.')}else{render();notify('Anamnese salva como rascunho.')}}catch(e){notify(e.message,true)}
}
function bindAnamnesisWizard(){
 const modal=document.getElementById('apm-anamnesis-modal');if(!modal||modal.dataset.anamnesisBound==='1')return;modal.dataset.anamnesisBound='1';
 modal.querySelectorAll('[data-anamnesis-pick-template]').forEach(b=>b.addEventListener('click',()=>{state.anamnesisPickingTemplate=false;state.anamnesisTemplateKey=b.dataset.anamnesisPickTemplate;state.anamnesisStep=1;state.anamnesisDraft={};render();open('apm-anamnesis-modal')}));
 if(state.anamnesisPickingTemplate)return;
 apmAnamnesisShowStep(state.anamnesisStep||1);
 const anamnesisForm=modal.querySelector('#apm-anamnesis-form');
 // Mantém o rascunho em memória sempre igual ao que está na tela, em cada tecla/seleção,
 // para que um redesenho inesperado da página nunca apague o que já foi preenchido.
 if(anamnesisForm){const syncDraft=()=>{state.anamnesisDraft=apmAnamnesisCollect(anamnesisForm)};anamnesisForm.addEventListener('input',syncDraft);anamnesisForm.addEventListener('change',syncDraft)}
 modal.querySelectorAll('[data-anamnesis-conditional]').forEach(sel=>{const apply=()=>{const d=modal.querySelector(`[data-anamnesis-detail="${sel.dataset.anamnesisConditional}"]`);if(d)d.classList.toggle('is-hidden',sel.value!=='yes')};sel.addEventListener('change',apply);apply()});
 modal.querySelector('[data-anamnesis-next]')?.addEventListener('click',()=>apmAnamnesisShowStep((state.anamnesisStep||1)+1));modal.querySelector('[data-anamnesis-prev]')?.addEventListener('click',()=>apmAnamnesisShowStep((state.anamnesisStep||1)-1));modal.querySelectorAll('[data-anamnesis-save]').forEach(b=>b.addEventListener('click',()=>saveAnamnesis(b.dataset.anamnesisSave)));
}
function recordAnamnesisTab(rows){
 const items=rows.filter(x=>x.type==='anamnesis');
 return `<div class="apm-record-tab-head"><div><h3>Anamneses</h3><p>Histórico das avaliações do cliente. Documentos assinados ficam protegidos e novas alterações geram uma nova versão.</p></div><button class="apm-btn apm-btn-secondary apm-btn-sm" data-anamnesis-new>+ Nova anamnese</button></div>${items.length?`<div class="apm-anamnesis-list">${items.map(r=>{const tpl=apmAnamnesisTemplate(r.template_key);const known=!!tpl;const signed=r.status==='signed';const canFillLink=known&&!signed&&r.status!=='awaiting_signature';return `<article class="apm-anamnesis-card"><div class="apm-anamnesis-card-main"><div><span class="apm-anamnesis-template">${known?tpl.title.replace(/^Anamnese\s*—\s*/,''):'Anamnese'}</span><strong>${esc(r.title)}</strong><small>Versão ${Number(r.version_no||1)} · ${esc(formatDate((r.updated_at||r.created_at||'').slice(0,10)))}${r.professional_name?' · '+esc(r.professional_name):''}</small></div><span class="apm-status ${signed?'':'off'}">${recordStatusLabel(r.status)}</span></div><div class="apm-anamnesis-card-preview">${r.content||'<span class="apm-muted">Sem conteúdo.</span>'}</div><div class="apm-anamnesis-card-actions">${known&&!signed?`<button class="apm-btn apm-btn-secondary apm-btn-sm" data-anamnesis-edit="${r.id}">Continuar preenchimento</button>`:''}${known&&signed?`<button class="apm-btn apm-btn-secondary apm-btn-sm" data-anamnesis-version="${r.id}">Criar nova versão</button>`:''}${!known&&!signed?`<button class="apm-btn apm-btn-secondary apm-btn-sm" data-edit-record="${r.id}">Editar registro antigo</button>`:''}${canFillLink?`<button class="apm-btn apm-btn-secondary apm-btn-sm" data-anamnesis-fill-link="${r.id}">Enviar para o cliente preencher</button>`:''}${!signed&&r.status!=='awaiting_signature'?`<button class="apm-btn apm-btn-primary apm-btn-sm" data-signature-record="${r.id}">Solicitar assinatura</button>`:''}</div></article>`}).join('')}</div>`:`<div class="apm-record-empty-state"><strong>Nenhuma anamnese preenchida</strong><span>Crie a anamnese com dados do cliente, avaliação profissional e assinatura — ou envie o link para o próprio cliente preencher.</span><button class="apm-btn apm-btn-primary apm-btn-sm" data-anamnesis-new>Preencher primeira anamnese</button></div>`}<div class="apm-anamnesis-sessions-summary"><div class="apm-record-tab-head compact"><div><h3>Controle de sessões / atendimentos</h3><p>Atualizado automaticamente a partir da Agenda.</p></div></div>${apmAnamnesisSessionsMarkup()}</div>`
}
function anamnesisFillLinkModal(){
 const l=state.anamnesisFillLink;if(!l)return modalWrap('apm-anamnesis-fill-link-modal','Link de preenchimento','<div class="apm-modal-body"></div>',true);
 return modalWrap('apm-anamnesis-fill-link-modal','Link para o cliente preencher',`<div class="apm-modal-body"><div class="apm-info-strip">Envie este link para ${esc(l.clientName||'o cliente')} por WhatsApp, e-mail ou SMS. Ele vai responder as perguntas e assinar sozinho — o documento entra automaticamente no prontuário assim que ele confirmar. O link expira em 7 dias.</div><div class="apm-field full"><label>Link</label><div class="apm-copy-field"><input class="apm-input" value="${esc(l.url)}" readonly><button type="button" class="apm-btn apm-btn-secondary apm-btn-sm" data-copy="${esc(l.url)}">Copiar</button></div></div></div><div class="apm-modal-footer"><button type="button" class="apm-btn apm-btn-primary" data-close="apm-anamnesis-fill-link-modal">Concluir</button></div>`,true)
}
function openRecordForClient(type='evolution'){
 if(type==='anamnesis'){openAnamnesisWizard();return}
 const f=document.getElementById('apm-record-form');if(!f)return;f.reset();f.elements.client_id.value=String(state.recordClientId||'');f.elements.type.value=type;f.elements.status.value='draft';const titles={evolution:'Registro de atendimento',term:'Documento',consent:'Termo de consentimento',other:'Registro do cliente'};f.elements.title.value=titles[type]||'Registro do cliente';const modal=document.getElementById('apm-record-modal');if(modal){const h=modal.querySelector('.apm-modal-head h3');const submit=modal.querySelector('button[type="submit"]');if(h)h.textContent=type==='evolution'?'Registrar atendimento':'Adicionar documento';if(submit)submit.textContent=type==='evolution'?'Salvar atendimento':'Salvar documento'}open('apm-record-modal')
}
