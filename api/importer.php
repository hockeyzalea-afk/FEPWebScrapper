<?php
// api/v1/competiciones.php   ← pon este archivo directamente en htdocs/competiciones.php

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *'); // ¡Cambiar en producción!
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ==============================================
//          CONFIGURACIÓN BD
// ==============================================
const DB_HOST = 'sql311.infinityfree.com';
const DB_NAME = 'if0_40641551_hockey_fep';
const DB_USER = 'if0_40641551';
const DB_PASS = 'dM0V4bqHIrVz';

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    die(json_encode(['error' => 'Error de conexión a BD']));
}

// ==============================================
//          RUTEO SIMPLE
// ==============================================
$requestMethod = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = explode('/', trim($uri, '/'));

$resource = $uri[2] ?? '';      // temporadas
$object = explode(".", $resource)[0];
$id = $uri[3] ?? null;          // id opcional


if ($resource !== 'importer.php') {
    http_response_code(404);
    echo json_encode(['error' => 'Recurso no encontrado']);
    exit;
}

// ==============================================
//          POST - Crear (una o varias)
// ==============================================
if ($requestMethod === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!is_array($data) || empty($data)) {
        http_response_code(400);
        echo json_encode(['error' => 'Datos inválidos o vacíos']);
        exit;
    }
    $competionc_id = $data[0];
    $equipos = $data[1];
    $partidos = $data[3];
    $jugadores = $data[5];
    $estadisticas = $data[7];
    $isBulk_equipos = isset($equipos) && is_array($equipos);
    $isBulk_partidos = isset($partidos) && is_array($partidos);
    $isBulk_jugadores = isset($jugadores) && is_array($jugadores);
    $isBulk_estadisticas = isset($estadisticas) && is_array($estadisticas);
    $insertadas = 0;
    $errores = [];
    $inserted_items = [];

    // Verificar que existen las 4 listas
    if (!isset($equipos) || !isset($partidos) || !isset($jugadores) || !isset($estadisticas)) {
        echo json_encode(['error' => 'Faltan listas en el objeto']);
        exit;
    }
    try {
        // Conectar a la base de datos usando PDO
        //$pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
        //$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Iniciar transacción para inserts masivos
        $pdo->beginTransaction();


        $stmt_del = $pdo->prepare("
            DELETE FROM partidos_tmp WHERE equipo_local_id = :local_id and equipo_visitante_id = :visit_id
        ");

        // Insertar lista1 en tabla1
        $stmt1 = $pdo->prepare("
            INSERT INTO equipos 
            (id, nombre, nombre_corto, escudo, ciudad, pabellon, telefono, email, miEquipo, competicion_id, puntos, pj, pg, pe, pp, gf, gc, gav, created_at)
            VALUES (:id, :nombre, :nombre_corto, :escudo, :ciudad, :pabellon, :telefono, :email, :miEquipo, :competicion_id, :puntos, :pj, :pg, :pe, :pp, :gf, :gc, :gav, NOW())
            ON DUPLICATE KEY UPDATE 
            puntos = VALUES(puntos), 
            pj = VALUES(pj), 
            pg = VALUES(pg), 
            pe = VALUES(pe), 
            pp = VALUES(pp), 
            gf = VALUES(gf), 
            gc = VALUES(gc), 
            gav = VALUES(gav)
        ");
        
        foreach ($equipos as $item) {
            if (empty(trim($item['id'] ?? ''))) {
                $errores[] = "Falta id en uno de los registros";
                continue;
            }
            $competicion_id = !empty($item['competicion_id']) ? (int)$item['competicion_id'] : null;
            // Opcional: verificar que la competición existe
            if ($competicion_id !== null) {
                $check = $pdo->prepare("SELECT 1 FROM competiciones WHERE id = ?");
                $check->execute([$competicion_id]);
                if (!$check->fetch()) {
                    $errores[] = "Competicion ID $competicion_id no existe";
                    continue;
                }
            }
            $stmt1->execute([
                ':id'           => trim($item['id']),
                ':nombre'       => trim($item['name']),
                ':nombre_corto' => trim($item['shortname']),
                ':escudo'       => trim($item['logo']),
                ':ciudad'       => trim($item['ciudad']),
                ':pabellon'     => trim($item['pabellon']),
                ':telefono'     => trim($item['telefono']),
                ':email'        => trim($item['email']),
                ':miEquipo'     => trim($item['miEquipo']),
                ':competicion_id' => $competicion_id,
                ':puntos'       => (int)$item['puntos'],
                ':pj'           => (int)$item['pj'],
                ':pg'           => (int)$item['pg'],
                ':pe'           => (int)$item['pe'],
                ':pp'           => (int)$item['pp'],
                ':gf'           => (int)$item['gf'],
                ':gc'           => (int)$item['gc'],
                ':gav'          => (int)$item['gav']
            ]);
            $insertadas++;
        }

        
        // Insertar lista2 en tabla2
        $stmt2 = $pdo->prepare("
        INSERT IGNORE INTO jugadores 
        (id, nombre, apellidos, fecha_nacimiento, dorsal, posicion, telefono, email, foto, activo, fecha_alta, created_at)
        VALUES (:id, :nombre, :apellido, :fecha_nacimiento, :dorsal, :posicion, :telefono, :email, :foto, :activo, NOW(), NOW())
    ");
        $stmt3 = $pdo->prepare("INSERT IGNORE INTO `Jugador_equipo`(jugador_id, equipo_id) VALUES (:jugador_id, :equipo_id)");
        foreach ($jugadores as $index => $item) {
            // Validaciones básicas
            if (!isset($item['id'], $item['nombre'], $item['apellidos'])) {
                $errores[] = "Ítem en índice $index carece de campos obligatorios.";
                continue;           
            }
            $stmt2->execute([
                ':id'              => trim($item['id']),
                ':nombre'         => trim($item['nombre']),
                ':apellido'       => trim($item['apellidos']),
                ':fecha_nacimiento' => trim($item['fecha_nacimiento'] ?? null),
                ':dorsal'        => trim($item['dorsal'] ?? null),
                ':posicion'      => trim($item['posicion'] ?? null),
                ':telefono'      => trim($item['telefono'] ?? null),
                ':email'         => trim($item['email'] ?? null),
                ':foto'          => trim($item['foto'] ?? null),
                ':activo'        => isset($item['activo']) ? (bool)$item['activo'] : true,
            ]);
            $insertadas++;
            try {
                $stmt3->execute([
                    ':jugador_id' => trim($item['id']),
                    ':equipo_id' => trim($item['equipo_id'])
                ]);
            } catch (Exception $e) {
                $errores[] = "Error al insertar jugador_equipo para jugador ID " . trim($item['id']) . ": " . $e->getMessage();
            }
            
        }
        
        
        $stmt4 = $pdo->prepare("
            INSERT IGNORE INTO partidos 
            (id, fecha, hora, competicion_id, equipo_local_id, equipo_visitante_id, goles_local, goles_visitante, lugar, jornada, video, observaciones, finalizado, created_at)
            VALUES (:id, :fecha, :hora, :competicion_id, :equipo_local_id, :equipo_visitante_id, :goles_local, :goles_visitante, :lugar, :jornada, :video, :observaciones, :finalizado, NOW())
        ");
        $stmt5 = $pdo->prepare("
            INSERT IGNORE INTO partidos_tmp 
            (fecha, hora, competicion_id, equipo_local_id, equipo_visitante_id, goles_local, goles_visitante, lugar, jornada, observaciones, finalizado, created_at)
            VALUES (:fecha, :hora, :competicion_id, :equipo_local_id, :equipo_visitante_id, :goles_local, :goles_visitante, :lugar, :jornada, :observaciones, :finalizado, NOW())
        ");
        //$stmt4 = $pdo->prepare("INSERT INTO tabla3 (valor, fecha) VALUES (?, ?)");
        
        foreach ($partidos as $index => $item) {
            $inserted_items[] = $item;
            // Validaciones básicas
            if (!isset($item['id']) || $item['id'] === '') {
                $jornada = explode(" ", trim($item['jornada']));
                $jornada_str = $jornada[1] ?? '0';
                $jornada_int = intval($jornada_str);
                $newvalue = date('Y-m-d', strtotime(trim($item['fecha'])));
                $fecha = explode("/", trim($item['fecha']));
                $dia = $fecha[0] ?? '01';
                $mes = $fecha[1] ?? '01';
                $ano = $fecha[2] ?? '2000';
                // Eliminar posible partido temporal previo antes de insertar el definitivo     
                $newvalue = $ano . '-' . $mes . '-' . $dia;
                $stmt5->execute([
                    ':fecha'           => $newvalue,
                    ':hora'            => trim($item['hora']),
                    ':competicion_id'  => trim($item['competicion_id']),
                    ':equipo_local_id' => trim($item['local_id']),
                    ':equipo_visitante_id' => trim($item['visit_id']),
                    ':goles_local'     => 0,
                    ':goles_visitante' => 0,
                    ':lugar'           => trim($item['lugar']),
                    ':jornada'         => $jornada_int,
                    ':observaciones'   => trim($item['observaciones']),
                    ':finalizado'      => 0,

                ]);
            } else {
                $resultado = explode("-", trim($item['resultado']));
                $goles_local = isset($resultado[0]) ? trim($resultado[0]) : 0;
                $goles_visitante = isset($resultado[1]) ? trim($resultado[1]) : 0;
                $jornada = explode(" ", trim($item['jornada']));
                $jornada_str = $jornada[1] ?? '0';
                $jornada_int = intval($jornada_str);
                $newvalue = date('Y-m-d', strtotime(trim($item['fecha'])));
                $fecha = explode("/", trim($item['fecha']));
                $dia = $fecha[0] ?? '01';
                $mes = $fecha[1] ?? '01';
                $ano = $fecha[2] ?? '2000';
                // Eliminar posible partido temporal previo antes de insertar el definitivo     
                $newvalue = $ano . '-' . $mes . '-' . $dia;
                $stmt_del->execute([
                    ':local_id' => trim($item['local_id']),
                    ':visit_id' => trim($item['visit_id'])
                ]);
                $stmt4->execute([
                    ':id'              => trim($item['id']),
                    ':fecha'           => $newvalue,
                    ':hora'            => trim($item['hora']),
                    ':competicion_id'  => trim($item['competicion_id']),
                    ':equipo_local_id' => trim($item['local_id']),
                    ':equipo_visitante_id' => trim($item['visit_id']),
                    ':goles_local'     => $goles_local,
                    ':goles_visitante' => $goles_visitante,
                    ':lugar'           => trim($item['lugar']),
                    ':jornada'         => $jornada_int,
                    ':video'           => trim($item['video'] ?? null),
                    ':observaciones'   => trim($item['observaciones']),
                    ':finalizado'      => 1,

                ]);
            }
            $insertadas++;
        }
        
        $stmt6 = $pdo->prepare("
            INSERT IGNORE INTO `estadisticas_partidos`(partido_id, jugador_id, equipo_id, minutos_jugados, goles, asistencias, tarjetas_amarillas, tarjetas_azules, tarjetas_rojas, faltas, created_at) 
            VALUES (:partido_id, :jugador_id, :equipo_id, :minutos_jugados, :goles, :asistencias, :tarjetas_amarillas, :tarjetas_azules, :tarjetas_rojas, :faltas, NOW())
        ");
        // Insertar lista4 en tabla4
        /*$stmt4 = $pdo->prepare("INSERT INTO tabla4 (usuario, email) VALUES (?, ?)");*/
        foreach ($estadisticas as $item) {
            if (!isset($item['partido_id'], $item['jugador_id'], $item['equipo_id'])) {
                $errores[] = "Ítem carece de campos obligatorios.";
                continue;           
            }
            $stmt6->execute([
                    ':partido_id'      => trim($item['partido_id']),
                    ':jugador_id'      => trim($item['jugador_id']),
                    ':equipo_id'       => trim($item['equipo_id']),
                    ':minutos_jugados' => trim($item['minutos_jugados']),
                    ':goles'           => trim($item['goles']),
                    ':asistencias'     => trim($item['asistencias']),
                    ':tarjetas_amarillas' => trim($item['tarjetas_amarillas']),
                    ':tarjetas_azules' => trim($item['tarjetas_azules']),
                    ':tarjetas_rojas'  => trim($item['tarjetas_rojas']),
                    ':faltas'          => trim($item['faltas']),

                ]);
        }
        
        // Confirmar transacción
        $pdo->commit();
        
        echo json_encode(['success' => true, 'mensaje' => 'Datos insertados correctamente']);
        
    } catch (PDOException $e) {
        // Revertir transacción en caso de error
        if (isset($pdo)) $pdo->rollBack();
        echo json_encode(['error' => 'Error en la base de datos: ' . $e->getMessage()]);
    }
    //echo json_encode(['status' => 'ok','data' => $data]);

}
