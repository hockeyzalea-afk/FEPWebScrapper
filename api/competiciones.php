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


if ($resource !== 'competiciones.php') {
    http_response_code(404);
    echo json_encode(['error' => 'Recurso no encontrado']);
    exit;
}

// ==============================================
//          GET - Listar todas (con filtro opcional), una o por temporada
// ==============================================
if ($requestMethod === 'GET') {
    // Obtenemos el parámetro temporada_id si existe
    $temporada_id = isset($_GET['temporada_id']) ? filter_var($_GET['temporada_id'], FILTER_VALIDATE_INT) : null;

    if ($id !== false && $id !== null) {
        // GET /competiciones.php/123 → una competición específica
        $stmt = $pdo->prepare("
            SELECT c.*, t.nombre AS temporada_nombre 
            FROM competiciones c
            LEFT JOIN temporadas t ON c.temporada_id = t.id
            WHERE c.id = ?
        ");
        $stmt->execute([$id]);
        $competicion = $stmt->fetch();
        
        if (!$competicion) {
            http_response_code(404);
            echo json_encode(['error' => 'Competición no encontrada']);
        } else {
            echo json_encode($competicion);
        }
    } 
    elseif ($temporada_id !== null && $temporada_id !== false) {
        // GET /competiciones.php?temporada_id=5 → competiciones de una temporada
        $stmt = $pdo->prepare("
            SELECT c.*, t.nombre AS temporada_nombre 
            FROM competiciones c
            LEFT JOIN temporadas t ON c.temporada_id = t.id
            WHERE c.temporada_id = ?
            ORDER BY c.nombre ASC
        ");
        $stmt->execute([$temporada_id]);
        $competiciones = $stmt->fetchAll();
        
        echo json_encode([
            'total' => count($competiciones),
            'temporada_id' => $temporada_id,
            'data' => $competiciones
        ]);
    } 
    else {
        // GET /competiciones.php → todas las competiciones
        $stmt = $pdo->query("
            SELECT c.*, t.nombre AS temporada_nombre 
            FROM competiciones c
            LEFT JOIN temporadas t ON c.temporada_id = t.id
            ORDER BY t.nombre DESC, c.nombre ASC
        ");
        $competiciones = $stmt->fetchAll();
        
        echo json_encode([
            'total' => count($competiciones),
            'data' => $competiciones
        ]);
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

    $isBulk = isset($data[0]) && is_array($data[0]);
    $items = $isBulk ? $data : [$data];
    $insertadas = 0;
    $errores = [];

    $stmt = $pdo->prepare("
        INSERT INTO competiciones 
        (nombre, temporada_id) 
        VALUES (:nombre, :temporada_id)
    ");

    $pdo->beginTransaction();

    try {
        foreach ($items as $item) {
            if (empty(trim($item['nombre'] ?? ''))) {
                $errores[] = "Falta nombre en uno de los registros";
                continue;
            }

            $temporada_id = !empty($item['temporada_id']) ? (int)$item['temporada_id'] : null;

            // Opcional: verificar que la temporada existe
            if ($temporada_id !== null) {
                $check = $pdo->prepare("SELECT 1 FROM temporadas WHERE id = ?");
                $check->execute([$temporada_id]);
                if (!$check->fetch()) {
                    $errores[] = "Temporada ID $temporada_id no existe";
                    continue;
                }
            }

            $stmt->execute([
                ':nombre'       => trim($item['nombre']),
                ':temporada_id' => $temporada_id
            ]);

            $insertadas++;
        }

        $pdo->commit();

        http_response_code(201);
        echo json_encode([
            'mensaje'    => 'Competición(es) creada(s) correctamente',
            'insertadas' => $insertadas,
            'errores'    => $errores ?: null
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
if ($requestMethod === 'PUT' && $id !== false && $id !== null) {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!is_array($data) || empty($data)) {
        http_response_code(400);
        echo json_encode(['error' => 'Datos inválidos']);
        exit;
    }

    $updates = [];
    $params = [':id' => $id];

    if (isset($data['nombre'])) {
        $updates[] = "nombre = :nombre";
        $params[':nombre'] = trim($data['nombre']);
    }
    if (array_key_exists('temporada_id', $data)) {
        $updates[] = "temporada_id = :temporada_id";
        $params[':temporada_id'] = $data['temporada_id'] ? (int)$data['temporada_id'] : null;
    }

    if (empty($updates)) {
        http_response_code(400);
        echo json_encode(['error' => 'No se enviaron campos para actualizar']);
        exit;
    }

    $sql = "UPDATE competiciones SET " . implode(', ', $updates) . " WHERE id = :id";
    $stmt = $pdo->prepare($sql);

    try {
        $stmt->execute($params);
        
        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['error' => 'Competición no encontrada o sin cambios']);
        } else {
            echo json_encode(['mensaje' => 'Competición actualizada correctamente']);
        }
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ==============================================
//          DELETE
// ==============================================
if ($requestMethod === 'DELETE' && $id !== false && $id !== null) {
    $stmt = $pdo->prepare("DELETE FROM competiciones WHERE id = ?");
    
    try {
        $stmt->execute([$id]);
        
        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['error' => 'Competición no encontrada']);
        } else {
            http_response_code(200);
            echo json_encode(['mensaje' => 'Competición eliminada correctamente']);
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