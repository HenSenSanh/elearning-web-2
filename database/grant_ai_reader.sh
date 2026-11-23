#!/bin/sh
# Run after the schema exists: sh database/grant_ai_reader.sh
set -eu
docker compose exec -T mysql sh -c '
  case "$AI_DB_USERNAME" in ""|*[!a-zA-Z0-9_]*) echo "AI_DB_USERNAME chỉ được gồm chữ, số, gạch dưới" >&2; exit 2;; esac
  case "$AI_DB_PASSWORD" in ""|*[!a-zA-Z0-9_-]*) echo "AI_DB_PASSWORD phải là chuỗi chữ/số/gạch dưới/gạch ngang, không rỗng" >&2; exit 2;; esac
  case "$MYSQL_DATABASE" in ""|*[!a-zA-Z0-9_]*) echo "MYSQL_DATABASE chỉ được gồm chữ, số, gạch dưới" >&2; exit 2;; esac
  export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"
  mysql -uroot -e "CREATE USER IF NOT EXISTS '\''$AI_DB_USERNAME'\''@'\''%'\'' IDENTIFIED BY '\''$AI_DB_PASSWORD'\'';"
  mysql -uroot -e "ALTER USER '\''$AI_DB_USERNAME'\''@'\''%'\'' IDENTIFIED BY '\''$AI_DB_PASSWORD'\'';"
  for table in categories products units warehouses warehouse_locations product_lots product_prices inventory_documents inventory_lines stock_balances stock_movements; do
    mysql -uroot -e "GRANT SELECT ON $MYSQL_DATABASE.$table TO '\''$AI_DB_USERNAME'\''@'\''%'\'';"
  done
  mysql -uroot -e "FLUSH PRIVILEGES;"
'
echo "Read-only AI grants applied to ${DB_DATABASE:-admin_product_db}."
