<?php
// api/v1/temporadas.php

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');           // ← ¡Cambiar en producción!
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ==============================================
//          CONFIGURACIÓN
// ==============================================
const DB_HOST = 'sql311.infinityfree.com';
const DB_NAME = 'if0_40641551_hockey_fep';
const DB_USER = 'if0_40641551';
const DB_PASS = 'dM0V4bqHIrVz';

$charset = 'utf8mb4';

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

if ($resource !== 'temporadas.php') {
    http_response_code(404);
    echo json_encode(['error' => 'Recurso no encontrado']);
    exit;
}



// ==============================================
//          GET - Listar todas o una
// ==============================================
if ($requestMethod === 'GET') {
    if ($id === null) {
        // GET /temporadas → todas
        $stmt = $pdo->query("SELECT * FROM temporadas ORDER BY fecha_inicio DESC");
        $temporadas = $stmt->fetchAll();
        
        echo json_encode([
            'data' => $temporadas
        ]);
        //echo json_encode($temporadas);
    } else {
        // GET /temporadas/123 → una
        $stmt = $pdo->prepare("SELECT * FROM temporadas WHERE id = ?");
        $stmt->execute([$id]);
        $temporada = $stmt->fetch();
        
        if (!$temporada) {
            http_response_code(404);
            echo json_encode(['error' => 'Temporada no encontrada']);
        } else {
            echo json_encode($temporada);
        }
    }
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

    // Si es array de arrays → bulk insert
    $isBulk = isset($data[0]) && is_array($data[0]);

    $items = $isBulk ? $data : [$data];
    $insertadas = 0;
    $errores = [];

    $stmt = $pdo->prepare("
        INSERT INTO temporadas 
        (nombre, fecha_inicio, fecha_fin, activa) 
        VALUES (:nombre, :inicio, :fin, :activa)
    ");

    $pdo->beginTransaction();

    try {
        foreach ($items as $item) {
            if (empty($item['nombre'])) {
                $errores[] = "Falta nombre en uno de los registros";
                continue;
            }

            $stmt->execute([
                ':nombre' => trim($item['nombre']),
                ':inicio' => $item['fecha_inicio'] ?? null,
                ':fin'    => $item['fecha_fin'] ?? null,
                ':activa' => isset($item['activa']) ? (int)$item['activa'] : 1
            ]);

            $insertadas++;
        }

        $pdo->commit();

        http_response_code(201);
        echo json_encode([
            'mensaje' => 'Temporada(s) creada(s) correctamente',
            'insertadas' => $insertadas,
            'errores' => $errores ?: null
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ==============================================
//          PUT - Actualizar
// ==============================================
if ($requestMethod === 'PUT' && $id !== null) {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!is_array($data) || empty($data)) {
        http_response_code(400);
        echo json_encode(['error' => 'Datos inválidos']);
        exit;
    }

    // Campos que se pueden actualizar
    $updates = [];
    $params = [];

    if (isset($data['nombre'])) {
        $updates[] = "nombre = :nombre";
        $params[':nombre'] = trim($data['nombre']);
    }
    if (array_key_exists('fecha_inicio', $data)) {
        $updates[] = "fecha_inicio = :inicio";
        $params[':inicio'] = $data['fecha_inicio'] ?: null;
    }
    if (array_key_exists('fecha_fin', $data)) {
        $updates[] = "fecha_fin = :fin";
        $params[':fin'] = $data['fecha_fin'] ?: null;
    }
    if (isset($data['activa'])) {
        $updates[] = "activa = :activa";
        $params[':activa'] = (int)$data['activa'];
    }

    if (empty($updates)) {
        http_response_code(400);
        echo json_encode(['error' => 'No se enviaron campos para actualizar']);
        exit;
    }

    $params[':id'] = $id;

    $sql = "UPDATE temporadas SET " . implode(', ', $updates) . " WHERE id = :id";
    $stmt = $pdo->prepare($sql);

    try {
        $stmt->execute($params);
        
        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['error' => 'Temporada no encontrada o sin cambios']);
        } else {
            echo json_encode(['mensaje' => 'Temporada actualizada correctamente']);
        }
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ==============================================
//          DELETE - Eliminar
// ==============================================
if ($requestMethod === 'DELETE' && $id !== null) {
    $stmt = $pdo->prepare("DELETE FROM temporadas WHERE id = ?");
    
    try {
        $stmt->execute([$id]);
        
        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['error' => 'Temporada no encontrada']);
        } else {
            http_response_code(200);
            echo json_encode(['mensaje' => 'Temporada eliminada correctamente']);
        }
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// Método no soportado
http_response_code(405);
echo json_encode(['error' => 'Método no permitido']);