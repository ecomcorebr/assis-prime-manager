(()=>{
'use strict';
const root=document.getElementById('apm-record-fill-root');
const esc=v=>String(v??'').replace(/[&<>'"]/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[m]));
const api=async(path,opts={})=>{
  const r=await fetch(APMRecordFill.rest+path,{...opts,headers:{'Content-Type':'application/json',...(opts.headers||{})}});
  let j={}; try{j=await r.json()}catch(e){}
  if(!r.ok) throw new Error(j.message||'Não foi possível concluir.');
  return j;
};
let state=null,step=0;
function fieldMarkup(f,value){
  const v=value??'';
  if(f.type==='yesno'){
    const detail=state.data[f.key+'_detail']||'';
    return `<div class="apm-field"><label>${esc(f.label)}</label><div class="apm-yesno"><label><input type="radio" name="${esc(f.key)}" value="yes" ${v==='yes'?'checked':''}><span>Sim</span></label><label><input type="radio" name="${esc(f.key)}" value="no" ${v==='no'?'checked':''}><span>Não</span></label></div>${f.detailLabel?`<div class="apm-field-detail ${v==='yes'?'':'is-hidden'}" data-detail-for="${esc(f.key)}"><label>${esc(f.detailLabel)}</label><input class="apm-text-input" name="${esc(f.key)}_detail" value="${esc(detail)}"></div>`:''}</div>`;
  }
  if(f.type==='select'){
    return `<div class="apm-field"><label>${esc(f.label)}</label><select class="apm-select-input" name="${esc(f.key)}">${(f.options||[]).map(([ov,ol])=>`<option value="${esc(ov)}" ${String(v)===String(ov)?'selected':''}>${esc(ol)}</option>`).join('')}</select></div>`;
  }
  if(f.type==='date'){
    return `<div class="apm-field"><label>${esc(f.label)}</label><input class="apm-text-input" type="date" name="${esc(f.key)}" value="${esc(v)}"></div>`;
  }
  if(f.type==='textarea'){
    return `<div class="apm-field full"><label>${esc(f.label)}</label><textarea class="apm-textarea-input" name="${esc(f.key)}" rows="4">${esc(v)}</textarea></div>`;
  }
  return `<div class="apm-field"><label>${esc(f.label)}</label><input class="apm-text-input" name="${esc(f.key)}" value="${esc(v)}"></div>`;
}
function stepMarkup(s,idx,total){
  const isLast=idx===total-1;
  return `<section class="apm-fill-step"><div class="apm-fill-step-head"><span class="apm-fill-step-count">Etapa ${idx+1} de ${total}</span><h2>${esc(s.label)}</h2>${s.intro?`<p>${esc(s.intro)}</p>`:''}</div><div class="apm-fill-grid">${s.fields.map(f=>fieldMarkup(f,state.data[f.key])).join('')}</div>
  ${isLast?`<label class="apm-fill-declaration"><input type="checkbox" name="declaration_confirmed" ${state.data.declaration_confirmed?'checked':''}><span>Declaro que as informações acima são verdadeiras.</span></label>`:''}
  <div class="apm-fill-actions">${idx>0?'<button type="button" class="apm-btn-ghost" data-fill-prev>Voltar</button>':'<span></span>'}${isLast?'<button type="submit" class="apm-btn-primary">Enviar e assinar</button>':'<button type="button" class="apm-btn-primary" data-fill-next>Continuar</button>'}</div>
  </section>`;
}
function render(){
  const tpl=state.template;
  const appt=state.appointment?`<div class="apm-fill-appointment">Referente ao atendimento de <strong>${esc(state.appointment.service_name||'')}</strong> em ${esc(new Date(state.appointment.start_at.replace(' ','T')).toLocaleDateString('pt-BR'))}</div>`:'';
  root.innerHTML=`<main class="apm-fill-shell">
    <section class="apm-fill-brand"><img src="${APMRecordFill.logo}" alt="Assis Prime"><span>FORMULÁRIO DO CLIENTE</span></section>
    <section class="apm-fill-card">
      <h1>${esc(tpl.title)}</h1>
      <p class="apm-fill-meta">Olá, <strong>${esc(state.client_name||'')}</strong>. Preencha as informações abaixo com atenção.</p>
      ${appt}
      <form id="apm-fill-form">
        <div class="apm-fill-progress">${tpl.steps.map((s,i)=>`<span class="${i===step?'active':i<step?'done':''}"></span>`).join('')}</div>
        ${tpl.steps.map((s,i)=>stepMarkup(s,i,tpl.steps.length)).join('')}
      </form>
      <footer>Assis Prime Spa e Beleza · Registro eletrônico com trilha de auditoria</footer>
    </section>
  </main>`;
  showStep(step);
  bind();
}
function showStep(n){
  step=n;
  root.querySelectorAll('.apm-fill-step').forEach((el,i)=>el.classList.toggle('active',i===step));
}
function collect(form){
  const fd=new FormData(form),data={};
  for(const [k,v] of fd.entries()) data[k]=v;
  data.declaration_confirmed=form.elements.declaration_confirmed?.checked?1:0;
  return data;
}
function bind(){
  const form=document.getElementById('apm-fill-form');
  if(!form)return;
  form.querySelectorAll('[data-fill-next]').forEach(b=>b.addEventListener('click',()=>{state.data={...state.data,...collect(form)};showStep(Math.min(state.template.steps.length-1,step+1))}));
  form.querySelectorAll('[data-fill-prev]').forEach(b=>b.addEventListener('click',()=>{state.data={...state.data,...collect(form)};showStep(Math.max(0,step-1))}));
  form.querySelectorAll('input[type="radio"]').forEach(r=>r.addEventListener('change',()=>{
    const wrap=r.closest('.apm-yesno');if(!wrap)return;
    const detail=wrap.parentElement.querySelector('[data-detail-for]');
    if(detail)detail.classList.toggle('is-hidden',r.value!=='yes'||!r.checked);
  }));
  form.addEventListener('submit',async e=>{
    e.preventDefault();
    state.data={...state.data,...collect(form)};
    if(!state.data.declaration_confirmed){alert('Confirme a declaração antes de enviar.');return}
    const btn=form.querySelector('button[type="submit"]');btn.disabled=true;btn.textContent='Enviando…';
    try{
      const res=await api('record-fill/'+encodeURIComponent(APMRecordFill.token),{method:'POST',body:JSON.stringify(state.data)});
      window.location.href=res.signature_url;
    }catch(err){
      alert(err.message);btn.disabled=false;btn.textContent='Enviar e assinar';
    }
  });
}
async function load(){
  try{
    const d=await api('record-fill/'+encodeURIComponent(APMRecordFill.token));
    state={template:d.template,client_name:d.client_name,appointment:d.appointment,data:d.data||{}};
    step=0;
    render();
  }catch(e){
    root.innerHTML=`<div class="apm-fill-error"><strong>Não foi possível abrir o formulário.</strong><p>${esc(e.message)}</p></div>`;
  }
}
load();
})();
