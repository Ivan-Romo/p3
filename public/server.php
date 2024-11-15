<?php
session_start();
$logFile = './logs/game_log.log';

function logMessage($message)
{
    global $logFile;
    error_log(date('[Y-m-d H:i:s] ') . $message . PHP_EOL, 3, $logFile);
}


try {
    $db = new PDO('sqlite:../private/users.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['error' => 'Conexión con la base de datos fallida: ' . $e->getMessage()]);
    exit();
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

switch ($action) {
    case 'join':
        if (!isset($_SESSION['player_id']) || $_GET['reset'] === 'true') {
            $_SESSION['player_id'] = uniqid();
        }

        $player_id = $_SESSION['player_id'];
        $game_id = null;

        $stmt = $db->prepare('SELECT game_id FROM games WHERE player2 IS NULL LIMIT 1');
        $stmt->execute();
        $game = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($game) {
            $game_id = $game['game_id'];
            $stmt = $db->prepare('UPDATE games SET player2 = :player_id WHERE game_id = :game_id');
            $stmt->bindValue(':player_id', $player_id);
            $stmt->bindValue(':game_id', $game_id);
            $stmt->execute();
        } else {
            $game_id = uniqid();
            $stmt = $db->prepare('INSERT INTO games (game_id, player1) VALUES (:game_id, :player_id)');
            $stmt->bindValue(':game_id', $game_id);
            $stmt->bindValue(':player_id', $player_id);
            $stmt->execute();

            $squareX = rand(100, 700);
            $squareY = rand(100, 500);
            $stmt = $db->prepare('INSERT INTO squares (game_id, x, y) VALUES (:game_id, :x, :y)');
            $stmt->bindValue(':game_id', $game_id);
            $stmt->bindValue(':x', $squareX);
            $stmt->bindValue(':y', $squareY);
            $stmt->execute();
        }

        echo json_encode(['gameId' => $game_id, 'playerId' => $player_id]);
        break;

    case 'update':
        $data = json_decode(file_get_contents('php://input'), true);
        $game_id = $data['gameId'];
        $player_id = $data['playerId'];

        $bullets = json_encode($data['bullets']);
        $stmt = $db->prepare('SELECT COUNT(*) FROM players WHERE game_id = :game_id AND player_id = :player_id');
        $stmt->bindValue(':game_id', $game_id);
        $stmt->bindValue(':player_id', $player_id);
        $stmt->execute();
        $playerExists = $stmt->fetchColumn();

        if ($playerExists) {
            $stmt = $db->prepare('UPDATE players 
                                      SET x = :x, y = :y, angle = :angle, bullets = :bullets 
                                      WHERE game_id = :game_id AND player_id = :player_id');
            $stmt->bindValue(':game_id', $game_id);
            $stmt->bindValue(':player_id', $player_id);
            $stmt->bindValue(':x', $data['x']);
            $stmt->bindValue(':y', $data['y']);
            $stmt->bindValue(':angle', $data['angle']);
            $stmt->bindValue(':bullets', $bullets);
            $stmt->execute();
        } else {
            $stmt = $db->prepare('INSERT INTO players (game_id, player_id, x, y, angle, bullets, score) 
                                      VALUES (:game_id, :player_id, :x, :y, :angle, :bullets, 0)');
            $stmt->bindValue(':game_id', $game_id);
            $stmt->bindValue(':player_id', $player_id);
            $stmt->bindValue(':x', $data['x']);
            $stmt->bindValue(':y', $data['y']);
            $stmt->bindValue(':angle', $data['angle']);
            $stmt->bindValue(':bullets', $bullets);
            $stmt->execute();
        }
        // temps a 1 segon
        $stmt = $db->prepare('SELECT * FROM game_collisions WHERE game_id = :game_id  AND (strftime("%s", "now") - strftime("%s", timeCollide)) > 1');
        $stmt->bindValue(':game_id', $game_id);
        $stmt->execute();
        $collisions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($collisions)) {
            $bulletsArray = [];
            $totalWeight = 0;
        
            foreach ($collisions as $collision) {
                $timeCollide = $collision['timeCollide'];
                $latency = $collision['latency'];
                
                //Assignem un pes a cada colsio
                $weight = 1 / ($timeCollide + $latency);
                $totalWeight += $weight;
                
                $bulletsArray[] = [
                    'bullet_id' => $collision['bullet_id'],
                    'player_id' => $collision['player_id'],
                    'weight' => $weight
                ];
            }
        
            // Seleccionamos un ganador en función de los pesos
            $random = mt_rand() / mt_getrandmax() * $totalWeight;
            $currentWeight = 0;
            foreach ($bulletsArray as $bullet) {
                $currentWeight += $bullet['weight'];
                if ($currentWeight >= $random) {
                    $winner = $bullet;
                    break;
                }
            }
        
            // Actualización de puntaje y posicionamiento del nuevo cuadrado
            $stmt = $db->prepare('UPDATE players SET score = score + 1 WHERE game_id = :game_id AND player_id = :player_id');
            $stmt->bindValue(':game_id', $game_id);
            $stmt->bindValue(':player_id', $winner['player_id']);
            $stmt->execute();
        
            $stmt = $db->prepare('UPDATE squares SET is_visible = 0 WHERE game_id = :game_id');
            $stmt->bindValue(':game_id', $game_id);
            $stmt->execute();
        
            $newSquareX = rand(100, 700);
            $newSquareY = rand(100, 500);
            $stmt = $db->prepare('UPDATE squares SET x = :x, y = :y, is_visible = 1 WHERE game_id = :game_id');
            $stmt->bindValue(':x', $newSquareX);
            $stmt->bindValue(':y', $newSquareY);
            $stmt->bindValue(':game_id', $game_id);
            $stmt->execute();
        
            $stmt = $db->prepare('DELETE FROM game_collisions WHERE game_id = :game_id ');
            $stmt->bindValue(':game_id', $game_id);
            $stmt->execute();
        }
        echo json_encode(["status" => "success"]);
        
        break;

    case 'shoot':
        $data = json_decode(file_get_contents('php://input'), true);
        $game_id = $data['gameId'];
        $player_id = $data['playerId'];  // Añadir el player_id al obtener los datos
        $x = $data['x'];
        $y = $data['y'];
        $direction = $data['direction'];

        $bullet_id = uniqid();

        $stmt = $db->prepare('INSERT INTO bullets (bullet_id, game_id, player_id, x, y, direction, created_at) 
                                VALUES (:bullet_id, :game_id, :player_id, :x, :y, :direction, CURRENT_TIMESTAMP)');
        $stmt->bindValue(':bullet_id', $bullet_id);
        $stmt->bindValue(':game_id', $game_id);
        $stmt->bindValue(':player_id', $player_id);  // Asignar el player_id aquí
        $stmt->bindValue(':x', $x);
        $stmt->bindValue(':y', $y);
        $stmt->bindValue(':direction', $direction);
        $stmt->execute();
        break;

    case 'cleanBullets':
        $stmt = $db->prepare('DELETE FROM bullets WHERE (strftime("%s", "now") - strftime("%s", created_at)) > 5');
        $stmt->execute();
        break;

    case 'getBullets':
        $game_id = $_GET['gameId'];
        // Obtener las balas del juego
        $stmt = $db->prepare('SELECT bullet_id, player_id, x, y, direction, created_at FROM bullets WHERE game_id = :game_id');
        $stmt->bindValue(':game_id', $game_id);
        $stmt->execute();
        $bullets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['bullets' => $bullets]);
        $stmt = $db->prepare('SELECT x, y, is_visible FROM squares WHERE game_id = :game_id');
        $stmt->bindValue(':game_id', $game_id);
        $stmt->execute();
        $square = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($square && $square['is_visible'] == 1) {
            $squareX = $square['x'];
            $squareY = $square['y'];

            $initialBulletSpeed = 140;

            foreach ($bullets as $bullet) {
                $bulletCreationTime = new DateTime($bullet['created_at']);
                $currentTime = new DateTime();

                $bulletCreationTimeInMilliseconds = (float) $bulletCreationTime->format('U.u');
                $currentTimeInMilliseconds = microtime(true);
                $timeElapsed = $currentTimeInMilliseconds - $bulletCreationTimeInMilliseconds;

                $accelerationFactor = 1 + ($timeElapsed * 0.3);
                $bulletSpeed = $initialBulletSpeed * $accelerationFactor;

                $newBulletX = $bullet['x'] + $bulletSpeed * $timeElapsed * cos($bullet['direction']);
                $newBulletY = $bullet['y'] + $bulletSpeed * $timeElapsed * sin($bullet['direction']);


                if (sqrt(pow($newBulletX - $squareX, 2) + pow($newBulletY - $squareY, 2)) < 25) {
                    $possibleCollisions[] = $bullet;
                    $latency = isset($data['latency']) ? (int) $data['latency'] : null;

                    $stmt = $db->prepare('INSERT INTO game_collisions (game_id, bullet_id, timeCollide, player_id, latency) VALUES (:game_id, :bullet_id, CURRENT_TIMESTAMP, :player_id, :latency)');
                    $stmt->bindValue(':game_id', $game_id);
                    $stmt->bindValue(':bullet_id', $bullet['bullet_id']);
                    $stmt->bindValue(':player_id', $bullet['player_id']);
                    $stmt->bindValue(':latency', $latency);
                    $stmt->execute();

                    foreach ($possibleCollisions as $collisionBullet) {
                        $stmt = $db->prepare('DELETE FROM bullets WHERE bullet_id = :bullet_id');
                        $stmt->bindValue(':bullet_id', $collisionBullet['bullet_id']);
                        $stmt->execute();
                    }

                    break;
                }
            }
        }

        echo json_encode(['bullets' => $bullets]);
        break;

    case 'status':
        $game_id = $_GET['gameId'];

        $stmt = $db->prepare('SELECT player_id, x, y, angle, bullets, score FROM players WHERE game_id = :game_id');
        $stmt->bindValue(':game_id', $game_id);
        $stmt->execute();
        $players = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $db->prepare('SELECT x, y, is_visible FROM squares WHERE game_id = :game_id');
        $stmt->bindValue(':game_id', $game_id);
        $stmt->execute();
        $square = $stmt->fetch(PDO::FETCH_ASSOC);

        $otherPlayers = [];
        foreach ($players as $player) {
            $otherPlayers[$player['player_id']] = [
                'x' => $player['x'],
                'y' => $player['y'],
                'angle' => $player['angle'],
                'bullets' => json_decode($player['bullets'], true),
                'score' => $player['score']
            ];
        }

        echo json_encode(['otherPlayers' => $otherPlayers, 'square' => $square]);
        break;

    case 'updateLatency':

        logMessage("EPPP");
        $input = json_decode(file_get_contents('php://input'), true);
        $playerId = $input['playerId'];
        $gameId = $input['gameId'];
        $latency = $input['latency'];
    
        // Actualizar la latencia en la tabla game_collisions
        $query = "UPDATE game_collisions SET latency = :latency WHERE player_id = :playerId AND game_id = :gameId";
        $stmt = $db->prepare($query);
        $stmt->execute(['latency' => $latency, 'playerId' => $playerId, 'gameId' => $gameId]);
        echo json_encode(["status" => "success"]);
        break;
    default:
        echo json_encode(['error' => 'Acción no válida']);
        break;
}