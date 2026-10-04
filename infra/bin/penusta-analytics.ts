import { App, LegacyStackSynthesizer, Tags } from 'aws-cdk-lib';
import { PenustaAnalyticsStack } from '../lib/penusta-analytics-stack';

// bootstrap しない前提。アセットを生む構成は使わない
const app = new App({ defaultStackSynthesizer: new LegacyStackSynthesizer() });

new PenustaAnalyticsStack(app, 'PenustaAnalytics', {
  env: { region: 'us-east-1' },
});

Tags.of(app).add('project', 'penusta-analytics');
