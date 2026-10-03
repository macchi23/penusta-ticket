-- DuckDB 共通セットアップ: httpfs の導入と MinIO への一時シークレット（PERSISTENT は平文保存されるので使わない）
SET extension_directory='/duckdb-ext';
INSTALL httpfs;
LOAD httpfs;
SET TimeZone='UTC';
CREATE OR REPLACE SECRET minio (TYPE s3, PROVIDER config, KEY_ID 'penusta', SECRET 'penusta-secret',
  REGION 'us-east-1', ENDPOINT 'minio:9000', URL_STYLE 'path', USE_SSL false);
