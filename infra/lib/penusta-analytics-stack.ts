import { Aws, CfnOutput, Duration, RemovalPolicy, Stack, StackProps } from 'aws-cdk-lib';
import { CfnWorkGroup } from 'aws-cdk-lib/aws-athena';
import { BlockPublicAccess, Bucket, BucketEncryption } from 'aws-cdk-lib/aws-s3';
import { CfnDatabase, CfnTable } from 'aws-cdk-lib/aws-glue';
import { Construct } from 'constructs';

export class PenustaAnalyticsStack extends Stack {
  constructor(scope: Construct, id: string, props?: StackProps) {
    super(scope, id, props);

    // autoDeleteObjects は Lambda を生むので使わない。空にしてから destroy する
    const bucket = new Bucket(this, 'Bucket', {
      bucketName: `penusta-analytics-${Aws.ACCOUNT_ID}`,
      blockPublicAccess: BlockPublicAccess.BLOCK_ALL,
      encryption: BucketEncryption.S3_MANAGED,
      enforceSSL: true,
      removalPolicy: RemovalPolicy.DESTROY,
      // 中断したマルチパートのパートは ls / rm に出ず課金が残る
      lifecycleRules: [{ abortIncompleteMultipartUploadAfter: Duration.days(1) }],
    });

    const database = new CfnDatabase(this, 'Database', {
      catalogId: Aws.ACCOUNT_ID,
      databaseInput: { name: 'penusta_analytics' },
    });

    // dt は string で宣言し、projection.dt.type=date で日付として射影する
    const eventsTable = (id: string, name: string, prefix: string, columns: { name: string; type: string }[], format: {
      classification: string;
      inputFormat: string;
      outputFormat: string;
      serializationLibrary: string;
    }) => {
      const table = new CfnTable(this, id, {
        catalogId: Aws.ACCOUNT_ID,
        databaseName: 'penusta_analytics',
        tableInput: {
          name,
          tableType: 'EXTERNAL_TABLE',
          partitionKeys: [{ name: 'dt', type: 'string' }],
          parameters: {
            classification: format.classification,
            'projection.enabled': 'true',
            'projection.dt.type': 'date',
            'projection.dt.format': 'yyyy-MM-dd',
            // 候補日ごとに S3 LIST が走るので、データ（〜2026-11-21）を覆う最小の固定範囲にする
            'projection.dt.range': '2025-12-01,2026-12-31',
            'projection.dt.interval': '1',
            'projection.dt.interval.unit': 'DAYS',
            'storage.location.template': `s3://${bucket.bucketName}/${prefix}/dt=\${dt}/`,
          },
          storageDescriptor: {
            columns,
            location: `s3://${bucket.bucketName}/${prefix}/`,
            inputFormat: format.inputFormat,
            outputFormat: format.outputFormat,
            serdeInfo: { serializationLibrary: format.serializationLibrary },
          },
        },
      });
      table.addResourceDependency(database);
    };

    eventsTable(
      'EventsRawTable',
      'events_raw',
      'raw/events',
      [
        { name: 'id', type: 'bigint' },
        { name: 'event_type', type: 'string' },
        { name: 'user_id', type: 'bigint' },
        { name: 'session_id', type: 'string' },
        { name: 'device', type: 'string' },
        { name: 'payload', type: 'struct<match_id:bigint,block_code:string,order_id:bigint,quantity:int,seat_id:bigint>' },
        // ISO8601 Z 文字列。クエリ側で from_iso8601_timestamp する
        { name: 'occurred_at', type: 'string' },
        { name: 'created_at', type: 'string' },
      ],
      {
        classification: 'json',
        inputFormat: 'org.apache.hadoop.mapred.TextInputFormat',
        outputFormat: 'org.apache.hadoop.hive.ql.io.HiveIgnoreKeyTextOutputFormat',
        serializationLibrary: 'org.apache.hive.hcatalog.data.JsonSerDe',
      },
    );

    eventsTable(
      'EventsCuratedTable',
      'events_curated',
      'curated/events',
      [
        { name: 'id', type: 'bigint' },
        { name: 'event_type', type: 'string' },
        { name: 'user_id', type: 'bigint' },
        { name: 'session_id', type: 'string' },
        { name: 'device', type: 'string' },
        { name: 'payload', type: 'string' },
        { name: 'occurred_at', type: 'timestamp' },
        { name: 'created_at', type: 'timestamp' },
        { name: 'match_id', type: 'bigint' },
        { name: 'jst_date', type: 'date' },
      ],
      {
        classification: 'parquet',
        inputFormat: 'org.apache.hadoop.hive.ql.io.parquet.MapredParquetInputFormat',
        outputFormat: 'org.apache.hadoop.hive.ql.io.parquet.MapredParquetOutputFormat',
        serializationLibrary: 'org.apache.hadoop.hive.ql.io.parquet.serde.ParquetHiveSerDe',
      },
    );

    const workGroup = new CfnWorkGroup(this, 'WorkGroup', {
      name: 'penusta-analytics',
      recursiveDeleteOption: true,
      workGroupConfiguration: {
        bytesScannedCutoffPerQuery: 1_000_000_000,
        enforceWorkGroupConfiguration: true,
        publishCloudWatchMetricsEnabled: false,
        engineVersion: { selectedEngineVersion: 'Athena engine version 3' },
        managedQueryResultsConfiguration: { enabled: true },
      },
    });

    new CfnOutput(this, 'BucketName', { value: bucket.bucketName });
    new CfnOutput(this, 'WorkGroupName', { value: workGroup.name });
    new CfnOutput(this, 'DatabaseName', { value: 'penusta_analytics' });
  }
}
