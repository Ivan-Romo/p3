const canvas = document.getElementById('gameCanvas');
const ctx = canvas.getContext('2d');

// Ajustar el tamaño del canvas
canvas.width = window.innerWidth;
canvas.height = window.innerHeight;

showedBullets = []

const keys = {
  w: false,
  a: false,
  s: false,
  d: false
};

let mouseX = canvas.width / 2;
let mouseY = canvas.height / 2;

// Identificadores para el jugador y juego
let playerId, gameId;

// Definir el tanque
const player = {
  id: null,
  x: canvas.width / 2,
  y: canvas.height / 2,
  radius: 20,
  color: 'blue',
  speed: 3,
  angle: 0,
  bullets: []
};

// Otros jugadores en la partida
let otherPlayers = {};

// Definir la bala
function Bullet(x, y, angle, id) {
  this.id = id
  this.x = x;
  this.y = y;
  this.radius = 5;
  this.speed = 5;
  this.angle = angle;
}

// Actualizar posición de la bala
Bullet.prototype.update = function () {
  this.x += this.speed * Math.cos(this.angle);
  this.y += this.speed * Math.sin(this.angle);
};

// Detectar colisiones para eliminar las balas fuera de la pantalla
Bullet.prototype.isOffScreen = function () {
  return (
    this.x < 0 ||
    this.x > canvas.width ||
    this.y < 0 ||
    this.y > canvas.height
  );
};

// Dibujar el tanque
function drawPlayer(playerObj) {
  ctx.save();
  ctx.translate(playerObj.x, playerObj.y);
  ctx.rotate(playerObj.angle);
  ctx.fillStyle = playerObj.color;
  ctx.beginPath();
  ctx.arc(0, 0, playerObj.radius, 0, Math.PI * 2);
  ctx.fill();

  // Dibujar el cañón
  ctx.fillStyle = 'black';
  ctx.fillRect(0, -5, 30, 10);
  ctx.restore();
}

// Dibujar una bala
function drawBullet(bullet) {
  ctx.fillStyle = 'black';
  ctx.beginPath();
  ctx.arc(bullet.x, bullet.y, bullet.radius, 0, Math.PI * 2);
  ctx.fill();
}

// Función para actualizar el estado del juego
function update() {
  // Movimiento del jugador
  if (keys.w) player.y -= player.speed;
  if (keys.s) player.y += player.speed;
  if (keys.a) player.x -= player.speed;
  if (keys.d) player.x += player.speed;

  // Girar el tanque hacia el ratón
  const dx = mouseX - player.x;
  const dy = mouseY - player.y;
  player.angle = Math.atan2(dy, dx);

  // Actualizar posición de las balas del jugador
  player.bullets.forEach((bullet, index) => {
    bullet.update();
    if (bullet.isOffScreen()) {
      player.bullets.splice(index, 1); // Eliminar la bala si sale de la pantalla
    }
  });

  // Actualizar balas de otros jugadores

}

// Función para renderizar el juego
function render() {
  ctx.clearRect(0, 0, canvas.width, canvas.height);

  // Dibujar al propio jugador
  drawPlayer(player);
  player.bullets.forEach(drawBullet);

  // Dibujar otros jugadores
  Object.values(otherPlayers).forEach(playerObj => {
    if (playerObj.id !== player.id) {
      drawPlayer(playerObj);
      playerObj.bullets.forEach(drawBullet);
    }
  });

  // Dibujar el cuadrado si es visible
  if (square && square.is_visible == 1) {
    drawSquare(square.x, square.y);
  }

  ctx.fillStyle = 'black';
  ctx.font = '20px Arial';
  ctx.fillText(`Tu Puntuación: ${playerScore}`, 10, 30);
  ctx.fillText(`Puntuación Enemigo: ${enemyScore}`, canvas.width - 300, 30);
}

function updateScores(data) {
  playerScore = data.otherPlayers[playerId].score;
  enemyScore = Object.values(data.otherPlayers).find(p => p.id !== playerId).score;
}


let square = null; // Variable para almacenar el cuadrado

function drawSquare(x, y) {
  if (square && square.is_visible == 1) {
    ctx.fillStyle = 'yellow';
    ctx.fillRect(x - 25, y - 25, 50, 50);  // Dibujar el cuadrado amarillo
  }
}

let shownBullets = [];

function updateBullets() {
  // Recibir las balas desde el servidor
  fetch(`server.php?action=getBullets&gameId=${gameId}`)
    .then(response => response.json())
    .then(data => {

      data.bullets.forEach(bulletData => {
        console.log(bulletData.bullet_id);

        // Verificar si la bala ya está en el array shownBullets
        const bulletExists = player.bullets.some(b => b.id === bulletData.bullet_id);
        const alreadyShown = shownBullets.includes(bulletData.bullet_id);

        // Solo crear la bala si no existe y no ha sido registrada antes
        if (!bulletExists && !alreadyShown) {
          const bullet = new Bullet(bulletData.x, bulletData.y, bulletData.direction, bulletData.bullet_id);
          player.bullets.push(bullet);

          // Añadir el id de la bala al array shownBullets
          shownBullets.push(bulletData.bullet_id);
        }
      });
    });
}
// Enviar el estado del jugador al servidor
function sendGameState() {
  const data = {
    playerId: playerId,
    gameId: gameId,
    x: player.x,
    y: player.y,
    angle: player.angle,
    bullets: player.bullets
  };

  fetch('server.php?action=update', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json'
    },
    body: JSON.stringify(data)
  });
}

let playerScore = 0;
let enemyScore = 0;

// Recibir el estado del juego desde el servidor
function getGameState() {
  fetch(`server.php?action=status&gameId=${gameId}`)
    .then(response => response.json())
    .then(data => {
      // Actualizar los otros jugadores
      otherPlayers = data.otherPlayers;

      // Información del cuadrado
      square = data.square;

      // Actualizar puntuaciones
      updateScores(data);

      Object.keys(otherPlayers).forEach(playerId => {
        otherPlayers[playerId].radius = 20; // Tamaño estándar de los tanques
        otherPlayers[playerId].color = 'red'; // Color de los tanques de otros jugadores
        otherPlayers[playerId].id = playerId;

        // Convertir las balas recibidas en instancias de Bullet
        otherPlayers[playerId].bullets = otherPlayers[playerId].bullets.map(bulletData =>
          new Bullet(bulletData.x, bulletData.y, bulletData.direction)
        );
      });
    });
}
// Bucle principal del juego
function gameLoop() {
  update();
  render();
  requestAnimationFrame(gameLoop);
}

// Disparar una bala al hacer clic
canvas.addEventListener('mousedown', function () {
  const bullet = new Bullet(player.x, player.y, player.angle, player.id);  // Asignar el id del jugador
  player.bullets.push(bullet);

  // Informar al servidor de la bala disparada
  const bulletData = {
    gameId: gameId,
    playerId: playerId,  // Enviar también el playerId
    x: bullet.x,
    y: bullet.y,
    direction: bullet.angle
  };

  fetch('server.php?action=shoot', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json'
    },
    body: JSON.stringify(bulletData)
  });
});

// Actualizar posición del ratón
canvas.addEventListener('mousemove', function (event) {
  mouseX = event.clientX;
  mouseY = event.clientY;
});

// Control de teclas
window.addEventListener('keydown', function (e) {
  if (e.key === 'w') keys.w = true;
  if (e.key === 'a') keys.a = true;
  if (e.key === 's') keys.s = true;
  if (e.key === 'd') keys.d = true;
});

window.addEventListener('keyup', function (e) {
  if (e.key === 'w') keys.w = false;
  if (e.key === 'a') keys.a = false;
  if (e.key === 's') keys.s = false;
  if (e.key === 'd') keys.d = false;
});


function cleanBullets() {
  fetch('server.php?action=cleanBullets')
    .then(response => {
      if (!response.ok) {
        console.error('Error al limpiar balas');
      }
    })
    .catch(error => {
      console.error('Error al hacer la solicitud de cleanBullets:', error);
    });
}

// Unirse al juego
function joinGame() {
  fetch('server.php?action=join')
    .then(response => response.json())
    .then(data => {
      playerId = data.playerId;
      gameId = data.gameId;
      player.id = playerId;

      // Comenzar a recibir el estado del juego regularmente
      setInterval(getGameState, 100); // Obtener el estado del juego cada 200 ms
      setInterval(updateBullets, 100); // Actualizar balas cada 200 ms

      // Enviar el estado del jugador al servidor cada 500 ms
      setInterval(sendGameState, 100);
      setInterval(cleanBullets, 200); // Llamada cada 200 ms (5 veces por segundo)
    });
}

// Iniciar el juego
joinGame();
gameLoop();
