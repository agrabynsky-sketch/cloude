#!/bin/sh
# Полная пересборка кэша силами ClickHouse (без PHP): staging -> сборка пачками -> атомарная подмена.
# Держит ту же блокировку, что scripts/search-sync.php, поэтому PHP-воркер на этой машине не пишет
# в старую таблицу во время сборки. Изменения, поставленные в очередь за это время, воркер разберёт после подмены.
#   CH="clickhouse-client --host clickhouse.internal --user search_writer --password ..." CHUNKS=16 ./full_load.sh
set -e
DIR=$(cd "$(dirname "$0")" && pwd)
CH=${CH:-clickhouse-client}
CHUNKS=${CHUNKS:-16}
LOCK=${LOCK:-/tmp/search-sync.lock}

exec 9>"$LOCK"
flock -n 9 || { echo "another sync process is running"; exit 1; }

$CH --multiquery < "$DIR/03_full_load_from_mysql.sql"
i=0
while [ $i -lt "$CHUNKS" ]; do
    $CH --param_chunks="$CHUNKS" --param_chunk="$i" --multiquery < "$DIR/03b_build_chunk.sql"
    i=$((i + 1))
done
$CH --query "SELECT 'built', count(), uniqExact(hotel_id), uniqExact(rate_room_id) FROM unit_search.search_stay_new"
$CH --multiquery < "$DIR/04_full_load_swap.sql"
# склеить части: FINAL-запросы быстрее, когда у каждой строки одна версия (~4 с на 10 млн строк)
$CH --query "OPTIMIZE TABLE unit_search.search_stay FINAL"
echo "full load done"
