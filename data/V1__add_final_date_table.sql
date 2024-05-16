create table final_sum_dates
(
    last_date date null,
    constraint final_sum_date_pk
        unique (last_date)
);