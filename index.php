<?php
session_start();
require_once __DIR__.'/src/ListaDoblementeEnlazada.php';
function e($valor): string { return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8'); }
$tablas = [
 'clientes'=>['id'=>'id_cliente','campos'=>['nombre','email','edad'],'orden'=>'nombre'],
 'pedidos'=>['id'=>'id_pedido','campos'=>['id_cliente','fecha','total'],'orden'=>'total'],
 'productos'=>['id'=>'id_producto','campos'=>['nombre','categoria','precio','stock'],'orden'=>'nombre']
];
$tabla = $_GET['tabla'] ?? 'clientes';
if (!is_string($tabla) || !isset($tablas[$tabla])) { http_response_code(400); exit('Tabla inválida.'); }
$meta = $tablas[$tabla];
$idCampo = $meta['id'];
$error = '';
$mensaje = $_SESSION['mensaje'] ?? '';
unset($_SESSION['mensaje']);
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
function validar(string $tabla, array $datos): void {
    foreach ($datos as $valor) if ($valor === '') throw new RuntimeException('Completa todos los campos.');
    foreach (['nombre'=>100,'email'=>150,'categoria'=>50] as $campo=>$max) {
        if (isset($datos[$campo])) {
            $largo = preg_match_all('/./us', $datos[$campo]);
            if ($largo === false || $largo > $max) throw new RuntimeException("Longitud inválida en $campo.");
        }
    }
    if ($tabla === 'clientes') {
        if (!filter_var($datos['email'], FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Email inválido.');
        if (filter_var($datos['edad'], FILTER_VALIDATE_INT) === false || (int)$datos['edad'] < 18 || (int)$datos['edad'] > 2147483647) throw new RuntimeException('La edad debe ser un entero de 18 o más.');
    }
    foreach (['precio','total'] as $campo) if (isset($datos[$campo])) {
        if (!preg_match('/^\d{1,8}(\.\d{1,2})?$/D', $datos[$campo]) || ($campo === 'precio' && (float)$datos[$campo] <= 0)) throw new RuntimeException("$campo debe ser válido, con hasta dos decimales; precio mayor que cero.");
    }
    if (isset($datos['stock']) && (filter_var($datos['stock'], FILTER_VALIDATE_INT) === false || (int)$datos['stock'] < 0 || (int)$datos['stock'] > 2147483647)) throw new RuntimeException('Stock: entero de cero o más.');
    if ($tabla === 'pedidos') {
        if (filter_var($datos['id_cliente'], FILTER_VALIDATE_INT) === false || (int)$datos['id_cliente'] < 1) throw new RuntimeException('Selecciona un cliente válido.');
        $fecha = DateTime::createFromFormat('!Y-m-d', $datos['fecha']);
        if (!$fecha || $fecha->format('Y-m-d') !== $datos['fecha'] || $datos['fecha'] < '1000-01-01') throw new RuntimeException('Fecha inválida.');
    }
}
try {
    if (!is_file(__DIR__.'/config/database.php')) throw new RuntimeException('Copia config/database.example.php como config/database.php y configura tus credenciales locales.');
    $c = require __DIR__.'/config/database.php';
    $db = new PDO("mysql:host={$c['host']};port={$c['port']};dbname={$c['database']};charset=utf8mb4", $c['username'], $c['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
} catch (Throwable $ex) { http_response_code(500); exit(e('No se pudo conectar. Revisa el servidor, PDO MySQL y config/database.php. '.$ex->getMessage())); }
$edicion = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) throw new RuntimeException('Formulario vencido. Recarga la página.');
        $accion = $_POST['accion'] ?? '';
        $id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
        if ($accion === 'eliminar') {
            if (!$id || $id < 1) throw new RuntimeException('ID inválido.');
            $stmt = $db->prepare("DELETE FROM `$tabla` WHERE `$idCampo` = ?");
            $stmt->execute([$id]);
            if (!$stmt->rowCount()) throw new RuntimeException('Registro no encontrado.');
            $_SESSION['mensaje'] = "Registro #$id eliminado correctamente.";
        } elseif ($accion === 'guardar') {
            $datos = [];
            foreach ($meta['campos'] as $campo) {
                if (!is_string($_POST[$campo] ?? null)) throw new RuntimeException('Datos inválidos.');
                $datos[$campo] = trim($_POST[$campo]);
            }
            $edicion = $datos;
            $edicion[$idCampo] = $id ?: '';
            validar($tabla, $datos);
            if (($_POST['id'] ?? '') !== '') {
                if (!$id || $id < 1) throw new RuntimeException('ID inválido.');
                $existe = $db->prepare("SELECT `$idCampo` FROM `$tabla` WHERE `$idCampo` = ?");
                $existe->execute([$id]);
                if (!$existe->fetch()) throw new RuntimeException('Registro no encontrado.');
                $asignaciones = implode(', ', array_map(fn($campo)=>"`$campo` = ?", $meta['campos']));
                $stmt = $db->prepare("UPDATE `$tabla` SET $asignaciones WHERE `$idCampo` = ?");
                $stmt->execute([...array_values($datos), $id]);
                $_SESSION['mensaje'] = "Registro #$id actualizado correctamente. Comprueba los valores en la tabla.";
            } else {
                $columnas = '`'.implode('`,`', $meta['campos']).'`';
                $marcas = implode(',', array_fill(0, count($datos), '?'));
                $stmt = $db->prepare("INSERT INTO `$tabla` ($columnas) VALUES ($marcas)");
                $stmt->execute(array_values($datos));
                $_SESSION['mensaje'] = 'Registro #'.$db->lastInsertId().' creado correctamente.';
            }
        } else throw new RuntimeException('Acción inválida.');
        header('Location: index.php?tabla='.urlencode($tabla)); exit;
    } catch (PDOException $ex) {
        $numero = $ex->errorInfo[1] ?? 0;
        $error = $numero === 1062 ? 'El email o nombre ya existe.' : ($numero === 1451 ? 'No puedes eliminar un cliente que tiene pedidos.' : ($numero === 1452 ? 'El cliente seleccionado no existe.' : 'MySQL rechazó la operación. Revisa las restricciones y los datos.'));
    } catch (RuntimeException $ex) { $error = $ex->getMessage(); }
} elseif (isset($_GET['editar'])) {
    $id = filter_var($_GET['editar'], FILTER_VALIDATE_INT);
    if ($id && $id > 0) {
        $stmt = $db->prepare("SELECT * FROM `$tabla` WHERE `$idCampo` = ?");
        $stmt->execute([$id]); $edicion = $stmt->fetch() ?: [];
        if (!$edicion) $error = 'Registro no encontrado.';
    } else $error = 'ID inválido.';
}
$lista = new ListaDoblementeEnlazada();
$consulta = $db->query("SELECT * FROM `$tabla` ORDER BY `$idCampo` ASC");
while ($registro = $consulta->fetch()) $lista->insertar($registro);
$ordenar = ($_GET['orden'] ?? 'burbuja') === 'burbuja';
if ($ordenar) $lista->burbuja($meta['orden'], $tabla === 'pedidos');
$clientes = new ListaDoblementeEnlazada();
if ($tabla === 'pedidos') {
    $consulta = $db->query('SELECT id_cliente, nombre FROM clientes ORDER BY id_cliente');
    while ($registro = $consulta->fetch()) $clientes->insertar($registro);
}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Tienda Osco</title><link rel="stylesheet" href="estilos.css"></head><body><main>
<h1>Tienda Osco</h1><p>PHP · MySQL · Lista doblemente enlazada · Bubble Sort</p>
<nav><?php foreach ($tablas as $nombre=>$config): ?><a href="?tabla=<?=e($nombre)?>"><?=e(ucfirst($nombre))?></a><?php endforeach; ?></nav>
<h2>Gestión de <?=e($tabla)?></h2>
<?php if ($mensaje): ?><p class="exito"><?=e($mensaje)?></p><?php endif; ?>
<?php if ($error): ?><p class="error" role="alert"><?=e($error)?></p><?php endif; ?>
<section><h3><?=empty($edicion[$idCampo]) ? 'Registrar' : 'Modificar registro #'.e($edicion[$idCampo])?></h3>
<form method="post"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="accion" value="guardar"><input type="hidden" name="id" value="<?=e($edicion[$idCampo] ?? '')?>">
<?php foreach ($meta['campos'] as $campo): ?><label><?=e(ucfirst(str_replace('_',' ', $campo)))?>
<?php if ($campo === 'id_cliente'): ?><select name="id_cliente" required><option value="">Seleccionar cliente</option><?php foreach ($clientes->recorrer() as $cliente): ?><option value="<?=e($cliente['id_cliente'])?>" <?=($edicion['id_cliente'] ?? '') == $cliente['id_cliente'] ? 'selected' : ''?>>#<?=e($cliente['id_cliente'])?> — <?=e($cliente['nombre'])?></option><?php endforeach; ?></select>
<?php else: $tipo = $campo === 'email' ? 'email' : ($campo === 'fecha' ? 'date' : (in_array($campo,['edad','stock','precio','total'],true) ? 'number' : 'text')); ?>
<input name="<?=e($campo)?>" type="<?=e($tipo)?>" value="<?=e($edicion[$campo] ?? '')?>" required <?=in_array($campo,['nombre','email','categoria'],true) ? 'maxlength="'.(['nombre'=>100,'email'=>150,'categoria'=>50][$campo]).'"' : ''?> <?=in_array($campo,['precio','total'],true) ? 'step="0.01" min="'.($campo === 'precio' ? '0.01' : '0').'" max="99999999.99"' : ''?> <?=in_array($campo,['edad','stock'],true) ? 'step="1" max="2147483647" min="'.($campo === 'edad' ? '18' : '0').'"' : ''?>>
<?php endif; ?></label><?php endforeach; ?>
<button type="submit"><?=empty($edicion[$idCampo]) ? 'Registrar' : 'Guardar modificación'?></button> <a href="?tabla=<?=e($tabla)?>">Nuevo / cancelar</a></form></section>
<section><h3>Registros en la lista: <?=$lista->cantidad?></h3><p><?= $ordenar ? 'Bubble Sort ascendente por '.e($meta['orden']) : 'Lista sin Bubble Sort, en orden de ID'?>.</p><p><a href="?tabla=<?=e($tabla)?>&orden=original">Ver antes de ordenar</a> · <a href="?tabla=<?=e($tabla)?>&orden=burbuja">Aplicar Bubble Sort</a></p>
<div class="tabla"><table><thead><tr><?php foreach ([$idCampo, ...$meta['campos']] as $campo): ?><th><?=e($campo)?></th><?php endforeach; ?><th>Acciones</th></tr></thead><tbody>
<?php foreach ($lista->recorrer() as $registro): ?><tr><?php foreach ([$idCampo, ...$meta['campos']] as $campo): ?><td><?=e($registro[$campo])?></td><?php endforeach; ?><td><a href="?tabla=<?=e($tabla)?>&editar=<?=e($registro[$idCampo])?>">Modificar</a>
<form class="inline" method="post" onsubmit="return confirm('¿Eliminar este registro?')"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?=e($registro[$idCampo])?>"><button class="eliminar">Eliminar</button></form></td></tr><?php endforeach; ?>
<?php if (!$lista->cantidad): ?><tr><td colspan="<?=count($meta['campos'])+2?>">No hay registros.</td></tr><?php endif; ?></tbody></table></div></section></main></body></html>
