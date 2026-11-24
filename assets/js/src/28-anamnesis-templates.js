/* Cadastro de modelos de anamnese (tipos de ficha). Cada serviço pode escolher um desses
   modelos (ou nenhum) em Serviços > Ficha de anamnese; a etapa de Identificação e a etapa
   final de declaração são sempre adicionadas automaticamente pelo servidor — aqui só se
   cadastram as perguntas específicas do procedimento. */
function anamnesisFieldTypeOptions(v){return [['yesno','Sim ou não'],['text','Texto curto'],['textarea','Texto longo'],['date','Data'],['select','Múltipla escolha']].map(([val,label])=>`<option value="${val}" ${v===val?'selected':''}>${label}</option>`).join('')}
function anamnesisTemplateFieldRow(stepIdx,fieldIdx,f){
 return `<div class="apm-anamnesis-field-row" data-step="${stepIdx}" data-field="${fieldIdx}">
  <input class="apm-input" data-field-label value="${esc(f.label||'')}" placeholder="Pergunta (ex.: Possui alergia?)">
  <select class="apm-select" data-field-type>${anamnesisFieldTypeOptions(f.type||'yesno')}</select>
  ${f.type==='yesno'?`<input class="apm-input" data-field-detail value="${esc(f.detailLabel||'')}" placeholder="Pergunta extra se responder 'Sim' (opcional)">`:''}
  ${f.type==='select'?`<textarea class="apm-textarea" data-field-options rows="3" placeholder="Uma opção por linha">${esc((f.options||[]).map(o=>Array.isArray(o)?o[1]:o).join('\n'))}</textarea>`:''}
  <button type="button" class="apm-action-icon" data-remove-field title="Remover pergunta">${moduleIcon('close')}</button>
 </div>`
}
function anamnesisTemplateStepMarkup(step,stepIdx){
 return `<div class="apm-anamnesis-step-card" data-step="${stepIdx}">
  <div class="apm-anamnesis-step-card-head">
   <input class="apm-input" data-step-label value="${esc(step.label||'')}" placeholder="Nome da etapa (ex.: Saúde e rotina)">
   <select class="apm-select" data-step-audience title="Quem vê estas perguntas"><option value="both" ${step.audience!=='professional'?'selected':''}>Cliente e equipe</option><option value="professional" ${step.audience==='professional'?'selected':''}>Somente a equipe</option></select>
   <button type="button" class="apm-btn apm-btn-danger apm-btn-sm" data-remove-step>Remover etapa</button>
  </div>
  <input class="apm-input" data-step-intro value="${esc(step.intro||'')}" placeholder="Texto de introdução para quem vai responder (opcional)">
  <div class="apm-anamnesis-fields-list">${(step.fields||[]).map((f,fi)=>anamnesisTemplateFieldRow(stepIdx,fi,f)).join('')}</div>
  <button type="button" class="apm-btn apm-btn-secondary apm-btn-sm" data-add-field="${stepIdx}">+ Adicionar pergunta</button>
 </div>`
}
function anamnesisTemplateFormModal(){
 const t=state.anamnesisTemplateEditing;
 if(!t)return modalWrap('apm-anamnesis-template-form-modal','Modelo de anamnese','<div class="apm-modal-body"></div>',false);
 return modalWrap('apm-anamnesis-template-form-modal',t.id?'Editar modelo de anamnese':'Novo modelo de anamnese',`<div class="apm-modal-body">
  <div class="apm-field full"><label>Nome do modelo *</label><input class="apm-input" id="apm-anamnesis-template-title" value="${esc(t.title||'')}" placeholder="Ex.: Anamnese — Depilação"></div>
  <div class="apm-info-strip">A etapa de Identificação (CPF, nascimento, telefone...) e a etapa final com a declaração de veracidade são incluídas automaticamente pelo sistema — aqui você só cadastra as perguntas específicas deste procedimento.</div>
  <div id="apm-anamnesis-template-steps">${(t.custom_steps||[]).map((s,i)=>anamnesisTemplateStepMarkup(s,i)).join('')}</div>
  ${!(t.custom_steps||[]).length?'<div class="apm-empty"><strong>Nenhuma etapa ainda</strong>Adicione ao menos uma etapa com uma pergunta.</div>':''}
  <button type="button" class="apm-btn apm-btn-secondary" data-add-step>+ Adicionar etapa</button>
 </div><div class="apm-modal-footer"><button type="button" class="apm-btn apm-btn-secondary" data-close="apm-anamnesis-template-form-modal">Cancelar</button><button type="button" class="apm-btn apm-btn-primary" data-action="save-anamnesis-template">Salvar modelo</button></div>`,false)
}
function anamnesisTemplatesModal(){
 const rows=state.anamnesisTemplatesList||[];
 return modalWrap('apm-anamnesis-templates-modal','Modelos de anamnese',`<div class="apm-modal-body">
  <div class="apm-info-strip">Cada serviço pode ser vinculado a um desses modelos em Serviços &gt; Ficha de anamnese. A cliente só recebe a ficha por e-mail quando o serviço agendado tiver um modelo vinculado.</div>
  <button type="button" class="apm-btn apm-btn-primary apm-btn-sm" data-action="new-anamnesis-template" style="margin:4px 0 12px">+ Novo modelo</button>
  <div class="apm-list">${rows.length?rows.map(r=>`<div class="apm-list-item"><div><strong>${esc(r.title)}</strong><small>${(r.custom_steps||[]).length} etapa${(r.custom_steps||[]).length===1?'':'s'} cadastrada${(r.custom_steps||[]).length===1?'':'s'} · ${Number(r.services_count||0)} serviço${Number(r.services_count||0)===1?'':'s'} vinculado${Number(r.services_count||0)===1?'':'s'}</small></div><div class="apm-category-row-actions"><button class="apm-action-icon" data-edit-anamnesis-template="${r.id}" title="Editar">${moduleIcon('edit')}</button><button class="apm-btn apm-btn-danger apm-btn-sm" data-delete-anamnesis-template="${r.id}" ${Number(r.services_count||0)>0?'title="Desvincule dos serviços antes de excluir"':''}>Excluir</button></div></div>`).join(''):'<div class="apm-empty"><strong>Nenhum modelo cadastrado</strong>Crie o primeiro modelo de ficha.</div>'}</div>
 </div><div class="apm-modal-footer"><button class="apm-btn apm-btn-secondary" data-close="apm-anamnesis-templates-modal">Fechar</button></div>`,true)
}
function collectAnamnesisTemplateForm(){
 const modal=document.getElementById('apm-anamnesis-template-form-modal');const t=state.anamnesisTemplateEditing;if(!modal||!t)return;
 const titleEl=modal.querySelector('#apm-anamnesis-template-title');if(titleEl)t.title=titleEl.value;
 modal.querySelectorAll('.apm-anamnesis-step-card').forEach(stepEl=>{
  const si=Number(stepEl.dataset.step);const step=t.custom_steps[si];if(!step)return;
  step.label=stepEl.querySelector('[data-step-label]')?.value||'';
  step.intro=stepEl.querySelector('[data-step-intro]')?.value||'';
  step.audience=stepEl.querySelector('[data-step-audience]')?.value||'both';
  stepEl.querySelectorAll('.apm-anamnesis-field-row').forEach(fieldEl=>{
   const fi=Number(fieldEl.dataset.field);const f=step.fields[fi];if(!f)return;
   f.label=fieldEl.querySelector('[data-field-label]')?.value||'';
   f.type=fieldEl.querySelector('[data-field-type]')?.value||'text';
   f.detailLabel=fieldEl.querySelector('[data-field-detail]')?.value||'';
   const optText=fieldEl.querySelector('[data-field-options]')?.value||'';
   f.options=optText.split('\n').map(l=>l.trim()).filter(Boolean).map(l=>[l,l]);
  });
 });
}
function reopenAnamnesisTemplateForm(){render();open('apm-anamnesis-template-form-modal')}
function addAnamnesisTemplateStep(){collectAnamnesisTemplateForm();state.anamnesisTemplateEditing.custom_steps.push({label:'',intro:'',audience:'both',fields:[{label:'',type:'yesno'}]});reopenAnamnesisTemplateForm()}
function removeAnamnesisTemplateStep(si){collectAnamnesisTemplateForm();state.anamnesisTemplateEditing.custom_steps.splice(si,1);reopenAnamnesisTemplateForm()}
function addAnamnesisTemplateField(si){collectAnamnesisTemplateForm();state.anamnesisTemplateEditing.custom_steps[si].fields.push({label:'',type:'yesno'});reopenAnamnesisTemplateForm()}
function removeAnamnesisTemplateField(si,fi){collectAnamnesisTemplateForm();state.anamnesisTemplateEditing.custom_steps[si].fields.splice(fi,1);reopenAnamnesisTemplateForm()}
async function loadAnamnesisTemplatesList(){try{state.anamnesisTemplatesList=await api('anamnesis-templates')}catch(e){notify(e.message,true)}render()}
async function openAnamnesisTemplatesList(){await loadAnamnesisTemplatesList();open('apm-anamnesis-templates-modal')}
function openNewAnamnesisTemplate(){state.anamnesisTemplateEditing={id:0,title:'',custom_steps:[{label:'',intro:'',audience:'both',fields:[{label:'',type:'yesno'}]}]};close('apm-anamnesis-templates-modal');reopenAnamnesisTemplateForm()}
function openEditAnamnesisTemplate(id){
 const row=(state.anamnesisTemplatesList||[]).find(r=>Number(r.id)===Number(id));if(!row)return;
 state.anamnesisTemplateEditing={id:row.id,title:row.title,custom_steps:JSON.parse(JSON.stringify(row.custom_steps||[]))};
 close('apm-anamnesis-templates-modal');reopenAnamnesisTemplateForm()
}
async function saveAnamnesisTemplate(){
 collectAnamnesisTemplateForm();const t=state.anamnesisTemplateEditing;if(!t)return;
 try{
  await api('anamnesis-templates'+(t.id?`/${t.id}`:''),{method:'POST',body:JSON.stringify({title:t.title,custom_steps:t.custom_steps})});
  close('apm-anamnesis-template-form-modal');state.anamnesisTemplateEditing=null;
  state.data=await api('bootstrap');await loadAnamnesisTemplatesList();open('apm-anamnesis-templates-modal');notify('Modelo de anamnese salvo.')
 }catch(e){notify(e.message,true)}
}
async function deleteAnamnesisTemplate(id){
 if(!confirm('Excluir este modelo de anamnese?'))return;
 try{await api(`anamnesis-templates/${id}`,{method:'DELETE'});state.data=await api('bootstrap');await loadAnamnesisTemplatesList()}catch(e){notify(e.message,true)}
}
function bindAnamnesisTemplateForm(){
 const modal=document.getElementById('apm-anamnesis-template-form-modal');if(!modal)return;
 modal.querySelector('[data-add-step]')?.addEventListener('click',addAnamnesisTemplateStep);
 modal.querySelectorAll('[data-add-field]').forEach(b=>b.addEventListener('click',()=>addAnamnesisTemplateField(Number(b.dataset.addField))));
 modal.querySelectorAll('[data-remove-step]').forEach(b=>b.addEventListener('click',()=>removeAnamnesisTemplateStep(Number(b.closest('.apm-anamnesis-step-card').dataset.step))));
 modal.querySelectorAll('[data-remove-field]').forEach(b=>b.addEventListener('click',()=>{const row=b.closest('.apm-anamnesis-field-row');removeAnamnesisTemplateField(Number(row.dataset.step),Number(row.dataset.field))}));
 modal.querySelectorAll('[data-field-type]').forEach(s=>s.addEventListener('change',()=>{const row=s.closest('.apm-anamnesis-field-row');collectAnamnesisTemplateForm();reopenAnamnesisTemplateForm()}));
 modal.querySelector('[data-action="save-anamnesis-template"]')?.addEventListener('click',saveAnamnesisTemplate);
}
