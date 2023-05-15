<?php
class Config
{
    /**
     * Для: заголовка CrossOrigin, вывода ошибок бд
     */
    private static bool $debug;
    private static bool $display_err;
    private static bool $auto_login;

    private static bool $enable_all_flags = false;
    private static bool $disable_all_flags = false;

    public static string $debug_token = "hduh43yh5u43ij4tj43jy";
    public static string $auto_auth_token = "hduh43yh5u43ij4tj43jy";
    public static string $vk_token = "is_a_scrt";
    public static int $test_user_id = 1;

    public string $db_host;
    public string $db_login;
    public string $db_password;
    public string $db_name;

    public function __construct()
    {
        self::$debug = filter_var(getenv('KB_BACK_DEBUG'), FILTER_VALIDATE_BOOL);
        self::$display_err = filter_var(getenv('KB_BACK_DISPLAY_ERR'), FILTER_VALIDATE_BOOL);
        self::$auto_login = filter_var(getenv('KB_BACK_AUTO_LOGIN'), FILTER_VALIDATE_BOOL);

        $this->db_host = getenv('DB_HOST');
        $this->db_name = getenv('DB_NAME');
        $this->db_login = getenv("DB_LOGIN");
        $this->db_password = getenv("DB_PASS");
//        $this->db_login = self::$debug ? 'root' : 'admin';
//        $this->db_password = self::$debug ? '1234' : 'eife4Wienein';

        if(self::$enable_all_flags){
            self::$debug = true;
            self::$display_err = true;
            self::$auto_login = true;
        }
        if(self::$disable_all_flags){
            self::$debug = false;
            self::$display_err = false;
            self::$auto_login = false;
        }
        if(isset($_GET['auto_auth_token']) AND $_GET['auto_auth_token'] === self::$auto_auth_token){
            self::$auto_login = true;
        }

        if(self::$auto_login) error_log("Auto login enabled");
        else error_log("Auto login disabled");
    }

    public static function isAutoLogin()
    {
        return self::$auto_login;
    }

    public function configure()
    {
        header('Access-Control-Allow-Origin: *');
        if((isset($_POST['debug']) and $_POST['debug'] === self::$debug_token) or (isset($_GET['debug']) and $_GET['debug'] === self::$debug_token)){
            self::$debug = true;
        }

        if(self::isDisplayErr()){
            ini_set('error_reporting', E_ALL);
            ini_set('display_errors', 1);
            ini_set('display_startup_errors', 1);
        }

        if (!headers_sent() and self::isDebug()) {
            header('Access-Control-Allow-Origin: *');
        }
    }

    public static function isDebug(){
        return self::$debug;
    }

    public static function isDisplayErr() : bool
    {
        return self::$display_err;
    }
}
