<?php

class DB
{
    protected $connection = null;
    protected $user = null;

    public function __construct($config)
    {
        $db_name = $config->db_name;

        $this->connection = new mysqli($config->db_host, $config->db_login, $config->db_password, $db_name);
        $this->connection->set_charset('utf8');
        $this->connection->query("SET GLOBAL sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''));");
    }

    public function query($str, $types = null, ...$args)
    {
        $stmt = $this->connection->prepare($str);

        if (!$stmt and Config::isDebug()) echo $this->connection->error;

        if (count($args)) {
            $stmt->bind_param($types, ...$args);
        }
        $stmt->execute();
        return $stmt;
    }

    public function last_insert_id()
    {
        return mysqli_insert_id($this->connection);
    }

    public function categories()
    {
        $cats = $this->query(
            "SELECT categories.*, ct.type_id type FROM categories
                LEFT JOIN cat_type ct on categories.id = ct.cat_id
                order by parent"
        )->get_result();
        $ret = [];

        while ($cat = $cats->fetch_assoc()) {
            $ret[$cat['id']]['id'] = (int)$cat['id'];
            $ret[$cat['id']]['title'] = $cat['title'];
            $ret[$cat['id']]['img'] = $cat['img'];
            $ret[$cat['id']]['parent'] = $cat['parent'];
            $ret[$cat['id']]['color'] = $cat['color'];
            $ret[$cat['id']]['types'][] = $cat['type'];
        }

        return $ret;
    }

//
    public function transactions($with_date = true)
    {
        $date_cond = ($with_date) ? ' date(time) = @curr_date ' : ' month(time) = @curr_month AND year(time) = @curr_year ';

        $res = $this->query("
                SELECT t.*,w.title, w2.title to_title, trans_tag.tag_id
                FROM transactions t
                LEFT JOIN wallets w on t.wallet_id = w.id
                LEFT JOIN wallets w2 on t.to_wallet = w2.id
                LEFT JOIN trans_tag on trans_tag.trans_id = t.id
                WHERE w.user_id = @user_id AND
                $date_cond
                ORDER BY t.time
                "
        )->get_result();
        $ret = [];

        while ($trans = $res->fetch_assoc()) {
            $tag_id = $trans['tag_id'];
            unset($trans['tag_id']);

            if (!isset($ret[$trans['id']])) {
                $ret[$trans['id']] = $trans;
                if (!is_null($tag_id)) {
                    $ret[$trans['id']]['tags_ids'] = [$tag_id];
                } else {
                    $ret[$trans['id']]['tags_ids'] = [];
                }
            } else {
                if (!is_null($tag_id)) $ret[$trans['id']]['tags_ids'][] = $tag_id;
            }
        }

        return array_values($ret);
    }

    public function stored()
    {
        $curr_period = $this->get_curr_period();
        if(is_null($curr_period)) return null;
        $start = $curr_period['start_date'];
        $init_store = $curr_period['init_store'];

        $stored = (int)$this->query(
            "SELECT coalesce(sum(value), 0) `sum` 
            FROM transactions 
            LEFT JOIN wallets on transactions.wallet_id = wallets.id
            WHERE type = 4
            AND date(time) BETWEEN '$start' AND @curr_date AND user_id = @user_id"
        )->get_result()->fetch_assoc()['sum'];

        return $stored + $init_store;
    }

    public function invested()
    {
        $curr_period = $this->get_curr_period();
        if(is_null($curr_period)) return null;
        $start = $curr_period['start_date'];

        return (int)$this->query(
            "SELECT coalesce(sum(value), 0) `sum` 
            FROM transactions
            LEFT JOIN wallets on transactions.wallet_id = wallets.id
            WHERE type = 5
            AND date(time) BETWEEN '$start' AND @curr_date
            AND user_id = @user_id"
        )->get_result()->fetch_assoc()['sum'];
    }

    //возвращает сумму тех доходов, которые не учитываются в балансе
    public function unaccounted_incomes(){
        $period = $this->get_curr_period();
        if(is_null($period)) return 0;

        return (int) $this->query(
            "SELECT coalesce(sum(value),0) minus
            FROM transactions
            LEFT JOIN wallets on transactions.wallet_id = wallets.id
            where type = 3 
              AND is_add_to_balance <> 1
              AND time BETWEEN ? AND ?
              AND user_id = @user_id", 'ss', $period['start_date'], $period['end_date']
        )->get_result()->fetch_assoc()['minus'];
    }
//
    public function month_analytics()
    {
        $ans = [];
        $period = $this->get_curr_period();
        if(is_null($period)) return $ans;

        $outcomes_by_category = $this->query(
            "SELECT
                    categories.title, categories.color, categories.img,
                    cast(coalesce(sum(CASE WHEN transactions.type in (1,5) THEN value END),0) as signed) `sum`
                FROM transactions   
                LEFT JOIN wallets on transactions.wallet_id = wallets.id
                LEFT JOIN categories on categories.id = transactions.category
                WHERE time BETWEEN ? AND ?
                    AND user_id = @user_id
                    AND transactions.type in (1,5)
                GROUP BY categories.id
                ORDER BY sum DESC", 'ss', $period['start_date'], $period['end_date']
        )->get_result()->fetch_all(1);

        $ans['outcomes_by_category'] = $outcomes_by_category;

        return $ans;
    }

    public function new_wallet($title = null)
    {
        if (is_null($title)) {
            $num = $this->query(
                "SELECT count(*) c FROM wallets WHERE user_id = @user_id"
            )->get_result()->fetch_assoc()['c'];
            $num = (int)$num + 1;
            $title = "Счет №$num";
        }

        $this->query("INSERT INTO wallets (user_id, title) VALUES (@user_id,?)", 's', $title);
        return $this->last_insert_id();
    }

    public function wallet_del()
    {
        $res = $this->query("DELETE FROM wallets WHERE id = @wallet_id and user_id = @user_id");

        return $res->affected_rows;
    }

    public function period_new($start, $end, $init_store, $wallets, $limits)
    {
        $isPeriodsInInterval = $this->query(
            "SELECT * 
            FROM periods 
            where user_id = @user_id 
              AND (
                  (start_date BETWEEN '$start' AND '$end') OR 
                  (end_date BETWEEN '$start' AND '$end')
            )")->get_result()->num_rows;

        if ($isPeriodsInInterval) return false;

        $this->query("INSERT INTO periods (user_id, init_store, start_date, end_date) VALUES (@user_id, ?, ?, ?)", 'iss', $init_store, $start, $end);

        $periodId = $this->connection->insert_id;

        foreach ($wallets as $wallet){
            $sum = $wallet['sum'];
            $wallet_id = $wallet['walletId'];
            $is_add_to_balance = ($wallet['isAddToBalance'] ?? 1) ? 1 : 0;
            $this->query("INSERT INTO period_wallet (period_id, wallet_id, sum, is_add_to_balance) VALUES (?,?,?,?)"
                ,'iiii',
                $periodId, $wallet_id, $sum, $is_add_to_balance
            );
        }

        foreach ($limits as $limit){
            $this->query("INSERT INTO period_limit (period_id, category_id, amount) VALUES (?,?,?)", 'iii',
                $periodId, $limit["categoryId"], $limit["amount"]);
        }

        return $periodId;
    }

    public function period_del($period_id)
    {
        $this->query("DELETE FROM period_limit WHERE period_id = ?", 'i', $period_id);
        $this->query("DELETE FROM periods WHERE id = ?", 'i', $period_id);
        return true;
    }

    public function get_last_period()
    {
        $res = $this->query("SELECT * FROM periods WHERE end_date <= @curr_date AND user_id = @user_id ORDER BY end_date DESC LIMIT 0,1")->get_result();
        if ($res->num_rows < 1) return null;

        return $res->fetch_assoc();
    }

    public function get_curr_period($curr_date = null)
    {
        $date_cond = (is_null($curr_date)) ? '@curr_date' : "'$curr_date'";
        $res = $this->query("SELECT * FROM periods WHERE $date_cond BETWEEN start_date AND end_date AND user_id = @user_id")->get_result();
        if ($res->num_rows < 1) return null;

        return $res->fetch_assoc();
    }

    public function get_last_init_period_with_sum($wallet_id)
    {
        $res = $this->query(
            "SELECT periods.*, sum 
            FROM periods
            LEFT JOIN period_wallet on periods.id = period_wallet.period_id
            WHERE sum is not null
                AND wallet_id = ?
                AND start_date <= @curr_date 
                AND user_id = @user_id
            ORDER BY start_date DESC 
            LIMIT 0, 1", 'i', $wallet_id
        )->get_result();
        if ($res->num_rows !== 1) return null;

        return $res->fetch_assoc();
    }

    public function get_wallet($wallet_id, $date = null)
    {
        $last_period = $this->get_last_init_period_with_sum($wallet_id);
        $init_sum = (!is_null($last_period)) ? $last_period['sum'] : 0;
        $last_period_start = (is_null($last_period)) ? '2000-01-01' : $last_period['start_date'];
        $date_cond = (is_null($date)) ? '@curr_date' : "'$date'";

        $diff = $this->query(
            "SELECT CAST(coalesce(sum(
                CASE WHEN (from_wallet AND is_minus) THEN transactions.value
                WHEN (from_wallet AND !is_minus) THEN -transactions.value
                END
            ), 0) AS signed) diff
            FROM transactions
            LEFT JOIN transactions_types on transactions.type = transactions_types.id
            WHERE wallet_id = $wallet_id
                AND date(time) >= '$last_period_start'
                AND date(time) <= $date_cond"
        )->get_result()->fetch_assoc()['diff'];
        $transferred_to = $this->query(
            "SELECT CAST(coalesce(sum(CASE WHEN type = 2 THEN transactions.value END),0) as signed) transferred_to 
            FROM transactions
            WHERE to_wallet = $wallet_id
                AND date(time) >= '$last_period_start'
                AND date(time) <= $date_cond"
        )->get_result()->fetch_assoc()['transferred_to'];

        $value = $init_sum - $diff + $transferred_to;

        $wallet = $this->query("SELECT wallets.*, $init_sum init, $value value FROM wallets where id = ?", 'i', $wallet_id)->get_result()->fetch_assoc();

        return $wallet;
    }

    public function get_wallets()
    {
        $wallets_ids = array_column($this->query("SELECT id FROM wallets WHERE user_id = @user_id")
            ->get_result()->fetch_all(), 0);
        return array_map(function ($id){return $this->get_wallet($id);}, $wallets_ids);
    }

    public function trans_types()
    {
        return $this->query("SELECT * FROM transactions_types")->get_result()->fetch_all(1);
    }

    public function get_init_sum($curr_period)
    {
        $date_before_start = date_create_from_format('Y-m-d', $curr_period['start_date'])->sub(
            new DateInterval("P1D")
        );
        $wallets_ids = array_column($this->get_wallets(), 'id');
        $inited_wallets = $this->query("SELECT * FROM period_wallet where period_id = $curr_period[id]")->get_result()->fetch_all(1);

        $init_store = $curr_period['init_store'];
        $init_sum = array_sum(array_map(function ($wallet_id) use ($inited_wallets, $curr_period, $date_before_start){
            foreach ($inited_wallets as $inited_wallet){
                if($inited_wallet['wallet_id'] === $wallet_id) {
                    return $inited_wallet['is_add_to_balance'] ? $inited_wallet['sum'] : 0;
                }
            }
            return $this->get_wallet($wallet_id, $date_before_start->format('Y-m-d'))['value'];
        }, $wallets_ids));

        $limits_sum = (int) $this->query(
            "SELECT coalesce(sum(amount), 0) s from period_limit WHERE period_id = ?", 'i', $curr_period['id'])
            ->get_result()->fetch_assoc()['s'];

        return $init_sum - $init_store - $limits_sum;
    }

    public function days_count($period)
    {
        $start = date_create_from_format('Y-m-d', $period['start_date']);
        $end = date_create_from_format('Y-m-d', $period['end_date']);
        return $start->diff($end)->days + 1;
    }

    public function per_day_in_limit($limit, $period = null)
    {
        if(is_null($period)) $period = $this->get_curr_period();
        if(is_null($period)) return null;
        return round($limit['amount']/$this->days_count($period));
    }

    public function per_day($period = null)
    {
        if(is_null($period)) $period = $this->get_curr_period();
        if(is_null($period)) return null;
        return round($this->get_init_sum($period)/$this->days_count($period));
    }

    public function days_past($period, $curr_date)
    {
        $start = date_create_from_format('Y-m-d', $period['start_date']);
        $today = date_create_from_format('Y-m-d', $curr_date);

        return $start->diff($today)->days + 1;
    }

    public function get_periods()
    {
        $periods = $this->query("SELECT * FROM periods WHERE user_id = @user_id")->get_result()->fetch_all(1);
        foreach ($periods as &$period){
            $period_id = $period['id'];
            $period['wallets_inited'] = $this->query(
                "SELECT wallets.id, period_wallet.sum, period_wallet.is_add_to_balance
                FROM period_wallet
                LEFT JOIN wallets on period_wallet.wallet_id = wallets.id
                WHERE period_id = ".$period_id."
                "
            )->get_result()->fetch_all(1);
        }

        return $periods;
    }

    public function get_all_limits(){
        return $this->query("SELECT * FROM period_limit")
            ->get_result()->fetch_all(1);
    }

    public function get_limits($period = null){
        if(is_null($period)) return [];
        return $this->query("SELECT * FROM period_limit WHERE period_id = ?", 'i', $period['id'])
            ->get_result()->fetch_all(1);
    }

    public function get_limit_balances($days = 1)
    {
        $ans = [];
        $days--;
        for ($i = 0; $i <= $days; $i++) {
            $curr_date = $this->query("SELECT date_add(@curr_date, INTERVAL $i DAY) date")->get_result()->fetch_assoc()['date'];
            $curr_period = $this->get_curr_period($curr_date);
            if (is_null($curr_period)) {
                $ans[$curr_date] = null;
                continue;
            }

            $period_limits = $this->get_limits($curr_period);
            foreach ($period_limits as $period_limit) {
                $categoryId = $period_limit['category_id'];
                $categoryFilter = "AND transactions.category = $categoryId";
                $diff = $this->get_diff($curr_date, $curr_period, $categoryFilter);
                $per_day = $this->per_day_in_limit($period_limit, $curr_period);
                $days_past = $this->days_past($curr_period, $curr_date);
                $ans[$curr_date][$categoryId] = round($per_day * $days_past - $diff);
            }
        }
        return $ans;
    }

    public function get_balances($days = 1)
    {
        $ans = [];
        $days--;
        for ($i = 0; $i <= $days; $i++) {
            $curr_date = $this->query("SELECT date_add(@curr_date, INTERVAL $i DAY) date")->get_result()->fetch_assoc()['date'];
            $curr_period = $this->get_curr_period($curr_date);
            if (is_null($curr_period)) {
                $ans[$curr_date] = null;
                continue;
            }

            $limits = $this->get_limits($curr_period);
            $categories_accounted_in_limits = array_map(function ($limit){return $limit['category_id'];}, $limits);
            $categoriesFilter = "";
            if(!empty($categories_accounted_in_limits)) {
                $categoriesFilter = "AND (transactions.category is null OR transactions.category NOT IN (" . join(",", $categories_accounted_in_limits)."))";
            }

            $diff = $this->get_diff($curr_date, $curr_period, $categoriesFilter);

            $per_day = $this->per_day($curr_period);
            $days_past = $this->days_past($curr_period, $curr_date);

            $ans[$curr_date] = round($per_day * $days_past - $diff);
        }
        return $ans;
    }

    public function get_diff($curr_date, $curr_period, $categoryFilter = "")
    {
        return $this->query(
            "SELECT coalesce(sum(
                    CASE WHEN (from_balance AND is_minus) THEN transactions.value
                    WHEN ((from_balance AND !is_minus) OR (type = 3 AND transactions.is_add_to_balance)) THEN -transactions.value
                    END
                ), 0) diff
                FROM transactions
                LEFT JOIN transactions_types on transactions.type = transactions_types.id
                LEFT JOIN wallets on transactions.wallet_id = wallets.id
                LEFT JOIN period_wallet pw on wallets.id = pw.wallet_id AND pw.period_id = ?
                WHERE user_id = @user_id
                    AND (period_id is null OR pw.is_add_to_balance)
                    {$categoryFilter}
                    AND date(time) >= '{$curr_period['start_date']}'
                    AND date(time) <= '$curr_date'", 'i', $curr_period['id']
        )->get_result()->fetch_assoc()['diff'];
    }


    public function sign_up($login, $password)
    {
        $vk_token = substr(md5($login . $password), 0, 5);

        $res = $this->query("INSERT INTO users (login,password,token) VALUES (?,?,?)", 'sss', $login, $password, $vk_token);

        return ($res->errno === 0);
    }

    public function sign_in($login, $password)
    {
        $res = $this->query("SELECT * FROM users WHERE login = ? AND password = ?", 'ss', $login, $password)->get_result();
        if ($res->num_rows != 1) {
            return false;
        }

        $user = $res->fetch_assoc();
        $token = md5($user['id'] . $user['login'] . $user['password']);
        return $token;
    }

    public function authUser()
    {
        if ((isset($_COOKIE['loc_login']) and isset($_COOKIE['token'])) or Config::isAutoLogin()) {
            if (!Config::isAutoLogin()) {
                $login = $_COOKIE['loc_login'];
                $token = $_COOKIE['token'];
            } else {
                [$login, $token] = $this->query("SELECT login, md5(CONCAT(id,login,password)) token FROM users WHERE id = ?",'i', Config::$test_user_id)->get_result()->fetch_array();
                Methods::set_cookie_auth($login, $token);
            }
            $res = $this->query("SELECT *, md5(CONCAT(id,login,`password`)) token FROM users WHERE login = ?", 's', $login)->get_result();
            $user = $res->fetch_assoc();
            if ($user['token'] === $token) {
                $this->user = $user;
                $this->query("SET @user_id = ?;", 'i', $user['id']);
                error_log("AUTH");
            } else {
                error_log("!!AUTH");
                throw new AuthException();
            }
        } else {
            error_log("!!AUTH");
            throw new AuthException();
        }
    }

}
