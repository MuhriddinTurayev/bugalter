#!/bin/sh
# Runs once, when the MySQL volume is first created.
#
# Every company gets its own database (stancl/tenancy, database prefix "tenant"),
# so the application user must be able to create and use those databases too,
# not only DB_DATABASE. Keep the pattern in sync with config/tenancy.php.
set -e

mysql -uroot -p"$MYSQL_ROOT_PASSWORD" <<SQL
GRANT ALL PRIVILEGES ON \`tenant%\`.* TO '$MYSQL_USER'@'%';
FLUSH PRIVILEGES;
SQL
