const canvas = document.getElementById('gameCanvas');
const ctx = canvas.getContext('2d');

document.getElementById('restartGameButton').addEventListener('click', restartGame);


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

let playerId, gameId;

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

let otherPlayers = {};

function Bullet(x, y, angle, id) {
  this.id = id
  this.x = x;
  this.y = y;
  this.radius = 5;
  this.speed = 5;
  this.angle = angle;
}

Bullet.prototype.update = function () {
  this.x += this.speed * Math.cos(this.angle);
  this.y += this.speed * Math.sin(this.angle);
};

Bullet.prototype.isOffScreen = function () {
  return (
    this.x < 0 ||
    this.x > canvas.width ||
    this.y < 0 ||
    this.y > canvas.height
  );
};

function drawPlayer(playerObj) {
  ctx.save();
  ctx.translate(playerObj.x, playerObj.y);
  ctx.rotate(playerObj.angle);
  ctx.fillStyle = playerObj.color;
  ctx.beginPath();
  ctx.arc(0, 0, playerObj.radius, 0, Math.PI * 2);
  ctx.fill();

  ctx.fillStyle = 'black';
  ctx.fillRect(0, -5, 30, 10);
  ctx.restore();
}

function drawBullet(bullet) {
  ctx.fillStyle = 'black';
  ctx.beginPath();
  ctx.arc(bullet.x, bullet.y, bullet.radius, 0, Math.PI * 2);
  ctx.fill();
}

function update() {
  if (keys.w) player.y -= player.speed;
  if (keys.s) player.y += player.speed;
  if (keys.a) player.x -= player.speed;
  if (keys.d) player.x += player.speed;

  const dx = mouseX - player.x;
  const dy = mouseY - player.y;
  player.angle = Math.atan2(dy, dx);

  player.bullets.forEach((bullet, index) => {
    bullet.update();
    if (bullet.isOffScreen()) {
      player.bullets.splice(index, 1);
    }
  });
}

function render() {
  ctx.clearRect(0, 0, canvas.width, canvas.height);
  drawPlayer(player);
  player.bullets.forEach(drawBullet);
  console.log("player id interno: " + player.id)

  Object.values(otherPlayers).forEach(playerObj => {
    console.log("player id servidor: " + playerObj.id)
    if (playerObj.id != player.id) {
      drawPlayer(playerObj);
      playerObj.bullets.forEach(drawBullet);
    }
  });

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


let square = null; 

function drawSquare(x, y) {
  if (square && square.is_visible == 1) {
    ctx.fillStyle = 'yellow';
    ctx.fillRect(x - 25, y - 25, 50, 50); 
  }
}

let shownBullets = [];

function updateBullets() {
  fetch(`server.php?action=getBullets&gameId=${gameId}`)
    .then(response => response.json())
    .then(data => {

      data.bullets.forEach(bulletData => {

        
        const bulletExists = player.bullets.some(b => b.id === bulletData.bullet_id);
        const alreadyShown = shownBullets.includes(bulletData.bullet_id);

        if (!bulletExists && !alreadyShown) {
          const bullet = new Bullet(bulletData.x, bulletData.y, bulletData.direction, bulletData.bullet_id);
          player.bullets.push(bullet);

          shownBullets.push(bulletData.bullet_id);
        }
      });
    });
}

let latency = 0; // Variable global para almacenar la latencia

function sendGameState() {
  const latencyStart = Date.now(); // Inicia el temporizador de latencia

  const data = {
      playerId: playerId,
      gameId: gameId,
      x: player.x,
      y: player.y,
      angle: player.angle,
      bullets: player.bullets,
  };

  fetch('server.php?action=update', {
      method: 'POST',
      headers: {
          'Content-Type': 'application/json'
      },
      body: JSON.stringify(data)
  })
  .then(response => {
      const latencyEnd = Date.now();
      latency = latencyEnd - latencyStart; // Calcula la latencia en ms y la guarda en la variable global
      
      if (!response.ok) {
          throw new Error('Error en la respuesta del servidor');
      }

      return response.json(); // Asegura que la respuesta sea JSON
  })
  .then(() => {
      console.log("Respuesta recibida y JSON procesado");
      updateLatencyOnServer(latency); // Llama a la función para actualizar la latencia en la base de datos
  })
  .catch(error => {
      console.error('Error en la solicitud de sendGameState:', error);
  });
}

function updateLatencyOnServer(latency) {
    // Envía la latencia al servidor para almacenar en la base de datos
    
    const data = {
        playerId: playerId,
        gameId: gameId,
        latency: latency
    };

    fetch('server.php?action=updateLatency', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(data)
    }).catch(error => {
        console.error('Error al enviar la latencia:', error);
    });
}

let playerScore = 0;
let enemyScore = 0;

function getGameState() {
  fetch(`server.php?action=status&gameId=${gameId}`)
    .then(response => response.json())
    .then(data => {
      otherPlayers = data.otherPlayers;

      console.log(otherPlayers);

      square = data.square;

      updateScores(data);

      Object.keys(otherPlayers).forEach(playerId => {
        otherPlayers[playerId].radius = 20; 
        otherPlayers[playerId].color = 'red'; 
        otherPlayers[playerId].id = playerId;

        otherPlayers[playerId].bullets = otherPlayers[playerId].bullets.map(bulletData =>
          new Bullet(bulletData.x, bulletData.y, bulletData.direction)
        );
      });
    });
}
function gameLoop() {
  update();
  render();
  requestAnimationFrame(gameLoop);
}

canvas.addEventListener('mousedown', function () {
  const bullet = new Bullet(player.x, player.y, player.angle, player.id); 
  player.bullets.push(bullet);

  const bulletData = {
    gameId: gameId,
    playerId: playerId, 
    x: bullet.x,
    y: bullet.y,
    direction: bullet.angle,
  };

  fetch('server.php?action=shoot', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json'
    },
    body: JSON.stringify(bulletData)
  }).then(response => { 
    if (!response.ok) {
      console.error('Error al enviar los datos de la bala');
    }
  }).catch(error => {
    console.error('Error en la solicitud de disparo:', error);
  });
});

canvas.addEventListener('mousemove', function (event) {
  mouseX = event.clientX;
  mouseY = event.clientY;
});

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

function joinGame(reset = false) {
  const resetQuery = reset ? '&reset=true' : '';
  fetch(`server.php?action=join${resetQuery}`)
    .then(response => response.json())
    .then(data => {
      playerId = data.playerId; // Nuevo playerId
      gameId = data.gameId;
      player.id = playerId;

      otherPlayers = {}; // Limpiar jugadores anteriores
      player.bullets = []; // Limpiar balas anteriores

      setInterval(getGameState, 100); 
      setInterval(updateBullets, 100); 
      setInterval(sendGameState, 100);
      setInterval(cleanBullets, 200); 
    });
}


function restartGame() {
  clearInterval(getGameState);
  clearInterval(updateBullets);
  clearInterval(sendGameState);
  clearInterval(cleanBullets);

  joinGame(true); // Reinicia y genera nuevas credenciales
  gameLoop(); // Reinicia el bucle del juego
}

// Iniciar el juego
joinGame();
gameLoop();
