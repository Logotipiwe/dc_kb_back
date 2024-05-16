create table cat_type
(
    cat_id  int not null,
    type_id int not null,
    primary key (cat_id, type_id)
);

create index cat_type_transactions_types_id_fk
    on cat_type (type_id);

create table categories
(
    id     int auto_increment
        primary key,
    title  varchar(255) null,
    img    varchar(255) null,
    parent int          null,
    color  varchar(255) null,
    constraint categories_title_uindex
        unique (title)
);

create index categories_categories_id_fk
    on categories (parent);

create table period_wallet
(
    period_id         int                  not null,
    wallet_id         int                  not null,
    sum               int                  null,
    is_add_to_balance tinyint(1) default 1 not null,
    primary key (wallet_id, period_id)
);

create index period_wallet_periods_id_fk
    on period_wallet (period_id);

create table periods
(
    id         int auto_increment
        primary key,
    user_id    int           null,
    start_date date          null,
    end_date   date          null,
    init_store int default 0 not null
);

create table period_limit
(
    id          int auto_increment
        primary key,
    period_id   int not null,
    category_id int not null,
    amount      int not null,
    constraint period_limit_categories_id_fk
        foreign key (period_id) references periods (id),
    constraint period_limit_periods_id_fk
        foreign key (category_id) references categories (id)
);

create index periods_users_id_fk
    on periods (user_id);

create table tags
(
    id      int auto_increment
        primary key,
    title   varchar(20) null,
    user_id int         null,
    constraint tags_title_user_id_uindex
        unique (title, user_id)
);

create index tags_users_id_fk
    on tags (user_id);

create table trans_tag
(
    trans_id int not null,
    tag_id   int not null,
    primary key (trans_id, tag_id)
);

create index trans_tag_tags_id_fk
    on trans_tag (tag_id);

create table transactions
(
    id                int auto_increment
        primary key,
    wallet_id         int                                  null,
    time              timestamp  default CURRENT_TIMESTAMP null,
    value             int                                  null,
    type              int                                  null,
    to_wallet         int                                  null,
    category          int                                  null,
    is_add_to_balance tinyint(1) default 1                 null,
    is_unnecessary    tinyint(1) default 0                 not null
);

create index to_wallet
    on transactions (to_wallet);

create index transactions_categories_id_fk
    on transactions (category);

create index type
    on transactions (type);

create index wallet_id
    on transactions (wallet_id);

create table transactions_types
(
    id           int auto_increment
        primary key,
    title        varchar(30)  null,
    img          varchar(255) null,
    is_minus     tinyint(1)   null,
    from_wallet  tinyint(1)   null,
    from_balance tinyint(1)   null
);

create table users
(
    id                    int auto_increment
        primary key,
    login                 varchar(20) null,
    password              tinytext    null,
    token                 varchar(50) null,
    vk_stage              int         null,
    vk_peer_id            int         null,
    vk_selected_type      int         null,
    vk_selected_wallet    int         null,
    vk_selected_wallet_to int         null,
    constraint login
        unique (login),
    constraint users_vk_peer_id_uindex
        unique (vk_peer_id)
);

create table wallets
(
    id      int auto_increment
        primary key,
    user_id int         null,
    title   varchar(25) null
);

create index user_id
    on wallets (user_id);