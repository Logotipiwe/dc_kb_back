<?php

class Validating
{
    protected static function err($field, $prop)
    {
        throw new ValidationException(['field' => $field, 'prop' => $prop]);
    }

    protected static function check_prop($f, $prop, $field_name, DB $db)
    {
        if (count(explode(':', $prop)) > 1) {
            $param = explode(':', $prop)[1];
            $prop = explode(':', $prop)[0];
        }
        $params = explode('.', isset($param) ? $param : '');


        switch ($prop) {
            case 'required':
                if (!isset($f) or $f === '') self::err($field_name, $prop);
                break;
            case 'nullable':
                if (!isset($f) or is_null($f) or ($f === 'null')) throw new NullableException();
                break;
            case 'integer':
            case 'number':
            case 'int':
                if (!is_numeric($f)) self::err($field_name, $prop);
                if (isset($param)) {
                    if ($params[0] = "use_db") {
                        if (isset($params[1])) $db->query("SET @$params[1] = ?", 'i', $f);
                        else $db->query("SET @$field_name = ?", 'i', $f);
                    }
                }
                break;
            case 'positive':
                if ($f < 0) self::err($field_name, $prop);
                break;
            case 'string':
            case 'str':
                if (gettype($f) !== 'string' and gettype($f) !== 'integer') self::err($field_name, $prop);
                break;
            case 'date':
                $date = date_create_from_format('Y-m-d', $f);
                if (gettype($date) !== "object" OR get_class($date) !== 'DateTime') self::err($field_name, $prop);
                if ($params[0] === 'use_db') {
                    $alias = (isset($params[1])) ? $params[1] : 'curr';
                    $year = $date->format('Y');
                    $month = $date->format('m');
                    $day = $date->format('d');
                    $date_str = $date->format('Y-m-d');
                    $db->query("SET @" . $alias . "_year = ?;", 'i', $year);
                    $db->query("SET @" . $alias . "_month = ?;", 'i', $month);
                    $db->query("SET @" . $alias . "_day = ?;", 'i', $day);
                    $db->query("SET @" . $alias . "_date = ?;", 's', $date_str);
                }
                break;
            case 'array':
            case 'arr':
                if (gettype($f) !== 'array') self::err($field_name, $prop);
                if ($params[0] === 'check') {
                    $table = $params[1];
                    $keys_to_check = array_keys($f);
                    foreach ($keys_to_check as $key) {
                        self::check_prop($key, "check:$table", $field_name, $db);
                    }
                }
                break;
            case 'min':
                if (mb_strlen($f) < (int)$param) self::err($field_name, $prop . ':' . $param);
                break;
            case 'max':
                if (mb_strlen($f) > (int)$param) self::err($field_name, $prop . ':' . $param);
                break;
            case 'max_val':
                if ($f > (int)$param) self::err($field_name, $prop . ':' . $param);
                break;
            case 'min_val':
                if ($f < (int)$param) self::err($field_name, $prop . ':' . $param);
                break;
            case 'check':
                $table = $params[0];
                switch ($table) {
                    case 'wallets':
                        if (!$db->query("SELECT * FROM wallets WHERE id = ? AND user_id = @user_id", 'i', $f)->get_result()->num_rows)
                            self::err($field_name, $prop . ':' . $table);
                        break;
                    case 'periods':
                        if (!$db->query("SELECT * FROM periods WHERE id = ? AND user_id = @user_id", 'i', $f)->get_result()->num_rows)
                            self::err($field_name, $prop . ':' . $table);
                        break;
                }
                break;
            case 'belongs':
                $table = $params[0];
                $field = $params[1];
                switch ($table) {
                    case 'wallets':
                        if (!$db->query("SELECT * FROM wallets where $field = ? AND user_id = @user_id", 'i', $f)->get_result()->num_rows) {
                            throw new AuthException();
                        }
                        break;
                    case 'transactions':
                        if (!$db
                            ->query("SELECT * FROM transactions t LEFT JOIN wallets w on t.wallet_id = w.id WHERE t.id = ? AND w.user_id = @user_id", 'i', $f)
                            ->get_result()
                            ->num_rows
                        ) throw new AuthException();
                        break;
                    default:
                        self::err('table', 'err');
                }
                break;
            case 'exists':
                $table = explode('.', $param)[0];
                $field = explode('.', $param)[1];

                if (gettype($f) === 'array') {
                    foreach ($f as $value) {
                        $count = $db->query(
                            "SELECT COUNT(*) c FROM $table WHERE $field = ?", 's', $value
                        )->get_result()->fetch_assoc()['c'];
                        if ($count < 1) self::err($field_name, $prop . ':' . $param);
                    }
                } else {
                    $count = $db->query(
                        "SELECT COUNT(*) c FROM $table WHERE $field = ?", 's', $f
                    )->get_result()->fetch_assoc()['c'];
                    if ($count < 1) self::err($field_name, $prop . ':' . $param);
                }
                break;
            case 'missing':
                $table = explode('.', $param)[0];
                $field = explode('.', $param)[1];
                $count = $db->query(
                    "SELECT COUNT(*) c FROM $table WHERE $field = ?", 's', $f
                )->get_result()->fetch_assoc()['c'];
                if ($count > 0) self::err($field_name, $prop . ':' . $param);
                break;
            default:
                self::err($field_name, 'error');
        }
    }

    public static function validate($rules, $data, DB $db)
    {
        $fields = array_keys($rules); //поля которые пройдут проверку

        foreach ($fields as $field) {

            $field_props = explode('|', $rules[$field]);

            try {
                foreach ($field_props as $prop) {
                    self::check_prop((isset($data[$field])) ? $data[$field] : null, $prop, $field, $db);
                }
            } catch (NullableException $e) {
            } //если поле nullable - проверка заканчивается тут
        }
    }

}
