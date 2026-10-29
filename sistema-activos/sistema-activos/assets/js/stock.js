(() => {

/* Ventanas personalizadas COAPATAP: no usan alert, prompt ni confirm del navegador. */
function ventanaStock({titulo,descripcion='',tipo='texto',valor='',opciones=[],filas=[]}) {
    return new Promise(resolve=>{
        const overlay=document.createElement('div');
        overlay.className='coapatap-dialog-overlay';
        const escape=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        const campo=tipo==='seleccion'
          ? `<label class="coapatap-dialog-label" for="coapatap-dialog-input">Empleado responsable</label><select id="coapatap-dialog-input" class="coapatap-dialog-input"><option value="">— Selecciona un empleado —</option>${opciones.map(x=>`<option value="${escape(x.id)}">${escape(x.nombre)}${x.numero_nomina?' · Nómina '+escape(x.numero_nomina):''}</option>`).join('')}</select>`
          : tipo==='texto' ? `<label class="coapatap-dialog-label" for="coapatap-dialog-input">Motivo del movimiento</label><textarea id="coapatap-dialog-input" class="coapatap-dialog-input" rows="3" maxlength="500" placeholder="Escribe el motivo..." required>${escape(valor)}</textarea>`
          : tipo==='historial' ? `<div class="coapatap-dialog-history">${filas.length?filas.map(m=>`<div class="coapatap-dialog-history-row"><strong>${escape(m.tipo)}</strong><small>${escape(m.fecha)}</small><p>${escape(m.motivo||'Sin motivo')} · ${escape(m.usuario_nombre||'Sistema')}</p></div>`).join(''):'<p>No hay movimientos registrados.</p>'}</div>` : '';
        overlay.innerHTML=`<div class="coapatap-dialog" role="dialog" aria-modal="true" aria-labelledby="coapatap-dialog-title"><div class="coapatap-dialog-header"><h2 id="coapatap-dialog-title">${escape(titulo)}</h2><button type="button" class="coapatap-dialog-x" aria-label="Cerrar">×</button></div><div class="coapatap-dialog-body">${descripcion?`<p>${escape(descripcion)}</p>`:''}${campo}</div><div class="coapatap-dialog-footer">${tipo!=='historial'?'<button type="button" class="btn btn--ghost coapatap-dialog-cancel">Cancelar</button>':''}<button type="button" class="btn btn--primary coapatap-dialog-ok">${tipo==='historial'?'Cerrar':'Aceptar'}</button></div></div>`;
        document.body.appendChild(overlay);
        const input=overlay.querySelector('#coapatap-dialog-input');
        const cerrar=v=>{document.removeEventListener('keydown',tecla);overlay.remove();resolve(v)};
        const tecla=e=>{if(e.key==='Escape'){e.preventDefault();cerrar(null)}};
        document.addEventListener('keydown',tecla);
        overlay.querySelector('.coapatap-dialog-x').onclick=()=>cerrar(null);
        const cancelar=overlay.querySelector('.coapatap-dialog-cancel');
        if(cancelar)cancelar.onclick=()=>cerrar(null);
        overlay.querySelector('.coapatap-dialog-ok').onclick=()=>{
            if(tipo==='historial'||tipo==='confirmar')return cerrar(true);
            if(!input.value.trim()){input.focus();input.classList.add('coapatap-dialog-invalid');return}
            cerrar(input.value.trim());
        };
        if(input)input.focus();else overlay.querySelector('.coapatap-dialog-ok').focus();
    });
}

const $=id=>document.getElementById(id); let bienes=[]; let empleados=[];
const esc=s=>App.esc(String(s??''));
const susp=a=>[true,'t','1',1].includes(a.suspendido);
async function cargar(){try{
const [a,e]=await Promise.all([App.api('stock.php'),App.api('empleados.php')]);
if(!a.ok)throw Error(a.error); bienes=a.data; empleados=e.ok?e.data:[]; pintar();
}catch(err){App.toast(err.message,'error')}}
function pintar(){const q=$('buscarStock').value.toLowerCase(); const lista=bienes.filter(a=>[a.descripcion,a.num_inventario,a.marca].some(v=>String(v||'').toLowerCase().includes(q)));
$('resumenStock').textContent=`En stock: ${bienes.length} | Disponibles: ${bienes.filter(a=>!susp(a)).length} | Suspendidos: ${bienes.filter(susp).length}`;
$('stockTabla').innerHTML=lista.map(a=>`<tr><td>${esc(a.descripcion)}</td><td>${susp(a)?'⏸ Suspendido':'✅ Disponible'}</td><td>${esc(a.fecha_ingreso_stock||'')}</td><td><div class="stock-actions"><button class="btn btn--ghost btn--sm" data-action="historial" data-id="${Number(a.id)}">📋 Historial</button>${susp(a)?'<span title="Primero reactiva desde Activos">⏸ No asignable</span>':`<button class="btn btn--primary btn--sm" data-action="salida" data-id="${Number(a.id)}">👤 Asignar</button>`}</div></td></tr>`).join('')||'<tr><td colspan="4">No hay activos en stock.</td></tr>';
}
async function mover(data){try{const r=await App.api('stock.php',{method:'POST',body:data});if(!r.ok)throw Error(r.error);App.toast(r.message,'success');await cargar()}catch(err){App.toast(err.message,'error')}}
$('stockTabla').addEventListener('click',async e=>{const b=e.target.closest('button[data-action]');if(!b)return;const id=Number(b.dataset.id);
if(b.dataset.action==='historial'){
  try{
    const r=await App.api(`stock.php?historial=${id}`);
    if(!r.ok)throw Error(r.error);
    await ventanaStock({titulo:'📋 Historial de movimientos',tipo:'historial',filas:r.data||[]});
  }catch(err){App.toast(err.message,'error')}
  return;
}
if(b.dataset.action==='salida'){
  const eid=await ventanaStock({titulo:'👤 Asignar activo',descripcion:'Selecciona el empleado que recibirá este bien del almacén general.',tipo:'seleccion',opciones:empleados});
  if(eid===null)return;
  const motivo=await ventanaStock({titulo:'📦 Motivo de salida',descripcion:'Indica por qué se entrega este activo.',tipo:'texto'});
  if(motivo===null)return;
  await mover({accion:'salida',activo_id:id,empleado_id:Number(eid),motivo});
}
});

$('buscarStock').addEventListener('input',pintar);
// Al abrir la página y volver a ella, consultar datos actualizados.
cargar();
document.addEventListener('visibilitychange',()=>{if(!document.hidden)cargar();});
window.addEventListener('focus',()=>{if(!document.hidden)cargar();});
// Sin botón Actualizar: sincronizar automáticamente cada 10 segundos.
setInterval(()=>{if(!document.hidden)cargar();},10000);
})();
