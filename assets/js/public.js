(()=>{
  'use strict';

  const cfg=window.SabriCF03||{};
  const request=async(path,options={})=>{
    const headers={'Content-Type':'application/json',...(options.headers||{})};
    if(cfg.nonce)headers['X-WP-Nonce']=cfg.nonce;
    const response=await fetch((cfg.root||'')+path,{credentials:'same-origin',...options,headers});
    const body=await response.json().catch(()=>({message:cfg.messages?.failed||'Request failed'}));
    if(!response.ok)throw new Error(body.message||body.code||cfg.messages?.failed||'Request failed');
    return body;
  };
  const key=()=>{
    if(!globalThis.crypto?.getRandomValues)throw new Error(cfg.messages?.secureContext||'A secure browser context is required.');
    const bytes=new Uint8Array(24);
    crypto.getRandomValues(bytes);
    return 'web-'+Array.from(bytes,b=>b.toString(16).padStart(2,'0')).join('');
  };
  const parseCustomMinor=value=>{
    const text=String(value??'').trim();
    if(text==='')return null;
    if(!/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/.test(text)){
      throw new Error(cfg.messages?.invalidAmount||'Choose or enter a valid positive amount.');
    }
    const [wholeText,fractionText='']=text.split('.');
    const whole=Number(wholeText);
    if(!Number.isSafeInteger(whole)||whole>Math.floor(Number.MAX_SAFE_INTEGER/100)){
      throw new Error(cfg.messages?.invalidAmount||'Choose or enter a valid positive amount.');
    }
    const fraction=Number((fractionText+'00').slice(0,2));
    const minor=whole*100+fraction;
    if(!Number.isSafeInteger(minor)||minor<=0){
      throw new Error(cfg.messages?.invalidAmount||'Choose or enter a valid positive amount.');
    }
    return minor;
  };

  document.addEventListener('change',event=>{
    const radio=event.target.closest('.sabri-cf03-donation-form input[name="amount_minor"]');
    if(!radio)return;
    const form=radio.form;
    const custom=form?.elements?.custom_amount;
    if(custom)custom.value='';
  });

  document.addEventListener('input',event=>{
    const custom=event.target.closest('.sabri-cf03-donation-form input[name="custom_amount"]');
    if(!custom||String(custom.value).trim()==='')return;
    const form=custom.form;
    form?.querySelectorAll('input[name="amount_minor"]').forEach(input=>{input.checked=false;});
  });

  document.addEventListener('submit',async event=>{
    const form=event.target.closest('.sabri-cf03-donation-form');
    if(!form)return;
    event.preventDefault();
    if(form.dataset.submitting==='1')return;

    const status=form.querySelector('.sabri-cf03-status');
    const submit=form.querySelector('button[type="submit"]');
    if(cfg.collectionEnabled!==true||submit?.disabled){
      if(status)status.textContent=cfg.messages?.unavailable||'Secure donation collection is not currently available.';
      return;
    }

    form.dataset.submitting='1';
    if(submit)submit.disabled=true;
    if(status)status.textContent=cfg.messages?.working||'Working…';

    try{
      const data=new FormData(form);
      const selected=data.get('amount_minor');
      const customMinor=parseCustomMinor(data.get('custom_amount'));
      const selectedMinor=selected===null?null:Number(selected);
      const minor=customMinor??selectedMinor;
      if(!Number.isSafeInteger(minor)||minor<=0){
        throw new Error(cfg.messages?.invalidAmount||'Choose or enter a valid positive amount.');
      }

      let idempotency=String(form.elements.idempotency_key?.value||'');
      if(!idempotency){
        idempotency=key();
        form.elements.idempotency_key.value=idempotency;
      }
      const monthly=data.get('monthly')==='1';
      const result=await request('donation-intents',{
        method:'POST',
        headers:{'Idempotency-Key':idempotency},
        body:JSON.stringify({
          amount_minor:minor,
          currency:'USD',
          monthly,
          monthly_consent:monthly,
          idempotency_key:idempotency
        })
      });
      if(result.hosted_url){
        window.location.assign(result.hosted_url);
        return;
      }
      if(status)status.textContent=result.message||result.status||cfg.messages?.recorded||'Request recorded.';
    }catch(error){
      if(status)status.textContent=error instanceof Error?error.message:(cfg.messages?.failed||'Request failed');
    }finally{
      form.dataset.submitting='0';
      if(submit&&cfg.collectionEnabled===true)submit.disabled=false;
    }
  });

  document.addEventListener('click',async event=>{
    const button=event.target.closest('[data-sabri-cf03-load-billing]');
    if(!button)return;
    const root=button.closest('.sabri-cf03-billing');
    const target=root?.querySelector('.sabri-cf03-billing-results');
    if(!target)return;
    button.disabled=true;
    target.textContent=cfg.messages?.working||'Working…';
    try{
      const result=await request('billing');
      target.textContent='';
      for(const [group,records] of Object.entries(result)){
        if(!Array.isArray(records))continue;
        const heading=document.createElement('h3');
        heading.textContent=group.replaceAll('_',' ');
        target.append(heading);
        for(const record of records){
          const article=document.createElement('article');
          article.className='sabri-cf03-record';
          const dl=document.createElement('dl');
          for(const [name,value] of Object.entries(record)){
            const dt=document.createElement('dt');
            dt.textContent=name.replaceAll('_',' ');
            const dd=document.createElement('dd');
            dd.textContent=value==null?'—':String(value);
            dl.append(dt,dd);
          }
          article.append(dl);
          target.append(article);
        }
      }
    }catch(error){
      target.textContent=error instanceof Error?error.message:(cfg.messages?.failed||'Request failed');
    }finally{
      button.disabled=false;
    }
  });
})();
