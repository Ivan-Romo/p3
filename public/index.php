<?php
// - descomentada la linea extension=openssl
//Iván Romo i Jordi Porta
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;


require '../PHPMailer/src/Exception.php';
require '../PHPMailer/src/PHPMailer.php';
require '../PHPMailer/src/SMTP.php';


session_start();
// defaults
$template = 'plantilla_home';
$db_connection = 'sqlite:../private/users.db';
$configuration = array(
    '{FEEDBACK}' => '',
    '{LOGIN_LOGOUT_TEXT}' => 'Identificar-me',
    '{LOGIN_LOGOUT_URL}' => '/?page=login',
    '{LOGED_URL}' => '/?page=loged',
    '{GAME_URL}' => '/?page=game',
    '{PLAY_TEXT}' => 'JUGAR',
    '{FORGOTTEN_URL}' => '/?page=forgotten',
    '{RESET_PASSWORD_URL}' => '/?page=reset_password',
    '{METHOD}' => 'GET',
    '{REGISTER_URL}' => '/?page=register',
    '{SITE_NAME}' => 'La meva pàgina'
);

define('FIXED_SALT', '1a2b3c4d5e6f7g8h9i0j1k2l3m4n5o6p');
define('PBKDF2_ALGORITHM', 'sha256'); 
define('PBKDF2_ITERATIONS', 100000);  
define('PBKDF2_KEY_LENGTH', 64);      

function create_pbkdf2_hash($password)
{
    return hash_pbkdf2(
        PBKDF2_ALGORITHM,
        $password,
        FIXED_SALT,
        PBKDF2_ITERATIONS,
        PBKDF2_KEY_LENGTH
    );
}

$parameters = $_GET;
//si hi ha cookie
if (isset($_COOKIE['user'])) {
    // Si te la cookie no inicia sessió
    $configuration['{FEEDBACK}'] = 'Benvingut de nou, <b>' . htmlentities($_COOKIE['user']) . '</b>';
    $configuration['{LOGIN_LOGOUT_TEXT}'] = 'Tancar "sessió"';
    $configuration['{LOGIN_LOGOUT_URL}'] = '/?page=logout';
    $template = 'plantilla_loged';
} 

if (isset($parameters['page'])) {
    

    if ($parameters['page'] == 'register') {
        $template = 'plantilla_register';
        $configuration['{REGISTER_USERNAME}'] = '';
        $configuration['{LOGIN_LOGOUT_TEXT}'] = 'Ja tinc un compte';
    } else if ($parameters['page'] == 'login') {
        $template = 'plantilla_login';
        $configuration['{LOGIN_USERNAME}'] = '';
    } else if ($parameters['page'] == 'forgotten') {
        $template = 'plantilla_forgotten';
    } else if ($parameters['page'] == 'game') {
        $template = '../public/index';
    }else if ($parameters['page'] == 'reset_password') {
        $template = 'plantilla_reset_password';
    }  
    else if ($parameters['page'] == 'logout') {
        setcookie('user', '', time() - 3600, "/"); // elimino la cookie si fa logout
        $configuration['{LOGIN_LOGOUT_TEXT}'] = 'Iniciar sessió';
        $configuration['{LOGIN_LOGOUT_URL}'] = '/?page=login';
        $configuration['{FEEDBACK}'] = 'Has tancat la sessió correctament.';
        $template = 'plantilla_home';
    }
} else if (isset($_POST['register'])) {
    if (!isset($_POST['g-recaptcha-response']) || empty($_POST['g-recaptcha-response'])) {
        $configuration['{FEEDBACK}'] = '<mark>ERROR: Has de completar el CAPTCHA</mark>';
    } else {
        $recaptcha_response = $_POST['g-recaptcha-response'];
        $recaptcha_url = 'https://www.google.com/recaptcha/api/siteverify';
        $recaptcha_secret_key = '6Le5PlkqAAAAAJpSy9qZACJ7pjMmKTd0j8fKUZT3';
        $response = file_get_contents($recaptcha_url . '?secret=' . $recaptcha_secret_key . '&response=' . $recaptcha_response);
        $response_data = json_decode($response);

        if (!$response_data->success) {
            $configuration['{FEEDBACK}'] = '<mark>ERROR: Verificació CAPTCHA fallida. Torna-ho a intentar.</mark>';
        } 
        else {
            $db = new PDO($db_connection);

            $sql = 'SELECT COUNT(*) FROM users WHERE user_name = :user_name';
            $query = $db->prepare($sql);
            $query->bindValue(':user_name', $_POST['user_name']);
            $query->execute();
            $user_exists = $query->fetchColumn();

            // Si el nom d'usuari ja existex, mostrem un error
            if ($user_exists > 0) {
                $configuration['{FEEDBACK}'] = '<mark>ERROR: El nom d\'usuari ja està en ús. Tria un altre.</mark>';
            } else {
                if (strlen($_POST['user_password']) < 8) {
                    // Si la contraseña te menys de 8 caracters, mostrar un mensaje de error
                    $configuration['{FEEDBACK}'] = '<mark>ERROR: La contrasenya ha de tenir almenys 8 caràcters.</mark>';
                } elseif (filter_var($_POST['user_name'], FILTER_VALIDATE_EMAIL) === false) {
                    $configuration['{FEEDBACK}'] = '<mark>ERROR: El nom d\'usuari no es un correu electronic.</mark>';
                } else {
                    $hashed_password = create_pbkdf2_hash($_POST['user_password']);

                    $sql = 'INSERT INTO users (user_name, user_password) VALUES (:user_name, :user_password)';
                    $query = $db->prepare($sql);
                    $query->bindValue(':user_name', $_POST['user_name']);
                    $query->bindValue(':user_password', $hashed_password);
                    if ($query->execute()) {
                        $configuration['{FEEDBACK}'] = 'Creat el compte <b>' . htmlentities($_POST['user_name']) . '</b>';
                        $template = 'plantilla_home';
                    } else {
                        $configuration['{FEEDBACK}'] = "<mark>ERROR: No s'ha pogut crear el compte <b>"
                            . htmlentities($_POST['user_name']) . '</b></mark>';
                    }
                }
            }
        }
    }

}else if (isset($_POST['game'])) {
    $template = '../public/index'; // Ruta a la plantilla de juego en public
}
 else if (isset($_POST['login'])) {
    $db = new PDO($db_connection);
    $sql = 'SELECT * FROM users WHERE user_name = :user_name';
    $query = $db->prepare($sql);
    $query->bindValue(':user_name', $_POST['user_name']);
    $query->execute();
    $result_row = $query->fetchObject();
    if ($result_row) {
        $db_hashed_password = create_pbkdf2_hash($_POST['user_password']);
        if ($db_hashed_password === $result_row->user_password) {
            $configuration['{FEEDBACK}'] = '"Sessió" iniciada com <b>' . htmlentities($_POST['user_name']) . '</b>';
            $configuration['{LOGIN_LOGOUT_TEXT}'] = 'Tancar "sessió"';
            $configuration['{LOGIN_LOGOUT_URL}'] = '/?page=logout';
            //afegim la cookie
            setcookie('user', $_POST['user_name'], time() + (86400 * 30), "/");
            $template = 'plantilla_loged';
        } else {
            $configuration['{FEEDBACK}'] = '<mark>ERROR: Constrasenya incorrecta</mark>';
        }
    } else {
        $configuration['{FEEDBACK}'] = '<mark>ERROR: Usuari desconegut </mark>';
    }
} else if (isset($_POST['forgotten'])) {
    $db = new PDO($db_connection);
    $sql = 'SELECT * FROM users WHERE user_name = :user_name';
    $query = $db->prepare($sql);
    $query->bindValue(':user_name', $_POST['user_name']);
    $query->execute();
    $result_row = $query->fetchObject();
    if ($result_row) {
        $verification_code = rand(100000, 999999);
        $mail = new PHPMailer(true);

        try {

            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = 'u1979279@campus.udg.edu';
            $mail->Password = 'jejg blkv aubg gsdw';
            $mail->SMTPSecure = 'ssl';
            $mail->Port = 465;

          
            $mail->setFrom('no-reply@tu-dominio.com', 'Nombre de tu App');
            $mail->addAddress($result_row->user_name); 
            $mail->Subject = "Restablecer tu contraseña";
            $mail->Body = "Hola,\n\nAquí tienes tu código para restablecer la contraseña: " . $verification_code . "\n\nSi crees que es un error, ignora este correo.";
            $mail->send();

            $configuration['{FEEDBACK}'] = 'Correo enviado! Revisa la carpeta de spam si no lo encuentras <b>' . htmlentities($_POST['user_name']) . '</b>';
            $_SESSION['verification_code'] = $verification_code;
            $_SESSION['user_name'] = $_POST['user_name'];
            header('Location: /?page=reset_password');
        } catch (Exception $e) {
  
            $configuration['{FEEDBACK}'] = '<mark>ERROR: No se ha podido enviar el correo. Error de PHPMailer: ' . $mail->ErrorInfo . '</mark>';
        }
    } else {
        $configuration['{FEEDBACK}'] = '<mark>ERROR: No existe un usuario con ese correo.</mark>';

    }
} else if (isset($_POST['reset_password'])) {  

    if (isset($_POST['user_name'], $_POST['verification_code'], $_POST['new_password'])) {

        $db = new PDO($db_connection);
        $sql = 'SELECT * FROM users WHERE user_name = :user_name';
        $query = $db->prepare($sql);
        $query->bindValue(':user_name', $_POST['user_name']);
        $query->execute();
        $result_row = $query->fetchObject();

        if ($result_row) {
            $input_verification_code = $_POST['verification_code'];
            $new_password = $_POST['new_password'];

            session_start();
            if (isset($_SESSION['verification_code']) && $_SESSION['verification_code'] == $input_verification_code) {
                $hashed_password = create_pbkdf2_hash($new_password);

                $sql = 'UPDATE users SET user_password = :user_password WHERE user_name = :user_name';
                $query = $db->prepare($sql);
                $query->bindValue(':user_password', $hashed_password);
                $query->bindValue(':user_name', $_POST['user_name']);

                if ($query->execute()) {
                    $configuration['{FEEDBACK}'] = 'Contraseña actualizada correctamente para <b>' . htmlentities($_POST['user_name']) . '</b>';
                } else {
                    $configuration['{FEEDBACK}'] = '<mark>ERROR: No se pudo actualizar la contraseña.</mark>';
                }
            } else {
                $configuration['{FEEDBACK}'] = '<mark>ERROR: El código de verificación es incorrecto.</mark>';
            }
        } else {
            $configuration['{FEEDBACK}'] = '<mark>ERROR: No existe un usuario con ese correo.</mark>';
        }
    } else {
        $configuration['{FEEDBACK}'] = '<mark>ERROR: Faltan parámetros requeridos.</mark>';
    }
}
// process template and show output
$html = file_get_contents( $template . '.html', true);
$html = str_replace(array_keys($configuration), array_values($configuration), $html);
echo $html;
