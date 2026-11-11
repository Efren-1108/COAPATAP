<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
$user = require_permission_csrf('stock');
$isSuper = !empty($user['es_superusuario']);
$pdo = db();
try {
 if ($_SERVER['REQUEST_METHOD'] === 'GET') {
   // El selector para asignaciones usa la propia API de Stock: no requiere permiso
   // adicional para administrar empleados y nunca ofrece otras áreas a usuarios comunes.
   if (isset($_GET['empleados'])) {
     if (!has_action_permission('stock', 'modificar')) {
       json_response(['ok'=>false,'error'=>'Tu rol no puede realizar movimientos de Stock'],403);
     }
     if (!$isSuper && !read_area_ids($user)) {
       json_response(['ok'=>false,'error'=>'Tu rol no tiene áreas autorizadas para movimientos'],403);
     }
     $sql = 'SELECT id, nombre, numero_nomina FROM public.empleados';
     if (!$isSuper) $sql .= ' WHERE ' . read_area_condition('area_id', $user);
     $sql .= ' ORDER BY nombre, id';
     $st = $pdo->prepare($sql);
     $st->execute();
     json_response(['ok'=>true,'data'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
   }
   if (isset($_GET['historial'])) {
     $id=filter_var($_GET['historial'], FILTER_VALIDATE_INT);
     if (!$id || $id<1) json_response(['ok'=>false,'error'=>'ID inválido'],400);
     // El historial no debe permitir consultar por ID bienes de otras áreas fuera de Stock.
     $histSql = 'SELECT m.tipo, m.fecha, m.motivo, m.usuario_nombre
        FROM public.movimientos_stock m JOIN public.activos a ON a.id = m.activo_id
        WHERE m.activo_id = :id';
     if (!$isSuper) $histSql .= " AND a.situacion_inventario = 'STOCK'";
     $histSql .= ' ORDER BY m.fecha DESC, m.id DESC';
     $st=$pdo->prepare($histSql);
     $st->execute([':id'=>$id]);
     json_response(['ok'=>true,'data'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
   }
   $st=$pdo->query("SELECT a.id,a.descripcion,a.num_inventario,a.marca,a.modelo,a.costo,a.estado_fisico,a.suspendido,a.motivo_suspension,a.fecha_ingreso_stock FROM public.activos a WHERE a.situacion_inventario='STOCK' ORDER BY a.fecha_ingreso_stock DESC NULLS LAST,a.id DESC");
   json_response(['ok'=>true,'data'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
 }
 if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['ok'=>false,'error'=>'Método no permitido'],405);
 if (!has_action_permission('stock', 'modificar')) {
   json_response(['ok'=>false,'error'=>'Tu rol no tiene permiso para realizar movimientos de Stock'],403);
 }
 if (!$isSuper && !read_area_ids($user)) {
   json_response(['ok'=>false,'error'=>'Tu rol no tiene áreas autorizadas para movimientos'],403);
 }
 $in=json_input(); $id=filter_var($in['activo_id']??null,FILTER_VALIDATE_INT);
 $accion=$in['accion']??''; $motivo=trim((string)($in['motivo']??''));
 if (!$id || $id<1 || !in_array($accion,['entrada','salida'],true)) json_response(['ok'=>false,'error'=>'Solicitud inválida'],400);
 if ($motivo==='' || (function_exists('mb_strlen') ? mb_strlen($motivo, 'UTF-8') : strlen($motivo))>500) json_response(['ok'=>false,'error'=>'Escribe un motivo de 1 a 500 caracteres'],400);
 $pdo->beginTransaction();
 $st=$pdo->prepare('SELECT id,descripcion,empleado_id,area_id,suspendido,situacion_inventario,estado_fisico FROM public.activos WHERE id=:id FOR UPDATE');
 $st->execute([':id'=>$id]); $a=$st->fetch(PDO::FETCH_ASSOC);
 if (!$a) { $pdo->rollBack(); json_response(['ok'=>false,'error'=>'Activo no encontrado'],404); }
 if ($a['estado_fisico']==='INSERVIBLE') { $pdo->rollBack(); json_response(['ok'=>false,'error'=>'Los bienes inservibles no pueden enviarse ni asignarse desde stock.'],409); }
 if ($accion==='entrada') {
   if (!can_write_area($a['area_id'] !== null ? (int)$a['area_id'] : null, $user)) {
     $pdo->rollBack(); json_response(['ok'=>false,'error'=>'No puedes enviar a Stock activos fuera de tus áreas autorizadas'],403);
   }
   if ($a['situacion_inventario']==='STOCK') { $pdo->rollBack(); json_response(['ok'=>false,'error'=>'Ya está en stock'],409); }
   $st=$pdo->prepare("UPDATE public.activos SET empleado_id=NULL,area_id=NULL,situacion_inventario='STOCK',fecha_ingreso_stock=NOW() WHERE id=:id");
   $st->execute([':id'=>$id]); $nuevo=null;
 } else {
   if ($a['situacion_inventario']!=='STOCK') { $pdo->rollBack(); json_response(['ok'=>false,'error'=>'El activo no está en stock'],409); }
   if (in_array($a['suspendido'],[true,'t','1',1],true)) { $pdo->rollBack(); json_response(['ok'=>false,'error'=>'Primero reactiva el activo suspendido'],409); }
   $nuevo=filter_var($in['empleado_id']??null,FILTER_VALIDATE_INT);
   if (!$nuevo || $nuevo<1) { $pdo->rollBack(); json_response(['ok'=>false,'error'=>'Selecciona un empleado'],400); }
   $st=$pdo->prepare('SELECT area_id FROM public.empleados WHERE id=:id FOR SHARE');$st->execute([':id'=>$nuevo]);$area=$st->fetchColumn();
   if (!$area) { $pdo->rollBack(); json_response(['ok'=>false,'error'=>'Empleado inexistente o sin área'],400); }
   if (!can_write_area((int)$area, $user)) {
     $pdo->rollBack(); json_response(['ok'=>false,'error'=>'Solo puedes asignar bienes a empleados de áreas autorizadas'],403);
   }
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
