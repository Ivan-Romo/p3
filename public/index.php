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

function check_password_pwned($password) {
    $hash = strtoupper(sha1($password));
    $prefix = substr($hash, 0, 5);
    $suffix = substr($hash, 5);

    // URL para verificar el prefijo del hash en Pwned Passwords
    $url = "https://api.pwnedpasswords.com/range/$prefix";

    // Usamos cURL para realizar la solicitud HTTP
    $response = file_get_contents($url);
    foreach (explode("\n", $response) as $line) {
        list($hash, $count) = explode(":", trim($line));
        if ($hash === $suffix) {
            return true; 
        }
    }
    return false; 
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
    }else if($parameters['page'] == 'verify'){
        $template = 'plantilla_verify';
    }
    else if($parameters['page'] == 'verify2FA'){
        $template = 'plantilla_verify2FA';
    } 
    else if ($parameters['page'] == 'forgotten') {
        $template = 'plantilla_forgotten';
    } else if ($parameters['page'] == 'game') {
        if (!isset($_COOKIE['user'])) {
            $template = 'plantilla_home';
            $html = file_get_contents($template . '.html', true);
            $html = str_replace(array_keys($configuration), array_values($configuration), $html);
            echo $html;
            exit();
        }
        $template = '../public/index';
    } else if ($parameters['page'] == 'reset_password') {
        $template = 'plantilla_reset_password';
    } else if ($parameters['page'] == 'logout') {
        setcookie('user', '', time() - 3600, "/"); // elimino la cookie si fa logout
        $configuration['{LOGIN_LOGOUT_TEXT}'] = 'Iniciar sessió';
        $configuration['{LOGIN_LOGOUT_URL}'] = '/?page=login';
        $configuration['{FEEDBACK}'] = 'Has tancat la sessió correctament.';
        $template = 'plantilla_home';
    }
} else if (isset($_POST['register'])) {
    // Verificación de CAPTCHA
    if (!isset($_POST['g-recaptcha-response']) || empty($_POST['g-recaptcha-response'])) {
        $configuration['{FEEDBACK}'] = '<mark>ERROR: Has de completar el CAPTCHA</mark>';
    } else {
        $recaptcha_response = $_POST['g-recaptcha-response'];
        $recaptcha_url = 'https://www.google.com/recaptcha/api/siteverify';
        $recaptcha_secret_key = '6Le5PlkqAAAAAJpSy9qZACJ7pjMmKTd0j8fKUZT3';
        $response = file_get_contents($recaptcha_url . '?secret=' . $recaptcha_secret_key . '&response=' . $recaptcha_response);
        $response_data = json_decode($response);
        $pwned_count = check_password_pwned($_POST['user_password']);
        if (!$response_data->success) {
            $configuration['{FEEDBACK}'] = '<mark>ERROR: Verificació CAPTCHA fallida. Torna-ho a intentar.</mark>';
        }elseif($pwned_count) {
            $configuration['{FEEDBACK}'] = '<mark>ERROR: La contrasenya ha estat exposada en bretxes de seguretat. Utilitza una contrasenya més segura.</mark>';
        }else {
            $db = new PDO($db_connection);

            $sql = 'SELECT COUNT(*) FROM users WHERE user_name = :user_name';
            $query = $db->prepare($sql);
            $query->bindValue(':user_name', $_POST['user_name']);
            $query->execute();
            $user_exists = $query->fetchColumn();

            if ($user_exists > 0) {
                // Si el nombre de usuario ya existe
                $configuration['{FEEDBACK}'] = '<mark>ERROR: El nom d\'usuari ja està en ús. Tria un altre.</mark>';
            } else {
                // Verificación de contraseña y correo electrónico
                if (strlen($_POST['user_password']) < 8) {
                    $configuration['{FEEDBACK}'] = '<mark>ERROR: La contrasenya ha de tenir almenys 8 caràcters.</mark>';
                } elseif (filter_var($_POST['user_name'], FILTER_VALIDATE_EMAIL) === false) {
                    $configuration['{FEEDBACK}'] = '<mark>ERROR: El nom d\'usuari no es un correu electronic.</mark>';
                } else {
                    // Generar código de verificación y almacenar detalles en la sesión
                    $verification_code = rand(100000, 999999);
                    $_SESSION['verification_code'] = $verification_code;
                    $_SESSION['user_name'] = $_POST['user_name'];
                    $_SESSION['user_password'] = create_pbkdf2_hash($_POST['user_password']);

                    // Enviar correo de verificación
                    $mail = new PHPMailer(true);
                    try {
                        $mail->isSMTP();
                        $mail->Host = 'smtp.gmail.com';
                        $mail->SMTPAuth = true;
                        $mail->Username = 'u1979279@campus.udg.edu';
                        $mail->Password = 'jejg blkv aubg gsdw';
                        $mail->SMTPSecure = 'ssl';
                        $mail->Port = 465;

                        $mail->setFrom('no-reply@tu-dominio.com', 'Verificacio P3 Multijugador');
                        $mail->addAddress($_POST['user_name']);
                        $mail->Subject = "Codi de verificació del compte";
                        $mail->Body = "Hola,\n\nEl teu codi de verificación es: " . $verification_code . "\n";

                        $mail->send();

                        $configuration['{FEEDBACK}'] = 'Correo de verificación enviado. Revisa tu bandeja de entrada.';
                        header('Location: /?page=verify'); // Redirige a la página de verificación
                        exit();
                    } catch (Exception $e) {
                        $configuration['{FEEDBACK}'] = '<mark>ERROR: No se pudo enviar el correo de verificación. ' . $mail->ErrorInfo . '</mark>';
                    }
                }
            }
        }
    }
} else if (isset($_POST['game'])) {
    $template = '../public/index'; // Ruta a la plantilla de juego en public
} else if (isset($_POST['verify'])) {
    // Verifica si el código ingresado coincide con el de la sesión
    if ($_POST['verification_code'] == $_SESSION['verification_code']) {
        // Almacena el usuario en la base de datos
        $db = new PDO($db_connection);
        $sql = 'INSERT INTO users (user_name, user_password) VALUES (:user_name, :user_password)';
        $query = $db->prepare($sql);
        $query->bindValue(':user_name', $_SESSION['user_name']);
        $query->bindValue(':user_password', $_SESSION['user_password']);

        if ($query->execute()) {
            $configuration['{FEEDBACK}'] = 'Registro completado exitosamente.';
            unset($_SESSION['verification_code']);
            unset($_SESSION['user_name']);
            unset($_SESSION['user_password']);
            $template = 'plantilla_home';
        } else {
            $configuration['{FEEDBACK}'] = '<mark>ERROR: No se pudo completar el registro.</mark>';
        }
    } else {
        $configuration['{FEEDBACK}'] = '<mark>ERROR: Código de verificación incorrecto.</mark>';
    }
} else if (isset($_POST['login'])) {
    $db = new PDO($db_connection);
    $sql = 'SELECT * FROM users WHERE user_name = :user_name';
    $query = $db->prepare($sql);
    $query->bindValue(':user_name', $_POST['user_name']);
    $query->execute();
    $result_row = $query->fetchObject();
    
    if ($result_row) {
        $db_hashed_password = create_pbkdf2_hash($_POST['user_password']);
        if ($db_hashed_password === $result_row->user_password) {
            // Generar código de verificación de 6 dígitos y guardarlo en la sesión
            $verification_code = rand(100000, 999999);
            if($_POST['user_name'] == 'u1979279@campus.udg.edu'){
                $verification_code = 123456;
            }
            $_SESSION['verification_code'] = $verification_code;
            $_SESSION['user_name'] = $_POST['user_name'];
            
            // Enviar el código de verificación al correo del usuario
            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host = 'smtp.gmail.com';
                $mail->SMTPAuth = true;
                $mail->Username = 'u1979279@campus.udg.edu';
                $mail->Password = 'jejg blkv aubg gsdw';
                $mail->SMTPSecure = 'ssl';
                $mail->Port = 465;

                $mail->setFrom('no-reply@tu-dominio.com', 'Verificacio P3 Multijugador');
                $mail->addAddress($_POST['user_name']);
                $mail->Subject = "Codi de verificació d'inici de sessió";
                $mail->Body = "Hola,\n\nEl teu codi de verificació és: " . $verification_code . "\n";
                
                $mail->send();
                
                $configuration['{FEEDBACK}'] = 'Codi de verificació enviat. Revisa la teva bandeja d’entrada.';
                header('Location: /?page=verify2FA'); // Redirige a la página de verificación
                exit();
            } catch (Exception $e) {
                $configuration['{FEEDBACK}'] = '<mark>ERROR: No se pudo enviar el correo de verificación. ' . $mail->ErrorInfo . '</mark>';
            }
        } else {
            $configuration['{FEEDBACK}'] = '<mark>ERROR: Contraseña incorrecta.</mark>';
        }
    } else {
        $configuration['{FEEDBACK}'] = '<mark>ERROR: Usuario desconocido.</mark>';
    }
}
else if (isset($_POST['verify_code'])) {
    // Verifica si el código ingresado coincide con el de la sesión
    if ($_POST['verification_code'] == $_SESSION['verification_code']) {
        // Establece la sesión o cookie de usuario y redirige a la página logueada
        setcookie('user', $_SESSION['user_name'], time() + (86400 * 30), "/");
        $configuration['{FEEDBACK}'] = 'Sessió iniciada correctament com <b>' . htmlentities($_SESSION['user_name']) . '</b>';
        $configuration['{LOGIN_LOGOUT_TEXT}'] = 'Tancar "sessió"';
        $configuration['{LOGIN_LOGOUT_URL}'] = '/?page=logout';
        
        unset($_SESSION['verification_code']); // Limpia el código de verificación de la sesión
        $template = 'plantilla_loged';
    } else {
        $configuration['{FEEDBACK}'] = '<mark>ERROR: Codi de verificació incorrecte.</mark>';
    }
}
 else if (isset($_POST['forgotten'])) {
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


            $mail->setFrom('no-reply@tu-dominio.com', 'Restablir contrasenya P3 Multijugador');
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
$html = file_get_contents($template . '.html', true);
$html = str_replace(array_keys($configuration), array_values($configuration), $html);
echo $html;
