import { Aws, CfnOutput, Duration, RemovalPolicy, Stack, StackProps } from 'aws-cdk-lib';
import { CfnWorkGroup } from 'aws-cdk-lib/aws-athena';
import { BlockPublicAccess, Bucket, BucketEncryption } from 'aws-cdk-lib/aws-s3';
import { CfnDatabase } from 'aws-cdk-lib/aws-glue';
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

    new CfnDatabase(this, 'Database', {
      catalogId: Aws.ACCOUNT_ID,
      databaseInput: { name: 'penusta_analytics' },
    });

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
