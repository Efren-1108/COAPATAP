<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
$user = require_auth_csrf();
if (!has_permission('activos')) json_response(['ok'=>false,'error'=>'Sin permiso de activos'],403);
$pdo = db();
try {
 if ($_SERVER['REQUEST_METHOD'] === 'GET') {
   if (isset($_GET['historial'])) {
     $id=filter_var($_GET['historial'], FILTER_VALIDATE_INT);
     if (!$id || $id<1) json_response(['ok'=>false,'error'=>'ID inválido'],400);
     $st=$pdo->prepare('SELECT * FROM public.movimientos_stock WHERE activo_id=:id ORDER BY fecha DESC,id DESC');
     $st->execute([':id'=>$id]);
     json_response(['ok'=>true,'data'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
   }
   $st=$pdo->query("SELECT a.id,a.descripcion,a.num_inventario,a.marca,a.modelo,a.costo,a.estado_fisico,a.suspendido,a.motivo_suspension,a.fecha_ingreso_stock FROM public.activos a WHERE a.situacion_inventario='STOCK' ORDER BY a.fecha_ingreso_stock DESC NULLS LAST,a.id DESC");
   json_response(['ok'=>true,'data'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
 }
 if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['ok'=>false,'error'=>'Método no permitido'],405);
 if (empty($user['es_superusuario'])) json_response(['ok'=>false,'error'=>'Solo el superusuario puede realizar movimientos del almacén general'],403);
 $in=json_input(); $id=filter_var($in['activo_id']??null,FILTER_VALIDATE_INT);
 $accion=$in['accion']??''; $motivo=trim((string)($in['motivo']??''));
 if (!$id || $id<1 || !in_array($accion,['entrada','salida'],true)) json_response(['ok'=>false,'error'=>'Solicitud inválida'],400);
 if ($motivo==='' || (function_exists('mb_strlen') ? mb_strlen($motivo, 'UTF-8') : strlen($motivo))>500) json_response(['ok'=>false,'error'=>'Escribe un motivo de 1 a 500 caracteres'],400);
 $pdo->beginTransaction();
 $st=$pdo->prepare('SELECT id,descripcion,empleado_id,suspendido,situacion_inventario FROM public.activos WHERE id=:id FOR UPDATE');
 $st->execute([':id'=>$id]); $a=$st->fetch(PDO::FETCH_ASSOC);
 if (!$a) { $pdo->rollBack(); json_response(['ok'=>false,'error'=>'Activo no encontrado'],404); }
 if ($accion==='entrada') {
   if ($a['situacion_inventario']==='STOCK') { $pdo->rollBack(); json_response(['ok'=>false,'error'=>'Ya está en stock'],409); }
   $st=$pdo->prepare("UPDATE public.activos SET empleado_id=NULL,area_id=NULL,situacion_inventario='STOCK',fecha_ingreso_stock=NOW() WHERE id=:id");
   $st->execute([':id'=>$id]); $nuevo=null;
 } else {
   if ($a['situacion_inventario']!=='STOCK') { $pdo->rollBack(); json_response(['ok'=>false,'error'=>'El activo no está en stock'],409); }
   if (in_array($a['suspendido'],[true,'t','1',1],true)) { $pdo->rollBack(); json_response(['ok'=>false,'error'=>'Primero reactiva el activo suspendido'],409); }
   $nuevo=filter_var($in['empleado_id']??null,FILTER_VALIDATE_INT);
   if (!$nuevo || $nuevo<1) { $pdo->rollBack(); json_response(['ok'=>false,'error'=>'Selecciona un empleado'],400); }
   $st=$pdo->prepare('SELECT area_id FROM public.empleados WHERE id=:id');$st->execute([':id'=>$nuevo]);$area=$st->fetchColumn();
   if (!$area) { $pdo->rollBack(); json_response(['ok'=>false,'error'=>'Empleado inexistente o sin área'],400); }
   $st=$pdo->prepare("UPDATE public.activos SET empleado_id=:eid,area_id=:aid,situacion_inventario='ASIGNADO',fecha_ingreso_stock=NULL WHERE id=:id");
   $st->execute([':eid'=>$nuevo,':aid'=>$area,':id'=>$id]);
 }
 $st=$pdo->prepare('INSERT INTO public.movimientos_stock(activo_id,tipo,empleado_anterior_id,empleado_nuevo_id,motivo,usuario_id,usuario_nombre) VALUES (:id,:tipo,:old,:new,:motivo,:uid,:usr)');
 $st->execute([':id'=>$id,':tipo'=>$accion==='entrada'?'ENTRADA':'SALIDA',':old'=>$a['empleado_id'],':new'=>$nuevo,':motivo'=>$motivo,':uid'=>$user['id'],':usr'=>$user['nombre_usuario']]);
 $pdo->commit(); json_response(['ok'=>true,'message'=>$accion==='entrada'?'Activo enviado a stock':'Activo asignado desde stock']);
} catch(Throwable $e) {
 if ($pdo->inTransaction()) $pdo->rollBack();
 logger('Error módulo stock: '.$e->getMessage(),'ERROR');
 json_response(['ok'=>false,'error'=>'No se pudo completar el movimiento. Comprueba que instalaste la migración SQL.'],500);
}
