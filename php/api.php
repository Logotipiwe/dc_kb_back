<?php
spl_autoload_register(
    function ($class_name) {
        foreach (['', 'exceptions/'] as $dir) {
            $filename = $dir . $class_name . '.php';
            if (file_exists($filename)) {
                require_once($filename);
                return;
            }
        }
    }
);

$input = json_decode(file_get_contents('php://input'),1);
if(!is_array($input)) $input = [];
$data = array_merge($_GET, $_POST, $input);
$method = $data['method'];

$configure = new Config();
$configure->configure();

if(is_callable(['Methods',$method])){
    try{
        $db = new DB($configure);

        if (!in_array($method, Methods::without_auth)) {
            $db->authUser();
        }

        $ans = call_user_func(['Methods',$method],$data,$db);

        echo $ans;

    } catch (ValidationException $e){

        http_response_code(400);

        echo json_encode([
            'ok'=>false,
            'err'=>'invalid',
            'field' => $e->getOptions('field'),
            'prop' => $e->getOptions('prop')
        ]);
    } catch (AuthException $e){

        http_response_code(401);

        echo json_encode([
            'ok'=>false,
            'err'=>'auth_err',
            'cookies' => $_COOKIE
        ]);
    }
} else {
    http_response_code(400);
    echo json_encode(['ok'=>false,'err'=>'method_err']);
}
