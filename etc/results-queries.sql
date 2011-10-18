select provider_id, type, round(avg(encode_time), 2) as avg_encode_time, 100*(round(stddev(encode_time)/avg(encode_time), 2)) as encode_time_std_dev, round(avg(transfer_time), 2) as avg_transfer_time, 100*(round(stddev(transfer_time)/avg(transfer_time), 2)) as transfer_time_std_dev, count(*) as num_tests from zc_encoding_test where status='complete' and batch_id is null and started>'2011-09-27' group by provider_id, type order by type, avg_encode_time into outfile '/tmp/zencoder-single.csv' FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"';
select provider_id, type, round(avg(encode_time), 2) as avg_encode_time, 100*(round(stddev(encode_time)/avg(encode_time), 2)) as encode_time_std_dev, round(avg(transfer_time), 2) as avg_transfer_time, 100*(round(stddev(transfer_time)/avg(transfer_time), 2)) as transfer_time_std_dev, count(*) as num_tests from zc_encoding_test where status='complete' and batch_id is not null and started>'2011-09-27' group by provider_id, type order by type, avg_encode_time into outfile '/tmp/zencoder-parallel.csv' FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"';

select provider_id, type, count(*) as num_errors from zc_encoding_test where status='error' and batch_id is null and started>'2011-09-27' group by provider_id, type order by num_errors into outfile '/tmp/zencoder-single-errors.csv' FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"';
select provider_id, type, count(*) as num_errors from zc_encoding_test where status='error' and batch_id is not null and started>'2011-09-27' group by provider_id, type order by num_errors into outfile '/tmp/zencoder-parallel-errors.csv' FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"';