(()=>{
'use strict';
const root=document.getElementById('apm-signature-root');
const esc=v=>String(v??'').replace(/[&<>'"]/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[m]));
const api=async(path,opts={})=>{
  const r=await fetch(APMSignature.rest+path,{...opts,headers:{'Content-Type':'application/json',...(opts.headers||{})}});
  let j={}; try{j=await r.json()}catch(e){}
  if(!r.ok) throw new Error(j.message||'Não foi possível concluir.');
  return j;
};
async function load(){
  try{
    const d=await api('signature/'+encodeURIComponent(APMSignature.token));
    const signed=d.status==='signed';
    const expired=d.status==='expired';
    const attachmentHtml=d.attachments?`<div class="apm-signature-files"><strong>Anexos/referências</strong><p>${esc(d.attachments)}</p></div>`:'';
    const needsOtp=!signed&&!expired&&Number(d.otp_required)===1&&Number(d.otp_verified)!==1;
    let actionHtml='';
    if(signed){
      actionHtml=`<div class="apm-signature-proof"><strong>Assinatura registrada</strong><p>${esc(d.signer_name)} · ${esc(d.signed_at||'')}</p><small>Hash do documento: ${esc(d.document_hash||'')}</small></div>`;
    }else if(expired){
      actionHtml='<div class="apm-signature-alert">Este link expirou. Solicite um novo link à Assis Prime.</div>';
    }else if(needsOtp){
      const phoneMasked=esc(d.signer_phone||d.client_phone||'')?`terminado em ${esc(String(d.signer_phone||d.client_phone||'').replace(/\D+/g,'').slice(-4))}`:'cadastrado';
      actionHtml=`<div class="apm-signature-otp">
        <strong>Confirmação por WhatsApp</strong>
        <p>Por segurança, antes de assinar enviamos um código de 6 dígitos para o WhatsApp ${phoneMasked}.</p>
        <div id="apm-otp-step-send">
          <button type="button" id="apm-otp-send-btn">Enviar código por WhatsApp</button>
        </div>
        <form id="apm-otp-verify-form" style="display:none">
          <div class="apm-field"><label>Código recebido</label><input name="code" inputmode="numeric" maxlength="6" autocomplete="one-time-code" required></div>
          <button type="submit">Confirmar código</button>
          <button type="button" id="apm-otp-resend-btn" class="apm-signature-linklike">Reenviar código</button>
        </form>
        <div id="apm-otp-msg" class="apm-signature-otp-msg"></div>
      </div>`;
    }else{
      actionHtml=`<form id="apm-signature-form">
        <div class="apm-field"><label>Nome completo</label><input name="name" value="${esc(d.signer_name||d.client_name||'')}" required></div>
        <div class="apm-grid">
          <div class="apm-field"><label>CPF</label><input name="cpf" value="${esc(d.signer_cpf||d.client_cpf||'')}"></div>
          <div class="apm-field"><label>WhatsApp</label><input name="phone" value="${esc(d.signer_phone||d.client_phone||'')}"></div>
        </div>
        <label class="apm-consent"><input type="checkbox" name="confirm" value="1" required><span>Li o documento acima e concordo com seu conteúdo. Confirmo que os dados informados são meus e autorizo o registro eletrônico desta assinatura.</span></label>
        <button type="submit">Assinar documento</button>
      </form>`;
    }
    root.innerHTML=`<main class="apm-signature-shell">
      <section class="apm-signature-brand"><img src="${APMSignature.logo}" alt="Assis Prime"><span>ASSINATURA ELETRÔNICA</span></section>
      <section class="apm-signature-card">
        <div class="apm-signature-status ${signed?'ok':expired?'bad':'pending'}">${signed?'Documento assinado':expired?'Link expirado':'Aguardando assinatura'}</div>
        <h1>${esc(d.title)}</h1>
        <p class="apm-signature-meta">Documento destinado a <strong>${esc(d.client_name||d.signer_name||'cliente')}</strong>.</p>
        <article class="apm-signature-document">${d.content||'<p>Documento sem conteúdo.</p>'}</article>
        ${attachmentHtml}
        ${actionHtml}
        <footer>Assis Prime Spa e Beleza · Registro eletrônico com trilha de auditoria</footer>
      </section>
    </main>`;
    const f=document.getElementById('apm-signature-form');
    if(f)f.addEventListener('submit',async e=>{
      e.preventDefault();
      const fd=new FormData(f),data=Object.fromEntries(fd.entries());
      data.confirm=f.elements.confirm.checked?1:0;
      const btn=f.querySelector('button'); btn.disabled=true; btn.textContent='Registrando assinatura…';
      try{await api('signature/'+encodeURIComponent(APMSignature.token)+'/sign',{method:'POST',body:JSON.stringify(data)});await load()}
      catch(err){alert(err.message);btn.disabled=false;btn.textContent='Assinar documento'}
    });

    const otpMsg=document.getElementById('apm-otp-msg');
    const sendCode=async(btn)=>{
      btn.disabled=true;const original=btn.textContent;btn.textContent='Enviando…';
      try{
        await api('signature/'+encodeURIComponent(APMSignature.token)+'/send-code',{method:'POST'});
        document.getElementById('apm-otp-step-send').style.display='none';
        document.getElementById('apm-otp-verify-form').style.display='';
        if(otpMsg){otpMsg.textContent='Código enviado! Confira o WhatsApp.';otpMsg.className='apm-signature-otp-msg ok'}
      }catch(err){
        if(otpMsg){otpMsg.textContent=err.message;otpMsg.className='apm-signature-otp-msg error'}
        btn.disabled=false;btn.textContent=original;
      }
    };
    const sendBtn=document.getElementById('apm-otp-send-btn');
    if(sendBtn)sendBtn.addEventListener('click',()=>sendCode(sendBtn));
    const resendBtn=document.getElementById('apm-otp-resend-btn');
    if(resendBtn)resendBtn.addEventListener('click',()=>sendCode(resendBtn));
    const otpForm=document.getElementById('apm-otp-verify-form');
    if(otpForm)otpForm.addEventListener('submit',async e=>{
      e.preventDefault();
      const code=otpForm.elements.code.value.trim();
      const btn=otpForm.querySelector('button[type="submit"]');btn.disabled=true;btn.textContent='Confirmando…';
      try{
        await api('signature/'+encodeURIComponent(APMSignature.token)+'/verify-code',{method:'POST',body:JSON.stringify({code})});
        await load();
      }catch(err){
        if(otpMsg){otpMsg.textContent=err.message;otpMsg.className='apm-signature-otp-msg error'}
        btn.disabled=false;btn.textContent='Confirmar código';
      }
    });
  }catch(e){
    root.innerHTML=`<div class="apm-signature-error"><strong>Não foi possível abrir o documento.</strong><p>${esc(e.message)}</p></div>`;
  }
}
load();
})();
