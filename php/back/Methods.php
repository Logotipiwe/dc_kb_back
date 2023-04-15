<?php

class Methods
{
    const without_auth = ['sign_in', 'sign_up'];

    public static function say_hi($data = null, DB $db = null)
    {
        return self::ok();
    }

    public static function json($data, DB $db)
    {
        return self::ok(['wallet 1', 'wallet 2']);
    }

    public static function ok($ans = null)
    {
        if (!is_null($ans)) return json_encode(["ok" => true, 'ans' => $ans]);
        return json_encode(["ok" => true]);
    }

    public static function err($err, $err_msg = null)
    {
        if ($err_msg) return json_encode(['ok' => false, 'err' => $err, 'err_msg' => $err_msg]);
        return json_encode(['ok' => false, 'err' => $err]);
    }

    public static function sign_up($data, DB $db)
    {
        Validating::validate([
            'login' => 'required|string|min:2|max:20|missing:users.login',
            'password' => 'required|string'
        ], $data, $db);

        if (!$db->sign_up($data['login'], $data['password'])) {
            return self::err('exists');
        }

        return self::sign_in($data, $db);
    }

    public static function sign_in($data, DB $db)
    {
        Validating::validate([
            'login' => 'required|string|min:2|max:20|exists:users.login',
            'password' => 'required|string'
        ], $data, $db);

        $token = $db->sign_in($data['login'], $data['password']);
        if (!$token) {
            return self::err('sign_in_err');
        }
        self::set_cookie_auth($data['login'], $token);
        return self::ok(['token' => $token]);
    }

    public static function log_out($data, DB $db)
    {
        self::set_cookie_auth('', '');
        return self::ok();
    }

    public static function set_cookie_auth($login, $token)
    {
        $cookie_time = time() + 3600 * 24 * 7;
        setcookie('loc_login', $login, $cookie_time, '/');
        setcookie('token', $token, $cookie_time, '/');
    }

    public static function wallet_new($data, DB $db)
    {
        Validating::validate([
            'title' => 'string|min:1|max:25',
        ], $data, $db);
        $wallet_id = $db->new_wallet($data['title']);
        if (!$wallet_id) return self::err('creation_err');
        return self::ok(['new_id' => $wallet_id]);
    }

    public static function wallet_del($data, DB $db)
    {
        Validating::validate([
            'wallet_id' => 'required|integer:use_db|exists:wallets.id'
        ], $data, $db);

        $affected = $db->wallet_del();

        if (!$affected) {
            return self::err('delete_err');
        }

        return self::ok();
    }

    public static function period_new($data, DB $db)
    {
        Validating::validate([
            'start_date' => 'date',
            'end_date' => 'date',
            'init_store' => 'int|min_val:0',
        ], $data, $db);

        $wallets = (isset($data['wallets'])) ? $data['wallets'] : [];

        $period_id = $db->period_new(
            $data['start_date'],
            $data['end_date'],
            $data['init_store'],
            $wallets
        );
        if (!$period_id) return self::err('db_err', 'Не удалось создать период');

        return self::ok($period_id);
    }

    public static function period_del($data, DB $db)
    {
        Validating::validate([
            'period_id' => 'check:periods'
        ], $data, $db);
        $db->period_del($data['period_id']);
        return self::ok();
    }

    public static function transaction_new($data, DB $db)
    {
        Validating::validate([
            'type' => 'required|integer:use_db|exists:transactions_types.id',
            'wallet_id' => 'required|integer:use_db|exists:wallets.id|belongs:wallets.id',
            'value' => 'required|integer:use_db|positive|min_val:1|max_val:100000000',
            'to_wallet' => 'nullable|integer:use_db|exists:wallets.id|belongs:wallets.id',
            'is_add_to_balance' => 'nullable|integer:use_db',
            'is_unnecessary' => 'int|min_val:0|max_val:1|int:use_db',
            'category' => 'nullable|integer:use_db|exists:categories.id',
            'tags_ids' => 'nullable|array|exists:tags.id',
            'date' => 'required|date:use_db.curr'
        ], $data, $db);

        $date = date_create_from_format('Y-m-d', $data['date']);
        //если дата сегодняшняя
        if ($date->format('Y-m-d') === date_create()->format('Y-m-d')) {
            $query = "INSERT INTO transactions (wallet_id, value, type, to_wallet,time, category, is_add_to_balance, is_unnecessary)
            VALUES (@wallet_id,@value,@type,@to_wallet,DEFAULT,@category, @is_add_to_balance, @is_unnecessary)";
            $res = $db->query($query);
        } else {
            $query = "INSERT INTO transactions (wallet_id, value, type, to_wallet,time, category, is_add_to_balance, is_unnecessary)
            VALUES (@wallet_id,@value,@type,@to_wallet,?,@category, @is_add_to_balance, @is_unnecessary)";
            $types = 's';
            $bind = $date->format('Y-m-d');
            $res = $db->query($query, $types, $bind);
        }
        $trans_id = $db->last_insert_id();

        if ($res->affected_rows < 1) {
            return self::err(["database_err", $trans_id, $res->error]);
        }

        if (isset($data['tags_ids'])) foreach ($data['tags_ids'] as $tag_id) {
            $db->query("INSERT INTO trans_tag (trans_id,tag_id) VALUES ($trans_id,$tag_id)");
        }

        return self::ok([
            'new_id' => $db->last_insert_id(),
        ]);
    }

    public static function last_init_sum($data, DB $db)
    {
        return self::ok($db->get_last_init_period_with_sum(3));
    }

    public static function transaction_del($data, DB $db)
    {
        Validating::validate([
            'id' => 'required|exists:transactions.id|integer:use_db|belongs:transactions.id',
        ], $data, $db);

        $db->query("DELETE FROM transactions WHERE id = @id");
        return self::ok();
    }

    public static function period_edit($data, DB $db)
    {
        Validating::validate([
            'id' => 'integer|check:periods',
            'start_date' => 'date',
            'end_date' => 'date',
//            'wallets' => 'nullable|array:check.wallets',
            'init_store' => 'int|min_val:0'
        ], $data, $db);
        $period_id = $data['id'];
        $db->query("UPDATE periods SET start_date = ?, end_date = ?, init_store = ? WHERE id = ?", 'ssii', $data['start_date'], $data['end_date'], $data['init_store'], $period_id);

        $wallets_init = (isset($data['wallets'])) ? $data['wallets'] : [];

        foreach ($wallets_init as $wallet) {
            if($db->query("SELECT * FROM period_wallet WHERE period_id = ? AND wallet_id = ?", 'ii', $period_id, $wallet['id'])->get_result()->num_rows) {
                $db->query("UPDATE period_wallet SET sum = ?, is_add_to_balance = ? WHERE period_id = ? AND wallet_id = ?", 'iiii',
                    $wallet['sum'], $wallet['is_add_to_balance'], $period_id, $wallet['id']);
            } else {
                $db->query("INSERT INTO period_wallet (period_id, wallet_id, sum, is_add_to_balance) VALUES (?,?,?,?)",'iiii',
                    $period_id, $wallet['id'], $wallet['sum'], $wallet['is_add_to_balance']);
            }
        }

        return self::ok();

    }

    public static function data_get($data, DB $db)
    {
        Validating::validate([
            'date' => 'required|date:use_db.curr',
        ], $data, $db);

        $curr_period = $db->get_curr_period();
        $ans = [
            'categories' => $db->categories(),
            'transactions' => $db->transactions(true),
            'balances' => $db->get_balances(3),
            'wallets' => $db->get_wallets(),
            'transaction_types' => $db->trans_types(),
            'periods' => $db->get_periods(),
            'curr_period' => $curr_period,
            'user_id' => $db->query("SELECT @user_id u")->get_result()->fetch_assoc()['u'],
        ];
        $value_sum = array_reduce(
            $ans['wallets'],
            function ($sum, $item) {
                return $sum + $item['value'];
            }, 0);
        $ans['analytics']['value_real_left'] = $value_sum;
        $ans['analytics']['value_sum'] = $value_sum - $db->unaccounted_incomes();
        $ans['analytics']['init_sum'] = array_reduce(
            $ans['wallets'],
            function ($sum, $item) {
                return $sum + $item['init'];
            }, 0);
        $ans['analytics']['stored'] = $db->stored();
        $ans['analytics']['invested'] = $db->invested();
        $ans['analytics']['per_day'] = $db->per_day();
        $ans['analytics']['month_analytics'] = $db->month_analytics();
        return self::ok($ans);
    }

    public static function test($data, DB $db)
    {
        Validating::validate([
            'val' => 'int|min:0'
        ], $data, $db);

        return self::ok();
    }
}
