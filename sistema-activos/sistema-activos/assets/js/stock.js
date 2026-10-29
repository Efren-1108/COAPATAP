(() => {
const $=id=>document.getElementById(id); let bienes=[]; let empleados=[];
const esc=s=>App.esc(String(s??''));
const susp=a=>[true,'t','1',1].includes(a.suspendido);
async function cargar(){try{
const [a,e]=await Promise.all([App.api('stock.php'),App.api('empleados.php')]);
if(!a.ok)throw Error(a.error); bienes=a.data; empleados=e.ok?e.data:[]; pintar();
}catch(err){App.toast(err.message,'error')}}
function pintar(){const q=$('buscarStock').value.toLowerCase(); const lista=bienes.filter(a=>[a.descripcion,a.num_inventario,a.marca].some(v=>String(v||'').toLowerCase().includes(q)));
$('resumenStock').textContent=`En stock: ${bienes.length} | Disponibles: ${bienes.filter(a=>!susp(a)).length} | Suspendidos: ${bienes.filter(susp).length}`;
$('stockTabla').innerHTML=lista.map(a=>`<tr><td>${Number(a.id)}</td><td>${esc(a.descripcion)}</td><td>${esc(a.num_inventario)}</td><td>${esc(a.estado_fisico||'Pendiente')}</td><td>${susp(a)?'⏸ Suspendido':'✅ Disponible'}</td><td>${a.costo===null?'Pendiente':App.money(a.costo)}</td><td>${esc(a.fecha_ingreso_stock||'')}</td><td><button class="btn btn--ghost btn--sm" data-action="historial" data-id="${Number(a.id)}">Historial</button> ${susp(a)?'<span title="Primero reactiva desde Activos">No asignable</span>':`<button class="btn btn--primary btn--sm" data-action="salida" data-id="${Number(a.id)}">Asignar</button>`}</td></tr>`).join('')||'<tr><td colspan="8">No hay activos en stock.</td></tr>';
}
async function mover(data){try{const r=await App.api('stock.php',{method:'POST',body:data});if(!r.ok)throw Error(r.error);App.toast(r.message,'success');await cargar()}catch(err){App.toast(err.message,'error')}}
$('stockTabla').addEventListener('click',async e=>{const b=e.target.closest('button[data-action]');if(!b)return;const id=Number(b.dataset.id);
if(b.dataset.action==='historial'){try{const r=await App.api(`stock.php?historial=${id}`);if(!r.ok)throw Error(r.error);alert(r.data.length?r.data.map(m=>`${m.fecha} — ${m.tipo}: ${m.motivo} (${m.usuario_nombre||'Sistema'})`).join('\n'):'Sin movimientos registrados')}catch(err){App.toast(err.message,'error')}return}
const opts=empleados.map(x=>`${x.id}: ${x.nombre}`).join('\n');const eid=prompt('ID del empleado que recibirá el bien:\n'+opts);if(eid===null)return;
if(!/^\d+$/.test(eid.trim())||!empleados.some(x=>Number(x.id)===Number(eid))){App.toast('Selecciona un ID válido de empleado','error');return}
const motivo=prompt('Motivo de salida / entrega:');if(motivo===null)return;await mover({accion:'salida',activo_id:id,empleado_id:Number(eid),motivo:motivo.trim()});});
$('btnEntrada').addEventListener('click',async()=>{const id=Number($('stockId').value);if(!Number.isSafeInteger(id)||id<1){App.toast('Escribe un ID de activo válido','error');return}if(!confirm('¿Enviar el activo '+id+' a stock? Se quitará su empleado y área actual.'))return;const motivo=prompt('Motivo de entrada al almacén:');if(motivo===null)return;await mover({accion:'entrada',activo_id:id,motivo:motivo.trim()})});
$('recargarStock').addEventListener('click',cargar);$('buscarStock').addEventListener('input',pintar);cargar();
})();
